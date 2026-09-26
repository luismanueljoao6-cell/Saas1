<?php

use Illuminate\Support\Facades\Route;
use Modules\Subscricoes\Http\Controllers\PlanoController;
use Modules\Subscricoes\Http\Controllers\SubscricaoController;
use Modules\Subscricoes\Models\Pagamento;

/*
|--------------------------------------------------------------------------
| Rotas de subscrição
|--------------------------------------------------------------------------
| Middleware 'auth' + 'tenant', mas DELIBERADAMENTE sem 'subscricao.ativa':
| uma empresa suspensa/expirada tem de conseguir chegar a estas páginas
| para poder pagar e reativar — bloqueá-las aqui criaria um impasse.
*/
Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/planos', [PlanoController::class, 'index'])->name('subscricoes.planos');
    Route::post('/subscricao', [SubscricaoController::class, 'iniciar'])->name('subscricoes.iniciar');

    Route::get('/subscricao/pagamentos/{pagamento}', function (Pagamento $pagamento) {
        // O route model binding já passa pela TenantScope — um utilizador
        // de outra empresa recebe 404, nunca vê a referência de outrem.
        return view('subscricoes::subscricao.pendente', ['pagamento' => $pagamento->load('subscricao.plano')]);
    })->name('subscricoes.pendente');
});
