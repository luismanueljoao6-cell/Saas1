<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API do módulo Core
|--------------------------------------------------------------------------
| Autenticação por tokens do Laravel Sanctum (tabela personal_access_tokens,
| criada em database/migrations). Serve principalmente para uma futura app
| móvel ou SPA separada consultar a empresa do utilizador autenticado.
*/
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('/core/empresa', function (Request $request) {
        return $request->user()->empresa()->select([
            'id', 'nome_comercial', 'nif', 'estado_subscricao', 'subscricao_expira_em',
        ])->first();
    })->name('api.core.empresa');
});
