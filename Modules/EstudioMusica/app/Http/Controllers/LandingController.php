<?php

namespace Modules\EstudioMusica\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Models\SolicitacaoOrcamento;

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
            ProjetoMusical::where('empresa_id', $empresa->id)->destaquesPortfolio()->latest()->get(),
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
        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'tipo_servico_desejado' => ['nullable', 'string', Rule::in(array_keys(config('estudiomusica.tipos_servico')))],
            'data_preferida' => ['nullable', 'date'],
            'mensagem' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->tenantManager->semTenant(fn () => SolicitacaoOrcamento::create([
            'empresa_id' => $empresa->id,
            ...$dados,
        ]));

        return back()->with('sucesso', 'Pedido enviado! A nossa equipa entra em contacto em breve.');
    }
}
