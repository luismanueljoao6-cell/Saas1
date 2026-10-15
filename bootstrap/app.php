<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Modules\Core\Console\ProvisionarServicosCommand;
use Modules\Core\Http\Middleware\IdentificarTenant;
use Modules\Core\Support\ProxiesConfiaveis;
use Modules\Faturacao\Console\SincronizarAgtCommand;
use Modules\Faturacao\Console\SolicitarSerieAgtCommand;
use Modules\Faturacao\Console\VerificarCadeiaFiscalCommand;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ProvisionarServicosCommand::class,
        VerificarCadeiaFiscalCommand::class,
        SolicitarSerieAgtCommand::class,
        SincronizarAgtCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxies de confiança: NUNCA '*' por omissão. Em produção define
        // TRUSTED_PROXIES com os IPs do teu proxy/load balancer (separados
        // por vírgula). No GitHub Codespaces (CODESPACES=true) confia-se em
        // tudo automaticamente. Com `config:cache`, define TRUSTED_PROXIES
        // como variável de ambiente real (env() fora de config/ não lê .env).
        $proxies = ProxiesConfiaveis::resolver(
            env('TRUSTED_PROXIES'),
            filter_var(env('CODESPACES'), FILTER_VALIDATE_BOOLEAN),
        );

        if ($proxies !== null) {
            $middleware->trustProxies(at: $proxies);
        }
        // O tenant tem de ficar definido ANTES do route model binding implícito:
        // sem isto, `Cliente $cliente` (BelongsToTenant) seria resolvido sem tenant
        // e daria sempre 404.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: IdentificarTenant::class,
        );

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // As rotas de login/painel pertencem ao módulo Core (nomes core.*).
        $middleware->redirectGuestsTo(fn () => route('core.login'));
        $middleware->redirectUsersTo(fn () => route('core.painel'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
