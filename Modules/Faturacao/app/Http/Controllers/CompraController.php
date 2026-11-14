<?php

namespace Modules\Faturacao\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Compra;
use Modules\Faturacao\Models\Fornecedor;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Services\CompraService;

class CompraController extends Controller
{
    public function index(Request $request): View
    {
        $dados = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:rascunho,recebida,cancelada'],
            'pagamento' => ['nullable', 'in:pendente,parcial,pago'],
            'vencimento' => ['nullable', 'in:vencidas,em_dia'],
        ]);

        $compras = Compra::query()
            ->with('fornecedor')
            ->when($dados['q'] ?? null, function ($query, string $termo): void {
                $query->where(function ($subquery) use ($termo): void {
                    $subquery->where('numero', 'like', '%'.$termo.'%')
                        ->orWhere('referencia_fornecedor', 'like', '%'.$termo.'%')
                        ->orWhereHas('fornecedor', fn ($fornecedor) => $fornecedor->where('nome', 'like', '%'.$termo.'%'));
                });
            })
            ->when($dados['estado'] ?? null, fn ($query, string $estado) => $query->where('estado', $estado))
            ->when($dados['pagamento'] ?? null, fn ($query, string $estado) => $query->where('estado_pagamento', $estado))
            ->when(($dados['vencimento'] ?? null) === 'vencidas', fn ($query) => $query->where('estado', 'recebida')->whereColumn('total_pago', '<', 'total')->whereNotNull('data_vencimento')->whereDate('data_vencimento', '<', today()))
            ->when(($dados['vencimento'] ?? null) === 'em_dia', fn ($query) => $query
                ->where('estado', 'recebida')
                ->whereColumn('total_pago', '<', 'total')
                ->where(function ($subquery) {
                    $subquery->whereNull('data_vencimento')
                        ->orWhereDate('data_vencimento', '>=', today());
                }))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $baseFinanceira = Compra::query()->where('estado', 'recebida')->whereColumn('total_pago', '<', 'total');
        $indicadores = [
            'saldo_em_divida' => (string) ($baseFinanceira->sum(DB::raw('total - total_pago')) ?? '0.00'),
            'compras_vencidas' => (clone $baseFinanceira)->whereNotNull('data_vencimento')->whereDate('data_vencimento', '<', today())->count(),
            'saldo_vencido' => (string) ((clone $baseFinanceira)->whereNotNull('data_vencimento')->whereDate('data_vencimento', '<', today())->sum(DB::raw('total - total_pago')) ?? '0.00'),
        ];

        return view('faturacao::compras.index', compact('compras', 'indicadores'));
    }

    public function criar(): View
    {
        return view('faturacao::compras.criar', [
            'fornecedores' => Fornecedor::query()->where('ativo', true)->orderBy('nome')->get(),
            'produtos' => Produto::query()
                ->where('ativo', true)->where('controla_stock', true)->orderBy('nome')->get(),
        ]);
    }

    public function guardar(Request $request, CompraService $compras): RedirectResponse
    {
        $empresa = Empresa::query()->findOrFail($request->user()->empresa_id);
        $empresaId = (int) $empresa->id;
        $dados = $request->validate([
            'fornecedor_id' => ['required', 'integer', 'exists:fornecedores,id'],
            'data_documento' => ['required', 'date'],
            'data_vencimento' => ['nullable', 'date', 'after_or_equal:data_documento'],
            'referencia_fornecedor' => ['nullable', 'string', 'max:80'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'linhas' => ['required', 'array', 'min:1', 'max:100'],
            'linhas.*.selecionado' => ['nullable', 'in:1'],
            'linhas.*.produto_id' => ['required', 'integer', 'exists:produtos,id'],
            'linhas.*.quantidade' => ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:99999999999.999'],
            'linhas.*.preco_unitario' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999.99'],
            'linhas.*.taxa_iva' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:100'],
        ]);

        abort_unless(
            Fornecedor::query()->where('empresa_id', $empresaId)->whereKey($dados['fornecedor_id'])->exists(),
            404
        );

        foreach ($dados['linhas'] as $linha) {
            abort_unless(
                Produto::query()
                    ->where('empresa_id', $empresaId)->where('controla_stock', true)->where('ativo', true)
                    ->whereKey($linha['produto_id'])->exists(),
                404
            );
        }

        $dados['linhas'] = collect($dados['linhas'])
            ->filter(fn (array $linha): bool => ($linha['selecionado'] ?? null) === '1')
            ->map(function (array $linha): array {
                unset($linha['selecionado']);

                return $linha;
            })
            ->values()
            ->all();

        if ($dados['linhas'] === []) {
            return back()->withInput()->withErrors(['linhas' => 'Seleciona pelo menos um artigo para a compra.']);
        }

        $compra = $compras->criarRascunho($empresa, $dados, (int) $request->user()->id);

        return to_route('faturacao.compras.mostrar', $compra)->with('sucesso', 'Compra registada como rascunho.');
    }

    public function mostrar(int $compraId): View
    {
        // Resolver o ID só depois do middleware tenant identificar a empresa.
        $compra = Compra::query()->findOrFail($compraId);

        return view('faturacao::compras.mostrar', [
            'compra' => $compra->load('fornecedor', 'linhas.produto', 'pagamentos'),
        ]);
    }

    public function pagar(Request $request, int $compraId, CompraService $compras): RedirectResponse
    {
        $empresa = Empresa::query()->findOrFail($request->user()->empresa_id);
        $compra = Compra::query()->findOrFail($compraId);
        $dados = $request->validate([
            'valor' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999.99'],
            'data_pagamento' => ['required', 'date'],
            'metodo' => ['required', 'in:dinheiro,transferencia,referencia,multicaixa,outro'],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
        ]);

        $dados['observacoes'] = $dados['observacoes'] ?? null;

        try {
            $compras->registarPagamento($empresa, $compra, $dados, (int) $request->user()->id);
        } catch (DomainException $exception) {
            return back()->withErrors(['pagamento' => $exception->getMessage()]);
        }

        return back()->with('sucesso', 'Pagamento registado.');
    }

    public function receber(Request $request, int $compraId, CompraService $compras): RedirectResponse
    {
        $empresa = Empresa::query()->findOrFail($request->user()->empresa_id);
        $compra = Compra::query()->findOrFail($compraId);

        try {
            $compras->receber($empresa, $compra, (int) $request->user()->id);
        } catch (DomainException $exception) {
            return back()->withErrors(['compra' => $exception->getMessage()]);
        }

        return back()->with('sucesso', 'Compra recebida e stock atualizado.');
    }
}
