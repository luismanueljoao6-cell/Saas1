<?php

namespace Modules\Faturacao\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Compra;
use Modules\Faturacao\Models\CompraPagamento;
use Modules\Faturacao\Models\Fornecedor;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Support\Dinheiro;

class CompraService
{
    public function criarRascunho(Empresa $empresa, array $dados, int $utilizadorId): Compra
    {
        return DB::transaction(function () use ($empresa, $dados, $utilizadorId): Compra {
            $fornecedor = Fornecedor::query()
                ->where('empresa_id', $empresa->id)
                ->where('ativo', true)
                ->findOrFail($dados['fornecedor_id']);

            $linhasCalculadas = [];
            $subtotalCentimos = 0;
            $ivaCentimos = 0;

            foreach ($dados['linhas'] as $linha) {
                $produto = Produto::query()
                    ->where('empresa_id', $empresa->id)
                    ->where('controla_stock', true)
                    ->where('ativo', true)
                    ->findOrFail($linha['produto_id']);

                $quantidade = (string) $linha['quantidade'];
                $preco = (string) $linha['preco_unitario'];
                $taxaIva = (string) ($linha['taxa_iva'] ?? '0.00');
                $valores = Dinheiro::calcularLinha($quantidade, $preco, $taxaIva);
                $linhasCalculadas[] = [
                    'empresa_id' => $empresa->id,
                    'produto_id' => $produto->id,
                    'descricao' => $produto->nome,
                    'quantidade' => $quantidade,
                    'preco_unitario' => Dinheiro::formatar(Dinheiro::centimos($preco)),
                    'taxa_iva' => Dinheiro::formatar(Dinheiro::centimos($taxaIva)),
                    'subtotal' => $valores['sem_iva'],
                    'valor_iva' => $valores['iva'],
                    'total' => $valores['total'],
                ];
                $subtotalCentimos += Dinheiro::centimos($valores['sem_iva']);
                $ivaCentimos += Dinheiro::centimos($valores['iva']);
            }

            $compra = Compra::create([
                'empresa_id' => $empresa->id,
                'fornecedor_id' => $fornecedor->id,
                'numero' => 'CMP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'data_documento' => $dados['data_documento'],
                'data_vencimento' => $dados['data_vencimento'] ?? null,
                'referencia_fornecedor' => $dados['referencia_fornecedor'] ?? null,
                'estado' => 'rascunho',
                'subtotal' => Dinheiro::formatar($subtotalCentimos),
                'total_iva' => Dinheiro::formatar($ivaCentimos),
                'total' => Dinheiro::formatar($subtotalCentimos + $ivaCentimos),
                'observacoes' => $dados['observacoes'] ?? null,
                'criado_por' => $utilizadorId,
            ]);

            $compra->linhas()->createMany($linhasCalculadas);

            return $compra->load('fornecedor', 'linhas.produto');
        }, attempts: 3);
    }

    public function receber(Empresa $empresa, Compra $compra, int $utilizadorId): Compra
    {
        return DB::transaction(function () use ($empresa, $compra, $utilizadorId): Compra {
            $bloqueada = Compra::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($compra->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $bloqueada->fornecedor_id !== (int) $compra->fornecedor_id) {
                throw new DomainException('A compra não pertence ao fornecedor esperado.');
            }

            if ($bloqueada->estado !== 'rascunho') {
                throw new DomainException('Só é possível receber compras em rascunho.');
            }

            $linhas = $bloqueada->linhas()->orderBy('id')->get();
            if ($linhas->isEmpty()) {
                throw new DomainException('Não é possível receber uma compra sem linhas.');
            }

            foreach ($linhas as $linha) {
                $produto = Produto::query()
                    ->where('empresa_id', $empresa->id)
                    ->where('controla_stock', true)
                    ->findOrFail($linha->produto_id);

                $this->stock->entrada(
                    $empresa,
                    $produto,
                    (string) $linha->quantidade,
                    'Receção da compra '.$bloqueada->numero,
                    $utilizadorId,
                    'compra:'.$bloqueada->id.':linha:'.$linha->id
                );
            }

            $bloqueada->forceFill([
                'estado' => 'recebida',
                'recebida_em' => now(),
            ])->save();

            return $bloqueada->load('fornecedor', 'linhas.produto');
        }, attempts: 3);
    }


    public function registarPagamento(Empresa $empresa, Compra $compra, array $dados, int $utilizadorId): CompraPagamento
    {
        return DB::transaction(function () use ($empresa, $compra, $dados, $utilizadorId): CompraPagamento {
            $bloqueada = Compra::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($compra->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($bloqueada->estado !== 'recebida') {
                throw new DomainException('Só é possível pagar compras já recebidas.');
            }

            $valorCentimos = Dinheiro::centimos((string) $dados['valor']);
            $totalCentimos = Dinheiro::centimos((string) $bloqueada->total);
            $pagoCentimos = Dinheiro::centimos((string) $bloqueada->total_pago);
            $saldoCentimos = $totalCentimos - $pagoCentimos;

            if ($valorCentimos <= 0 || $valorCentimos > $saldoCentimos) {
                throw new DomainException('O pagamento deve ser positivo e não pode exceder o saldo em dívida.');
            }

            $pagamento = CompraPagamento::create([
                'empresa_id' => $empresa->id,
                'compra_id' => $bloqueada->id,
                'valor' => Dinheiro::formatar($valorCentimos),
                'data_pagamento' => $dados['data_pagamento'],
                'metodo' => $dados['metodo'],
                'referencia' => $dados['referencia'] ?? null,
                'observacoes' => $dados['observacoes'] ?? null,
                'registado_por' => $utilizadorId,
            ]);

            $novoPago = $pagoCentimos + $valorCentimos;
            $bloqueada->forceFill([
                'total_pago' => Dinheiro::formatar($novoPago),
                'estado_pagamento' => $novoPago === $totalCentimos ? 'pago' : 'parcial',
            ])->save();

            return $pagamento;
        }, attempts: 3);
    }

    public function __construct(private readonly StockService $stock) {}
}
