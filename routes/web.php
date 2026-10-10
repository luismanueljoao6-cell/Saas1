<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Quem já tem sessão iniciada vai direto ao painel.
    return auth()->check()
        ? redirect()->route('core.painel')
        : view('core::publico.apresentacao');
})->name('inicio');
