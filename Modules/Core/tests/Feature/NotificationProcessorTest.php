<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Events\NotificationStateChanged;
use Modules\Core\Jobs\ProcessNotificationJob;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\NotificationAttempt;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Notifications\NotificationChannelRegistry;
use Modules\Core\Notifications\NotificationDispatcher;
use Modules\Core\Notifications\NotificationProcessor;
use Modules\Core\Services\TenantManager;
use RuntimeException;
use Tests\TestCase;

/**
 * Garantias do processador da Outbox que se podem provar sem um fornecedor
 * real: uma mensagem aceite nunca é reenviada por um job repetido; um worker
 * antigo nunca sobrepõe o resultado de uma tentativa já recuperada; uma
 * falha noutro módulo nunca desfaz o estado já confirmado; e uma empresa
 * nunca processa notificações de outra.
 *
 * Não se promete «exatamente uma vez»: se o lease expira a meio de um envio,
 * o fornecedor pode receber a mensagem duas vezes. O que é garantido é que
 * o estado guardado fica coerente com a última tentativa.
 */
class NotificationProcessorTest extends TestCase
{
    use RefreshDatabase;

    private Empresa $empresa;

    private Empresa $outraEmpresa;

    private CanalDeTeste $canal;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->empresa = Empresa::create(['nome_comercial' => 'Empresa A', 'nif' => '100000401']);
        $this->outraEmpresa = Empresa::create(['nome_comercial' => 'Empresa B', 'nif' => '100000402']);

