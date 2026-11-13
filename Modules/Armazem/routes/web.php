<?php

use Illuminate\Support\Facades\Route;
use Modules\Armazem\Http\Controllers\ArmazemController;

Route::middleware(['auth', 'tenant', 'subscricao.ativa', 'papel:Administrador'])
    ->prefix('armazem')
    ->name('armazem.')
    ->group(function (): void {
        Route::get('/', [ArmazemController::class, 'index'])->name('index');
        Route::post('/', [ArmazemController::class, 'store'])->middleware('throttle:20,1')->name('store');
        Route::put('/{armazem}', [ArmazemController::class, 'update'])->whereNumber('armazem')->middleware('throttle:20,1')->name('update');
        Route::patch('/{armazem}/estado', [ArmazemController::class, 'alternarEstado'])->whereNumber('armazem')->middleware('throttle:20,1')->name('estado');
        Route::post('/{armazem}/principal', [ArmazemController::class, 'definirPrincipal'])->whereNumber('armazem')->middleware('throttle:10,1')->name('principal');
    });
