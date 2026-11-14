<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Orcamento;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\ValidacaoLinhas;

class OrcamentoService
{
    public function criar(Empresa $empresa, Cliente $cliente, array $linhas, ?string $validade, ?string $observacoes): Orcamento
    {
        if ((int) $cliente->empresa_id !== (int) $empresa->id) {
            throw new \DomainException('O cliente não pertence a esta empresa.');
        }

        if (count($linhas) < 1 || count($linhas) > 100) {
            throw new \InvalidArgumentException('O orçamento deve conter entre 1 e 100 linhas.');
        }

        ValidacaoLinhas::validar($linhas, (int) $empresa->id);
        $totais = $this->totais($linhas);

        return Orcamento::create([
            'empresa_id' => $empresa->id,
            'cliente_id' => $cliente->id,
            'numero' => 'ORC-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'estado' => 'rascunho',
            'data_validade' => $validade,
            'linhas' => array_values($linhas),
            ...$totais,
            'observacoes' => $observacoes,
        ]);
    }

    /**
     * Atualiza apenas rascunhos. O lock e a consulta sem scopes garantem
     * isolamento por empresa mesmo em jobs ou chamadas sem tenant ambiente.
     *
     * @param array<int, array<string, mixed>> $linhas
     */
    public function atualizarRascunho(Orcamento $orcamento, Cliente $cliente, array $linhas, ?string $validade, ?string $observacoes): Orcamento
    {
        if ((int) $cliente->empresa_id !== (int) $orcamento->empresa_id) {
            throw new \DomainException('O cliente não pertence a esta empresa.');
        }

        if (count($linhas) < 1 || count($linhas) > 100) {
            throw new \InvalidArgumentException('O orçamento deve conter entre 1 e 100 linhas.');
        }

        if ($validade !== null && Carbon::parse($validade)->startOfDay()->isBefore(today())) {
            throw new \DomainException('A data de validade não pode estar no passado.');
        }

        ValidacaoLinhas::validar($linhas, (int) $orcamento->empresa_id);
        $totais = $this->totais($linhas);

        return DB::transaction(function () use ($orcamento, $cliente, $linhas, $validade, $observacoes, $totais) {
            $atual = Orcamento::withoutGlobalScopes()
                ->where('empresa_id', $orcamento->empresa_id)
                ->lockForUpdate()
                ->findOrFail($orcamento->id);

            if ($atual->estado !== 'rascunho' || $atual->fatura_id !== null) {
                throw new \DomainException('Só é possível editar orçamentos em rascunho.');
            }

            $atual->update([
                'cliente_id' => $cliente->id,
                'data_validade' => $validade,
                'linhas' => array_values($linhas),
                ...$totais,
                'observacoes' => $observacoes,
            ]);

            return $atual->fresh('cliente');
        }, attempts: 3);
    }

    /**
     * Cria uma nova proposta comercial a partir de outra, sem reutilizar
     * número, estado, fatura associada ou identidade do documento original.
     */
    public function duplicar(Orcamento $origem, Empresa $empresa, ?Cliente $cliente = null, ?string $validade = null): Orcamento
    {
        if ($validade !== null && Carbon::parse($validade)->startOfDay()->isBefore(today())) {
            throw new \DomainException('A data de validade não pode estar no passado.');
        }

        return DB::transaction(function () use ($origem, $empresa, $cliente, $validade) {
            $atual = Orcamento::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->lockForUpdate()
                ->findOrFail($origem->id);

            if ($cliente !== null && (int) $cliente->empresa_id !== (int) $empresa->id) {
                throw new \DomainException('O cliente selecionado não pertence a esta empresa.');
            }

            $clienteFinal = $cliente ?? Cliente::query()
                ->where('empresa_id', $empresa->id)
                ->findOrFail($atual->cliente_id);

            $linhas = array_values($atual->linhas ?? []);
            ValidacaoLinhas::validar($linhas, (int) $empresa->id);
            $totais = $this->totais($linhas);

            return Orcamento::create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $clienteFinal->id,
                'numero' => 'ORC-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
                'estado' => 'rascunho',
                'data_validade' => $validade,
                'linhas' => $linhas,
                ...$totais,
                'observacoes' => $atual->observacoes,
                'fatura_id' => null,
            ]);
        }, attempts: 3);
    }

    public function alterarEstado(Orcamento $orcamento, string $estado): Orcamento
    {
        if (in_array($estado, ['enviado', 'aceite', 'recusado'], true) === false) {
            throw new \InvalidArgumentException('Estado de orçamento inválido.');
        }

        return DB::transaction(function () use ($orcamento, $estado) {
            $atual = Orcamento::withoutGlobalScopes()
                ->where('empresa_id', $orcamento->empresa_id)
                ->lockForUpdate()
                ->findOrFail($orcamento->id);

            if ($atual->estado === 'convertido' || $atual->fatura_id !== null) {
                throw new \DomainException('Um orçamento convertido não pode mudar de estado.');
            }

            if ($atual->estado === 'expirado' || ($atual->data_validade && $atual->data_validade->isBefore(today()))) {
                throw new \DomainException('Este orçamento expirou e já não pode ser alterado.');
            }

            $transicoesPermitidas = [
                'rascunho' => ['enviado', 'aceite'],
                'enviado' => ['aceite', 'recusado'],
            ];

            if (in_array($estado, $transicoesPermitidas[$atual->estado] ?? [], true) === false) {
                throw new \DomainException('Transição de estado do orçamento não permitida.');
            }

            $atual->update(['estado' => $estado]);

            return $atual->refresh();
        });
    }

    public function converter(Orcamento $orcamento, Empresa $empresa, FaturaService $faturas): Fatura
    {
        return DB::transaction(function () use ($orcamento, $empresa, $faturas) {
            $atual = Orcamento::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->lockForUpdate()
                ->findOrFail($orcamento->id);

            if ((int) $atual->empresa_id !== (int) $empresa->id) {
                throw new \DomainException('Orçamento de outra empresa.');
            }

            if ($atual->estado !== 'aceite' || $atual->fatura_id !== null) {
                throw new \DomainException('Só é possível converter um orçamento aceite e ainda não convertido.');
            }

            if ($atual->estado === 'expirado' || ($atual->data_validade && $atual->data_validade->isBefore(today()))) {
                throw new \DomainException('O orçamento expirou.');
            }

            $cliente = Cliente::query()->findOrFail($atual->cliente_id);
            $fatura = $faturas->criarRascunho($empresa, $cliente, $atual->linhas, $atual->observacoes);

            $atual->update([
                'estado' => 'convertido',
                'fatura_id' => $fatura->id,
            ]);

            return $fatura;
        }, attempts: 3);
    }

    protected function totais(array $linhas): array
    {
        $semIva = 0;
        $iva = 0;

        foreach ($linhas as $linha) {
            $valores = Dinheiro::calcularLinha($linha['quantidade'], $linha['preco_unitario'], $linha['taxa_iva']);
            $semIva += Dinheiro::centimos($valores['sem_iva']);
            $iva += Dinheiro::centimos($valores['iva']);
        }

        return [
            'valor_sem_iva' => Dinheiro::formatar($semIva),
            'valor_iva' => Dinheiro::formatar($iva),
            'valor_total' => Dinheiro::formatar($semIva + $iva),
        ];
    }
}
