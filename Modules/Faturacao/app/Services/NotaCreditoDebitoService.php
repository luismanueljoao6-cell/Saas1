<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\NotaCreditoDebitoLinha;
use Throwable;

/**
 * Como uma Fatura emitida é imutável (ver Traits\Imutavel), a ÚNICA forma
 * de corrigir um erro nela é emitir uma Nota de Crédito a anulá-la (no todo
 * ou em parte) — nunca editar a fatura original. Esta classe segue
 * deliberadamente a mesma disciplina do FaturaService (numeração + hash
 * encadeado + imutabilidade), mas com a sua PRÓPRIA cadeia de hash (série
 * NC/ND, nunca partilhada com a série FT).
 */
class NotaCreditoDebitoService
{
    public function __construct(
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
        protected TenantManager $tenantManager,
    ) {
    }

    /**
     * Gera uma nota de crédito que anula uma fatura na totalidade,
     * replicando as suas linhas. Para anulação PARCIAL, usa
     * criarRascunho() diretamente com as linhas que quiseres corrigir.
     */
    public function criarParaAnularFatura(Fatura $fatura, string $motivo): NotaCreditoDebito
    {
        if (! $fatura->estaEmitido()) {
            throw new \InvalidArgumentException('Só é possível emitir uma nota de crédito sobre uma fatura já emitida.');
        }

        $linhas = $fatura->linhas->map(fn ($linha) => [
            'descricao' => $linha->descricao,
            'quantidade' => (float) $linha->quantidade,
            'preco_unitario' => (float) $linha->preco_unitario,
            'taxa_iva' => (float) $linha->taxa_iva,
        ])->all();

        return $this->criarRascunho($fatura->empresa_id, $fatura->cliente_id, 'credito', $motivo, $linhas, $fatura->id);
    }

    /**
     * @param  array<int, array{descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
     */
    public function criarRascunho(
        int $empresaId,
        int $clienteId,
        string $tipo,
        string $motivo,
        array $linhas,
        ?int $faturaId = null,
    ): NotaCreditoDebito {
        if (empty($linhas)) {
            throw new \InvalidArgumentException('Uma nota de crédito/débito precisa de pelo menos uma linha.');
        }

        return DB::transaction(function () use ($empresaId, $clienteId, $tipo, $motivo, $linhas, $faturaId) {
            $tipoDocumento = $tipo === 'debito' ? 'ND' : 'NC';

            $nota = NotaCreditoDebito::create([
                'empresa_id' => $empresaId,
                'cliente_id' => $clienteId,
                'fatura_id' => $faturaId,
                'tipo' => $tipo,
                'motivo' => $motivo,
                'serie_id' => $this->numeracaoService->obterOuCriarSerie($empresaId, $tipoDocumento)->id,
                'estado' => 'rascunho',
                'moeda' => 'AOA',
            ]);

            foreach (array_values($linhas) as $indice => $dadosLinha) {
                $linha = new NotaCreditoDebitoLinha([
                    'nota_credito_debito_id' => $nota->id,
                    'descricao' => $dadosLinha['descricao'],
                    'quantidade' => $dadosLinha['quantidade'],
                    'preco_unitario' => $dadosLinha['preco_unitario'],
                    'taxa_iva' => $dadosLinha['taxa_iva'],
                    'ordem' => $indice,
                ]);

                $linha->calcularValores()->save();
            }

            $linhasCriadas = $nota->linhas()->get();
            $nota->update([
                'valor_sem_iva' => $linhasCriadas->sum('valor_sem_iva'),
                'valor_iva' => $linhasCriadas->sum('valor_iva'),
                'valor_total' => $linhasCriadas->sum('valor_total'),
            ]);

            return $nota->fresh('linhas');
        });
    }

    public function emitir(NotaCreditoDebito $nota): NotaCreditoDebito
    {
        if ($nota->estaEmitido()) {
            return $nota;
        }

        try {
            return DB::transaction(function () use ($nota) {
                $tipoDocumento = $nota->tipoDocumentoFiscal();

                $resultado = $this->numeracaoService->proximoNumero($nota->empresa_id, $tipoDocumento);

                $hashAnterior = $this->tenantManager->semTenant(
                    fn () => NotaCreditoDebito::query()
                        ->where('serie_id', $resultado['serie']->id)
                        ->where('numero_sequencial', $resultado['numero_sequencial'] - 1)
                        ->value('hash')
                );

                $nota->serie_id = $resultado['serie']->id;

                $this->assinaturaFiscalService->assinarEEmitir(
                    $nota,
                    $resultado['numero_sequencial'],
                    $resultado['numero_documento'],
                    now(),
                    $hashAnterior,
                );

                $nota->save();

                Log::info('Nota de crédito/débito emitida', [
                    'nota_id' => $nota->id,
                    'numero_documento' => $nota->numero_documento,
                    'fatura_id' => $nota->fatura_id,
                ]);

                return $nota->fresh('linhas');
            });
        } catch (Throwable $e) {
            Log::error('Falha ao emitir nota de crédito/débito', [
                'nota_id' => $nota->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
