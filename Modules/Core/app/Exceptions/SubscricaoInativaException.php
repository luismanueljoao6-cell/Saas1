<?php

namespace Modules\Core\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SubscricaoInativaException extends Exception
{
    protected $message = 'A subscrição da tua empresa não está ativa. Regulariza o pagamento para continuar a aceder.';

    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 402);
        }

        return redirect()
            ->route('core.subscricao.expirada')
            ->with('erro', $this->getMessage());
    }
}
