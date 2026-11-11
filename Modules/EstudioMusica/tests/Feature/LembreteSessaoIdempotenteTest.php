<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Jobs\EnviarLembretesSessaoJob;
use Modules\EstudioMusica\Jobs\EnviarMensagemClienteJob;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Services\NotificacaoEstudioService;
use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

require_once __DIR__.'/../Support/ComEstudioAutenticado.php';

/**
 * Fase 7 da PR #33 (ponto 5.1): um lembrete repetido, ou uma marcação de
 * coluna perdida, nunca pode duplicar a mensagem ao cliente.
 */
class LembreteSessaoIdempotenteTest extends TestCase
{
    use ComEstudioAutenticado;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'estudiomusica.exigir_autorizacao_apd' => true,
            'estudiomusica.apd_autorizacao_transferencia' => 'APD-TESTE-123',
            'estudiomusica.canal_whatsapp' => 'whatsapp_cloud_api',
            'estudiomusica.canal_sms' => 'twilio',
        ]);

        $this->admin = $this->registarEstudio('510000401');
        $this->noTenantDe($this->admin);
    }

    public function test_lembrete_por_telefone_vai_pela_outbox_com_chave_deterministica(): void
    {
        Queue::fake();
        $sessao = $this->sessao(['telefone' => '923000000']);

        $this->assertTrue(app(NotificacaoEstudioService::class)->lembreteSessao($sessao, 24));

        $outbox = NotificationOutbox::withoutGlobalScopes()->sole();
        $this->assertSame('estudio.whatsapp', $outbox->channel);
        $this->assertSame('244923000000', $outbox->recipient);
        $this->assertStringStartsWith('estudio-lembrete-sessao-'.$sessao->id.'-24h-', $outbox->idempotency_key);
        // Só telefone: o job legado (sem idempotência) não é usado.
        Queue::assertNotPushed(EnviarMensagemClienteJob::class);
    }

    public function test_repetir_o_lembrete_nao_cria_segunda_notificacao_nem_segundo_email(): void
    {
        Queue::fake();
        $sessao = $this->sessao(['telefone' => '923000000', 'email' => 'artista@example.test']);
        $servico = app(NotificacaoEstudioService::class);

        $this->assertTrue($servico->lembreteSessao($sessao, 24));
        $this->assertTrue($servico->lembreteSessao($sessao, 24));

        $this->assertSame(2, NotificationOutbox::withoutGlobalScopes()->count());
        $this->assertSame(1, NotificationOutbox::withoutGlobalScopes()->where('channel', 'estudio.email')->count());
        $this->assertSame(1, NotificationOutbox::withoutGlobalScopes()->where('channel', 'estudio.whatsapp')->count());
        Queue::assertNotPushed(EnviarMensagemClienteJob::class);
    }

    public function test_marcacao_da_coluna_perdida_nao_duplica_quando_o_job_corre_outra_vez(): void
    {
        Queue::fake();
        $sessao = $this->sessao(['telefone' => '923000000']);

        $this->correrJob();
        $this->assertSame(1, NotificationOutbox::withoutGlobalScopes()->count());

        app(TenantManager::class)->semTenant(fn () => SessaoEstudio::withoutGlobalScopes()
            ->whereKey($sessao->id)->update(['lembrete_24h_enviado_em' => null]));

        $this->correrJob();

        $this->assertSame(1, NotificationOutbox::withoutGlobalScopes()->count());
    }

    public function test_janelas_de_24h_e_2h_nao_partilham_chave(): void
    {
        Queue::fake();
        $sessao = $this->sessao(['telefone' => '923000000']);
        $servico = app(NotificacaoEstudioService::class);

        $servico->lembreteSessao($sessao, 24);
        $servico->lembreteSessao($sessao, 2);

        $this->assertSame(2, NotificationOutbox::withoutGlobalScopes()->count());
    }

    public function test_sessao_reagendada_gera_nova_chave_sem_excecao(): void
    {
        Queue::fake();
        $sessao = $this->sessao(['telefone' => '923000000']);
        $servico = app(NotificacaoEstudioService::class);

        $servico->lembreteSessao($sessao, 24);

        $sessao->forceFill(['inicio_previsto' => now()->addHours(30), 'fim_previsto' => now()->addHours(31)])->save();
        $servico->lembreteSessao($sessao->fresh(), 24);

        $this->assertSame(2, NotificationOutbox::withoutGlobalScopes()->count());
    }

    public function test_sem_autorizacao_apd_o_telefone_nao_e_enfileirado_mas_o_email_sim(): void
    {
        Queue::fake();
        config(['estudiomusica.apd_autorizacao_transferencia' => null]);
        $sessao = $this->sessao(['telefone' => '923000000', 'email' => 'artista@example.test']);

        $this->assertTrue(app(NotificacaoEstudioService::class)->lembreteSessao($sessao, 24));

        $this->assertSame(1, NotificationOutbox::withoutGlobalScopes()->count());
        $this->assertSame('estudio.email', NotificationOutbox::withoutGlobalScopes()->sole()->channel);
        Queue::assertNotPushed(EnviarMensagemClienteJob::class);
    }

    public function test_sem_nenhum_canal_elegivel_devolve_false(): void
    {
        Queue::fake();
        config(['estudiomusica.apd_autorizacao_transferencia' => null]);
        $sessao = $this->sessao(['telefone' => '923000000']);

        $this->assertFalse(app(NotificacaoEstudioService::class)->lembreteSessao($sessao, 24));
        $this->assertSame(0, NotificationOutbox::withoutGlobalScopes()->count());
    }

    public function test_lembrete_por_email_nao_depende_da_cache(): void
    {
        Queue::fake();
        $sessao = $this->sessao(['email' => 'artista@example.test']);
        $servico = app(NotificacaoEstudioService::class);

        $servico->lembreteSessao($sessao, 24);
        Cache::flush();
        $servico->lembreteSessao($sessao, 24);

        $outbox = NotificationOutbox::withoutGlobalScopes()->sole();
        $this->assertSame('estudio.email', $outbox->channel);
        $this->assertSame('artista@example.test', $outbox->recipient);
        $this->assertSame('Lembrete de sessão de estúdio', $outbox->payload['subject']);
        Queue::assertNotPushed(EnviarMensagemClienteJob::class);
    }

    private function correrJob(): void
    {
        (new EnviarLembretesSessaoJob)->handle(app(TenantManager::class), app(NotificacaoEstudioService::class));
    }

    /** Sessão daqui a 24 h (dentro da janela do job) com o contacto pedido. */
    private function sessao(array $contacto): SessaoEstudio
    {
        $empresaId = (int) $this->admin->empresa_id;

        // Fora do tenant: com o tenant ativo, o BelongsToTenant sobrepõe o empresa_id.
        return app(TenantManager::class)->semTenant(function () use ($empresaId, $contacto): SessaoEstudio {
            $sala = SalaEstudio::withoutGlobalScopes()->create(['empresa_id' => $empresaId, 'nome' => 'Sala', 'preco_hora' => 1000]);
            $cliente = (new Cliente)->forceFill(array_merge(['empresa_id' => $empresaId, 'nome' => 'Artista Lembrete'], $contacto));
            $cliente->save();

            $sessao = new SessaoEstudio;
            $sessao->forceFill([
                'empresa_id' => $empresaId,
                'sala_estudio_id' => $sala->id,
                'cliente_id' => $cliente->id,
                'tipo_servico' => 'mixagem',
                'inicio_previsto' => now()->addHours(24),
                'fim_previsto' => now()->addHours(25),
                'estado' => 'agendada',
            ])->save();

            return $sessao;
        });
    }
}
