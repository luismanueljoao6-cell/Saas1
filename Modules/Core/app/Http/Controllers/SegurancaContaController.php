<?php

namespace Modules\Core\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Requests\ConfirmarDoisFatoresRequest;
use Modules\Core\Http\Requests\ConfirmarPalavraPasseRequest;
use Modules\Core\Models\User;
use Modules\Core\Services\DoisFatoresService;

/**
 * Definições de segurança do PRÓPRIO utilizador autenticado. Nenhuma ação
 * recebe um id de utilizador: tudo corre sobre $request->user().
 *
 * Os códigos de recuperação em claro só existem na resposta que os mostra
 * (nunca vão para a sessão nem para a BD em claro) e essa resposta não é
 * guardada em cache.
 */
class SegurancaContaController extends Controller
{
    protected const MAX_TENTATIVAS = 5;

    protected const JANELA_SEGUNDOS = 300;

    public function __construct(protected DoisFatoresService $doisFatores) {}

    public function index(Request $request): Response
    {
        $utilizador = $this->utilizador($request);

        $ativo = $utilizador->temDoisFatoresAtivos();
        $segredo = $ativo ? null : $utilizador->two_factor_secret;

        return $this->semCache(response()->view('core::conta.seguranca', [
            'ativo' => $ativo,
            'ativoDesde' => $ativo ? $utilizador->two_factor_confirmed_at : null,
            'restantes' => $ativo ? $this->doisFatores->codigosRestantes($utilizador) : 0,
            'segredo' => $segredo,
            'segredoFormatado' => $segredo !== null ? trim(chunk_split($segredo, 4, ' ')) : null,
            'uri' => $segredo !== null ? $this->doisFatores->uriOtpauth($utilizador->email, $segredo) : null,
        ]));
    }

    /** Passo 1: gera (ou reaproveita) o segredo pendente. Ainda NÃO exige 2FA no login. */
    public function iniciar(ConfirmarPalavraPasseRequest $request): RedirectResponse
    {
        try {
            $this->doisFatores->prepararAtivacao($this->utilizador($request));
        } catch (DomainException $e) {
            return redirect()->route('core.seguranca.index')->with('erro', $e->getMessage());
        }

        return redirect()->route('core.seguranca.index');
    }

    /** Passo 2: prova que a app lê o segredo. Só aqui o 2FA passa a valer. */
    public function confirmar(ConfirmarDoisFatoresRequest $request): Response
    {
        $utilizador = $this->utilizador($request);
        $chave = '2fa-ativar|'.$utilizador->id;

        if (RateLimiter::tooManyAttempts($chave, self::MAX_TENTATIVAS)) {
            throw ValidationException::withMessages([
                'codigo' => 'Demasiadas tentativas. Tenta novamente dentro de '.RateLimiter::availableIn($chave).' segundos.',
            ]);
        }

        $codigos = $this->doisFatores->confirmarAtivacao($utilizador, (string) $request->validated('codigo'));

        if ($codigos === null) {
            RateLimiter::hit($chave, self::JANELA_SEGUNDOS);

            throw ValidationException::withMessages([
                'codigo' => 'O código indicado não é válido. Confirma que a hora do telemóvel está certa.',
            ]);
        }

        RateLimiter::clear($chave);

        Log::info('Verificação em dois passos ativada.', [
            'utilizador_id' => $utilizador->id,
            'empresa_id' => $utilizador->empresa_id,
        ]);

        return $this->mostrarCodigos($codigos, 'A verificação em dois passos ficou ativa.');
    }

    public function regenerarCodigos(ConfirmarPalavraPasseRequest $request): Response|RedirectResponse
    {
        $utilizador = $this->utilizador($request);

        $codigos = $this->doisFatores->regenerarCodigosRecuperacao($utilizador);

        if ($codigos === null) {
            return redirect()->route('core.seguranca.index')
                ->with('erro', 'Ativa primeiro a verificação em dois passos.');
        }

        Log::info('Códigos de recuperação de 2FA regenerados.', [
            'utilizador_id' => $utilizador->id,
            'empresa_id' => $utilizador->empresa_id,
        ]);

        return $this->mostrarCodigos($codigos, 'Gerámos códigos novos. Os anteriores deixaram de funcionar.');
    }

    /** Também serve para cancelar uma ativação ainda pendente (apaga o segredo). */
    public function desativar(ConfirmarPalavraPasseRequest $request): RedirectResponse
    {
        $utilizador = $this->utilizador($request);

        $this->doisFatores->desativar($utilizador);

        Log::info('Verificação em dois passos desativada.', [
            'utilizador_id' => $utilizador->id,
            'empresa_id' => $utilizador->empresa_id,
        ]);

        return redirect()->route('core.seguranca.index')
            ->with('sucesso', 'A verificação em dois passos foi desativada.');
    }

    /** @param  list<string>  $codigos */
    protected function mostrarCodigos(array $codigos, string $mensagem): Response
    {
        return $this->semCache(response()->view('core::conta.codigos-recuperacao', [
            'codigos' => $codigos,
            'mensagem' => $mensagem,
        ]));
    }

    protected function semCache(Response $resposta): Response
    {
        return $resposta->header('Cache-Control', 'no-store, private');
    }

    protected function utilizador(Request $request): User
    {
        /** @var User $utilizador */
        $utilizador = $request->user();

        return $utilizador;
    }
}
