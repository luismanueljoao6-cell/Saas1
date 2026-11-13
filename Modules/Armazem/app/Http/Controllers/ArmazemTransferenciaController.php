<?php

namespace Modules\Armazem\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Armazem\Models\Armazem;
use Modules\Armazem\Models\ArmazemTransferencia;
use Modules\Armazem\Services\ArmazemTransferenciaService;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Produto;

class ArmazemTransferenciaController extends Controller
{
    public function index(Request $request): View
    {
        $empresa = $request->user()->empresa;

        $armazens = Armazem::query()
            ->where('ativo', true)
            ->orderByDesc('principal')
            ->orderBy('nome')
            ->get();

        $produtos = Produto::query()
            ->where('ativo', true)
            ->where('controla_stock', true)
            ->orderBy('nome')
            ->get(['id', 'nome']);

        $transferencias = ArmazemTransferencia::query()
            ->with(['origem:id,nome,codigo', 'destino:id,nome,codigo', 'criador:id,name', 'expedidor:id,name', 'recebedor:id,name', 'linhas'])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('armazem::transferencias.index', compact('armazens', 'produtos', 'transferencias', 'empresa'));
    }

    public function store(Request $request, ArmazemTransferenciaService $service): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        $empresaId = (int) $empresa->id;

        $dados = $request->validate([
            'armazem_origem_id' => ['required', 'integer', 'different:armazem_destino_id', 'exists:armazens,id,empresa_id,'.$empresaId.',ativo,1'],
            'armazem_destino_id' => ['required', 'integer', 'exists:armazens,id,empresa_id,'.$empresaId.',ativo,1'],
            'motivo' => ['required', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'linhas' => ['required', 'array', 'min:1'],
            'linhas.*.produto_id' => ['required', 'integer', 'distinct', 'exists:produtos,id,empresa_id,'.$empresaId.',ativo,1,controla_stock,1'],
            'linhas.*.quantidade' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ]);

        $origem = Armazem::query()->findOrFail($dados['armazem_origem_id']);
        $destino = Armazem::query()->findOrFail($dados['armazem_destino_id']);

        $service->criar(
            $empresa,
            $origem,
            $destino,
            $dados['linhas'],
            $dados['motivo'],
            (int) $request->user()->id,
            $dados['observacoes'] ?? null
        );

        return back()->with('sucesso', 'Transferência criada em rascunho.');
    }

    public function expedir(Request $request, int $transferencia, ArmazemTransferenciaService $service): RedirectResponse
    {
        $alvo = $this->obterTransferencia($request, $transferencia);

        try {
            $service->expedir($request->user()->empresa, $alvo, (int) $request->user()->id);
        } catch (DomainException $exception) {
            return back()->with('erro', $exception->getMessage());
        }

        return back()->with('sucesso', 'Transferência expedida. O stock saiu da origem e está em trânsito.');
    }

    public function receber(Request $request, int $transferencia, ArmazemTransferenciaService $service): RedirectResponse
    {
        $alvo = $this->obterTransferencia($request, $transferencia);

        try {
            $service->receber($request->user()->empresa, $alvo, (int) $request->user()->id);
        } catch (DomainException $exception) {
            return back()->with('erro', $exception->getMessage());
        }

        return back()->with('sucesso', 'Transferência recebida. O stock foi creditado no destino.');
    }

    public function cancelar(Request $request, int $transferencia, ArmazemTransferenciaService $service): RedirectResponse
    {
        $alvo = $this->obterTransferencia($request, $transferencia);

        try {
            $service->cancelar($request->user()->empresa, $alvo);
        } catch (DomainException $exception) {
            return back()->with('erro', $exception->getMessage());
        }

        return back()->with('sucesso', 'Rascunho de transferência cancelado.');
    }

    private function obterTransferencia(Request $request, int $id): ArmazemTransferencia
    {
        return ArmazemTransferencia::query()
            ->where('empresa_id', (int) $request->user()->empresa_id)
            ->findOrFail($id);
    }
}
