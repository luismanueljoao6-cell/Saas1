<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Listeners\EnviarNotificacaoMudancaEstado;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoOrcamento;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Listeners\SincronizarEstadoCampanhaOutbox;
use Modules\Atelier\Models\Perfil;
use Modules\Atelier\Notifications\ClienteAtelierNotification;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\NotificationChannelRegistry;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

class NotificacoesELandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_notifica_o_cliente_mesmo_sem_tenant_definido(): void
    {
        Notification::fake();
        config(['atelier.canais_notificacao' => ['mail']]);

        $empresa = Empresa::create(['nome_comercial' => 'Atelier Fila', 'nif' => '400000101']);
        $tenant = app(TenantManager::class);
        $tenant->set($empresa->id);

        $cliente = Cliente::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Maria',
            'email' => 'maria@example.test',
        ]);

        $pedido = Pedido::create([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Vestido de teste',
            'valor_orcamento' => 10000,
            'taxa_iva_aplicada' => 14,
            'status' => 'primeira_prova',
        ]);

        $evento = new PedidoMudouEstado($pedido->fresh(), 'em_costura');

        // Como num worker da fila: nenhum tenant definido.
        $tenant->clear();

        app(EnviarNotificacaoMudancaEstado::class)->handle($evento);

        Notification::assertSentOnDemand(
            ClienteAtelierNotification::class,
            fn ($notificacao, $canais, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'maria@example.test'
        );
    }

    public function test_atelier_regista_canais_e_persiste_notificacao_na_outbox_do_core(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier Outbox', 'nif' => '400000104']);
        $tenant = app(TenantManager::class);
        $tenant->set($empresa->id);

        $cliente = Cliente::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Cliente Outbox',
            'telefone' => '923456789',
        ]);

        $registry = app(NotificationChannelRegistry::class);
        $this->assertTrue($registry->has('atelier.whatsapp'));
        $this->assertTrue($registry->has('atelier.sms'));

        $notificacao = app(NotificadorClienteService::class)->enfileirarViaOutbox(
            $cliente,
            'whatsapp',
            'A sua encomenda está pronta.',
            'atelier-pedido-'.$cliente->id.'-pronto',
        );

        $this->assertInstanceOf(NotificationOutbox::class, $notificacao);
        $this->assertSame($empresa->id, (int) $notificacao->empresa_id);
        $this->assertSame('atelier.whatsapp', $notificacao->channel);
        $this->assertSame('244923456789', $notificacao->recipient);
        $this->assertSame(['message' => 'A sua encomenda está pronta.'], $notificacao->payload);
        $this->assertSame(NotificationOutbox::STATUS_PENDING, $notificacao->status);

        // Repetir o mesmo pedido com a mesma chave não cria um segundo envio.
        $repetida = app(NotificadorClienteService::class)->enfileirarViaOutbox(
            $cliente,
            'whatsapp',
            'A sua encomenda está pronta.',
            'atelier-pedido-'.$cliente->id.'-pronto',
        );

        $this->assertSame($notificacao->id, $repetida->id);
        $this->assertSame(
            1,
            NotificationOutbox::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('idempotency_key', 'atelier-pedido-'.$cliente->id.'-pronto')
                ->where('channel', 'atelier.whatsapp')
                ->count()
        );

        // Uma chave existente não pode ser reutilizada para outro conteúdo.
        try {
            app(NotificadorClienteService::class)->enfileirarViaOutbox(
                $cliente,
                'whatsapp',
                'Conteúdo diferente.',
                'atelier-pedido-'.$cliente->id.'-pronto',
            );
            $this->fail('Era esperado rejeitar a reutilização da chave com conteúdo diferente.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('dados diferentes', $exception->getMessage());
        }
    }

    public function test_envio_de_campanha_por_email_e_executado_no_proprio_job(): void
    {
        Notification::fake();

        $empresa = Empresa::create(['nome_comercial' => 'Atelier Campanha Mail', 'nif' => '400000105']);
        app(TenantManager::class)->set($empresa->id);

        $cliente = Cliente::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Cliente Campanha',
            'email' => 'campanha@example.test',
        ]);

        app(NotificadorClienteService::class)->notificarViaCanalUnico(
            $cliente,
            'mail',
            'Campanha de teste',
            'Mensagem de teste',
        );

        Notification::assertSentOnDemand(
            ClienteAtelierNotification::class,
            fn ($notification, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'campanha@example.test'
        );
    }

    public function test_estado_aceite_da_outbox_actualiza_envio_de_campanha_sem_confundir_aceite_com_entrega(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier Estado Outbox', 'nif' => '400000106']);
        $campanha = Campanha::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Campanha de estado',
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'enviada',
        ]);

        $outbox = NotificationOutbox::create([
            'empresa_id' => $empresa->id,
            'idempotency_key' => 'campanha-'.$campanha->id.'-cliente-1',
            'channel' => 'atelier.whatsapp',
            'recipient' => '244923456789',
            'payload' => ['message' => 'Mensagem de teste'],
        ]);

        $envio = CampanhaEnvio::create([
            'campanha_id' => $campanha->id,
            'cliente_id' => 1,
            'canal' => 'whatsapp',
            'estado' => CampanhaEnvio::ESTADO_PENDENTE,
            'notification_outbox_id' => $outbox->id,
        ]);

        app(SincronizarEstadoCampanhaOutbox::class)->handle(
            new \Modules\Core\Events\NotificationStateChanged(
                (int) $outbox->id,
                (int) $empresa->id,
                NotificationOutbox::STATUS_ACCEPTED,
            )
        );

        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envio->fresh()->estado);
        $this->assertNull($envio->fresh()->enviado_em);
    }

    public function test_estado_da_outbox_de_outra_empresa_nao_actualiza_envio_de_campanha(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier Estado Isolamento', 'nif' => '400000107']);
        $outraEmpresa = Empresa::create(['nome_comercial' => 'Outra Empresa', 'nif' => '400000108']);
        $campanha = Campanha::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Campanha isolada',
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'enviada',
        ]);
        $outbox = NotificationOutbox::create([
            'empresa_id' => $empresa->id,
            'idempotency_key' => 'campanha-isolada-'.$campanha->id,
            'channel' => 'atelier.whatsapp',
            'recipient' => '244923456789',
            'payload' => ['message' => 'Mensagem de teste'],
        ]);
        $envio = CampanhaEnvio::create([
            'campanha_id' => $campanha->id,
            'cliente_id' => 1,
            'canal' => 'whatsapp',
            'estado' => CampanhaEnvio::ESTADO_PENDENTE,
            'notification_outbox_id' => $outbox->id,
        ]);

        app(SincronizarEstadoCampanhaOutbox::class)->handle(
            new \Modules\Core\Events\NotificationStateChanged(
                (int) $outbox->id,
                (int) $outraEmpresa->id,
                NotificationOutbox::STATUS_ACCEPTED,
            )
        );

        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $envio->fresh()->estado);
    }

    public function test_formulario_de_orcamento_de_atelier_nao_publicado_devolve_404(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier Privado', 'nif' => '400000102']);
        $empresa->aderirServicos(['atelier']);
        $empresa->forceFill(['slug' => 'atelier-privado'])->save();
        Perfil::create(['empresa_id' => $empresa->id, 'publicado' => false]);

        $this->post(route('atelier.landing.solicitar-orcamento', ['empresa' => 'atelier-privado']), [
            'nome' => 'Visitante',
            'contacto' => '900000000',
            'consentimento' => '1',
        ])->assertNotFound();

        $this->assertSame(0, PedidoOrcamento::withoutGlobalScopes()->count());
    }

    public function test_formulario_de_orcamento_de_atelier_publicado_regista_o_pedido(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier Publico', 'nif' => '400000103']);
        $empresa->aderirServicos(['atelier']);
        $empresa->forceFill(['slug' => 'atelier-publico'])->save();
        Perfil::create(['empresa_id' => $empresa->id, 'publicado' => true]);

        $this->post(route('atelier.landing.solicitar-orcamento', ['empresa' => 'atelier-publico']), [
            'nome' => 'Visitante',
            'contacto' => '900000000',
            'consentimento' => '1',
        ])->assertRedirect();

        $this->assertSame(1, PedidoOrcamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
    }
}
