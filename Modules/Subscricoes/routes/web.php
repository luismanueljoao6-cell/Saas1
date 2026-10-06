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
|
| ATENÇÃO — models com BelongsToTenant (Pagamento, Subscricao...) NÃO podem
| usar route model binding implícito ({pagamento} tipado como model): o
| Laravel resolve o binding antes do middleware 'tenant', quando ainda não
| há empresa definida, e a TenantScope devolveria sempre 404. Recebe-se o
| id e faz-se o findOrFail já dentro do handler.
*/
Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/planos', [PlanoController::class, 'index'])->name('subscricoes.planos');
    Route::post('/subscricao', [SubscricaoController::class, 'iniciar'])->name('subscricoes.iniciar');
    Route::post('/subscricao/{subscricao}/renovacao', [SubscricaoController::class, 'alternarRenovacao'])
        ->name('subscricoes.renovacao');

    Route::get('/subscricao/pagamentos/{pagamento}', function (int $pagamento) {
        $modelo = Pagamento::with('subscricao.plano')->findOrFail($pagamento);

        return view('subscricoes::subscricao.pendente', ['pagamento' => $modelo]);
    })->name('subscricoes.pendente');
});
