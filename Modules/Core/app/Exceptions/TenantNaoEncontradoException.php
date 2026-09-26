<?php

namespace Modules\Core\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TenantNaoEncontradoException extends Exception
{
    protected $message = 'Não foi possível identificar a empresa associada a este pedido.';

    public function render(Request $request): Response|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return response($this->getMessage(), 409);
    }
}
