<?php

namespace Modules\Armazem\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\MenuRegistry;

class ArmazemServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'armazem');
        $this->app->register(RouteServiceProvider::class);

        $menu = $this->app->make(MenuRegistry::class);

        $menu->adicionar(
            'armazem.index',
            'Armazéns',
            46,
            null,
            fn () => auth()->user()?->hasRole('Administrador') ?? false,
        );

        $menu->adicionar(
            'armazem.transferencias.index',
            'Transferências',
            47,
            null,
            fn () => auth()->user()?->hasRole('Administrador') ?? false,
        );
    }
}
