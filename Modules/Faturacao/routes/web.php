<?php

use Illuminate\Support\Facades\Route;
use Modules\Faturacao\Http\Controllers\ClienteController;
use Modules\Faturacao\Http\Controllers\FaturaController;
use Modules\Faturacao\Http\Controllers\ProdutoController;
use Modules\Faturacao\Http\Controllers\SafTExportController;

/*
| 'auth' + 'tenant' + 'subscricao.ativa' (Core). Ações com valor fiscal
| (emitir, SAF-T) exigem o papel Administrador ('papel' corre DEPOIS de
| 'tenant'). Durante o grace period, a emissão é bloqueada no controller.
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
        Route::get('/faturas/{fatura}', [FaturaController::class, 'mostrar'])->whereNumber('fatura')->name('faturas.mostrar');

        Route::middleware('papel:Administrador')->group(function () {
            Route::post('/faturas/{fatura}/emitir', [FaturaController::class, 'emitir'])
                ->whereNumber('fatura')->middleware('throttle:20,1')->name('faturas.emitir');

            Route::get('/saft', [SafTExportController::class, 'criar'])->name('saft.criar');
            Route::post('/saft', [SafTExportController::class, 'despachar'])->middleware('throttle:3,1')->name('saft.despachar');
            Route::get('/saft/{ficheiro}', [SafTExportController::class, 'descarregar'])
                ->where('ficheiro', '[A-Za-z0-9_\-\.]+')->name('saft.descarregar');
        });
    });
