<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\FaturaLinha;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\GuardaEmissao;
use Modules\Faturacao\Support\ValidacaoLinhas;
use Throwable;

class FaturaService
{
    public function __construct(
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
    ) {}

    /**
     * @param  array<int, array{produto_id?: int, descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
     */
    public function criarRascunho(Empresa $empresa, Cliente $cliente, array $linhas, ?string $observacoes = null): Fatura
    {
        if (empty($linhas)) {
            throw new \InvalidArgumentException('Uma fatura precisa de pelo menos uma linha.');
        }

        if ((int) $cliente->empresa_id !== (int) $empresa->id) {
            throw new \DomainException('O cliente não pertence a esta empresa.');
        }

        ValidacaoLinhas::validar($linhas, (int) $empresa->id);

        return DB::transaction(function () use ($empresa, $cliente, $linhas, $observacoes) {
            $fatura = Fatura::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $cliente->id,
                // Série do ano corrente, só como referência do tipo: a série
                // DEFINITIVA (e o número) são atribuídos em emitir().
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
     * Atribui número sequencial, encadeia o hash, assina (RSA) e marca como
     * emitida. Idempotente: emitir duas vezes devolve a mesma fatura sem
     * consumir um segundo número.
     *
     * @throws Throwable
     */
    public function emitir(Fatura $fatura): Fatura
    {
        try {
            return DB::transaction(function () use ($fatura) {
                // Lock explícito por empresa, SEM depender do tenant ambiente
                // (jobs, comandos e testes não passam pelo middleware).
                $fatura = Fatura::withoutGlobalScopes()
                    ->where('empresa_id', $fatura->empresa_id)
                    ->lockForUpdate()
                    ->findOrFail($fatura->id);

                if ($fatura->estaEmitido()) {
                    return $fatura->fresh('linhas');
                }

                GuardaEmissao::garantir((int) $fatura->empresa_id);

                GuardaEmissao::garantirLimiteMensalFaturas((int) $fatura->empresa_id);

                if ($fatura->linhas()->doesntExist()) {
                    throw new \DomainException('Não é possível emitir uma fatura sem linhas.');
                }

                // O valor assinado tem de bater exatamente com as linhas.
                $this->recalcularTotais($fatura);

                $resultado = $this->numeracaoService->proximoNumero($fatura->empresa_id, 'FT');

                $hashAnterior = $this->numeracaoService->hashDoDocumentoAnterior(
                    Fatura::class,
                    $resultado['serie']->id,
                    $resultado['numero_sequencial'],
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
            }, attempts: 5);
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

    /**
     * Edita um RASCUNHO (substitui cliente, linhas e observações). Lock na
     * linha da fatura: uma edição e uma emissão em simultâneo ficam
     * serializadas e a segunda vê o estado final. Uma fatura emitida nunca
     * se edita — corrige-se por Nota de Crédito.
     *
     * @param  array<int, array{produto_id?: int|null, descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
     */
    public function atualizarRascunho(Fatura $fatura, Cliente $cliente, array $linhas, ?string $observacoes = null): Fatura
    {
        if (empty($linhas)) {
            throw new \InvalidArgumentException('Uma fatura precisa de pelo menos uma linha.');
        }

        if ((int) $cliente->empresa_id !== (int) $fatura->empresa_id) {
            throw new \DomainException('O cliente não pertence a esta empresa.');
        }

        ValidacaoLinhas::validar($linhas, (int) $fatura->empresa_id);

        return DB::transaction(function () use ($fatura, $cliente, $linhas, $observacoes) {
            $atual = $this->rascunhoComLock($fatura);

            $atual->update(['cliente_id' => $cliente->id, 'observacoes' => $observacoes]);

            $this->substituirLinhas($atual, $linhas);
            $this->recalcularTotais($atual);

            return $atual->fresh('linhas');
        });
    }

    /** Elimina um RASCUNHO (sem número nem assinatura). Documentos emitidos nunca se apagam. */
    public function eliminarRascunho(Fatura $fatura): void
    {
        DB::transaction(function () use ($fatura) {
            $atual = $this->rascunhoComLock($fatura);

            $atual->linhas()->delete();
            $atual->delete();
        });

        Log::info('Rascunho de fatura eliminado', [
            'fatura_id' => $fatura->id,
            'empresa_id' => $fatura->empresa_id,
        ]);
    }

    /** Relê o rascunho com lock, sem depender do tenant ambiente, e recusa qualquer outro estado. */
    protected function rascunhoComLock(Fatura $fatura): Fatura
    {
        $atual = Fatura::withoutGlobalScopes()
            ->where('empresa_id', $fatura->empresa_id)
            ->lockForUpdate()
            ->findOrFail($fatura->id);

        if ($atual->estado !== 'rascunho') {
            throw new \DomainException('Só é possível alterar ou apagar faturas em rascunho.');
        }

        return $atual;
    }

    protected function recalcularTotais(Fatura $fatura): void
    {
        $linhas = $fatura->linhas()->get();

        $soma = fn (string $campo): string => Dinheiro::formatar(
            (int) $linhas->sum(fn ($l) => Dinheiro::centimos($l->{$campo}))
        );

        $fatura->update([
            'valor_sem_iva' => $soma('valor_sem_iva'),
            'valor_iva' => $soma('valor_iva'),
            'valor_total' => $soma('valor_total'),
        ]);
    }
}
