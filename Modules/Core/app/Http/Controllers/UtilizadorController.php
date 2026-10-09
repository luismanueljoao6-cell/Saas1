<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Http\Requests\ConvidarUtilizadorRequest;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Nota sobre o "convite": por agora o administrador cria o utilizador
 * diretamente, definindo ele próprio uma palavra-passe temporária (que
 * comunica ao colega por fora, ex.: WhatsApp). Um convite "a sério" —
 * e-mail com link e token, o próprio utilizador escolhe a sua palavra-passe
 * — é a evolução natural, mas depende de haver um fluxo de recuperação de
 * palavra-passe/verificação de e-mail, que ainda não existe (ver README).
 */
class UtilizadorController extends Controller
{
    public function __construct(protected LimitesDaEmpresa $limites) {}

    public function index(Request $request): View
    {
        $this->garantirAdministrador($request->user());

        return view('core::utilizadores.index', [
            'utilizadores' => $request->user()->empresa->utilizadores()->orderBy('name')->get(),
        ]);
    }

    public function store(ConvidarUtilizadorRequest $request): RedirectResponse
    {
        $empresa = $request->user()->empresa;

        if ($mensagem = $this->mensagemSeLimiteAtingido($empresa)) {
            return back()->withInput($request->except('password', 'password_confirmation'))
                ->with('erro', $mensagem);
        }

        try {
            $novoUtilizador = User::create([
                'empresa_id' => $empresa->id,
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => Hash::make($request->validated('password')),
            ]);

            $this->atribuirPapel($novoUtilizador, $empresa->id, $request->boolean('administrador'));

            Log::info('Novo utilizador adicionado à empresa', [
                'empresa_id' => $empresa->id,
                'utilizador_id' => $novoUtilizador->id,
                'criado_por' => $request->user()->id,
            ]);
        } catch (Throwable $e) {
            Log::error('Falha ao adicionar utilizador', ['empresa_id' => $empresa->id, 'erro' => $e->getMessage()]);

            return back()->withInput($request->except('password', 'password_confirmation'))
                ->with('erro', 'Não foi possível criar o utilizador. Tenta novamente.');
        }

        return back()->with('sucesso', "Utilizador {$novoUtilizador->name} criado.");
    }

    public function alternarAtivo(Request $request, User $utilizador): RedirectResponse
    {
        $admin = $request->user();

        $this->garantirAdministrador($admin);

        // User não usa BelongsToTenant (ver nota no model), por isso o
        // route model binding NÃO filtra por empresa — esta verificação
        // explícita é a única barreira contra desativar alguém de outra
        // empresa, e nunca deve ser removida.
        abort_unless($admin->empresa_id === $utilizador->empresa_id, 403);

        if ($admin->id === $utilizador->id) {
            return back()->with('erro', 'Não podes desativar a tua própria conta.');
        }

        // Reativar também conta para o limite — senão desativar e reativar
        // seria uma forma de contornar o número de utilizadores do plano.
        if (! $utilizador->ativo && ($mensagem = $this->mensagemSeLimiteAtingido($admin->empresa))) {
            return back()->with('erro', $mensagem);
        }

        $utilizador->update(['ativo' => ! $utilizador->ativo]);

        return back()->with('sucesso', $utilizador->ativo ? 'Utilizador reativado.' : 'Utilizador desativado.');
    }

    protected function mensagemSeLimiteAtingido(Empresa $empresa): ?string
    {
        $limite = $this->limites->limite($empresa, 'max_utilizadores');

        if ($limite === null) {
            return null;
        }

        if ($empresa->utilizadores()->where('ativo', true)->count() < $limite) {
            return null;
        }

        return "O plano atual permite no máximo {$limite} utilizador(es) ativo(s). Desativa alguém ou passa para um plano superior.";
    }

    protected function atribuirPapel(User $utilizador, int $empresaId, bool $administrador): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($empresaId);

        // 'empresa_id' explícito: sem ele o firstOrCreate procura o papel
        // pelo nome em TODAS as empresas e reutilizaria o de outra.
        $papel = Role::firstOrCreate([
            'name' => $administrador ? 'Administrador' : 'Utilizador',
            'guard_name' => 'web',
            'empresa_id' => $empresaId,
        ]);

        $utilizador->assignRole($papel);
    }

    protected function garantirAdministrador(User $utilizador): void
    {
        abort_unless($utilizador->hasRole('Administrador'), 403);
    }
}
