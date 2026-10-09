<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Auth\AuthenticatedSessionController;
use Modules\Core\Http\Controllers\Auth\RegisteredTenantController;
use Modules\Core\Http\Controllers\EmpresaController;
use Modules\Core\Http\Controllers\PaginasController;
use Modules\Core\Http\Controllers\UtilizadorController;

/*
|--------------------------------------------------------------------------
| Rotas públicas (visitante)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/registo', [RegisteredTenantController::class, 'create'])->name('core.registo');
    Route::post('/registo', [RegisteredTenantController::class, 'store']);

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('core.login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('core.logout');

/*
| Página de subscrição inativa — sem 'subscricao.ativa' de propósito, para
| evitar um ciclo de redirecionamentos.
*/
Route::get('/subscricao/expirada', [PaginasController::class, 'subscricaoExpirada'])
    ->middleware(['auth', 'tenant'])
    ->name('core.subscricao.expirada');

/*
| Rotas autenticadas de negócio (tenant identificado + subscrição ativa)
*/
Route::middleware(['auth', 'tenant', 'subscricao.ativa'])->group(function () {
    Route::get('/painel', [PaginasController::class, 'painel'])->name('core.painel');

    Route::get('/empresas/{empresa}/definicoes', [EmpresaController::class, 'editar'])
        ->name('core.empresa.editar');
    Route::put('/empresas/{empresa}', [EmpresaController::class, 'atualizar'])
        ->name('core.empresa.atualizar');

    Route::get('/utilizadores', [UtilizadorController::class, 'index'])->name('core.utilizadores.index');
    Route::post('/utilizadores', [UtilizadorController::class, 'store'])->name('core.utilizadores.store');
    Route::post('/utilizadores/{utilizador}/alternar-ativo', [UtilizadorController::class, 'alternarAtivo'])
        ->name('core.utilizadores.alternar-ativo');
});
