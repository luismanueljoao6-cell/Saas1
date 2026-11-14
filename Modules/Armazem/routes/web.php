<?php

use Illuminate\Support\Facades\Route;
use Modules\Armazem\Http\Controllers\ArmazemController;
use Modules\Armazem\Http\Controllers\ArmazemTransferenciaController;

Route::middleware(['auth', 'tenant', 'subscricao.ativa', 'papel:Administrador'])
    ->prefix('armazem')
    ->name('armazem.')
    ->group(function (): void {
        Route::get('/', [ArmazemController::class, 'index'])->name('index');
        Route::post('/', [ArmazemController::class, 'store'])->middleware('throttle:20,1')->name('store');
        Route::put('/{armazem}', [ArmazemController::class, 'update'])->whereNumber('armazem')->middleware('throttle:20,1')->name('update');
        Route::patch('/{armazem}/estado', [ArmazemController::class, 'alternarEstado'])->whereNumber('armazem')->middleware('throttle:20,1')->name('estado');
        Route::post('/{armazem}/principal', [ArmazemController::class, 'definirPrincipal'])->whereNumber('armazem')->middleware('throttle:10,1')->name('principal');

        Route::get('/inventarios', [ArmazemInventarioController::class, 'index'])->name('inventarios.index');
        Route::post('/inventarios', [ArmazemInventarioController::class, 'store'])->middleware('throttle:10,1')->name('inventarios.store');
        Route::post('/inventarios/{inventario}/linhas/{linha}/contagem', [ArmazemInventarioController::class, 'contar'])->whereNumber('inventario')->whereNumber('linha')->middleware('throttle:60,1')->name('inventarios.contar');
        Route::post('/inventarios/{inventario}/concluir', [ArmazemInventarioController::class, 'concluir'])->whereNumber('inventario')->middleware('throttle:10,1')->name('inventarios.concluir');
        Route::post('/inventarios/{inventario}/cancelar', [ArmazemInventarioController::class, 'cancelar'])->whereNumber('inventario')->middleware('throttle:10,1')->name('inventarios.cancelar');

        Route::get('/transferencias', [ArmazemTransferenciaController::class, 'index'])->name('transferencias.index');
        Route::post('/transferencias', [ArmazemTransferenciaController::class, 'store'])->middleware('throttle:20,1')->name('transferencias.store');
        Route::post('/transferencias/{transferencia}/expedir', [ArmazemTransferenciaController::class, 'expedir'])->whereNumber('transferencia')->middleware('throttle:20,1')->name('transferencias.expedir');
        Route::post('/transferencias/{transferencia}/receber', [ArmazemTransferenciaController::class, 'receber'])->whereNumber('transferencia')->middleware('throttle:20,1')->name('transferencias.receber');
        Route::post('/transferencias/{transferencia}/rececao-parcial', [ArmazemTransferenciaController::class, 'rececaoParcial'])->whereNumber('transferencia')->middleware('throttle:20,1')->name('transferencias.recepcao-parcial');
        Route::post('/transferencias/{transferencia}/cancelar', [ArmazemTransferenciaController::class, 'cancelar'])->whereNumber('transferencia')->middleware('throttle:10,1')->name('transferencias.cancelar');
    });
