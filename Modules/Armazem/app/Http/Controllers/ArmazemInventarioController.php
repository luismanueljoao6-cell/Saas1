<?php

namespace Modules\Armazem\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\Armazem\Models\Armazem;
use Modules\Armazem\Models\ArmazemInventario;
use Modules\Armazem\Services\ArmazemInventarioService;
use Modules\Faturacao\Models\Produto;

class ArmazemInventarioController extends Controller
{
    public function index(Request $request): View
    {
        $empresaId = (int) $request->user()->empresa_id;

        return view('armazem::inventarios.index', [
            'armazens' => Armazem::query()->where('ativo', true)->orderBy('nome')->get(),
            'produtos' => Produto::query()
                ->where('ativo', true)
                ->where('controla_stock', true)
                ->orderBy('nome')
                ->get(['id', 'nome']),
            'inventarios' => ArmazemInventario::query()
                ->with(['armazem:id,nome,codigo', 'utilizadorAbertura:id,name', 'linhas.produto:id,nome'])
                ->where('empresa_id', $empresaId)
                ->latest()
                ->paginate(10)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request, ArmazemInventarioService $service): RedirectResponse
    {
        $empresaId = (int) $request->user()->empresa_id;
        $dados = $request->validate([
            'armazem_id' => [
                'required',
                'integer',
                Rule::exists('armazens', 'id')->where(fn ($query) => $query
                    ->where('empresa_id', $empresaId)
                    ->where('ativo', true)),
            ],
            'produto_ids' => ['required', 'array', 'min:1'],
            'produto_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('produtos', 'id')->where(fn ($query) => $query
                    ->where('empresa_id', $empresaId)
                    ->where('ativo', true)
                    ->where('controla_stock', true)),
            ],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $service->abrir(
                $request->user()->empresa,
                Armazem::query()->findOrFail($dados['armazem_id']),
                $dados['produto_ids'],
                (int) $request->user()->id,
                $dados['observacoes'] ?? null,
            );
        } catch (DomainException | InvalidArgumentException $exception) {
            return back()->withInput()->with('erro', $exception->getMessage());
        }

        return back()->with('sucesso', 'Inventário aberto. Os movimentos dos produtos selecionados ficam bloqueados até à conclusão ou cancelamento.');
    }

    public function contar(
        Request $request,
        int $inventario,
        int $linha,
        ArmazemInventarioService $service,
    ): RedirectResponse {
        $dados = $request->validate([
            'quantidade_contada' => ['required', 'numeric', 'gte:0', 'decimal:0,3'],
        ]);

        try {
            $service->registarContagem(
                $request->user()->empresa,
                $this->obterInventario($request, $inventario),
                $linha,
                (string) $dados['quantidade_contada'],
            );
        } catch (DomainException | InvalidArgumentException $exception) {
            return back()->withInput()->with('erro', $exception->getMessage());
        }

        return back()->with('sucesso', 'Contagem física registada.');
    }

    public function concluir(
        Request $request,
        int $inventario,
        ArmazemInventarioService $service
    ): RedirectResponse {
        try {
            $service->concluir(
                $request->user()->empresa,
                $this->obterInventario($request, $inventario),
                (int) $request->user()->id,
            );
        } catch (DomainException | InvalidArgumentException $exception) {
            return back()->with('erro', $exception->getMessage());
        }

        return back()->with('sucesso', 'Inventário concluído. As diferenças foram aplicadas como movimentos de ajuste auditáveis.');
    }

    public function cancelar(
        Request $request,
        int $inventario,
        ArmazemInventarioService $service
    ): RedirectResponse {
        try {
            $service->cancelar(
                $request->user()->empresa,
                $this->obterInventario($request, $inventario),
            );
        } catch (DomainException $exception) {
            return back()->with('erro', $exception->getMessage());
        }

        return back()->with('sucesso', 'Inventário cancelado. Nenhum ajuste foi aplicado.');
    }

    private function obterInventario(Request $request, int $id): ArmazemInventario
    {
        return ArmazemInventario::query()
            ->where('empresa_id', (int) $request->user()->empresa_id)
            ->findOrFail($id);
    }
}
