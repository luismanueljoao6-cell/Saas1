<?php

namespace Modules\Core\Http\Controllers\Auth;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Http\Requests\AceitarConviteRequest;
use Modules\Core\Services\ConviteService;

class AceitarConviteController extends Controller
{
    public function __construct(protected ConviteService $convites) {}

    public function mostrar(string $token): Response
    {
        try {
            $convite = $this->convites->pendentePorToken($token);
        } catch (DomainException $e) {
            // Mesma resposta para token desconhecido, expirado, revogado ou já usado.
            return $this->protegida(response()->view('core::auth.aceitar-convite', [
                'token' => $token,
                'convite' => null,
                'mensagem' => $e->getMessage(),
            ], 404));
        }

        return $this->protegida(response()->view('core::auth.aceitar-convite', [
            'token' => $token,
            'convite' => $convite,
            'mensagem' => null,
        ]));
    }

    public function aceitar(AceitarConviteRequest $request, string $token): RedirectResponse
    {
        try {
            $utilizador = $this->convites->aceitar(
                $token,
                $request->validated('name'),
                $request->validated('password'),
            );
        } catch (DomainException $e) {
            return back()->withInput($request->only('name'))->with('erro', $e->getMessage());
        }

        Auth::guard('web')->login($utilizador);
        $request->session()->regenerate();

        return redirect()->route('core.painel')->with('sucesso', 'Conta criada. Bem-vindo(a)!');
    }

    /** O token vai no URL: nada de Referer para terceiros (fontes, CDN) nem cache. */
    protected function protegida(Response $resposta): Response
    {
        return $resposta->withHeaders([
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ]);
    }
}
