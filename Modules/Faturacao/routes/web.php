<?php

use Illuminate\Support\Facades\Route;
use Modules\Faturacao\Http\Controllers\ClienteController;
use Modules\Faturacao\Http\Controllers\FaturaController;
use Modules\Faturacao\Http\Controllers\ProdutoController;
use Modules\Faturacao\Http\Controllers\SafTExportController;

/*
|--------------------------------------------------------------------------
| Rotas de faturação
|--------------------------------------------------------------------------
| 'auth' + 'tenant' + 'subscricao.ativa' (do Core) cobre o bloqueio total
| fora do grace period. O bloqueio mais fino — sem emitir novos documentos
| DURANTE o grace period — é feito dentro do FaturaController, chamando
| Empresa::podeEmitirFaturas() (ver garantirQuePodeEmitirFaturas()).
*/
Route::middleware(['auth', 'tenant', 'subscricao.ativa'])
    ->prefix('faturacao')
    ->name('faturacao.')
    ->group(function () {
        Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
        Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');

        Route::get('/produtos', [ProdutoController::class, 'index'])->name('produtos.index');
        Route::post('/produtos', [ProdutoController::class, 'store'])->name('produtos.store');

        Route::get('/faturas', [FaturaController::class, 'index'])->name('faturas.index');
        Route::get('/faturas/criar', [FaturaController::class, 'criar'])->name('faturas.criar');
        Route::post('/faturas', [FaturaController::class, 'guardar'])->name('faturas.guardar');
        Route::get('/faturas/{fatura}', [FaturaController::class, 'mostrar'])->name('faturas.mostrar');
        Route::post('/faturas/{fatura}/emitir', [FaturaController::class, 'emitir'])->name('faturas.emitir');

        Route::get('/saft', [SafTExportController::class, 'criar'])->name('saft.criar');
        Route::post('/saft', [SafTExportController::class, 'despachar'])->name('saft.despachar');
    });
