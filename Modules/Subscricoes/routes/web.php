<?php

use Illuminate\Support\Facades\Route;
use Modules\Subscricoes\Http\Controllers\PagamentoController;
use Modules\Subscricoes\Http\Controllers\PlanoController;
use Modules\Subscricoes\Http\Controllers\SubscricaoController;

/*
| 'auth' + 'tenant', DELIBERADAMENTE sem 'subscricao.ativa': uma empresa
| suspensa/expirada tem de conseguir chegar aqui para pagar e reativar.
| Models com BelongsToTenant NÃO usam route model binding implícito: recebe-se
| o id e faz-se o findOrFail dentro do controller, já com o tenant definido.
*/
Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/planos', [PlanoController::class, 'index'])->name('subscricoes.planos');
    Route::post('/subscricao', [SubscricaoController::class, 'iniciar'])->name('subscricoes.iniciar');
    Route::post('/subscricao/{subscricao}/renovacao', [SubscricaoController::class, 'alternarRenovacao'])
        ->whereNumber('subscricao')
        ->name('subscricoes.renovacao');

    Route::get('/subscricao/pagamentos/{pagamento}', [PagamentoController::class, 'mostrar'])
        ->whereNumber('pagamento')
        ->name('subscricoes.pendente');
});
