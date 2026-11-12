<?php

namespace Modules\Faturacao\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\MenuRegistry;
use Modules\Faturacao\Console\ValidarSafTCommand;
use Modules\Faturacao\Contracts\FaturacaoGateway;
use Modules\Faturacao\Services\FaturacaoGatewayService;

class FaturacaoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'faturacao');

        // Ponto único e estável de integração para Atelier, Estúdio e módulos futuros.
        $this->app->bind(FaturacaoGateway::class, FaturacaoGatewayService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'faturacao');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->app->register(RouteServiceProvider::class);

        $this->commands([ValidarSafTCommand::class]);

        $menu = $this->app->make(MenuRegistry::class);
        $menu->adicionar('faturacao.painel', 'Visão geral', 10);
        $menu->adicionar('faturacao.faturas.index', 'Faturas', 20);
        $menu->adicionar('faturacao.notas.index', 'Notas de crédito e débito', 25);
        $menu->adicionar('faturacao.recibos.index', 'Recibos', 27);
        $menu->adicionar('faturacao.clientes.index', 'Clientes', 30);
        $menu->adicionar('faturacao.produtos.index', 'Produtos', 40);
        $menu->adicionar('faturacao.saft.criar', 'SAF-T (AO)', 80);

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('faturacao.php'),
        ], 'faturacao-config');
    }
}
