<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\EstudioMusica\Models\SolicitacaoOrcamento;
use Modules\Faturacao\Models\Cliente;

/**
 * Gestão, pela Receção, dos leads recebidos no formulário público da
 * landing page (secção G). A conversão em Cliente/Projeto real continua a
 * fazer-se nos ecrãs já existentes (Faturacao\ClienteController,
 * ProjetoMusicalController::create) — aqui só se acompanha o estado do
 * pedido em si.
 */
class SolicitacaoOrcamentoController extends Controller
{
    public function index(): View
    {
        return view('estudiomusica::solicitacoes.index', [
            'solicitacoes' => SolicitacaoOrcamento::latest()->paginate(20),
        ]);
    }

    public function atualizarEstado(Request $request, int $solicitacao): RedirectResponse
    {
        $request->validate([
            'estado' => ['required', Rule::in(['pendente', 'contactado', 'convertido', 'descartado'])],
        ]);

        $solicitacao = SolicitacaoOrcamento::findOrFail($solicitacao);
        $solicitacao->update(['estado' => $request->input('estado')]);

        return back()->with('sucesso', 'Pedido atualizado.');
    }

    /**
     * Converte o pedido num Cliente (ou reaproveita o que já existe com o
     * mesmo e-mail/telefone) levando consigo o consentimento de marketing
     * dado no formulário — assim o consentimento não se perde na passagem
     * de lead a cliente e a Receção não tem de voltar a perguntar.
     */
    public function converter(int $solicitacao): RedirectResponse
    {
        $solicitacao = SolicitacaoOrcamento::findOrFail($solicitacao);

        if ($solicitacao->estado === 'convertido') {
            return back()->with('erro', 'Este pedido já foi convertido em cliente.');
        }

        $cliente = DB::transaction(function () use ($solicitacao) {
            $existente = null;

            if ($solicitacao->email || $solicitacao->telefone) {
                $existente = Cliente::query()->where(function ($q) use ($solicitacao) {
                    if ($solicitacao->email) {
                        $q->orWhere('email', $solicitacao->email);
                    }

                    if ($solicitacao->telefone) {
                        $q->orWhere('telefone', $solicitacao->telefone);
                    }
                })->first();
            }

            $cliente = $existente ?? (new Cliente)->forceFill([
                'empresa_id' => $solicitacao->empresa_id,
                'nome' => $solicitacao->nome,
                'email' => $solicitacao->email,
                'telefone' => $solicitacao->telefone,
            ]);

            if ($solicitacao->aceita_marketing && ! $cliente->aceita_marketing) {
                $cliente->forceFill([
                    'aceita_marketing' => true,
                    'aceita_marketing_em' => $solicitacao->consentimento_dados_em ?? now(),
                ]);
            }

            $cliente->save();
            $solicitacao->update(['estado' => 'convertido']);

            return $cliente;
        });

        return back()->with('sucesso', "Cliente \"{$cliente->nome}\" pronto — já podes criar o projeto ou agendar a sessão.");
    }
}
