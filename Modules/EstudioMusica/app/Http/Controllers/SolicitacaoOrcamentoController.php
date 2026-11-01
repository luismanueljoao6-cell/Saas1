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
        $cliente = DB::transaction(function () use ($solicitacao) {
            // Com lock: dois cliques (ou dois utilizadores) já não criam o cliente duas vezes.
            $solicitacao = SolicitacaoOrcamento::query()->lockForUpdate()->findOrFail($solicitacao);

            if ($solicitacao->estado === 'convertido') {
                return null;
            }

            // Primeiro pelo e-mail (é da própria pessoa); só depois pelo telefone, que pode ser
            // partilhado (número da família, da editora...).
            $existente = null;
            $porEmail = false;

            if ($solicitacao->email) {
                $existente = Cliente::query()->where('email', $solicitacao->email)->first();
                $porEmail = $existente !== null;
            }

            if ($existente === null && $solicitacao->telefone) {
                $existente = Cliente::query()->where('telefone', $solicitacao->telefone)->first();
            }

            $cliente = $existente ?? (new Cliente)->forceFill([
                'empresa_id' => $solicitacao->empresa_id,
                'nome' => $solicitacao->nome,
                'email' => $solicitacao->email,
                'telefone' => $solicitacao->telefone,
            ]);

            // O consentimento de marketing é pessoal: só passa para um cliente que já existia se o
            // e-mail coincidir. Um telefone igual pode ser de outra pessoa, que nunca consentiu.
            $podeHerdarConsentimento = $existente === null || $porEmail;

            if ($podeHerdarConsentimento && $solicitacao->aceita_marketing && ! $cliente->aceita_marketing) {
                $cliente->forceFill([
                    'aceita_marketing' => true,
                    'aceita_marketing_em' => $solicitacao->consentimento_dados_em ?? now(),
                ]);
            }

            $cliente->save();
            $solicitacao->update(['estado' => 'convertido']);

            return $cliente;
        });

        if ($cliente === null) {
            return back()->with('erro', 'Este pedido já foi convertido em cliente.');
        }

        return back()->with('sucesso', "Cliente \"{$cliente->nome}\" pronto — já podes criar o projeto ou agendar a sessão.");
    }
}
