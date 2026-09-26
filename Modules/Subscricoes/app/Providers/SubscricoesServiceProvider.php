<?php

namespace Modules\Subscricoes\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Modules\Subscricoes\Http\Middleware\VerificarAssinaturaWebhook;
use Modules\Subscricoes\Jobs\VerificarSubscricoesExpiradasJob;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\Gateways\ProxyPayGateway;

class SubscricoesServiceProvider extends ServiceProvider
{
    /**
     * Gateways disponíveis, mapeados pelo identificador usado em
     * config('subscricoes.gateway'). Acrescenta aqui uma entrada de cada
     * vez que implementares um novo GatewayPagamentoInterface.
     */
    protected array $gateways = [
        'proxypay' => ProxyPayGateway::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'subscricoes');

        $this->app->bind(GatewayPagamentoInterface::class, function ($app) {
            $identificador = config('subscricoes.gateway');
            $classe = $this->gateways[$identificador] ?? null;

            if (! $classe) {
                throw new \RuntimeException(
                    "Gateway de pagamento '{$identificador}' não está registado em SubscricoesServiceProvider::\$gateways."
                );
            }

            return $app->make($classe);
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'subscricoes');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('webhook.assinatura', VerificarAssinaturaWebhook::class);

        $this->app->register(RouteServiceProvider::class);

        $this->publishes([
            __DIR__.'/../../config/config.php' => config_path('subscricoes.php'),
        ], 'subscricoes-config');

        $this->agendarVerificacaoDiaria();
    }

    /**
     * Requer que `php artisan schedule:run` esteja no cron do servidor (ou
     * seja simulado pelo GitHub Codespaces durante o desenvolvimento) —
     * é a forma padrão do Laravel de correr tarefas agendadas,
     * independentemente de estares a usar bootstrap/app.php ou
     * Console/Kernel.php nesta versão do projeto.
     */
    protected function agendarVerificacaoDiaria(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->job(new VerificarSubscricoesExpiradasJob)
                ->dailyAt('02:00')
                ->name('subscricoes:verificar-expiradas')
                ->withoutOverlapping();
        });
    }
}
