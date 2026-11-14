<?php

namespace Modules\Armazem\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Armazem\Support\PapeisArmazem;
use Modules\Core\Services\MenuRegistry;
use Modules\Core\Services\TenantProvisionerRegistry;

class ArmazemServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'armazem');
        $this->app->register(RouteServiceProvider::class);

        // O Armazém acompanha o serviço de Faturação e é provisionado
        // automaticamente no registo, ativação ou reparação do serviço.
        $this->app->make(TenantProvisionerRegistry::class)->register(
            'faturacao',
            fn (int $empresaId) => PapeisArmazem::provisionar($empresaId),
        );

        $menu = $this->app->make(MenuRegistry::class);
        $podeConsultar = fn () => auth()->user()?->hasAnyPermission(PapeisArmazem::PERMISSOES) ?? false;

        $menu->adicionar('armazem.index', 'Armazéns', 46, null, $podeConsultar);
        $menu->adicionar('armazem.transferencias.index', 'Transferências', 47, null, $podeConsultar);
        $menu->adicionar('armazem.inventarios.index', 'Inventários', 48, null, $podeConsultar);
        $menu->adicionar(
            'armazem.relatorios.stock',
            'Relatórios de stock',
            49,
            null,
            fn () => auth()->user()?->can('armazem.stock.relatorios.ver') ?? false,
        );
    }
}
