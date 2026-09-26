<?php

namespace Modules\Core\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Http\Middleware\IdentificarTenant;
use Modules\Core\Http\Middleware\VerificarSubscricaoAtiva;
use Modules\Core\Repositories\Contracts\EmpresaRepositoryInterface;
use Modules\Core\Repositories\EmpresaRepository;
use Modules\Core\Services\TenantManager;

class CoreServiceProvider extends ServiceProvider
{
    protected string $nome = 'Core';

    protected string $nomeMinusculas = 'core';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'core');

        // Singleton: uma única instância do TenantManager por pedido HTTP
        // (ou por execução de comando artisan / job).
        $this->app->singleton(TenantManager::class);

        $this->app->bind(EmpresaRepositoryInterface::class, EmpresaRepository::class);
    }

    public function boot(): void
    {
        $this->registerViews();
        $this->registerMigrations();
        $this->registerMiddlewareAliases();

        $this->app->register(RouteServiceProvider::class);

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('core.php'),
        ], 'core-config');
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', $this->nomeMinusculas);
    }

    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function registerMiddlewareAliases(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];

        $router->aliasMiddleware('tenant', IdentificarTenant::class);
        $router->aliasMiddleware('subscricao.ativa', VerificarSubscricaoAtiva::class);
    }
}
