<?php

namespace Modules\Core\Providers;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Http\Middleware\CabecalhosDeSeguranca;
use Modules\Core\Http\Middleware\ExigirPapel;
use Modules\Core\Http\Middleware\ExigirServico;
use Modules\Core\Http\Middleware\ExigirServicoDaEmpresa;
use Modules\Core\Http\Middleware\IdentificarTenant;
use Modules\Core\Http\Middleware\VerificarSubscricaoAtiva;
use Modules\Core\Repositories\Contracts\EmpresaRepositoryInterface;
use Modules\Core\Repositories\EmpresaRepository;
use Modules\Core\Services\MenuRegistry;
use Modules\Core\Services\SemLimites;
use Modules\Core\Services\TenantManager;
use Modules\Core\Services\TenantProvisionerRegistry;

class CoreServiceProvider extends ServiceProvider
{
    protected string $nome = 'Core';

    protected string $nomeMinusculas = 'core';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'core');

        // Scoped: partilhado dentro do pedido/job atual, mas limpo entre
        // ciclos de vida em servidores persistentes (Octane/queue workers).
        // Singleton poderia conservar o tenant do pedido anterior.
        $this->app->scoped(TenantManager::class);

        // Idem para o MenuRegistry — cada módulo instalado regista aqui os
        // seus próprios itens (ver boot() de cada ServiceProvider); tem de
        // ser a MESMA instância em toda a aplicação, daí o singleton.
        $this->app->singleton(MenuRegistry::class);
        $this->app->singleton(TenantProvisionerRegistry::class);

        // Por omissão, sem limites. bindIf: se o módulo Subscrições já
        // registou a sua implementação (por ordem de carregamento), esta
        // linha não a substitui.
        $this->app->bindIf(LimitesDaEmpresa::class, SemLimites::class);

        $this->app->bind(EmpresaRepositoryInterface::class, EmpresaRepository::class);
    }

    public function boot(): void
    {
        $this->registerViews();
        $this->registerMigrations();
        $this->registerMiddlewareAliases();
        $this->registerMenuItems();
        $this->limparTenantEntreJobs();
        $this->configurarPoliticaDePasswords();
        $this->registarCabecalhosDeSeguranca();

        $this->app->register(RouteServiceProvider::class);

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('core.php'),
        ], 'core-config');
    }

    /**
     * Em produção exige 10+ caracteres com maiúsculas, minúsculas e números;
     * fora de produção mantém o mínimo de 8 para não atrapalhar o desenvolvimento.
     */
    protected function configurarPoliticaDePasswords(): void
    {
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()
            : Password::min(8));
    }

    /**
     * Cabeçalhos de segurança em TODAS as respostas. É middleware global e não
     * de grupo porque o middleware auth tem prioridade na ordenação e responde
     * (401/redirect) antes de um middleware de grupo chegar a correr.
     */
    protected function registarCabecalhosDeSeguranca(): void
    {
        $this->app->make(HttpKernel::class)->pushMiddleware(CabecalhosDeSeguranca::class);
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
        $router->aliasMiddleware('papel', ExigirPapel::class);
        $router->aliasMiddleware('servico', ExigirServico::class);
        $router->aliasMiddleware('servico.empresa', ExigirServicoDaEmpresa::class);
    }

    protected function registerMenuItems(): void
    {
        $menu = $this->app->make(MenuRegistry::class);

        $menu->adicionar('core.painel', 'Painel', 10);

        $menu->adicionar(
            'core.servicos.index',
            'Serviços',
            85,
            null,
            fn () => auth()->user()?->hasRole('Administrador') ?? false,
        );

        $menu->adicionar(
            'core.utilizadores.index',
            'Utilizadores',
            60,
            null,
            fn () => auth()->user()?->hasRole('Administrador') ?? false,
        );

        $menu->adicionar(
            'core.empresa.editar',
            'Empresa',
            90,
            fn () => [auth()->user()?->empresa_id],
            fn () => auth()->user()?->empresa_id !== null,
        );
    }

    /**
     * Num `queue:work` o processo é reutilizado: sem isto, o tenant definido
     * por um job vazaria para o seguinte. Ignora o driver 'sync' (corre dentro
     * do próprio pedido, onde o tenant tem de se manter).
     */
    protected function limparTenantEntreJobs(): void
    {
        Event::listen([JobProcessing::class, JobProcessed::class, JobFailed::class], function ($evento): void {
            if ($evento->connectionName === 'sync') {
                return;
            }

            $this->app->make(TenantManager::class)->clear();
        });
    }
}
