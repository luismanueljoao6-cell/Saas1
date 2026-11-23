<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Auth\AceitarConviteController;
use Modules\Core\Http\Controllers\Auth\AuthenticatedSessionController;
use Modules\Core\Http\Controllers\Auth\DesafioDoisFatoresController;
use Modules\Core\Http\Controllers\Auth\EmailVerificationController;
use Modules\Core\Http\Controllers\Auth\NewPasswordController;
use Modules\Core\Http\Controllers\Auth\PasswordResetLinkController;
use Modules\Core\Http\Controllers\Auth\RegisteredTenantController;
use Modules\Core\Http\Controllers\EmpresaController;
use Modules\Core\Http\Controllers\PainelController;
use Modules\Core\Http\Controllers\SegurancaContaController;
use Modules\Core\Http\Controllers\ServicoController;
use Modules\Core\Http\Controllers\UtilizadorController;

/*
|--------------------------------------------------------------------------
| Rotas públicas (visitante)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/registo', [RegisteredTenantController::class, 'create'])->name('core.registo');
    // 5 registos / 10 min por IP: trava criação massiva de empresas.
    Route::post('/registo', [RegisteredTenantController::class, 'store'])->middleware('throttle:5,10');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('core.login');
    // Camada grossa por IP; o LoginRequest pode ter a sua própria por conta.
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:20,1');

    // 2.º passo do login para contas com 2FA (só se chega aqui com a palavra-passe certa).
    Route::get('/dois-fatores/desafio', [DesafioDoisFatoresController::class, 'create'])
        ->name('core.2fa.desafio');
    Route::post('/dois-fatores/desafio', [DesafioDoisFatoresController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('core.2fa.desafio.store');

    // Recuperação de palavra-passe. Nomes password.* são os que o Laravel espera.
    Route::get('/esqueci-palavra-passe', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
    Route::post('/esqueci-palavra-passe', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/redefinir-palavra-passe/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('/redefinir-palavra-passe', [NewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.update');

    // Convites: o token (64 caracteres aleatórios) é o segredo; na BD só fica o hash.
    Route::get('/convite/{token}', [AceitarConviteController::class, 'mostrar'])
        ->middleware('throttle:30,1')
        ->name('core.convite.mostrar');
    Route::post('/convite/{token}', [AceitarConviteController::class, 'aceitar'])
        ->middleware('throttle:10,1')
        ->name('core.convite.aceitar');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('core.logout');

/*
|--------------------------------------------------------------------------
| Verificação de e-mail
|--------------------------------------------------------------------------
| Só 'auth', sem 'tenant': o middleware 'tenant' redireciona para aqui
| quem tem o e-mail por verificar (CORE_EXIGIR_EMAIL_VERIFICADO=true).
| Nomes verification.* são os que o Laravel espera.
*/
Route::middleware('auth')->group(function () {
    Route::get('/verificar-email', [EmailVerificationController::class, 'aviso'])
        ->name('verification.notice');
    Route::get('/verificar-email/{id}/{hash}', [EmailVerificationController::class, 'verificar'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/verificar-email/reenviar', [EmailVerificationController::class, 'reenviar'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

/*
|--------------------------------------------------------------------------
| Definições de segurança da conta (2FA)
|--------------------------------------------------------------------------
| 'auth' + 'tenant', sem 'subscricao.ativa': proteger a própria conta não pode
| ficar bloqueado por uma subscrição expirada. Todas as ações sensíveis pedem a
| palavra-passe atual e têm limite de pedidos.
*/
Route::middleware(['auth', 'tenant'])
    ->prefix('conta/seguranca')
    ->name('core.seguranca.')
    ->group(function () {
        Route::get('/', [SegurancaContaController::class, 'index'])->name('index');
        Route::post('/dois-fatores', [SegurancaContaController::class, 'iniciar'])
            ->middleware('throttle:5,1')
            ->name('2fa.iniciar');
        Route::post('/dois-fatores/confirmar', [SegurancaContaController::class, 'confirmar'])
            ->middleware('throttle:10,1')
            ->name('2fa.confirmar');
        Route::post('/dois-fatores/codigos', [SegurancaContaController::class, 'regenerarCodigos'])
            ->middleware('throttle:5,1')
            ->name('2fa.codigos');
        Route::post('/dois-fatores/desativar', [SegurancaContaController::class, 'desativar'])
            ->middleware('throttle:5,1')
            ->name('2fa.desativar');
    });

/*
|--------------------------------------------------------------------------
| Página de subscrição inativa
|--------------------------------------------------------------------------
| Sem o middleware 'subscricao.ativa' de propósito — é para onde esse
| middleware redireciona, evitar criar aqui um ciclo de redirecionamentos.
*/
Route::view('/subscricao/expirada', 'core::subscricao.expirada')
    ->middleware(['auth', 'tenant'])
    ->name('core.subscricao.expirada');

/*
|--------------------------------------------------------------------------
| Rotas autenticadas de negócio (tenant identificado + subscrição ativa)
|--------------------------------------------------------------------------
| Todos os outros módulos devem seguir este mesmo padrão de middleware
| nas suas próprias rotas autenticadas.
*/
Route::middleware(['auth', 'tenant', 'subscricao.ativa'])->group(function () {
    Route::get('/painel', PainelController::class)->name('core.painel');

    Route::get('/empresas/{empresa}/definicoes', [EmpresaController::class, 'editar'])
        ->name('core.empresa.editar');
    Route::put('/empresas/{empresa}', [EmpresaController::class, 'atualizar'])
        ->name('core.empresa.atualizar');

    Route::middleware('papel:Administrador')->group(function () {
        Route::get('/servicos', [ServicoController::class, 'index'])->name('core.servicos.index');
        Route::put('/servicos', [ServicoController::class, 'atualizar'])->name('core.servicos.atualizar');
    });

    Route::get('/utilizadores', [UtilizadorController::class, 'index'])->name('core.utilizadores.index');
    Route::post('/utilizadores', [UtilizadorController::class, 'store'])->name('core.utilizadores.store');
    Route::post('/utilizadores/convites/{convite}/revogar', [UtilizadorController::class, 'revogarConvite'])
        ->name('core.convites.revogar');
    Route::post('/utilizadores/{utilizador}/alternar-ativo', [UtilizadorController::class, 'alternarAtivo'])
        ->name('core.utilizadores.alternar-ativo');
});
