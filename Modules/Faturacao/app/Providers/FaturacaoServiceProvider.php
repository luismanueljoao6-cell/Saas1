<?php

namespace Modules\Faturacao\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\MenuRegistry;

class FaturacaoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'faturacao');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'faturacao');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->app->register(RouteServiceProvider::class);

        $menu = $this->app->make(MenuRegistry::class);
        $menu->adicionar('faturacao.faturas.index', 'Faturas', 20);
        $menu->adicionar('faturacao.clientes.index', 'Clientes', 30);
        $menu->adicionar('faturacao.produtos.index', 'Produtos', 40);
        $menu->adicionar('faturacao.saft.criar', 'SAF-T (AO)', 80);

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('faturacao.php'),
        ], 'faturacao-config');
    }
}
