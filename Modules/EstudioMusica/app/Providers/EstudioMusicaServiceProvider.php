<?php

namespace Modules\EstudioMusica\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Services\MenuRegistry;
use Modules\Core\Services\TenantProvisionerRegistry;
use Modules\EstudioMusica\Console\Commands\EnviarCampanhaGenericaCommand;
use Modules\EstudioMusica\Console\Commands\ExportarDadosArtistaCommand;
use Modules\EstudioMusica\Console\Commands\LimparDadosArtistaCommand;
use Modules\EstudioMusica\Http\Middleware\VerificarTokenPortalCliente;
use Modules\EstudioMusica\Jobs\EnviarCampanhaAniversarioJob;
use Modules\EstudioMusica\Jobs\EnviarFollowUpPosLancamentoJob;
use Modules\EstudioMusica\Jobs\EnviarLembretesSessaoJob;
use Modules\EstudioMusica\Jobs\PurgarSolicitacoesAntigasJob;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Modules\EstudioMusica\Notifications\Channels\LogChannel;
use Modules\EstudioMusica\Notifications\Channels\TwilioSmsChannel;
use Modules\EstudioMusica\Notifications\Channels\WhatsAppCloudApiChannel;
use Modules\EstudioMusica\Support\PapeisEstudio;
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
        $this->registarLimitadores();
        $this->registarProvisionador();

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('estudiomusica.php'),
        ], 'estudiomusica-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                EnviarCampanhaGenericaCommand::class,
                ExportarDadosArtistaCommand::class,
                LimparDadosArtistaCommand::class,
            ]);
        }

        $this->agendarTarefas();
    }

    /**
     * Chave 'estudio' = Servico::Estudio->value: o registry só corre este
     * provisionador para empresas com o serviço ativo.
     */
    protected function registarProvisionador(): void
    {
        $this->app->make(TenantProvisionerRegistry::class)->register(
            'estudio',
            fn (int $empresaId) => PapeisEstudio::provisionar($empresaId),
        );
    }

    protected function registerMenuItems(): void
    {
        $menu = $this->app->make(MenuRegistry::class);

        // Só mostra as entradas a quem as rotas deixam abrir (qualquer permissão do Estúdio).
        $equipaEstudio = fn () => auth()->user()?->hasAnyPermission(PapeisEstudio::PERMISSOES) ?? false;
        $administrador = fn () => auth()->user()?->hasRole('Administrador') ?? false;

        $menu->adicionar('estudiomusica.projetos.index', 'Projetos Musicais', 60, null, $equipaEstudio);
        $menu->adicionar('estudiomusica.sessoes.index', 'Sessões de Estúdio', 61, null, $equipaEstudio);
        $menu->adicionar('estudiomusica.salas.index', 'Salas', 62, null, $equipaEstudio);
        $menu->adicionar(
            'estudiomusica.equipa.index',
            'Equipa do Estúdio',
            63,
            null,
            $administrador,
        );
        $menu->adicionar('estudiomusica.reclamacoes.index', 'Reclamações', 64, null, $equipaEstudio);
        $menu->adicionar('estudiomusica.termos.index', 'Termos e Privacidade', 65, null, $administrador);
    }

    /**
     * Limite do portal POR TOKEN (e um teto por IP). Só por IP, várias
     * pessoas atrás do mesmo IP (redes móveis com CGNAT) partilhavam o
     * limite e o player — que faz vários pedidos Range por faixa — era
     * bloqueado com falsos positivos.
     */
    protected function registarLimitadores(): void
    {
        RateLimiter::for('estudiomusica-portal', fn (Request $request) => [
            Limit::perMinute(300)->by('token:'.hash('sha256', (string) $request->route('token'))),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);
    }

    /**
     * Regista os 4 Jobs agendados do módulo (secções D e F). Usa
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
                ->onOneServer()
                ->withoutOverlapping();

            $schedule->job(new EnviarCampanhaAniversarioJob)
                ->dailyAt('08:00')
                ->name('estudiomusica:campanha-aniversario')
                ->onOneServer()
                ->withoutOverlapping();

            $schedule->job(new EnviarFollowUpPosLancamentoJob)
                ->dailyAt('09:00')
                ->name('estudiomusica:follow-up-lancamento')
                ->onOneServer()
                ->withoutOverlapping();

            // Retenção de dados: apaga pedidos de orçamento antigos (Lei n.º 22/11).
            $schedule->job(new PurgarSolicitacoesAntigasJob)
                ->weeklyOn(0, '03:00')
                ->name('estudiomusica:purgar-solicitacoes')
                ->onOneServer()
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
