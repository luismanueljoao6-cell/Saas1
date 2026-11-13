<?php

namespace Modules\Faturacao\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Compra;
use Modules\Faturacao\Models\Fornecedor;
use Modules\Faturacao\Models\Produto;

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
            $subtotal = 0;
            $totalIva = 0;

            foreach ($dados['linhas'] as $linha) {
                $produto = Produto::query()
                    ->where('empresa_id', $empresa->id)
                    ->where('controla_stock', true)
                    ->findOrFail($linha['produto_id']);

                $quantidade = (float) $linha['quantidade'];
                $preco = (float) $linha['preco_unitario'];
                $base = round($quantidade * $preco, 2);
                $iva = round($base * ((float) ($linha['taxa_iva'] ?? 0) / 100), 2);
                $linhasCalculadas[] = [
                    'empresa_id' => $empresa->id,
                    'produto_id' => $produto->id,
                    'descricao' => $produto->nome,
                    'quantidade' => number_format($quantidade, 3, '.', ''),
                    'preco_unitario' => number_format($preco, 2, '.', ''),
                    'taxa_iva' => number_format((float) ($linha['taxa_iva'] ?? 0), 2, '.', ''),
                    'subtotal' => number_format($base, 2, '.', ''),
                    'valor_iva' => number_format($iva, 2, '.', ''),
                    'total' => number_format($base + $iva, 2, '.', ''),
                ];
                $subtotal += $base;
                $totalIva += $iva;
            }

            $compra = Compra::create([
                'empresa_id' => $empresa->id,
                'fornecedor_id' => $fornecedor->id,
                'numero' => 'CMP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'data_documento' => $dados['data_documento'],
                'data_vencimento' => $dados['data_vencimento'] ?? null,
                'referencia_fornecedor' => $dados['referencia_fornecedor'] ?? null,
                'estado' => 'rascunho',
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'total_iva' => number_format($totalIva, 2, '.', ''),
                'total' => number_format($subtotal + $totalIva, 2, '.', ''),
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

    public function __construct(private readonly StockService $stock) {}
}
