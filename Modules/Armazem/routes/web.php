<?php

use Illuminate\Support\Facades\Route;
use Modules\Armazem\Http\Controllers\ArmazemController;
use Modules\Armazem\Http\Controllers\ArmazemInventarioController;
use Modules\Armazem\Http\Controllers\ArmazemStockRelatorioController;
use Modules\Armazem\Http\Controllers\ArmazemTransferenciaController;

Route::middleware(['auth', 'tenant', 'subscricao.ativa'])
    ->prefix('armazem')
    ->name('armazem.')
    ->group(function (): void {
        Route::middleware('permission:armazem.ver')->group(function (): void {
            Route::get('/', [ArmazemController::class, 'index'])->name('index');
        });

        Route::get('/relatorios/stock', [ArmazemStockRelatorioController::class, 'index'])
            ->middleware('permission:armazem.stock.relatorios.ver')
            ->name('relatorios.stock');
        Route::post('/stock/minimos', [ArmazemStockRelatorioController::class, 'guardarMinimo'])
            ->middleware(['permission:armazem.stock.minimos.gerir', 'throttle:30,1'])
            ->name('stock.minimos.guardar');

        Route::middleware('permission:armazem.gerir')->group(function (): void {
            Route::post('/', [ArmazemController::class, 'store'])->middleware('throttle:20,1')->name('store');
            Route::put('/{armazem}', [ArmazemController::class, 'update'])->whereNumber('armazem')->middleware('throttle:20,1')->name('update');
            Route::patch('/{armazem}/estado', [ArmazemController::class, 'alternarEstado'])->whereNumber('armazem')->middleware('throttle:20,1')->name('estado');
            Route::post('/{armazem}/principal', [ArmazemController::class, 'definirPrincipal'])->whereNumber('armazem')->middleware('throttle:10,1')->name('principal');
        });

        Route::prefix('inventarios')->name('inventarios.')->group(function (): void {
            Route::middleware('permission:armazem.inventarios.ver')->get('/', [ArmazemInventarioController::class, 'index'])->name('index');
            Route::middleware('permission:armazem.inventarios.criar')->post('/', [ArmazemInventarioController::class, 'store'])->middleware('throttle:10,1')->name('store');
            Route::middleware('permission:armazem.inventarios.contar')->post('/{inventario}/linhas/{linha}/contagem', [ArmazemInventarioController::class, 'contar'])->whereNumber('inventario')->whereNumber('linha')->middleware('throttle:60,1')->name('contar');
            Route::middleware('permission:armazem.inventarios.concluir')->post('/{inventario}/concluir', [ArmazemInventarioController::class, 'concluir'])->whereNumber('inventario')->middleware('throttle:10,1')->name('concluir');
            Route::middleware('permission:armazem.inventarios.cancelar')->post('/{inventario}/cancelar', [ArmazemInventarioController::class, 'cancelar'])->whereNumber('inventario')->middleware('throttle:10,1')->name('cancelar');
        });

        Route::prefix('transferencias')->name('transferencias.')->group(function (): void {
            Route::middleware('permission:armazem.transferencias.ver')->get('/', [ArmazemTransferenciaController::class, 'index'])->name('index');
            Route::middleware('permission:armazem.transferencias.criar')->post('/', [ArmazemTransferenciaController::class, 'store'])->middleware('throttle:20,1')->name('store');
            Route::middleware('permission:armazem.transferencias.expedir')->post('/{transferencia}/expedir', [ArmazemTransferenciaController::class, 'expedir'])->whereNumber('transferencia')->middleware('throttle:20,1')->name('expedir');
            Route::middleware('permission:armazem.transferencias.receber')->post('/{transferencia}/receber', [ArmazemTransferenciaController::class, 'receber'])->whereNumber('transferencia')->middleware('throttle:20,1')->name('receber');
            Route::middleware('permission:armazem.transferencias.receber')->post('/{transferencia}/rececao-parcial', [ArmazemTransferenciaController::class, 'rececaoParcial'])->whereNumber('transferencia')->middleware('throttle:20,1')->name('recepcao-parcial');
            Route::middleware('permission:armazem.transferencias.cancelar')->post('/{transferencia}/cancelar', [ArmazemTransferenciaController::class, 'cancelar'])->whereNumber('transferencia')->middleware('throttle:10,1')->name('cancelar');
        });
    });
