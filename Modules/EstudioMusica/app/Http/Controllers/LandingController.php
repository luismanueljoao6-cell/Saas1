<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\Reclamacao;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Models\SolicitacaoOrcamento;
use Modules\EstudioMusica\Services\NotificacaoEstudioService;
use Modules\Faturacao\Models\Cliente;

/**
 * Landing page institucional pública (secção G) — sem autenticação e sem
 * tenant identificado por nenhum middleware, por isso precisa do bypass
 * explícito do TenantManager para consultar dados (SalaEstudio,
 * ProjetoMusical) desta empresa em particular, tal como
 * VerificarTokenPortalCliente faz antes de encontrar o cliente do token.
 *
 * A empresa é identificada pelo id na própria URL (/estudio/{empresa}) —
 * Empresa não usa BelongsToTenant (é o topo da hierarquia, ver o próprio
 * model), por isso o route model binding funciona sem qualquer bypass.
 */
class LandingController extends Controller
{
    public function __construct(protected TenantManager $tenantManager) {}

    public function mostrar(Empresa $empresa): View
    {
        [$salas, $destaques] = $this->tenantManager->semTenant(fn () => [
            SalaEstudio::where('empresa_id', $empresa->id)->ativas()->orderBy('nome')->get(),
            ProjetoMusical::where('empresa_id', $empresa->id)->destaquesPortfolio()->latest()->limit(12)->get(),
        ]);

        return view('estudiomusica::landing.index', [
            'empresa' => $empresa,
            'salas' => $salas,
            'destaques' => $destaques,
            'tiposServico' => config('estudiomusica.tipos_servico'),
        ]);
    }

    public function solicitarOrcamento(Request $request, Empresa $empresa): RedirectResponse
    {
        // Armadilha para robôs: o campo "website" está escondido; um humano
        // nunca o preenche. Respondemos como se tivesse corrido bem.
        if ($request->filled('website')) {
            return back()->with('sucesso', 'Pedido enviado! A nossa equipa entra em contacto em breve.');
        }

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            // Sem um meio de contacto o pedido não serve para nada.
            'email' => ['nullable', 'email', 'max:255', 'required_without:telefone'],
            'telefone' => ['nullable', 'string', 'max:30', 'required_without:email'],
            'tipo_servico_desejado' => ['nullable', 'string', Rule::in(array_keys(config('estudiomusica.tipos_servico')))],
            'data_preferida' => ['nullable', 'date', 'after_or_equal:today'],
            'mensagem' => ['nullable', 'string', 'max:2000'],
            // Lei n.º 22/11: o tratamento só avança com consentimento expresso.
            'aceita_politica' => ['accepted'],
            'aceita_marketing' => ['nullable', 'boolean'],
        ], [
            'aceita_politica.accepted' => 'Tens de aceitar o tratamento dos teus dados para enviar o pedido.',
        ]);

        $this->tenantManager->semTenant(fn () => SolicitacaoOrcamento::create([
            'empresa_id' => $empresa->id,
            'nome' => $dados['nome'],
            'email' => $dados['email'] ?? null,
            'telefone' => $dados['telefone'] ?? null,
            'tipo_servico_desejado' => $dados['tipo_servico_desejado'] ?? null,
            'data_preferida' => $dados['data_preferida'] ?? null,
            'mensagem' => $dados['mensagem'] ?? null,
            'aceita_marketing' => (bool) ($dados['aceita_marketing'] ?? false),
            'consentimento_dados_em' => now(),
        ]));

        return back()->with('sucesso', 'Pedido enviado! A nossa equipa entra em contacto em breve.');
    }

    /**
     * Direito de oposição ao marketing direto: ligação assinada (middleware
     * 'signed') incluída no rodapé de cada mensagem promocional. Não exige
     * login e é idempotente.
     */
    public function cancelarMarketing(Empresa $empresa, int $cliente): Response
    {
        $this->tenantManager->semTenant(
            fn () => Cliente::withTrashed()
                ->where('empresa_id', $empresa->id)
                ->whereKey($cliente)
                ->update(['aceita_marketing' => false, 'aceita_marketing_em' => null])
        );

        return response(
            'Pronto — já não vais receber mensagens promocionais deste estúdio.',
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /** Política de Privacidade pública (dever de informar — Lei n.º 22/11). */
    public function privacidade(Empresa $empresa): View
    {
        return view('estudiomusica::landing.privacidade', ['empresa' => $empresa]);
    }

    /**
     * Canal digital de reclamações. Complementa — não substitui — o Livro
     * de Reclamações físico exigido nos estabelecimentos (Decreto
     * Presidencial n.º 234/16).
     */
    public function registrarReclamacao(Request $request, Empresa $empresa, NotificacaoEstudioService $notificacao): RedirectResponse
    {
        if ($request->filled('website')) {
            return back()->with('sucesso', 'Reclamação registada.');
        }

        // Bag próprio: a página tem dois formulários e os erros não se misturam.
        $dados = $request->validateWithBag('reclamacao', [
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:telefone'],
            'telefone' => ['nullable', 'string', 'max:30', 'required_without:email'],
            'assunto' => ['required', 'string', 'max:150'],
            'descricao' => ['required', 'string', 'min:10', 'max:3000'],
            'aceita_politica' => ['accepted'],
        ], [
            'aceita_politica.accepted' => 'Tens de aceitar o tratamento dos teus dados para registar a reclamação.',
        ]);

        $reclamacao = $this->tenantManager->semTenant(fn () => Reclamacao::create([
            'empresa_id' => $empresa->id,
            'nome' => $dados['nome'],
            'email' => $dados['email'] ?? null,
            'telefone' => $dados['telefone'] ?? null,
            'assunto' => $dados['assunto'],
            'descricao' => $dados['descricao'],
            'consentimento_dados_em' => now(),
        ]));

        $notificacao->reclamacaoRecebida($empresa, $reclamacao);

        return back()->with('sucesso', "Reclamação registada (ref. #{$reclamacao->id}). Vamos responder o mais depressa possível.");
    }
}
