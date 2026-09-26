<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\FaturaLinha;
use Throwable;

class FaturaService
{
    public function __construct(
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
        protected TenantManager $tenantManager,
    ) {
    }

    /**
     * @param  array<int, array{produto_id?: int, descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
     */
    public function criarRascunho(Empresa $empresa, Cliente $cliente, array $linhas, ?string $observacoes = null): Fatura
    {
        if (empty($linhas)) {
            throw new \InvalidArgumentException('Uma fatura precisa de pelo menos uma linha.');
        }

        return DB::transaction(function () use ($empresa, $cliente, $linhas, $observacoes) {
            $fatura = Fatura::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $cliente->id,
                // serie_id só é atribuída na emissão (ver emitir()) —
                // um rascunho pode nunca chegar a ser emitido, e não faz
                // sentido reservar/consumir uma série por isso. Usamos a
                // série "por omissão" (FT/A) só como referência do tipo.
                'serie_id' => $this->numeracaoService->obterOuCriarSerie($empresa->id, 'FT')->id,
                'estado' => 'rascunho',
                'moeda' => 'AOA',
                'observacoes' => $observacoes,
            ]);

            $this->substituirLinhas($fatura, $linhas);
            $this->recalcularTotais($fatura);

            return $fatura->fresh('linhas');
        });
    }

    /**
     * O passo que dá valor fiscal ao documento: atribui o número
     * sequencial definitivo, encadeia com o hash do documento anterior da
     * mesma série, assina com RSA, e só então marca como emitida — a
     * partir daqui a Imutavel trait bloqueia qualquer alteração.
     *
     * @throws Throwable
     */
    public function emitir(Fatura $fatura): Fatura
    {
        if ($fatura->estaEmitido()) {
            return $fatura; // idempotente: emitir uma fatura já emitida não faz nada
        }

        try {
            return DB::transaction(function () use ($fatura) {
                $resultado = $this->numeracaoService->proximoNumero($fatura->empresa_id, 'FT');

                $hashAnterior = $this->tenantManager->semTenant(
                    fn () => Fatura::query()
                        ->where('serie_id', $resultado['serie']->id)
                        ->where('numero_sequencial', $resultado['numero_sequencial'] - 1)
                        ->value('hash')
                );

                $fatura->serie_id = $resultado['serie']->id;

                $this->assinaturaFiscalService->assinarEEmitir(
                    $fatura,
                    $resultado['numero_sequencial'],
                    $resultado['numero_documento'],
                    now(),
                    $hashAnterior,
                );

                $fatura->save();

                Log::info('Fatura emitida', [
                    'fatura_id' => $fatura->id,
                    'numero_documento' => $fatura->numero_documento,
                    'empresa_id' => $fatura->empresa_id,
                ]);

                return $fatura->fresh('linhas');
            });
        } catch (Throwable $e) {
            Log::error('Falha ao emitir fatura', [
                'fatura_id' => $fatura->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<int, array{produto_id?: int, descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
     */
    protected function substituirLinhas(Fatura $fatura, array $linhas): void
    {
        $fatura->linhas()->delete();

        foreach (array_values($linhas) as $indice => $dadosLinha) {
            $linha = new FaturaLinha([
                'fatura_id' => $fatura->id,
                'produto_id' => $dadosLinha['produto_id'] ?? null,
                'descricao' => $dadosLinha['descricao'],
                'quantidade' => $dadosLinha['quantidade'],
                'preco_unitario' => $dadosLinha['preco_unitario'],
                'taxa_iva' => $dadosLinha['taxa_iva'],
                'ordem' => $indice,
            ]);

            $linha->calcularValores()->save();
        }
    }

    protected function recalcularTotais(Fatura $fatura): void
    {
        $linhas = $fatura->linhas()->get();

        $fatura->update([
            'valor_sem_iva' => $linhas->sum('valor_sem_iva'),
            'valor_iva' => $linhas->sum('valor_iva'),
            'valor_total' => $linhas->sum('valor_total'),
        ]);
    }
}