        $this->canal = new CanalDeTeste;
        app(NotificationChannelRegistry::class)->register($this->canal);
    }

    // ---------------------------------------------------------------------
    // Uma mensagem já entregue ao fornecedor nunca volta a ser enviada
    // ---------------------------------------------------------------------

    public function test_um_segundo_job_nao_reenvia_uma_notificacao_ja_aceite(): void
    {
        $notificacao = $this->novaNotificacao('chave-aceite');

        // O job original e o job reagendado pelo recuperador chegam ao worker um a seguir ao outro.
        $this->processar($notificacao);
        $this->processar($notificacao);

        $this->assertSame(1, $this->canal->envios, 'O fornecedor só pode receber a mensagem uma vez.');
        $notificacao->refresh();
        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
        $this->assertSame(1, $notificacao->attempts);
        $this->assertSame(1, $this->tentativas($notificacao)->count());
    }

    public function test_um_job_repetido_nao_converte_uma_notificacao_aceite_em_dead_letter(): void
    {
        // Aceite precisamente na última tentativa permitida.
        $notificacao = $this->novaNotificacao('chave-ultima-tentativa', maxAttempts: 2);
        $notificacao->forceFill([
            'status' => NotificationOutbox::STATUS_ACCEPTED,
            'attempts' => 2,
            'accepted_at' => now(),
            'provider_message_id' => 'msg-original',
        ])->save();

        Event::fake([NotificationStateChanged::class]);

        $this->processar($notificacao);

        $notificacao->refresh();
        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
        $this->assertSame('msg-original', $notificacao->provider_message_id);
        $this->assertSame(0, $this->canal->envios);
        Event::assertNotDispatched(NotificationStateChanged::class);
    }

    public function test_estados_entregue_e_lido_tambem_nao_sao_reenviados(): void
    {
        foreach ([NotificationOutbox::STATUS_DELIVERED, NotificationOutbox::STATUS_READ] as $indice => $estado) {
            $notificacao = $this->novaNotificacao("chave-{$estado}");
            $notificacao->forceFill(['status' => $estado, 'attempts' => 1])->save();

            $this->processar($notificacao);

            $this->assertSame($estado, $notificacao->fresh()->status);
        }

        $this->assertSame(0, $this->canal->envios);
    }

    // ---------------------------------------------------------------------
    // Um problema noutro módulo nunca desfaz o estado já confirmado
    // ---------------------------------------------------------------------

    public function test_falha_de_um_listener_nao_desfaz_o_estado_aceite_nem_provoca_reenvio(): void
    {
        Event::listen(NotificationStateChanged::class, function (): void {
            throw new RuntimeException('listener de outro módulo avariado');
        });

        $notificacao = $this->novaNotificacao('chave-listener');
        $jobsAntes = Queue::pushed(ProcessNotificationJob::class)->count();

        $this->processar($notificacao);

        // O fornecedor aceitou a mensagem: o estado fica registado, mesmo com o listener avariado.
        $notificacao->refresh();
        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
        $this->assertNull($notificacao->lock_token);
        $this->assertSame('accepted', $this->tentativas($notificacao)->first()->status);

        // E nem o recuperador nem um job repetido voltam a enviá-la.
        Artisan::call('notifications:recover');
        $this->assertCount($jobsAntes, Queue::pushed(ProcessNotificationJob::class), 'O recuperador não pode reagendar uma mensagem aceite.');

        $this->processar($notificacao);
        $this->assertSame(1, $this->canal->envios);
    }

    // ---------------------------------------------------------------------
    // Um worker antigo nunca sobrepõe a tentativa já recuperada
    // ---------------------------------------------------------------------

    public function test_worker_antigo_nao_sobrepoe_o_resultado_da_tentativa_recuperada(): void
    {
        $notificacao = $this->novaNotificacao('chave-worker-antigo');

        // Durante o envio do worker 1 o lease expira e um segundo worker recupera e conclui a mensagem.
        $this->canal->aoEnviar = function (NotificationOutbox $enviada, int $numeroDoEnvio) use ($notificacao): NotificationSendResult {
            if ($numeroDoEnvio === 1) {
                $this->expirarLease($notificacao);
                $this->processar($notificacao);
            }

            return NotificationSendResult::accepted('teste', "msg-{$numeroDoEnvio}");
        };

        $this->processar($notificacao);

        $notificacao->refresh();
        $this->assertSame(2, $this->canal->envios, 'Com o lease expirado a meio do envio o fornecedor pode receber duas vezes…');
        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
        $this->assertSame('msg-2', $notificacao->provider_message_id, '…mas prevalece o resultado da tentativa recuperada.');
        $this->assertSame(2, $notificacao->attempts);

        $tentativas = $this->tentativas($notificacao);
        $this->assertSame('accepted', $tentativas->firstWhere('attempt_number', 2)->status);
        $this->assertNotSame('accepted', $tentativas->firstWhere('attempt_number', 1)->status, 'O worker antigo não pode marcar a sua tentativa como aceite.');
    }

    public function test_falha_tardia_de_um_worker_antigo_nao_estraga_a_tentativa_recuperada(): void
    {
        $notificacao = $this->novaNotificacao('chave-falha-tardia');
        $jobsAntes = Queue::pushed(ProcessNotificationJob::class)->count();

        $this->canal->aoEnviar = function (NotificationOutbox $enviada, int $numeroDoEnvio) use ($notificacao): NotificationSendResult {
            if ($numeroDoEnvio === 1) {
                $this->expirarLease($notificacao);
                $this->processar($notificacao);

                throw new RuntimeException('o worker antigo só falha depois de a tentativa ter sido recuperada');
            }

            return NotificationSendResult::accepted('teste', 'msg-recuperada');
        };

        $this->processar($notificacao);

        $notificacao->refresh();
        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
        $this->assertSame('msg-recuperada', $notificacao->provider_message_id);
        $this->assertNull($notificacao->last_error, 'A falha tardia do worker antigo não pode ser registada.');
        $this->assertCount($jobsAntes, Queue::pushed(ProcessNotificationJob::class), 'A falha tardia não pode agendar um retry.');
    }

    // ---------------------------------------------------------------------
    // Leases e recuperação
    // ---------------------------------------------------------------------

    public function test_lease_ativo_impede_um_segundo_worker_e_lease_expirado_permite_recuperar(): void
    {
        $notificacao = $this->novaNotificacao('chave-lease');
        $notificacao->forceFill([
            'status' => NotificationOutbox::STATUS_PROCESSING,
            'attempts' => 1,
            'locked_at' => now()->subSeconds(30),
            'lock_token' => 'worker-vivo',
        ])->save();

        $this->processar($notificacao);
        $this->assertSame(0, $this->canal->envios, 'Com o lease ativo ninguém mais pode enviar.');

        $this->expirarLease($notificacao);
        $this->processar($notificacao);

        $this->assertSame(1, $this->canal->envios);
        $notificacao->refresh();
        $this->assertSame(NotificationOutbox::STATUS_ACCEPTED, $notificacao->status);
        $this->assertSame(2, $notificacao->attempts, 'A tentativa perdida conta para o limite.');
    }

    public function test_tentativa_perdida_fica_registada_como_abandonada_e_nao_como_a_processar(): void
    {
        $notificacao = $this->novaNotificacao('chave-abandonada');

        // Um worker reivindicou a notificação e morreu sem concluir.
        $notificacao->forceFill([
            'status' => NotificationOutbox::STATUS_PROCESSING,
            'attempts' => 1,
            'locked_at' => now()->subMinutes(10),
            'lock_token' => 'worker-morto',
        ])->save();
        NotificationAttempt::create([
            'empresa_id' => $this->empresa->id,
            'notification_outbox_id' => $notificacao->id,
            'attempt_number' => 1,
            'status' => 'processing',
            'started_at' => now()->subMinutes(10),
        ]);

        $this->processar($notificacao);

        $primeira = $this->tentativas($notificacao)->firstWhere('attempt_number', 1);
        $this->assertSame('abandoned', $primeira->status, 'O resultado da tentativa perdida é desconhecido e tem de ficar assinalado.');
        $this->assertSame('lease_expired', $primeira->error_code);
        $this->assertNotNull($primeira->finished_at);
        $this->assertSame('accepted', $this->tentativas($notificacao)->firstWhere('attempt_number', 2)->status);
    }

    public function test_recuperador_reagenda_pendentes_e_abandonadas_mas_nunca_as_aceites(): void
    {
        $devida = $this->novaNotificacao('rec-pendente-devida');
        $retry = $this->novaNotificacao('rec-retry-devido');
        $retry->forceFill(['status' => NotificationOutbox::STATUS_RETRY_SCHEDULED, 'attempts' => 1, 'available_at' => now()->subMinute()])->save();
        $futura = $this->novaNotificacao('rec-retry-futuro');
        $futura->forceFill(['status' => NotificationOutbox::STATUS_RETRY_SCHEDULED, 'attempts' => 1, 'available_at' => now()->addHour()])->save();
        $abandonada = $this->novaNotificacao('rec-processing-abandonado');
        $abandonada->forceFill(['status' => NotificationOutbox::STATUS_PROCESSING, 'attempts' => 1, 'locked_at' => now()->subMinutes(5), 'lock_token' => 'morto'])->save();
        $viva = $this->novaNotificacao('rec-processing-vivo');
        $viva->forceFill(['status' => NotificationOutbox::STATUS_PROCESSING, 'attempts' => 1, 'locked_at' => now()->subSeconds(20), 'lock_token' => 'vivo'])->save();
        $aceite = $this->novaNotificacao('rec-aceite');
        $aceite->forceFill(['status' => NotificationOutbox::STATUS_ACCEPTED, 'attempts' => 1])->save();
        $entregue = $this->novaNotificacao('rec-entregue');
        $entregue->forceFill(['status' => NotificationOutbox::STATUS_DELIVERED, 'attempts' => 1])->save();

        Queue::fake(); // esquece os jobs iniciais: só interessam os que o recuperador agendar

        Artisan::call('notifications:recover');

        $reagendadas = Queue::pushed(ProcessNotificationJob::class)->map(fn ($job) => $job->notificationId)->sort()->values()->all();
        $this->assertSame(
            collect([$devida, $retry, $abandonada])->pluck('id')->sort()->values()->all(),
            $reagendadas,
            'Só pendentes devidas, retries devidos e leases abandonados são reagendados.'
        );

        $abandonada->refresh();
        $this->assertSame(NotificationOutbox::STATUS_RETRY_SCHEDULED, $abandonada->status);
        $this->assertNull($abandonada->lock_token);
        $this->assertSame('vivo', $viva->fresh()->lock_token, 'Um lease ativo não pode ser tocado.');
    }

    public function test_abandonada_no_limite_de_tentativas_vai_para_dead_letter_sem_novo_envio(): void
    {
        $notificacao = $this->novaNotificacao('chave-limite', maxAttempts: 2);
        $notificacao->forceFill([
            'status' => NotificationOutbox::STATUS_PROCESSING,
            'attempts' => 2,
            'locked_at' => now()->subMinutes(5),
            'lock_token' => 'morto',
        ])->save();

        Artisan::call('notifications:recover');
        $this->processar($notificacao);

        $this->assertSame(NotificationOutbox::STATUS_DEAD_LETTER, $notificacao->fresh()->status);
        $this->assertSame(0, $this->canal->envios, 'Passado o limite não há mais nenhuma tentativa.');
    }

    public function test_recuperador_assinala_abandonadas_e_dead_letter_recentes_para_monitorizacao(): void
    {
        $abandonada = $this->novaNotificacao('mon-abandonada');
        $abandonada->forceFill(['status' => NotificationOutbox::STATUS_PROCESSING, 'attempts' => 1, 'locked_at' => now()->subMinutes(5), 'lock_token' => 'morto'])->save();
        $morta = $this->novaNotificacao('mon-dead-letter');
        $morta->forceFill(['status' => NotificationOutbox::STATUS_DEAD_LETTER, 'attempts' => 5, 'failed_at' => now()->subHour()])->save();
        $antiga = $this->novaNotificacao('mon-dead-letter-antiga');
        $antiga->forceFill(['status' => NotificationOutbox::STATUS_DEAD_LETTER, 'attempts' => 5, 'failed_at' => now()->subDays(3)])->save();

        Log::spy();

        Artisan::call('notifications:recover');

        $saida = Artisan::output(); // só se pode ler uma vez

        $this->assertStringContainsString('abandonadas recuperadas: 1', $saida);
        $this->assertMatchesRegularExpression('/dead letter nas .+: 1\)/u', $saida);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $mensagem, array $contexto = []) => ($contexto['abandonadas'] ?? null) === 1)->once();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $mensagem, array $contexto = []) => ($contexto['dead_letter_24h'] ?? null) === 1)->once();
    }

    public function test_o_timeout_do_fornecedor_cabe_dentro_do_lease(): void
    {
        $job = new ProcessNotificationJob(1, 1);

        $this->assertLessThan($job->timeout, (int) config('atelier.http_timeout_segundos', 15), 'O pedido ao fornecedor tem de acabar antes de o worker ser interrompido.');
        $this->assertLessThan(NotificationProcessor::LEASE_SEGUNDOS, $job->timeout, 'O worker tem de ser interrompido antes de o lease poder ser recuperado por outro.');
    }

    // ---------------------------------------------------------------------
    // Isolamento entre empresas
    // ---------------------------------------------------------------------

    public function test_processador_ignora_notificacoes_de_outra_empresa(): void
    {
        $notificacao = $this->novaNotificacao('chave-isolamento');

        app(NotificationProcessor::class)->process($notificacao->id, $this->outraEmpresa->id);

        $this->assertSame(0, $this->canal->envios);
        $notificacao->refresh();
        $this->assertSame(NotificationOutbox::STATUS_PENDING, $notificacao->status);
        $this->assertSame(0, $notificacao->attempts);
    }

    public function test_dispatcher_recusa_empresa_diferente_do_tenant_e_isola_chaves_por_empresa(): void
    {
        $tenants = app(TenantManager::class);
        $dispatcher = app(NotificationDispatcher::class);

        $tenants->set($this->empresa->id);
        try {
            $dispatcher->dispatch('teste.canal', '+244923000001', ['corpo' => 'x'], 'chave-tenant', empresaId: $this->outraEmpresa->id);
            $this->fail('Devia recusar uma empresa diferente da do tenant atual.');
        } catch (RuntimeException $excecao) {
            $this->assertStringContainsString('tenant', $excecao->getMessage());
        }
        $tenants->clear();

        // A mesma chave, em duas empresas, são duas notificações diferentes.
        $a = $dispatcher->dispatch('teste.canal', '+244923000001', ['corpo' => 'x'], 'chave-partilhada', empresaId: $this->empresa->id);
        $b = $dispatcher->dispatch('teste.canal', '+244923000002', ['corpo' => 'y'], 'chave-partilhada', empresaId: $this->outraEmpresa->id);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame($this->empresa->id, (int) $a->empresa_id);
        $this->assertSame($this->outraEmpresa->id, (int) $b->empresa_id);
    }

    // ---------------------------------------------------------------------
    // Preparação
    // ---------------------------------------------------------------------

    private function novaNotificacao(string $chave, int $maxAttempts = 5): NotificationOutbox
    {
        return app(NotificationDispatcher::class)->dispatch(
            channel: 'teste.canal',
            recipient: '+244923000000',
            payload: ['corpo' => "Mensagem {$chave}"],
            idempotencyKey: $chave,
            maxAttempts: $maxAttempts,
            empresaId: $this->empresa->id,
        );
    }

    private function processar(NotificationOutbox $notificacao): void
    {
        app(NotificationProcessor::class)->process((int) $notificacao->id, (int) $notificacao->empresa_id);
    }

    private function expirarLease(NotificationOutbox $notificacao): void
    {
        NotificationOutbox::withoutGlobalScopes()
            ->whereKey($notificacao->id)
            ->update(['locked_at' => now()->subMinutes(10)]);
    }

    /** @return Collection<int, NotificationAttempt> */
    private function tentativas(NotificationOutbox $notificacao)
    {
        return NotificationAttempt::withoutGlobalScopes()
            ->where('notification_outbox_id', $notificacao->id)
            ->orderBy('attempt_number')
            ->get();
    }
}

/** Canal sem fornecedor: aceita tudo, conta os envios e deixa o teste interferir a meio de cada um. */
final class CanalDeTeste implements NotificationChannel
{
    public int $envios = 0;

    /** @var (callable(NotificationOutbox, int): NotificationSendResult)|null */
    public $aoEnviar = null;

    public function identifier(): string
    {
        return 'teste.canal';
    }

    public function send(NotificationOutbox $notification): NotificationSendResult
    {
        $this->envios++;

        if ($this->aoEnviar !== null) {
            return ($this->aoEnviar)($notification, $this->envios);
        }

        return NotificationSendResult::accepted('teste', "msg-{$this->envios}");
    }
}
