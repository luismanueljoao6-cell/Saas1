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

        $this->app->make(MenuRegistry::class)->adicionar(
            'armazem.index',
            'Armazéns',
            46,
            null,
            fn () => auth()->user()?->hasRole('Administrador') ?? false,
        );
    }
}
