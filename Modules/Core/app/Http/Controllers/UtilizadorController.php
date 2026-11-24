<?php

namespace Modules\Core\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Http\Requests\ConvidarUtilizadorRequest;
use Modules\Core\Models\Convite;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\ConviteService;
use Throwable;

/**
 * Utilizadores da empresa. Novos utilizadores entram por convite
 * (ConviteService): recebem uma ligação por e-mail e escolhem eles
 * próprios a palavra-passe. O administrador nunca a conhece.
 */
class UtilizadorController extends Controller
{
    public function __construct(
        protected LimitesDaEmpresa $limites,
        protected ConviteService $convites,
    ) {}

    public function index(Request $request): View
    {
        $this->garantirAdministrador($request->user());

        return view('core::utilizadores.index', [
            'utilizadores' => $request->user()->empresa->utilizadores()->orderBy('name')->get(),
            // A TenantScope do model restringe aos convites desta empresa.
            'convites' => Convite::query()->pendentes()->latest()->get(),
        ]);
    }

    public function store(ConvidarUtilizadorRequest $request): RedirectResponse
    {
        $admin = $request->user();
        $email = $request->validated('email');

        try {
            $this->convites->convidar(
                $admin,
                $email,
                $request->validated('name'),
                $request->boolean('administrador'),
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('erro', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Falha ao enviar convite', ['empresa_id' => $admin->empresa_id, 'erro' => $e->getMessage()]);

            return back()->withInput()->with(
                'erro',
                'Não foi possível enviar o convite. Tenta novamente (convidar o mesmo e-mail outra vez substitui o convite anterior).'
            );
        }

        return back()->with('sucesso', "Convite enviado para {$email}.");
    }

    public function revogarConvite(Request $request, Convite $convite): RedirectResponse
    {
        $admin = $request->user();

        $this->garantirAdministrador($admin);

        // O binding já é filtrado pela TenantScope (convite de outra empresa
        // = 404); esta verificação é a segunda barreira e não deve sair.
        abort_unless((int) $admin->empresa_id === (int) $convite->empresa_id, 403);

        $this->convites->revogar($admin, $convite);

        return back()->with('sucesso', 'Convite revogado.');
    }

    public function alternarAtivo(Request $request, User $utilizador): RedirectResponse
    {
        $admin = $request->user();

        $this->garantirAdministrador($admin);

        // User não usa BelongsToTenant (ver nota no model), por isso o
        // route model binding NÃO filtra por empresa — esta verificação
        // explícita é a única barreira contra desativar alguém de outra
        // empresa, e nunca deve ser removida.
        abort_unless((int) $admin->empresa_id === (int) $utilizador->empresa_id, 403);

        if ((int) $admin->id === (int) $utilizador->id) {
            return back()->with('erro', 'Não podes desativar a tua própria conta.');
        }

        try {
            [$utilizadorAtual, $mensagem] = DB::transaction(function () use ($admin, $utilizador) {
                $empresa = Empresa::query()
                    ->lockForUpdate()
                    ->findOrFail($admin->empresa_id);

                $utilizadorAtual = User::query()
                    ->where('empresa_id', $empresa->id)
                    ->lockForUpdate()
                    ->findOrFail($utilizador->id);

                // Reativar também conta para o limite. A contagem e a
                // alteração ficam serializadas pela linha da empresa.
                if (! $utilizadorAtual->ativo && ($mensagem = $this->mensagemSeLimiteAtingido($empresa))) {
                    return [null, $mensagem];
                }

                $utilizadorAtual->update(['ativo' => ! $utilizadorAtual->ativo]);

                return [$utilizadorAtual, null];
            });
        } catch (Throwable $e) {
            Log::error('Falha ao alterar estado do utilizador', [
                'empresa_id' => $admin->empresa_id,
                'utilizador_id' => $utilizador->id,
                'erro' => $e->getMessage(),
            ]);

            return back()->with('erro', 'Não foi possível alterar o estado do utilizador. Tenta novamente.');
        }

        if ($mensagem !== null) {
            return back()->with('erro', $mensagem);
        }

        return back()->with('sucesso', $utilizadorAtual->ativo ? 'Utilizador reativado.' : 'Utilizador desativado.');
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

    protected function garantirAdministrador(User $utilizador): void
    {
        abort_unless($utilizador->hasRole('Administrador'), 403);
    }
}
