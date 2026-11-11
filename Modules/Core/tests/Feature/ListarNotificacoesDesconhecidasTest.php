<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Notifications\NotificationChannelRegistry;
use Modules\Core\Notifications\NotificationDispatcher;
use Tests\TestCase;

/**
 * Fase 5 da PR #33: o que está em «unknown_result» tem de ser visível
 * (listagem sem dados pessoais) e gerar alerta se ficar parado.
 */
class ListarNotificacoesDesconhecidasTest extends TestCase
{
    use RefreshDatabase;

    private const DESTINATARIO = '+244923000999';

    private Empresa $empresaA;

    private Empresa $empresaB;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->empresaA = Empresa::create(['nome_comercial' => 'Empresa A', 'nif' => '100000511']);
        $this->empresaB = Empresa::create(['nome_comercial' => 'Empresa B', 'nif' => '100000512']);

        app(NotificationChannelRegistry::class)->register(new CanalListaDesconhecidas);
    }

    public function test_sem_notificacoes_desconhecidas_informa_lista_vazia(): void
    {
        $this->assertSame(0, Artisan::call('notifications:list-unknown'));
        $this->assertStringContainsString('Nenhuma notificação em resultado desconhecido', Artisan::output());
    }

    public function test_lista_apenas_unknown_result_e_nao_expoe_o_destinatario(): void
    {
        $this->nova($this->empresaA, 'l-1', NotificationOutbox::STATUS_UNKNOWN_RESULT);
        $this->nova($this->empresaA, 'l-2', NotificationOutbox::STATUS_ACCEPTED);
        $this->nova($this->empresaA, 'l-3', NotificationOutbox::STATUS_DEAD_LETTER);

        $this->assertSame(0, Artisan::call('notifications:list-unknown'));
        $saida = Artisan::output();

        $this->assertStringContainsString('A mostrar 1 de 1', $saida);
        $this->assertStringNotContainsString(self::DESTINATARIO, $saida);
        $this->assertStringNotContainsString('Mensagem', $saida);
    }

    public function test_filtra_por_empresa(): void
    {
        $this->nova($this->empresaA, 'e-a1', NotificationOutbox::STATUS_UNKNOWN_RESULT);
        $this->nova($this->empresaB, 'e-b1', NotificationOutbox::STATUS_UNKNOWN_RESULT);
        $this->nova($this->empresaB, 'e-b2', NotificationOutbox::STATUS_UNKNOWN_RESULT);

        Artisan::call('notifications:list-unknown', ['--empresa' => $this->empresaA->id]);
        $this->assertStringContainsString('A mostrar 1 de 1', Artisan::output());

        Artisan::call('notifications:list-unknown', ['--empresa' => $this->empresaB->id]);
        $this->assertStringContainsString('A mostrar 2 de 2', Artisan::output());

        Artisan::call('notifications:list-unknown');
        $this->assertStringContainsString('A mostrar 3 de 3', Artisan::output());
    }

    public function test_limite_restringe_as_linhas_mas_mostra_o_total(): void
    {
        foreach (['x-1', 'x-2', 'x-3'] as $chave) {
            $this->nova($this->empresaA, $chave, NotificationOutbox::STATUS_UNKNOWN_RESULT);
        }

        Artisan::call('notifications:list-unknown', ['--limit' => 2]);

        $this->assertStringContainsString('A mostrar 2 de 3', Artisan::output());
    }

    public function test_recover_alerta_quando_ha_desconhecidas_ha_mais_de_uma_hora(): void
    {
        $this->nova($this->empresaA, 'a-1', NotificationOutbox::STATUS_UNKNOWN_RESULT, minutosAtras: 120);

        Log::spy();
        Artisan::call('notifications:recover');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($mensagem, $contexto = []) => str_contains((string) $mensagem, 'há mais de 1 h')
                && ($contexto['unknown_result_1h'] ?? null) === 1)
            ->once();
    }

    public function test_recover_nao_alerta_para_desconhecidas_recentes(): void
    {
        $this->nova($this->empresaA, 'a-2', NotificationOutbox::STATUS_UNKNOWN_RESULT, minutosAtras: 10);

        // Qualquer aviso com o texto do alerta de 1 h não tem expectativa e falha o teste.
        Log::shouldReceive('warning')
            ->withArgs(fn ($mensagem) => ! str_contains((string) $mensagem, 'há mais de 1 h'))
            ->zeroOrMoreTimes();

        Artisan::call('notifications:recover');

        $this->addToAssertionCount(1);
    }

    private function nova(Empresa $empresa, string $chave, string $estado, int $minutosAtras = 5): NotificationOutbox
    {
        $notificacao = app(NotificationDispatcher::class)->dispatch(
            channel: 'teste.lista-desconhecidas',
            recipient: self::DESTINATARIO,
            payload: ['corpo' => "Mensagem {$chave}"],
            idempotencyKey: $chave,
            maxAttempts: 5,
            empresaId: $empresa->id,
        );

        $notificacao->forceFill(['status' => $estado, 'attempts' => 1, 'last_error' => 'Resultado desconhecido: teste.'])->save();

        NotificationOutbox::withoutGlobalScopes()
            ->whereKey($notificacao->id)
            ->update(['updated_at' => now()->subMinutes($minutosAtras)]);

        return $notificacao->fresh();
    }
}

final class CanalListaDesconhecidas implements NotificationChannel
{
    public function identifier(): string
    {
        return 'teste.lista-desconhecidas';
    }

    public function send(NotificationOutbox $notification): NotificationSendResult
    {
        return NotificationSendResult::accepted('teste', 'msg-lista');
    }
}
