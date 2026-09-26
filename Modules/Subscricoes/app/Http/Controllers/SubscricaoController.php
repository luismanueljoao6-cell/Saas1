<?php

namespace Modules\Subscricoes\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Subscricoes\Exceptions\GatewayPagamentoException;
use Modules\Subscricoes\Http\Requests\IniciarSubscricaoRequest;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Services\SubscricaoService;
use Throwable;

class SubscricaoController extends Controller
{
    public function __construct(protected SubscricaoService $subscricaoService)
    {
    }

    public function iniciar(IniciarSubscricaoRequest $request): RedirectResponse
    {
        $plano = Plano::findOrFail($request->validated('plano_id'));

        try {
            $pagamento = $this->subscricaoService->iniciar($request->user()->empresa, $plano);
        } catch (GatewayPagamentoException $e) {
            Log::error('Não foi possível gerar referência de pagamento', ['erro' => $e->getMessage()]);

            return back()->with('erro', 'Não foi possível gerar a referência de pagamento neste momento. Tenta novamente em instantes.');
        } catch (Throwable $e) {
            Log::error('Falha inesperada ao iniciar subscrição', ['erro' => $e->getMessage()]);

            return back()->with('erro', 'Ocorreu um erro ao iniciar a subscrição. Tenta novamente.');
        }

        return redirect()
            ->route('subscricoes.pendente', $pagamento)
            ->with('sucesso', 'Referência gerada. Assim que o pagamento for confirmado, a tua subscrição fica ativa automaticamente.');
    }
}
