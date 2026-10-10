<?php

use Illuminate\Support\Facades\Route;
use Modules\EstudioMusica\Http\Controllers\FaixaMusicalController;
use Modules\EstudioMusica\Http\Controllers\LandingController;
use Modules\EstudioMusica\Http\Controllers\PerfilArtistaController;
use Modules\EstudioMusica\Http\Controllers\PortalClienteController;
use Modules\EstudioMusica\Http\Controllers\ProjetoMusicalController;
use Modules\EstudioMusica\Http\Controllers\SalaEstudioController;
use Modules\EstudioMusica\Http\Controllers\SessaoEstudioController;
use Modules\EstudioMusica\Http\Controllers\SolicitacaoOrcamentoController;
use Modules\EstudioMusica\Http\Controllers\VersaoAudioController;

/*
|--------------------------------------------------------------------------
| Landing page institucional pública (secção G) — sem autenticação.
|--------------------------------------------------------------------------
| A empresa vai na própria URL: Empresa não usa BelongsToTenant (é o topo
| da hierarquia de tenancy, ver o model), por isso o binding implícito
| aqui é seguro mesmo sem nenhum middleware de tenant já ter corrido.
*/
Route::prefix('estudio/{empresa}')->middleware('servico.empresa:estudio')->name('estudiomusica.landing.')->group(function () {
    Route::get('/', [LandingController::class, 'mostrar'])->name('mostrar');
    Route::post('/orcamento', [LandingController::class, 'solicitarOrcamento'])->middleware('throttle:10,1')->name('orcamento');
});

/*
|--------------------------------------------------------------------------
| Portal do Cliente (secção C) — autenticado por token, nunca por 'auth'.
|--------------------------------------------------------------------------
*/
Route::prefix('portal/{token}')
    ->middleware('portal.cliente')
    ->name('estudiomusica.portal.')
    ->group(function () {
        Route::get('/', [PortalClienteController::class, 'mostrar'])->name('mostrar');
        Route::get('/versoes/{versao}/reproduzir', [PortalClienteController::class, 'reproduzir'])->name('versoes.reproduzir');
        Route::get('/versoes/{versao}/descarregar', [PortalClienteController::class, 'descarregar'])->name('versoes.descarregar');
        Route::post('/versoes/{versao}/comentar', [PortalClienteController::class, 'comentar'])->name('versoes.comentar');
        Route::post('/versoes/{versao}/aprovar', [PortalClienteController::class, 'aprovar'])->name('versoes.aprovar');
        Route::post('/versoes/{versao}/ajustes', [PortalClienteController::class, 'solicitarAjustes'])->name('versoes.ajustes');
    });

