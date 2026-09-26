<?php

namespace Modules\Faturacao\Providers;

use Illuminate\Support\ServiceProvider;

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

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('faturacao.php'),
        ], 'faturacao-config');
    }
}
