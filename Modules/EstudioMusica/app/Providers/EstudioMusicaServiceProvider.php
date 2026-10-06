<?php

namespace Modules\EstudioMusica\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\MenuRegistry;
use Modules\EstudioMusica\Console\Commands\EnviarCampanhaGenericaCommand;
use Modules\EstudioMusica\Http\Middleware\VerificarTokenPortalCliente;
use Modules\EstudioMusica\Jobs\EnviarCampanhaAniversarioJob;
use Modules\EstudioMusica\Jobs\EnviarFollowUpPosLancamentoJob;
use Modules\EstudioMusica\Jobs\EnviarLembretesSessaoJob;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Modules\EstudioMusica\Notifications\Channels\LogChannel;
use Modules\EstudioMusica\Notifications\Channels\TwilioSmsChannel;
use Modules\EstudioMusica\Notifications\Channels\WhatsAppCloudApiChannel;
use RuntimeException;

class EstudioMusicaServiceProvider extends ServiceProvider
{
    /**
     * Mesma ideia de Subscricoes\SubscricoesServiceProvider::$gateways:
     * trocar de canal é só mexer em config('estudiomusica.canal_whatsapp'
     * / 'canal_sms'), nunca no código que os consome (NotificacaoEstudioService).
     */
    protected array $canaisWhatsapp = [
        'log' => LogChannel::class,
        'whatsapp_cloud_api' => WhatsAppCloudApiChannel::class,
    ];

    protected array $canaisSms = [
        'log' => LogChannel::class,
        'twilio' => TwilioSmsChannel::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'estudiomusica');

        // Duas interfaces distintas (não a mesma duas vezes) de propósito:
        // o Laravel resolve bindings de construtor por TIPO, não por nome
        // de parâmetro — não há como pedir "dá-me o canal X" e "dá-me o
        // canal Y" ao container se ambos type-hintarem a mesma interface.
        // Ver Notifications\Channels\Contracts\CanalMensagemInterface.
        $this->app->bind(CanalWhatsAppInterface::class, fn ($app) => $app->make(
            $this->resolverCanal($this->canaisWhatsapp, config('estudiomusica.canal_whatsapp'), 'WhatsApp')
        ));

        $this->app->bind(CanalSmsInterface::class, fn ($app) => $app->make(
            $this->resolverCanal($this->canaisSms, config('estudiomusica.canal_sms'), 'SMS')
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'estudiomusica');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('portal.cliente', VerificarTokenPortalCliente::class);

        $this->app->register(RouteServiceProvider::class);

        $this->registerMenuItems();

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('estudiomusica.php'),
        ], 'estudiomusica-config');

        if ($this->app->runningInConsole()) {
            $this->commands([EnviarCampanhaGenericaCommand::class]);
        }

        $this->agendarTarefas();
    }

    protected function registerMenuItems(): void
    {
        $menu = $this->app->make(MenuRegistry::class);

        $menu->adicionar('estudiomusica.projetos.index', 'Projetos Musicais', 60);
        $menu->adicionar('estudiomusica.sessoes.index', 'Sessões de Estúdio', 61);
        $menu->adicionar('estudiomusica.salas.index', 'Salas', 62);
    }

    /**
     * Regista os 3 Jobs agendados do módulo (secções D e F). Usa
     * callAfterResolving() — a forma segura para pacotes/módulos — em vez
     * de $this->app->make(Schedule::class) diretamente: assim os jobs são
     * sempre acrescentados à instância de Schedule que o Laravel de facto
     * usa em `schedule:run`, seja qual for a ordem em que o kernel a
     * constrói, e nada é feito em pedidos web normais (onde o Schedule
     * nem chega a ser resolvido).
     *
     * O scheduler só corre se o servidor tiver o cron do Laravel ativo
     * (`* * * * * php artisan schedule:run`) e um worker de queue a
     * processar os jobs — ver o README do módulo.
     */
    protected function agendarTarefas(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new EnviarLembretesSessaoJob)
                ->hourly()
                ->name('estudiomusica:lembretes-sessao')
                ->withoutOverlapping();

            $schedule->job(new EnviarCampanhaAniversarioJob)
                ->dailyAt('08:00')
                ->name('estudiomusica:campanha-aniversario')
                ->withoutOverlapping();

            $schedule->job(new EnviarFollowUpPosLancamentoJob)
                ->dailyAt('09:00')
                ->name('estudiomusica:follow-up-lancamento')
                ->withoutOverlapping();
        });
    }

    /**
     * @param  array<string, class-string>  $mapa
     */
    protected function resolverCanal(array $mapa, ?string $identificador, string $tipo): string
    {
        $classe = $mapa[$identificador] ?? null;

        if (! $classe) {
            throw new RuntimeException("Canal de {$tipo} \"{$identificador}\" não está registado em EstudioMusicaServiceProvider.");
        }

        return $classe;
    }
}
