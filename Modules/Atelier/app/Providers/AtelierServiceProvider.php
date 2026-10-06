<?php

namespace Modules\Atelier\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Jobs\EnviarCampanhasAgendadasJob;
use Modules\Atelier\Jobs\LembretesProvaJob;
use Modules\Atelier\Jobs\VerificarAniversariosJob;
use Modules\Atelier\Listeners\EnviarNotificacaoMudancaEstado;
use Modules\Core\Services\MenuRegistry;

class AtelierServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'atelier');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'atelier');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        $this->app->register(RouteServiceProvider::class);

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('atelier.php'),
        ], 'atelier-config');

        $this->registarMenu();
        $this->registarListeners();
        $this->agendarTarefas();
    }

    protected function registarMenu(): void
    {
        $menu = $this->app->make(MenuRegistry::class);

        $menu->adicionar('atelier.pedidos.index', 'Pedidos (Atelier)', 60);
        $menu->adicionar('atelier.equipa.index', 'Equipa do Atelier', 65);
        $menu->adicionar('atelier.perfil.editar', 'Perfil Público', 70);
        $menu->adicionar('atelier.orcamentos.index', 'Pedidos de Orçamento', 74);
        $menu->adicionar('atelier.campanhas.index', 'Campanhas', 78);
    }

    protected function registarListeners(): void
    {
        Event::listen(PedidoMudouEstado::class, EnviarNotificacaoMudancaEstado::class);
    }

    /**
     * Mesmo padrão de SubscricoesServiceProvider::agendarVerificacaoDiaria()
     * — registado dentro de $this->app->booted() porque o Schedule só fica
     * disponível depois de a aplicação arrancar por completo, e requer
     * `php artisan schedule:run` no cron do servidor.
     */
    protected function agendarTarefas(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->job(new LembretesProvaJob)
                ->hourly()
                ->name('atelier:lembretes-prova')
                ->withoutOverlapping();

            $schedule->job(new VerificarAniversariosJob)
                ->dailyAt('08:00')
                ->name('atelier:verificar-aniversarios')
                ->withoutOverlapping();

            $schedule->job(new EnviarCampanhasAgendadasJob)
                ->hourly()
                ->name('atelier:enviar-campanhas-agendadas')
                ->withoutOverlapping();
        });
    }
}
