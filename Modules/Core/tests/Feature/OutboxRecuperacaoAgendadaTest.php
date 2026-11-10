<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Jobs\ProcessNotificationJob;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Notifications\NotificationChannelRegistry;
use Modules\Core\Notifications\NotificationDispatcher;
use Tests\TestCase;

/**
 * O recuperador da Outbox só existia como comando manual: linhas perdidas e
 * leases expirados ficavam presos para sempre. Agora corre agendado, e
 * --min-age evita empilhar um job por minuto para a mesma linha.
 */
class OutboxRecuperacaoAgendadaTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->empresa = Empresa::create(['nome_comercial' => 'Empresa Recuperação', 'nif' => '100000501']);

        app(NotificationChannelRegistry::class)->register(new class implements NotificationChannel
        {
            public function identifier(): string
            {
                return 'teste.recuperacao';
            }

            public function send(NotificationOutbox $notification): NotificationSendResult
            {
                return NotificationSendResult::accepted('teste');
            }
        });
    }

    public function test_a_recuperacao_da_outbox_esta_agendada_de_minuto_a_minuto(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => str_contains((string) $evento->command, 'notifications:recover'));

        $this->assertCount(1, $eventos, 'Sem agendamento, linhas perdidas e leases expirados ficam presos para sempre.');

        $evento = $eventos->first();
        $this->assertSame('* * * * *', $evento->expression);
        $this->assertStringContainsString('--min-age=', $evento->command);
        $this->assertTrue($evento->withoutOverlapping);
    }

    public function test_min_age_nao_reagenda_linhas_recentes_nem_empilha_jobs_a_cada_passagem(): void
    {
        $recente = $this->novaNotificacao('min-age-recente');
        $parada = $this->novaNotificacao('min-age-parada');
        NotificationOutbox::withoutGlobalScopes()->whereKey($parada->id)->update(['updated_at' => now()->subMinutes(5)]);

        Queue::fake(); // esquece os jobs iniciais
        Artisan::call('notifications:recover', ['--min-age' => 60]);

        $this->assertSame(
            [$parada->id],
            Queue::pushed(ProcessNotificationJob::class)->map(fn ($job) => $job->notificationId)->all(),
            'Só a linha parada há mais de --min-age é reagendada.'
        );

        Queue::fake();
        Artisan::call('notifications:recover', ['--min-age' => 60]);

        Queue::assertNothingPushed(); // a linha foi marcada na passagem anterior
        $this->assertNotNull($recente->fresh());
    }

    private function novaNotificacao(string $chave): NotificationOutbox
    {
        return app(NotificationDispatcher::class)->dispatch(
            channel: 'teste.recuperacao',
            recipient: '+244923000000',
            payload: ['message' => "Mensagem {$chave}"],
            idempotencyKey: $chave,
            empresaId: $this->empresa->id,
        );
    }
}
