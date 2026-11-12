<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Illuminate\Validation\Rule;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Orcamento;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\OrcamentoService;
use Modules\Faturacao\Support\ValidacaoLinhas;
use Throwable;

class OrcamentoController extends Controller
{
    public function index(): View
    {
        return view('faturacao::orcamentos.index', [
            'orcamentos' => Orcamento::with('cliente')->latest('id')->paginate(20),
        ]);
    }

    public function criar(): View
    {
        return view('faturacao::orcamentos.criar', [
            'clientes' => Cliente::query()->orderBy('nome')->get(),
            'taxasIva' => config('faturacao.taxas_iva_permitidas'),
            'taxaPadrao' => config('faturacao.taxa_iva_geral'),
        ]);
    }

    public function guardar(Request $request, OrcamentoService $service): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403);

        $dados = $request->validate([
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresa->id)],
            'data_validade' => ['nullable', 'date', 'after_or_equal:today'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
            'linhas' => ['required', 'array', 'min:1', 'max:100'],
            'linhas.*.produto_id' => ['nullable', 'integer', Rule::exists('produtos', 'id')->where('empresa_id', $empresa->id)],
            'linhas.*.descricao' => ['required', 'string', 'max:255'],
            'linhas.*.quantidade' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:10000'],
            'linhas.*.preco_unitario' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999.99'],
            'linhas.*.taxa_iva' => ['required', 'numeric'],
            'linhas.*.motivo_isencao' => ['nullable', 'string'],
            'linhas.*.operation_type' => ['nullable', 'string', Rule::in(ValidacaoLinhas::TIPOS_OPERACAO_AGT)],
        ]);

        try {
            $orcamento = $service->criar(
                $empresa,
                Cliente::query()->findOrFail($dados['cliente_id']),
                $dados['linhas'],
                $dados['data_validade'] ?? null,
                $dados['observacoes'] ?? null,
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['orcamento' => $e->getMessage()]);
        }

        return redirect()->route('faturacao.orcamentos.index')->with('sucesso', "Orçamento {$orcamento->numero} criado.");
    }

    public function estado(Request $request, int $orcamento, OrcamentoService $service): RedirectResponse
    {
        $dados = $request->validate(['estado' => ['required', Rule::in(['enviado', 'aceite', 'recusado'])]]);

        try {
            $service->alterarEstado(Orcamento::query()->findOrFail($orcamento), $dados['estado']);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Estado do orçamento atualizado.');
    }

    public function converter(int $orcamento, Request $request, OrcamentoService $service, FaturaService $faturas): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403);

        try {
            $fatura = $service->converter(Orcamento::query()->findOrFail($orcamento), $empresa, $faturas);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            return back()->with('erro', 'Não foi possível converter o orçamento. Tenta novamente.');
        }

        return redirect()->route('faturacao.faturas.mostrar', $fatura->id)
            ->with('sucesso', 'Orçamento convertido num rascunho de fatura. Confirma os dados antes de emitir.');
    }
}
