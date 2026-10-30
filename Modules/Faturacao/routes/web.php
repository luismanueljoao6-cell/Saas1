<?php

use Illuminate\Support\Facades\Route;
use Modules\Faturacao\Http\Controllers\ClienteController;
use Modules\Faturacao\Http\Controllers\FaturaController;
use Modules\Faturacao\Http\Controllers\NotaController;
use Modules\Faturacao\Http\Controllers\ProdutoController;
use Modules\Faturacao\Http\Controllers\ReciboController;
use Modules\Faturacao\Http\Controllers\SafTExportController;

/*
| 'auth' + 'tenant' + 'subscricao.ativa' (Core). Ações com valor fiscal
| (emitir, SAF-T) exigem o papel Administrador ('papel' corre DEPOIS de
| 'tenant'). Durante o grace period, a emissão é bloqueada no controller.
*/
Route::middleware(['auth', 'tenant', 'subscricao.ativa', 'servico:faturacao'])
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
        Route::get('/faturas/{fatura}/editar', [FaturaController::class, 'editar'])->whereNumber('fatura')->name('faturas.editar');
        Route::put('/faturas/{fatura}', [FaturaController::class, 'atualizar'])->whereNumber('fatura')->name('faturas.atualizar');
        Route::delete('/faturas/{fatura}', [FaturaController::class, 'eliminar'])
            ->whereNumber('fatura')->middleware('throttle:20,1')->name('faturas.eliminar');

        // Notas de crédito/débito: o rascunho é livre (como nas faturas); emitir é de Administrador.
        Route::get('/notas', [NotaController::class, 'index'])->name('notas.index');
        Route::get('/notas/criar', [NotaController::class, 'criar'])->name('notas.criar');
        Route::post('/notas', [NotaController::class, 'guardar'])->middleware('throttle:30,1')->name('notas.guardar');
        Route::get('/notas/{nota}', [NotaController::class, 'mostrar'])->whereNumber('nota')->name('notas.mostrar');
        Route::delete('/notas/{nota}', [NotaController::class, 'eliminar'])
            ->whereNumber('nota')->middleware('throttle:20,1')->name('notas.eliminar');

        Route::get('/recibos', [ReciboController::class, 'index'])->name('recibos.index');
        Route::get('/recibos/{recibo}', [ReciboController::class, 'mostrar'])->whereNumber('recibo')->name('recibos.mostrar');

        Route::middleware('papel:Administrador')->group(function () {
            Route::post('/faturas/{fatura}/emitir', [FaturaController::class, 'emitir'])
                ->whereNumber('fatura')->middleware('throttle:20,1')->name('faturas.emitir');

            Route::post('/faturas/{fatura}/anular', [FaturaController::class, 'anular'])
                ->whereNumber('fatura')->middleware('throttle:10,1')->name('faturas.anular');

            Route::post('/faturas/{fatura}/corrigir', [FaturaController::class, 'corrigir'])
                ->whereNumber('fatura')->middleware('throttle:10,1')->name('faturas.corrigir');

            Route::post('/notas/{nota}/emitir', [NotaController::class, 'emitir'])
                ->whereNumber('nota')->middleware('throttle:20,1')->name('notas.emitir');

            // O recibo é emitido logo, sem rascunho: criar e guardar são de Administrador.
            Route::get('/recibos/criar', [ReciboController::class, 'criar'])->name('recibos.criar');
            Route::post('/recibos', [ReciboController::class, 'guardar'])->middleware('throttle:20,1')->name('recibos.guardar');

            Route::get('/saft', [SafTExportController::class, 'criar'])->name('saft.criar');
            Route::post('/saft', [SafTExportController::class, 'despachar'])->middleware('throttle:3,1')->name('saft.despachar');
            Route::get('/saft/{ficheiro}', [SafTExportController::class, 'descarregar'])
                ->where('ficheiro', '[A-Za-z0-9_\-\.]+')->name('saft.descarregar');
        });
    });
