<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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
        VerificarCadeiaFiscalCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Proxies de confiança: NUNCA '*' por omissão. Em produção define
        // TRUSTED_PROXIES com os IPs do teu proxy/load balancer (separados
        // por vírgula). No GitHub Codespaces (CODESPACES=true) confia-se em
        // tudo automaticamente. Com `config:cache`, define TRUSTED_PROXIES
        // como variável de ambiente real (env() fora de config/ não lê .env).
        $proxies = env('TRUSTED_PROXIES');

        if ($proxies === null && filter_var(env('CODESPACES'), FILTER_VALIDATE_BOOLEAN)) {
            $proxies = '*';
        }

        if ($proxies) {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies))
            );
        }

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
