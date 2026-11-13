<?php

namespace Modules\Faturacao\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Orcamento;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\OrcamentoService;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\ValidacaoLinhas;
use Throwable;

class OrcamentoController extends Controller
{
    public function index(Request $request): View
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403);

        $filtros = $request->validate([
            'pesquisa' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in(['rascunho', 'enviado', 'aceite', 'recusado', 'convertido'])],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
        ]);

        $consulta = Orcamento::query()
            ->with('cliente')
            ->where('empresa_id', $empresa->id)
            ->when($filtros['pesquisa'] ?? null, function ($query, $termo) {
                $query->where(function ($subquery) use ($termo) {
                    $subquery->where('numero', 'like', '%'.$termo.'%')
                        ->orWhereHas('cliente', fn ($cliente) => $cliente->where('nome', 'like', '%'.$termo.'%'));
                });
            })
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->when($filtros['data_inicio'] ?? null, fn ($query, $data) => $query->whereDate('created_at', '>=', $data))
            ->when($filtros['data_fim'] ?? null, fn ($query, $data) => $query->whereDate('created_at', '<=', $data));

        return view('faturacao::orcamentos.index', [
            'orcamentos' => $consulta->latest('id')->paginate(20)->withQueryString(),
            'filtros' => $filtros,
        ]);
    }

    public function criar(): View
    {
        return view('faturacao::orcamentos.criar', [
            'clientes' => Cliente::query()->orderBy('nome')->get(),
            'taxasIva' => config('faturacao.taxas_iva_permitidas'),
            'taxaPadrao' => config('faturacao.taxa_iva_geral'),
            'motivosIsencao' => collect((array) config('faturacao.motivos_isencao', []))
                ->filter(fn (array $motivo) => in_array($motivo['tipo'] ?? null, ['ISE', 'NS'], true)),
            'tiposOperacaoAgt' => ValidacaoLinhas::TIPOS_OPERACAO_AGT,
        ]);
    }

    public function editar(Request $request, int $orcamento): View
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403);

        $documento = Orcamento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'rascunho')
            ->findOrFail($orcamento);

        return view('faturacao::orcamentos.editar', [
            'orcamento' => $documento,
            'clientes' => Cliente::query()->orderBy('nome')->get(),
            'taxasIva' => config('faturacao.taxas_iva_permitidas'),
            'taxaPadrao' => config('faturacao.taxa_iva_geral'),
            'motivosIsencao' => collect((array) config('faturacao.motivos_isencao', []))
                ->filter(fn (array $motivo) => in_array($motivo['tipo'] ?? null, ['ISE', 'NS'], true)),
            'tiposOperacaoAgt' => ValidacaoLinhas::TIPOS_OPERACAO_AGT,
        ]);
    }

    public function atualizar(Request $request, int $orcamento, OrcamentoService $service): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403);

        $documento = Orcamento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->findOrFail($orcamento);

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
            'linhas.*.motivo_isencao' => ['nullable', 'string', Rule::in($this->codigosMotivosIsencao())],
            'linhas.*.operation_type' => ['nullable', 'string', Rule::in(ValidacaoLinhas::TIPOS_OPERACAO_AGT)],
        ]);

        try {
            $service->atualizarRascunho(
                $documento,
                Cliente::query()->findOrFail($dados['cliente_id']),
                $dados['linhas'],
                $dados['data_validade'] ?? null,
                $dados['observacoes'] ?? null,
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['orcamento' => $e->getMessage()]);
        }

        return redirect()->route('faturacao.orcamentos.mostrar', $documento->id)
            ->with('sucesso', 'Orçamento atualizado.');
    }

    public function mostrar(Request $request, int $orcamento): View
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403);

        $documento = Orcamento::query()
            ->with('cliente')
            ->where('empresa_id', $empresa->id)
            ->findOrFail($orcamento);

        $linhas = collect($documento->linhas ?? [])->map(function (array $linha): array {
            $valores = Dinheiro::calcularLinha(
                $linha['quantidade'] ?? 0,
                $linha['preco_unitario'] ?? 0,
                $linha['taxa_iva'] ?? 0,
            );

            $linha['total_linha'] = Dinheiro::formatar(
                Dinheiro::centimos($valores['sem_iva'])
                + Dinheiro::centimos($valores['iva'])
            );

            return $linha;
        })->all();

        return view('faturacao::orcamentos.mostrar', [
            'orcamento' => $documento,
            'empresa' => $empresa,
            'linhas' => $linhas,
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
            'linhas.*.motivo_isencao' => ['nullable', 'string', Rule::in($this->codigosMotivosIsencao())],
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

    /** @return list<string> */
    protected function codigosMotivosIsencao(): array
    {
        return array_keys(array_filter(
            (array) config('faturacao.motivos_isencao', []),
            fn (array $motivo) => in_array($motivo['tipo'] ?? null, ['ISE', 'NS'], true),
        ));
    }

    public function duplicar(Request $request, int $orcamento, OrcamentoService $service): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403);

        $dados = $request->validate([
            'cliente_id' => ['nullable', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresa->id)],
            'data_validade' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $origem = Orcamento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->findOrFail($orcamento);
        $cliente = isset($dados['cliente_id'])
            ? Cliente::query()->findOrFail($dados['cliente_id'])
            : null;

        try {
            $novo = $service->duplicar($origem, $empresa, $cliente, $dados['data_validade'] ?? null);
        } catch (\\DomainException|\\InvalidArgumentException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->route('faturacao.orcamentos.editar', $novo->id)
            ->with('sucesso', 'Foi criada uma cópia em rascunho. Revê os dados antes de enviar.');
    }

    public function estado(Request $request, int $orcamento, OrcamentoService $service): RedirectResponse
    {
        $empresa = $request->user()->empresa;
        abort_if($empresa === null, 403);
        $dados = $request->validate(['estado' => ['required', Rule::in(['enviado', 'aceite', 'recusado'])]]);
        $documento = Orcamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->findOrFail($orcamento);

        try {
            $service->alterarEstado($documento, $dados['estado']);
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
            $documento = Orcamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->findOrFail($orcamento);
            $fatura = $service->converter($documento, $empresa, $faturas);
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