/*
|--------------------------------------------------------------------------
| Área autenticada da equipa — mesmo padrão de middleware que os outros
| módulos de negócio (ver Core\routes\web.php e Faturacao\routes\web.php).
|--------------------------------------------------------------------------
| Os grupos 'permission:' seguem os 3 papéis de staff da secção H do
| requisito (o 4º, Artista/Cliente, nunca aqui — vive só no Portal acima).
| Ver database/seeders/EstudioMusicaPermissoesSeeder para quem tem cada
| permissão.
*/
Route::middleware(['auth', 'tenant', 'subscricao.ativa', 'servico:estudio'])
    ->prefix('estudiomusica')
    ->name('estudiomusica.')
    ->group(function () {

        // Leitura: aberta a qualquer membro autenticado da empresa — os
        // 3 papéis de staff precisam todos de ver isto.
        // (whereNumber em projetos.show: sem isto '/projetos/criar', declarada mais
        // abaixo, seria apanhada por este wildcard com {projeto} = 'criar'.)
        Route::get('/salas', [SalaEstudioController::class, 'index'])->name('salas.index');
        Route::get('/projetos', [ProjetoMusicalController::class, 'index'])->name('projetos.index');
        Route::get('/projetos/{projeto}', [ProjetoMusicalController::class, 'show'])->whereNumber('projeto')->name('projetos.show');
        Route::get('/sessoes', [SessaoEstudioController::class, 'index'])->name('sessoes.index');
        Route::get('/versoes/{versao}/reproduzir', [VersaoAudioController::class, 'reproduzir'])->name('versoes.reproduzir');

        // Salas: só Administrador ("configuração de preços", secção H).
        Route::middleware('permission:estudiomusica.gerir-salas')->group(function () {
            Route::post('/salas', [SalaEstudioController::class, 'store'])->name('salas.store');
            Route::put('/salas/{sala}', [SalaEstudioController::class, 'update'])->name('salas.update');
        });

        // Projetos e ficha do artista: Administrador + Receção.
        Route::middleware('permission:estudiomusica.gerir-projetos')->group(function () {
            Route::get('/projetos/criar', [ProjetoMusicalController::class, 'create'])->name('projetos.criar');
            Route::post('/projetos', [ProjetoMusicalController::class, 'store'])->name('projetos.store');
            Route::post('/projetos/{projeto}/link-portal', [ProjetoMusicalController::class, 'gerarLinkPortal'])->name('projetos.link-portal');
            Route::put('/clientes/{cliente}/perfil-artista', [PerfilArtistaController::class, 'update'])->name('clientes.perfil-artista');
            Route::post('/versoes/{versao}/lembrete-aprovacao', [VersaoAudioController::class, 'lembrarAprovacao'])->name('versoes.lembrete-aprovacao');
            Route::post('/projetos/{projeto}/faixas', [FaixaMusicalController::class, 'store'])->name('projetos.faixas.store');
        });

        // Estado do projeto/faixa: Administrador + Engenheiro (quem
        // acompanha a produção tecnicamente).
        Route::middleware('permission:estudiomusica.atualizar-estado-projeto')->group(function () {
            Route::put('/projetos/{projeto}/estado', [ProjetoMusicalController::class, 'atualizarEstado'])->name('projetos.estado');
            Route::put('/faixas/{faixa}/estado', [FaixaMusicalController::class, 'atualizarEstado'])->name('faixas.estado');
        });

        // Faturação do projeto: Administrador + Receção ("emissão de
        // cobranças", secção H).
        Route::middleware('permission:estudiomusica.emitir-cobranca')->group(function () {
            Route::post('/projetos/{projeto}/sinal', [ProjetoMusicalController::class, 'gerarSinal'])->name('projetos.sinal');
            Route::post('/projetos/{projeto}/saldo-final', [ProjetoMusicalController::class, 'gerarSaldoFinal'])->name('projetos.saldo-final');
        });

        // Sessões e pedidos de orçamento recebidos: Administrador + Receção.
        Route::middleware('permission:estudiomusica.gerir-sessoes')->group(function () {
            Route::post('/sessoes', [SessaoEstudioController::class, 'store'])->name('sessoes.store');
            Route::delete('/sessoes/{sessao}', [SessaoEstudioController::class, 'cancelar'])->name('sessoes.cancelar');
            Route::get('/solicitacoes', [SolicitacaoOrcamentoController::class, 'index'])->name('solicitacoes.index');
            Route::put('/solicitacoes/{solicitacao}/estado', [SolicitacaoOrcamentoController::class, 'atualizarEstado'])->name('solicitacoes.estado');
        });

        // Check-in/out: Administrador + Receção.
        Route::middleware('permission:estudiomusica.checkin-checkout')->group(function () {
            Route::post('/sessoes/{sessao}/check-in', [SessaoEstudioController::class, 'checkIn'])->name('sessoes.check-in');
            Route::post('/sessoes/{sessao}/check-out', [SessaoEstudioController::class, 'checkOut'])->name('sessoes.check-out');
        });

        // Upload de áudio: Administrador + Engenheiro.
        Route::middleware('permission:estudiomusica.upload-audio')->group(function () {
            Route::post('/faixas/{faixa}/versoes', [VersaoAudioController::class, 'store'])->name('faixas.versoes.store');
        });
    });
