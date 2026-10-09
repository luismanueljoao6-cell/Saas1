<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Http\Requests\AtualizarPedidoRequest;
use Modules\Atelier\Http\Requests\AvancarEstadoPedidoRequest;
use Modules\Atelier\Http\Requests\GerarFaturaFinalRequest;
use Modules\Atelier\Http\Requests\GuardarPedidoRequest;
use Modules\Atelier\Http\Requests\RegistarPagamentoFinalRequest;
use Modules\Atelier\Http\Requests\RegistarSinalRequest;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoMaterial;
use Modules\Atelier\Services\PedidoFaturacaoService;
use Modules\Atelier\Services\PedidoStatusService;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Throwable;

class PedidoController extends Controller
{
    public function __construct(
        protected PedidoStatusService $statusService,
        protected PedidoFaturacaoService $faturacaoService,
        protected \Modules\Atelier\Services\PortalLinkService $portalLinkService,
    ) {
    }

    public function index(): View
    {
        return view('atelier::pedidos.index', [
            'pedidos' => Pedido::with(['cliente', 'responsavel'])
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function criar(): View
    {
        return view('atelier::pedidos.criar', [
            'clientes' => Cliente::orderBy('nome')->get(),
            'costureiras' => request()->user()->empresa->utilizadores()->orderBy('name')->get(),
            'tiposServico' => config('atelier.tipos_servico'),
        ]);
    }

    public function guardar(GuardarPedidoRequest $request): RedirectResponse
    {
        $pedido = DB::transaction(function () use ($request) {
            $pedido = Pedido::create([
                'cliente_id' => $request->validated('cliente_id'),
                'medida_id' => $request->validated('medida_id'),
                'responsavel_id' => $request->validated('responsavel_id'),
                'tipo_servico' => $request->validated('tipo_servico'),
                'descricao' => $request->validated('descricao'),
                'tecido_cor' => $request->validated('tecido_cor'),
                'aviamentos_necessarios' => $request->validated('aviamentos_necessarios'),
                'data_prevista_entrega' => $request->validated('data_prevista_entrega'),
                'prazo_interno' => $request->validated('prazo_interno'),
                'valor_orcamento' => $request->validated('valor_orcamento'),
                'percentual_sinal' => $request->validated('percentual_sinal'),
                'taxa_iva_aplicada' => (float) config('faturacao.taxa_iva_geral'),
            ]);

            foreach ($request->validated('materiais', []) as $indice => $dadosMaterial) {
                $material = new PedidoMaterial([
                    'pedido_id' => $pedido->id,
                    'descricao' => $dadosMaterial['descricao'],
                    'quantidade' => $dadosMaterial['quantidade'],
                    'valor_unitario' => $dadosMaterial['valor_unitario'],
                    'ordem' => $indice,
                ]);
                $material->calcularValores()->save();
            }

            foreach ($request->file('fotos', []) as $ficheiro) {
                $caminho = $ficheiro->store('atelier/pedidos', 'public');
                $pedido->fotos()->create(['caminho' => $caminho]);
            }

            return $pedido;
        });

        return redirect()->route('atelier.pedidos.mostrar', $pedido)
            ->with('sucesso', 'Pedido criado.');
    }

    public function mostrar(int $pedido): View
    {
        $pedido = Pedido::findOrFail($pedido);
        $pedido->load(['cliente', 'medida', 'responsavel', 'fotos', 'materiais', 'provas', 'fatura', 'reciboSinal', 'reciboSaldoFinal']);

        return view('atelier::pedidos.mostrar', [
            'pedido' => $pedido,
            'proximosEstados' => $this->statusService->proximosEstadosPossiveis($pedido),
            'podeEmitirFaturas' => $pedido->empresa->podeEmitirFaturas(),
            'linkPortal' => $pedido->cliente ? $this->portalLinkService->gerarLinkAcesso($pedido->cliente) : null,
            // Requisito G.3: a Costureira vê o painel de tarefas mas não
            // dados financeiros — a view usa isto para esconder valores,
            // sinal, fatura e recibos, não só os formulários de ação.
            'podeVerFinancas' => request()->user()->hasAnyRole(['Administrador', 'Secretária']),
        ]);
    }

    public function atualizar(AtualizarPedidoRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);
        $pedido->update($request->validated());

        return back()->with('sucesso', 'Pedido atualizado.');
    }

    public function avancarEstado(AvancarEstadoPedidoRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);

        try {
            $this->statusService->avancarPara(
                $pedido,
                $request->validated('novo_estado'),
                $request->validated('motivo_cancelamento'),
            );
        } catch (PedidoException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', 'Estado do pedido atualizado.');
    }

    public function registarSinal(RegistarSinalRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);
        $this->garantirQuePodeEmitirFaturas($pedido->empresa);

        try {
            $this->faturacaoService->registarSinal($pedido, (float) $request->validated('valor'), $request->validated('meio_pagamento'));
        } catch (PedidoException $e) {
            return back()->with('erro', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Falha ao registar sinal do Atelier', ['pedido_id' => $pedido->id, 'erro' => $e->getMessage()]);

            return back()->with('erro', 'Ocorreu um erro ao registar o sinal. Tenta novamente.');
        }

        return back()->with('sucesso', 'Sinal registado e recibo emitido.');
    }

    public function gerarFaturaFinal(GerarFaturaFinalRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);
        $this->garantirQuePodeEmitirFaturas($pedido->empresa);

        try {
            $fatura = $this->faturacaoService->gerarFaturaFinalRascunho($pedido, $request->validated('observacoes'));
        } catch (PedidoException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->route('faturacao.faturas.mostrar', $fatura)
            ->with('sucesso', 'Rascunho da fatura final criado — revê os valores e emite-a aqui.');
    }

    public function registarPagamentoFinal(RegistarPagamentoFinalRequest $request, int $pedido): RedirectResponse
    {
        $pedido = Pedido::findOrFail($pedido);
        $this->garantirQuePodeEmitirFaturas($pedido->empresa);

        try {
            $this->faturacaoService->registarPagamentoFinal($pedido, $request->validated('meio_pagamento'));
        } catch (PedidoException $e) {
            return back()->with('erro', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Falha ao registar pagamento final do Atelier', ['pedido_id' => $pedido->id, 'erro' => $e->getMessage()]);

            return back()->with('erro', 'Ocorreu um erro ao registar o pagamento. Tenta novamente.');
        }

        return back()->with('sucesso', 'Pagamento do saldo registado e recibo emitido.');
    }

    /** Mesma distinção que FaturaController já aplica: grace period dá acesso de leitura, mas não deixa emitir novos documentos fiscais. */
    protected function garantirQuePodeEmitirFaturas(Empresa $empresa): void
    {
        abort_unless(
            $empresa->podeEmitirFaturas(),
            402,
            'A tua empresa está no período de tolerância — não é possível gerar novos documentos fiscais até regularizares a subscrição.'
        );
    }
}
