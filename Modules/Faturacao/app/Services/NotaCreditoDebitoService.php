<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\NotaCreditoDebitoLinha;
use Modules\Faturacao\Support\Dinheiro;
use Throwable;

/**
 * Uma Fatura emitida é imutável: a ÚNICA forma de a corrigir é uma Nota de
 * Crédito (total ou parcial). Cadeia de hash própria (série NC/ND).
 */
class NotaCreditoDebitoService
{
    public function __construct(
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
    ) {}

    /**
     * Anulação TOTAL (uma única vez por fatura). Para correções parciais usa
     * criarRascunho() com as linhas a corrigir.
     */
    public function criarParaAnularFatura(Fatura $fatura, string $motivo): NotaCreditoDebito
    {
        if (! $fatura->estaEmitido()) {
            throw new \InvalidArgumentException('Só é possível emitir uma nota de crédito sobre uma fatura já emitida.');
        }

        $jaTemCredito = NotaCreditoDebito::withoutGlobalScopes()
            ->where('empresa_id', $fatura->empresa_id)
            ->where('fatura_id', $fatura->id)
            ->where('tipo', 'credito')
            ->exists();

        if ($jaTemCredito) {
            throw new \DomainException('Esta fatura já tem uma nota de crédito associada.');
        }

        $linhas = $fatura->linhas()->get()->map(fn ($linha) => [
            'descricao' => $linha->descricao,
            'quantidade' => $linha->quantidade,
            'preco_unitario' => $linha->preco_unitario,
            'taxa_iva' => $linha->taxa_iva,
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
        if (! in_array($tipo, ['credito', 'debito'], true)) {
            throw new \InvalidArgumentException("Tipo inválido: usa 'credito' ou 'debito'.");
        }

        if (trim($motivo) === '') {
            throw new \InvalidArgumentException('O motivo é obrigatório.');
        }

        if (empty($linhas)) {
            throw new \InvalidArgumentException('Uma nota de crédito/débito precisa de pelo menos uma linha.');
        }

        $cliente = Cliente::withoutGlobalScopes()->where('empresa_id', $empresaId)->find($clienteId);

        if (! $cliente) {
            throw new \DomainException('O cliente não pertence a esta empresa.');
        }

        if ($faturaId !== null) {
            $origem = Fatura::withoutGlobalScopes()->where('empresa_id', $empresaId)->find($faturaId);

            if (! $origem || ! $origem->estaEmitido()) {
                throw new \DomainException('A fatura de origem tem de existir nesta empresa e estar emitida.');
            }

            if ((int) $origem->cliente_id !== $clienteId) {
                throw new \DomainException('O cliente da nota tem de ser o da fatura de origem.');
            }
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
                (new NotaCreditoDebitoLinha([
                    'nota_credito_debito_id' => $nota->id,
                    'descricao' => $dadosLinha['descricao'],
                    'quantidade' => $dadosLinha['quantidade'],
                    'preco_unitario' => $dadosLinha['preco_unitario'],
                    'taxa_iva' => $dadosLinha['taxa_iva'],
                    'ordem' => $indice,
                ]))->calcularValores()->save();
            }

            $this->recalcularTotais($nota);

            return $nota->fresh('linhas');
        });
    }

    /**
     * Idempotente e seguro sob concorrência: relê a nota com lock dentro da
     * transação (antes verificava um objeto em memória → números duplicados).
     */
    public function emitir(NotaCreditoDebito $nota): NotaCreditoDebito
    {
        try {
            return DB::transaction(function () use ($nota) {
                $nota = NotaCreditoDebito::withoutGlobalScopes()
                    ->where('empresa_id', $nota->empresa_id)
                    ->lockForUpdate()
                    ->findOrFail($nota->id);

                if ($nota->estaEmitido()) {
                    return $nota->fresh('linhas');
                }

                if ($nota->linhas()->doesntExist()) {
                    throw new \DomainException('Não é possível emitir uma nota sem linhas.');
                }

                $this->recalcularTotais($nota);
                $this->garantirLimiteDeCredito($nota);

                $resultado = $this->numeracaoService->proximoNumero($nota->empresa_id, $nota->tipoDocumentoFiscal());

                $hashAnterior = $this->numeracaoService->hashDoDocumentoAnterior(
                    NotaCreditoDebito::class,
                    $resultado['serie']->id,
                    $resultado['numero_sequencial'],
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
            }, attempts: 5);
        } catch (Throwable $e) {
            Log::error('Falha ao emitir nota de crédito/débito', [
                'nota_id' => $nota->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /** Soma das NC emitidas + esta nota nunca pode exceder o valor da fatura. */
    protected function garantirLimiteDeCredito(NotaCreditoDebito $nota): void
    {
        if ($nota->tipo !== 'credito' || ! $nota->fatura_id) {
            return;
        }

        // Lock na fatura serializa emissões concorrentes de NC sobre ela.
        $fatura = Fatura::withoutGlobalScopes()
            ->where('empresa_id', $nota->empresa_id)
            ->lockForUpdate()
            ->findOrFail($nota->fatura_id);

        $jaCreditado = NotaCreditoDebito::withoutGlobalScopes()
            ->where('empresa_id', $nota->empresa_id)
            ->where('fatura_id', $fatura->id)
            ->where('tipo', 'credito')
            ->where('estado', 'emitida')
            ->where('id', '!=', $nota->id)
            ->get(['valor_total'])
            ->sum(fn ($n) => Dinheiro::centimos($n->valor_total));

        if ($jaCreditado + Dinheiro::centimos($nota->valor_total) > Dinheiro::centimos($fatura->valor_total)) {
            throw new \DomainException('O total creditado excede o valor da fatura de origem.');
        }
    }

    protected function recalcularTotais(NotaCreditoDebito $nota): void
    {
        $linhas = $nota->linhas()->get();

        $soma = fn (string $campo): string => Dinheiro::formatar(
            (int) $linhas->sum(fn ($l) => Dinheiro::centimos($l->{$campo}))
        );

        $nota->update([
            'valor_sem_iva' => $soma('valor_sem_iva'),
            'valor_iva' => $soma('valor_iva'),
            'valor_total' => $soma('valor_total'),
        ]);
    }
}
