<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Events\NotificationStateChanged;
use Modules\Core\Jobs\ProcessNotificationJob;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\NotificationAttempt;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Notifications\Exceptions\ResultadoIndeterminadoException;
use Modules\Core\Notifications\NotificationChannelRegistry;
use Modules\Core\Notifications\NotificationDispatcher;
use Modules\Core\Notifications\NotificationProcessor;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Fase 4 da PR #33: um resultado indeterminado nunca é repetido sozinho e só
 * sai de «unknown_result» por uma decisão explícita, justificada e isolada por
 * empresa. Falhas comuns (antes de o fornecedor aceitar) mantêm o retry.
 */
class OutboxResultadoDesconhecidoTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Empresa $outraEmpresa;

    private CanalIndeterminado $canal;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->empresa = Empresa::create(['nome_comercial' => 'Empresa A', 'nif' => '100000411']);
        $this->outraEmpresa = Empresa::create(['nome_comercial' => 'Empresa B', 'nif' => '100000412']);

        $this->canal = new CanalIndeterminado;
        app(NotificationChannelRegistry::class)->register($this->canal);
    }

    // ---------------------------------------------------------------------
    // Processador
    // ---------------------------------------------------------------------

    public function test_resultado_indeterminado_do_canal_nao_e_repetido_automaticamente(): void
    {
        $this->canal->falha = new ResultadoIndeterminadoException('Tempo esgotado depois de enviar o pedido.');
        $notificacao = $this->nova('ind-1');
        $jobsAntes = Queue::pushed(ProcessNotificationJob::class)->count();

        Event::fake([NotificationStateChanged::class]);

        $this->processar($notificacao);

        $notificacao->refresh();
        $this->assertSame(NotificationOutbox::STATUS_UNKNOWN_RESULT, $notificacao->status);
        $this->assertSame(1, $notificacao->attempts);
        $this->assertNull($notificacao->lock_token);
        $this->assertStringContainsString('Resultado desconhecido', (string) $notificacao->last_error);
        $this->assertStringContainsString('Tempo esgotado', (string) $notificacao->last_error);

        $tentativa = $this->ultimaTentativa($notificacao);
        $this->assertSame('unknown', $tentativa->status);
        $this->assertSame('unknown_result', $tentativa->error_code);
        $this->assertNotNull($tentativa->finished_at);

        $this->assertCount($jobsAntes, Queue::pushed(ProcessNotificationJob::class), 'Nenhum retry pode ser agendado.');
        Event::assertDispatched(NotificationStateChanged::class);

        // Nem um job repetido nem o recuperador voltam a enviar.
        $this->processar($notificacao);
        Artisan::call('notifications:recover');

        $this->assertSame(1, $this->canal->envios);
        $this->assertSame(NotificationOutbox::STATUS_UNKNOWN_RESULT, $notificacao->fresh()->status);
        $this->assertCount($jobsAntes, Queue::pushed(ProcessNotificationJob::class));
    }

    public function test_falha_comum_do_canal_continua_a_agendar_retry(): void
    {
        $this->canal->falha = new RuntimeException('Falhou antes de contactar o fornecedor.');
        $notificacao = $this->nova('falha-comum');
        $jobsAntes = Queue::pushed(ProcessNotificationJob::class)->count();

        $this->processar($notificacao);

        $this->assertSame(NotificationOutbox::STATUS_RETRY_SCHEDULED, $notificacao->fresh()->status);
        $this->assertCount($jobsAntes + 1, Queue::pushed(ProcessNotificationJob::class));
    }

    // ---------------------------------------------------------------------
    // Resolução controlada
    // ---------------------------------------------------------------------

    public function test_resolver_como_aceite_regista_decisao_e_nunca_reenvia(): void
    {
        $notificacao = $this->desconhecida('res-aceite');
        Queue::fake();
        Event::fake([NotificationStateChanged::class]);

        $codigo = $this->resolver($notificacao, [
            '--como' => 'accepted',
            '--motivo' => 'Confirmado no painel do fornecedor',
            '--provider-message-id' => 'wamid-123',
        ]);

        $this->assertSame(0, $codigo);
        $notificacao->refresh();
        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
        $this->assertSame('wamid-123', $notificacao->provider_message_id);
        $this->assertNotNull($notificacao->accepted_at);
        $this->assertStringContainsString('Confirmado no painel', (string) $notificacao->last_error);
        $this->assertSame('accepted', $this->ultimaTentativa($notificacao)->metadata['resolucao_manual']['decisao']);

        Event::assertDispatched(NotificationStateChanged::class);
        Queue::assertNothingPushed();

        $this->processar($notificacao);
        $this->assertSame(0, $this->canal->envios);
    }

    public function test_resolver_como_falhada_fecha_a_notificacao_sem_reenvio(): void
    {
        $notificacao = $this->desconhecida('res-falhada');
        Queue::fake();

        $codigo = $this->resolver($notificacao, ['--como' => 'failed', '--motivo' => 'Fornecedor confirma que não recebeu']);

        $this->assertSame(0, $codigo);
        $notificacao->refresh();
        $this->assertSame(NotificationOutbox::STATUS_FAILED, $notificacao->status);
        $this->assertNotNull($notificacao->failed_at);
        Queue::assertNothingPushed();
    }

    public function test_reenviar_exige_confirmacao_expressa_do_risco_de_duplicado(): void
    {
        $notificacao = $this->desconhecida('res-sem-confirmacao');
        Queue::fake();

        $codigo = $this->resolver($notificacao, ['--como' => 'requeue', '--motivo' => 'Quero reenviar']);

        $this->assertSame(1, $codigo);
        $this->assertSame(NotificationOutbox::STATUS_UNKNOWN_RESULT, $notificacao->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_reenviar_com_confirmacao_agenda_uma_nova_tentativa_mesmo_no_limite(): void
    {
        $notificacao = $this->desconhecida('res-reenvio', maxAttempts: 1);
        Queue::fake();

        $codigo = $this->resolver($notificacao, [
            '--como' => 'requeue',
            '--motivo' => 'Cliente confirma que não recebeu',
            '--confirmo-duplicado' => true,
        ]);

        $this->assertSame(0, $codigo);
        $notificacao->refresh();
        $this->assertSame(NotificationOutbox::STATUS_PENDING, $notificacao->status);
        $this->assertSame(2, $notificacao->max_attempts, 'A decisão humana garante mais uma tentativa.');
        $this->assertCount(1, Queue::pushed(ProcessNotificationJob::class));

        $this->processar($notificacao);

        $this->assertSame(1, $this->canal->envios);
        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->fresh()->status);
    }

    public function test_resolver_recusa_pedidos_incompletos_ou_de_outra_empresa(): void
    {
        $notificacao = $this->desconhecida('res-recusas');

        $semMotivo = $this->resolver($notificacao, ['--como' => 'accepted']);
        $comoInvalido = $this->resolver($notificacao, ['--como' => 'apagar', '--motivo' => 'x']);
        $outraEmpresa = $this->resolver($notificacao, ['--como' => 'accepted', '--motivo' => 'x'], $this->outraEmpresa->id);

        $this->assertSame([1, 1, 1], [$semMotivo, $comoInvalido, $outraEmpresa]);
        $this->assertSame(NotificationOutbox::STATUS_UNKNOWN_RESULT, $notificacao->fresh()->status);
    }

    public function test_so_se_resolve_o_estado_resultado_desconhecido(): void
    {
        $notificacao = $this->nova('res-estado-errado');
        $notificacao->forceFill(['status' => NotificationOutbox::STATUS_ACCEPTED, 'attempts' => 1])->save();

        $codigo = $this->resolver($notificacao, ['--como' => 'failed', '--motivo' => 'x']);

        $this->assertSame(1, $codigo);
        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Preparação
    // ---------------------------------------------------------------------

    private function nova(string $chave, int $maxAttempts = 5): NotificationOutbox
    {
        return app(NotificationDispatcher::class)->dispatch(
            channel: 'teste.indeterminado',
            recipient: '+244923000000',
            payload: ['corpo' => "Mensagem {$chave}"],
            idempotencyKey: $chave,
            maxAttempts: $maxAttempts,
            empresaId: $this->empresa->id,
        );
    }

    private function desconhecida(string $chave, int $maxAttempts = 5): NotificationOutbox
    {
        $notificacao = $this->nova($chave, $maxAttempts);
        $notificacao->forceFill([
            'status' => NotificationOutbox::STATUS_UNKNOWN_RESULT,
            'attempts' => 1,
            'last_error' => 'Resultado desconhecido: teste.',
        ])->save();

        NotificationAttempt::create([
            'empresa_id' => $this->empresa->id,
            'notification_outbox_id' => $notificacao->id,
            'attempt_number' => 1,
            'status' => 'abandoned',
            'started_at' => now()->subMinutes(5),
        ]);

        return $notificacao->fresh();
    }

    private function processar(NotificationOutbox $notificacao): void
    {
        app(NotificationProcessor::class)->process((int) $notificacao->id, (int) $notificacao->empresa_id);
    }

    private function resolver(NotificationOutbox $notificacao, array $opcoes, ?int $empresaId = null): int
    {
        return Artisan::call('notifications:resolve-unknown', array_merge([
            'id' => $notificacao->id,
            '--empresa' => $empresaId ?? $this->empresa->id,
        ], $opcoes));
    }

    private function ultimaTentativa(NotificationOutbox $notificacao): NotificationAttempt
    {
        return NotificationAttempt::withoutGlobalScopes()
            ->where('notification_outbox_id', $notificacao->id)
            ->orderByDesc('attempt_number')
            ->firstOrFail();
    }
}

/** Canal sem fornecedor: conta os envios e pode falhar com a exceção que o teste escolher. */
final class CanalIndeterminado implements NotificationChannel
{
    public int $envios = 0;

    public ?Throwable $falha = null;

    public function identifier(): string
    {
        return 'teste.indeterminado';
    }

    public function send(NotificationOutbox $notification): NotificationSendResult
    {
        $this->envios++;

        if ($this->falha !== null) {
            throw $this->falha;
        }

        return NotificationSendResult::accepted('teste', "msg-{$this->envios}");
    }
}
