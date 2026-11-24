<?php

namespace Modules\Core\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Core\Http\Requests\DesafioDoisFatoresRequest;
use Modules\Core\Models\User;
use Modules\Core\Services\DoisFatoresService;

/**
 * Segundo passo do login. Só se chega aqui depois de a palavra-passe estar
 * certa (o AuthenticatedSessionController guarda o estado na sessão); até o
 * código ser aceite o utilizador NÃO está autenticado.
 */
class DesafioDoisFatoresController extends Controller
{
    public const CHAVE_SESSAO = 'core.2fa.pendente';

    public const VALIDADE_MINUTOS = 10;

    protected const MAX_TENTATIVAS = 5;

    protected const JANELA_SEGUNDOS = 300;

    /** Falhas toleradas por utilizador, somando todos os IPs. */
    protected const MAX_TENTATIVAS_UTILIZADOR = 15;

    protected const JANELA_UTILIZADOR_SEGUNDOS = 900;

    public function __construct(protected DoisFatoresService $doisFatores) {}

    public function create(Request $request): View|RedirectResponse
    {
        if ($this->utilizadorPendente($request) === null) {
            return redirect()->route('core.login');
        }

        return view('core::auth.desafio-2fa');
    }

    public function store(DesafioDoisFatoresRequest $request): RedirectResponse
    {
        $utilizador = $this->utilizadorPendente($request);

        if ($utilizador === null) {
            return redirect()->route('core.login')
                ->with('erro', 'A verificação expirou. Inicia sessão outra vez.');
        }

        // Chave por utilizador+IP (não por sessão): voltar a fazer login não repõe o limite.
        $chave = '2fa|'.$utilizador->id.'|'.$request->ip();

        // Limite por utilizador (todos os IPs): quem já tem a palavra-passe mas roda
        // de IP não pode tentar códigos sem fim. Custo: sob ataque, o próprio
        // utilizador também espera; só chega aqui quem já sabe a palavra-passe.
        $chaveUtilizador = '2fa-utilizador|'.$utilizador->id;

        foreach ([[$chave, self::MAX_TENTATIVAS], [$chaveUtilizador, self::MAX_TENTATIVAS_UTILIZADOR]] as [$limite, $maximo]) {
            if (RateLimiter::tooManyAttempts($limite, $maximo)) {
                throw ValidationException::withMessages([
                    'codigo' => 'Demasiadas tentativas. Tenta novamente dentro de '.RateLimiter::availableIn($limite).' segundos.',
                ]);
            }
        }

        $codigo = preg_replace('/\s+/', '', (string) $request->validated('codigo'));
        $porRecuperacao = ! (strlen($codigo) === DoisFatoresService::DIGITOS && ctype_digit($codigo));

        $valido = $porRecuperacao
            ? $this->doisFatores->usarCodigoRecuperacao($utilizador, $codigo)
            : $this->doisFatores->validarCodigo($utilizador, $codigo);

        if (! $valido) {
            RateLimiter::hit($chave, self::JANELA_SEGUNDOS);
            RateLimiter::hit($chaveUtilizador, self::JANELA_UTILIZADOR_SEGUNDOS);

            // Mesma mensagem para os dois tipos de código.
            throw ValidationException::withMessages([
                'codigo' => 'O código indicado não é válido.',
            ]);
        }

        RateLimiter::clear($chave);
        RateLimiter::clear($chaveUtilizador);

        $lembrar = (bool) data_get($request->session()->get(self::CHAVE_SESSAO), 'lembrar', false);
        $request->session()->forget(self::CHAVE_SESSAO);

        Auth::guard('web')->login($utilizador, $lembrar);
        $request->session()->regenerate();

        $resposta = redirect()->intended(route('core.painel'));

        if ($porRecuperacao) {
            $restantes = $this->doisFatores->codigosRestantes($utilizador->fresh());

            $resposta->with('sucesso', "Entraste com um código de recuperação. Restam {$restantes}. Gera novos códigos nas definições de segurança.");
        }

        return $resposta;
    }

    /** O utilizador que já passou a palavra-passe e está à espera do código, ou null. */
    protected function utilizadorPendente(Request $request): ?User
    {
        $estado = $request->session()->get(self::CHAVE_SESSAO);

        if (! is_array($estado) || ($estado['ate'] ?? 0) < now()->timestamp) {
            $request->session()->forget(self::CHAVE_SESSAO);

            return null;
        }

        $utilizador = User::query()->find($estado['id'] ?? 0);

        // Volta a verificar: a conta pode ter sido desativada ou ter perdido o 2FA entretanto.
        if ($utilizador === null || ! $utilizador->ativo || ! $utilizador->temDoisFatoresAtivos()) {
            $request->session()->forget(self::CHAVE_SESSAO);

            return null;
        }

        return $utilizador;
    }
}
