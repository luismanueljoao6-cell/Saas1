<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\NotaCreditoDebitoLinha;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\GuardaEmissao;
use Modules\Faturacao\Support\ValidacaoLinhas;
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
        // Lock na fatura: dois pedidos de anulação em simultâneo ficam
        // serializados e o segundo já vê o rascunho do primeiro.
        return DB::transaction(function () use ($fatura, $motivo) {
            $origem = Fatura::withoutGlobalScopes()
                ->where('empresa_id', $fatura->empresa_id)
                ->lockForUpdate()
                ->findOrFail($fatura->id);

            return $this->prepararAnulacaoTotal($origem, $motivo);
        });
    }

    protected function prepararAnulacaoTotal(Fatura $fatura, string $motivo): NotaCreditoDebito
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
            'motivo_isencao' => $linha->motivo_isencao,
        ])->all();

        return $this->criarRascunho($fatura->empresa_id, $fatura->cliente_id, 'credito', $motivo, $linhas, $fatura->id);
    }

    /**
     * @param  array<int, array{descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
     */
    /** Elimina uma nota ainda em rascunho; uma nota emitida é um documento fiscal e não se apaga. */
    public function eliminarRascunho(NotaCreditoDebito $nota): void
    {
        DB::transaction(function () use ($nota) {
            $atual = NotaCreditoDebito::withoutGlobalScopes()
                ->where('empresa_id', $nota->empresa_id)
                ->lockForUpdate()
                ->findOrFail($nota->id);

            if ($atual->estado !== 'rascunho') {
                throw new \DomainException('Só é possível apagar notas em rascunho.');
            }

            $atual->linhas()->delete();
            $atual->delete();
        });

        Log::info('Rascunho de nota eliminado', [
            'nota_id' => $nota->id,
            'empresa_id' => $nota->empresa_id,
        ]);
    }

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

        if ($tipo === 'credito' && $faturaId === null) {
            throw new \DomainException('Uma nota de crédito exige a fatura de origem.');
        }

        if (empty($linhas)) {
            throw new \InvalidArgumentException('Uma nota de crédito/débito precisa de pelo menos uma linha.');
        }

        $cliente = Cliente::withoutGlobalScopes()->where('empresa_id', $empresaId)->find($clienteId);

        if (! $cliente) {
            throw new \DomainException('O cliente não pertence a esta empresa.');
        }

        $debitoSemIva = $this->debitoSemIva($tipo);

        // Notas de crédito espelham a fatura original: não se re-verificam as taxas (mas as linhas isentas
        // têm de trazer o motivo de isenção). Notas de débito sem IVA não usam a tabela de taxas nem levam
        // motivo de isenção: exigem taxa 0 (abaixo).
        ValidacaoLinhas::validar(
            $linhas,
            $empresaId,
            verificarTaxas: $tipo === 'debito' && ! $debitoSemIva,
            exigirMotivoIsencao: $tipo === 'credito' || ($tipo === 'debito' && ! $debitoSemIva),
        );

        if ($debitoSemIva) {
            foreach (array_values($linhas) as $indice => $dadosLinha) {
                if (Dinheiro::centimos($dadosLinha['taxa_iva']) !== 0) {
                    throw new \DomainException('Linha '.($indice + 1).': uma nota de débito não liquida IVA; a taxa tem de ser 0.');
                }
            }
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

        return DB::transaction(function () use ($empresaId, $clienteId, $tipo, $motivo, $linhas, $faturaId, $debitoSemIva) {
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
                    'motivo_isencao' => $debitoSemIva ? null : ValidacaoLinhas::normalizarMotivo($dadosLinha['motivo_isencao'] ?? null),
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

                GuardaEmissao::garantir((int) $nota->empresa_id);

                if ($nota->linhas()->doesntExist()) {
                    throw new \DomainException('Não é possível emitir uma nota sem linhas.');
                }

                $this->recalcularTotais($nota);
                $this->garantirDebitoSemIva($nota);
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
        if ($nota->tipo !== 'credito') {
            return;
        }

        if (! $nota->fatura_id) {
            throw new \DomainException('Uma nota de crédito exige a fatura de origem.');
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

        $this->garantirLimitePorTaxa($nota, $fatura);
    }

    /**
     * Além do total bruto, o valor líquido creditado a cada taxa de IVA nunca
     * pode exceder o valor líquido faturado a essa taxa (impede "trocar" IVA
     * entre taxas dentro do mesmo limite global).
     */
    protected function garantirLimitePorTaxa(NotaCreditoDebito $nota, Fatura $fatura): void
    {
        $agrupar = function ($linhas): array {
            $porTaxa = [];

            foreach ($linhas as $linha) {
                $taxa = number_format((float) $linha->taxa_iva, 2, '.', '');
                $porTaxa[$taxa] = ($porTaxa[$taxa] ?? 0) + Dinheiro::centimos($linha->valor_sem_iva);
            }

            return $porTaxa;
        };

        $faturado = $agrupar($fatura->linhas()->get());
        $creditado = $agrupar($nota->linhas()->get());

        $anteriores = NotaCreditoDebito::withoutGlobalScopes()
            ->where('empresa_id', $nota->empresa_id)
            ->where('fatura_id', $fatura->id)
            ->where('tipo', 'credito')
            ->where('estado', 'emitida')
            ->where('id', '!=', $nota->id)
            ->with('linhas')
            ->get();

        foreach ($anteriores as $anterior) {
            foreach ($agrupar($anterior->linhas) as $taxa => $valor) {
                $creditado[$taxa] = ($creditado[$taxa] ?? 0) + $valor;
            }
        }

        foreach ($creditado as $taxa => $valor) {
            if ($valor > ($faturado[$taxa] ?? 0)) {
                throw new \DomainException("O valor creditado à taxa de IVA {$taxa}% excede o valor faturado a essa taxa.");
            }
        }
    }

    /** Uma nota de débito não liquida IVA, salvo se a configuração o permitir expressamente. */
    protected function debitoSemIva(string $tipo): bool
    {
        return $tipo === 'debito' && ! config('faturacao.nota_debito_liquida_iva', false);
    }

    /** Última barreira antes de emitir: rascunhos antigos com IVA não passam. */
    protected function garantirDebitoSemIva(NotaCreditoDebito $nota): void
    {
        if (! $this->debitoSemIva((string) $nota->tipo)) {
            return;
        }

        $comIva = Dinheiro::centimos($nota->valor_iva) !== 0
            || $nota->linhas()->get()->contains(fn ($linha) => Dinheiro::centimos($linha->taxa_iva) !== 0);

        if ($comIva) {
            throw new \DomainException('Uma nota de débito não pode liquidar IVA.');
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
