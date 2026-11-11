<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Notifications\Outbox\AtelierWhatsAppOutboxChannel;
use Modules\Atelier\Services\CampanhaReconciliador;
use Modules\Atelier\Services\Notificacoes\WhatsAppCanal;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Models\User;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Notifications\NotificationChannelRegistry;
use Modules\Core\Notifications\NotificationRetryPolicy;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Fase 6 da PR #33: (a) o consentimento é confirmado no momento efetivo do
 * envio e (b) um resultado desconhecido na Outbox nunca passa por entregue
 * nem é reenviado: o envio fica «incerto» até haver decisão.
 */
class CampanhaConsentimentoEIncertosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Autorização APD presente: estes testes não a exercitam.
        config(['atelier.apd_autorizacao_transferencia' => 'APD-TESTE']);
    }

    // ------------------------------------------------------------------
    // Consentimento no momento do envio
    // ------------------------------------------------------------------

    public function test_a_mensagem_da_campanha_leva_finalidade_e_cliente_na_outbox(): void
    {
        $admin = $this->registarAtelier('500001001');
        $empresaId = (int) $admin->empresa_id;
        $cliente = $this->criarCliente($empresaId, 'Cliente payload');
        $campanha = Campanha::create([
            'empresa_id' => $empresaId,
            'nome' => 'Campanha payload',
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'rascunho',
            'criada_por_id' => $admin->id,
        ]);

        $canal = new class implements NotificationChannel
        {
            public array $payloads = [];

            public function identifier(): string
            {
                return 'atelier.whatsapp';
            }

            public function send(NotificationOutbox $notification): NotificationSendResult
            {
                $this->payloads[] = $notification->payload;

                return NotificationSendResult::accepted('teste', 'msg-1', 202);
            }
        };
        app(NotificationChannelRegistry::class)->register($canal);

        app()->call([new DispararCampanhaJob($campanha->id), 'handle']);

        $this->assertCount(1, $canal->payloads);
        $this->assertSame('marketing', $canal->payloads[0]['finalidade']);
        $this->assertEquals($cliente->id, $canal->payloads[0]['cliente_id']);
        $this->assertEquals($campanha->id, $canal->payloads[0]['campanha_id']);
    }

    public function test_consentimento_revogado_depois_do_enfileiramento_impede_o_envio(): void
    {
        $admin = $this->registarAtelier('500001011');
        $empresaId = (int) $admin->empresa_id;
        $cliente = $this->criarCliente($empresaId, 'Cliente revogou');
        $outbox = $this->outbox($empresaId, ['finalidade' => 'marketing', 'cliente_id' => $cliente->id]);

        ClienteCrm::withoutGlobalScopes()
            ->where('cliente_id', $cliente->id)
            ->update(['consentimento_revogado_em' => now()]);

        $resultado = $this->canal(fornecedorDeveReceber: false)->send($outbox);

        $this->assertFalse($resultado->accepted);
        $this->assertSame('consent_revoked', $resultado->errorCode);
    }

    public function test_consentimento_valido_permite_o_envio(): void
    {
        $admin = $this->registarAtelier('500001021');
        $empresaId = (int) $admin->empresa_id;
        $cliente = $this->criarCliente($empresaId, 'Cliente consentido');
        $outbox = $this->outbox($empresaId, ['finalidade' => 'marketing', 'cliente_id' => $cliente->id]);

        $resultado = $this->canal(fornecedorDeveReceber: true)->send($outbox);

        $this->assertTrue($resultado->accepted);
    }

    public function test_mensagem_sem_finalidade_de_marketing_nao_exige_consentimento(): void
    {
        $admin = $this->registarAtelier('500001031');
        $empresaId = (int) $admin->empresa_id;
        // Cliente sem qualquer registo CRM: uma mensagem transacional não depende disso.
        $cliente = Cliente::create(['empresa_id' => $empresaId, 'nome' => 'Sem CRM', 'telefone' => '923456781']);
        $outbox = $this->outbox($empresaId, ['cliente_id' => $cliente->id]);

        $resultado = $this->canal(fornecedorDeveReceber: true)->send($outbox);

        $this->assertTrue($resultado->accepted);
    }

    public function test_marketing_sem_cliente_identificado_e_recusado(): void
    {
        $admin = $this->registarAtelier('500001041');
        $outbox = $this->outbox((int) $admin->empresa_id, ['finalidade' => 'marketing']);

        $resultado = $this->canal(fornecedorDeveReceber: false)->send($outbox);

        $this->assertFalse($resultado->accepted);
        $this->assertSame('consent_revoked', $resultado->errorCode);
    }

    public function test_consentimento_de_outra_empresa_nao_conta(): void
    {
        $a = $this->registarAtelier('500001051');
        $b = $this->registarAtelier('500001052');
        $clienteDeA = $this->criarCliente((int) $a->empresa_id, 'Cliente de A');

        // A Outbox é da empresa B mas aponta para um cliente (com consentimento) da empresa A.
        $outbox = $this->outbox((int) $b->empresa_id, ['finalidade' => 'marketing', 'cliente_id' => $clienteDeA->id]);

        $resultado = $this->canal(fornecedorDeveReceber: false)->send($outbox);

        $this->assertFalse($resultado->accepted);
    }

    public function test_consentimento_revogado_e_falha_definitiva_sem_retry(): void
    {
        $classe = NotificationRetryPolicy::classify('consent_revoked');

        $this->assertSame(NotificationRetryPolicy::PERMANENT, $classe);
        $this->assertTrue(NotificationRetryPolicy::semRetry($classe));
    }

    // ------------------------------------------------------------------
    // Resultado desconhecido: o envio fica «incerto»
    // ------------------------------------------------------------------

    public function test_outbox_desconhecida_marca_o_envio_como_incerto_e_a_campanha_nao_fecha(): void
    {
        [$campanha, $envio] = $this->campanhaComEnvio('500001061', CampanhaEnvio::ESTADO_PENDENTE, NotificationOutbox::STATUS_UNKNOWN_RESULT);

        app(CampanhaReconciliador::class)->reconciliar($campanha, abandonarOrfaos: true);

        $this->assertSame(CampanhaEnvio::ESTADO_INCERTO, $envio->fresh()->estado);
        $this->assertSame(CampanhaEnvio::ERRO_INCERTO, $envio->fresh()->erro);
        $this->assertSame('em_processamento', $campanha->fresh()->estado);
    }

    public function test_incerto_resolvido_como_aceite_fecha_a_campanha(): void
    {
        [$campanha, $envio] = $this->campanhaComEnvio('500001071', CampanhaEnvio::ESTADO_INCERTO, NotificationOutbox::STATUS_ACCEPTED);

        app(CampanhaReconciliador::class)->reconciliar($campanha);

        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envio->fresh()->estado);
        $this->assertNull($envio->fresh()->erro);
        $this->assertSame('enviada', $campanha->fresh()->estado);
    }

    public function test_incerto_resolvido_como_falhado_conclui_com_falhas(): void
    {
        [$campanha, $envio] = $this->campanhaComEnvio('500001081', CampanhaEnvio::ESTADO_INCERTO, NotificationOutbox::STATUS_FAILED, 'Decidido manualmente.');

        app(CampanhaReconciliador::class)->reconciliar($campanha);

        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $envio->fresh()->estado);
        $this->assertSame('concluida_com_falhas', $campanha->fresh()->estado);
    }

    public function test_incerto_reenfileirado_volta_a_pendente_e_a_campanha_continua_aberta(): void
    {
        [$campanha, $envio] = $this->campanhaComEnvio('500001091', CampanhaEnvio::ESTADO_INCERTO, NotificationOutbox::STATUS_PENDING);

        app(CampanhaReconciliador::class)->reconciliar($campanha);

        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $envio->fresh()->estado);
        $this->assertSame('em_processamento', $campanha->fresh()->estado);
    }

    public function test_o_job_nunca_reenvia_um_envio_incerto(): void
    {
        [$campanha, $envio] = $this->campanhaComEnvio('500001101', CampanhaEnvio::ESTADO_INCERTO, NotificationOutbox::STATUS_UNKNOWN_RESULT);

        $canal = new class implements NotificationChannel
        {
            public int $envios = 0;

            public function identifier(): string
            {
                return 'atelier.whatsapp';
            }

            public function send(NotificationOutbox $notification): NotificationSendResult
            {
                $this->envios++;

                return NotificationSendResult::accepted('teste', 'msg-x', 202);
            }
        };
        app(NotificationChannelRegistry::class)->register($canal);

        app()->call([new DispararCampanhaJob($campanha->id), 'handle']);

        $this->assertSame(0, $canal->envios);
        $this->assertSame(CampanhaEnvio::ESTADO_INCERTO, $envio->fresh()->estado);
        $this->assertSame('em_processamento', $campanha->fresh()->estado);
    }

    // ------------------------------------------------------------------
    // Preparação
    // ------------------------------------------------------------------

    /** Canal real do Atelier com o fornecedor (WhatsAppCanal) simulado: nunca há HTTP. */
    protected function canal(bool $fornecedorDeveReceber): AtelierWhatsAppOutboxChannel
    {
        $this->mock(WhatsAppCanal::class, function ($mock) use ($fornecedorDeveReceber): void {
            if ($fornecedorDeveReceber) {
                $mock->shouldReceive('enviar')->once();
                $mock->shouldReceive('identificador')->andReturn('whatsapp');
            } else {
                $mock->shouldNotReceive('enviar');
            }
        });

        return app(AtelierWhatsAppOutboxChannel::class);
    }

    /** @return array{0: Campanha, 1: CampanhaEnvio} */
    protected function campanhaComEnvio(string $nif, string $estadoEnvio, string $statusOutbox, ?string $erro = null): array
    {
        $admin = $this->registarAtelier($nif);
        $empresaId = (int) $admin->empresa_id;

        $campanha = Campanha::create([
            'empresa_id' => $empresaId,
            'nome' => 'Campanha '.$nif,
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'em_processamento',
            'criada_por_id' => $admin->id,
        ]);

        $cliente = $this->criarCliente($empresaId, 'Cliente '.$nif);
        $outbox = $this->outbox($empresaId, [], $statusOutbox, $erro);

        $envio = CampanhaEnvio::create([
            'campanha_id' => $campanha->id,
            'cliente_id' => $cliente->id,
            'canal' => 'whatsapp',
            'estado' => $estadoEnvio,
            'tentativa' => 1,
            'notification_outbox_id' => $outbox->id,
        ]);

        return [$campanha, $envio];
    }

    protected function registarAtelier(string $nif): User
    {
        $admin = app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif],
            ['name' => 'Admin '.$nif, 'email' => "admin{$nif}@example.test", 'password' => 'Senha-segura-123'],
            ['atelier'],
        );

        // Como num worker: sem tenant ativo; os modelos levam empresa_id explícito.
        app(TenantManager::class)->clear();

        return $admin;
    }

    protected function criarCliente(int $empresaId, string $nome, string $telefone = '923456781'): Cliente
    {
        $cliente = Cliente::create(['empresa_id' => $empresaId, 'nome' => $nome, 'telefone' => $telefone]);

        ClienteCrm::create([
            'empresa_id' => $empresaId,
            'cliente_id' => $cliente->id,
            'aceita_marketing' => true,
            'consentimento_em' => now(),
            'consentimento_origem' => 'teste',
        ]);

        return $cliente;
    }

    protected function outbox(int $empresaId, array $payload = [], string $status = NotificationOutbox::STATUS_PENDING, ?string $erro = null): NotificationOutbox
    {
        return NotificationOutbox::withoutGlobalScopes()->create([
            'empresa_id' => $empresaId,
            'idempotency_key' => 'teste-'.Str::uuid(),
            'channel' => 'atelier.whatsapp',
            'recipient' => '244923456781',
            'payload' => array_merge(['message' => 'Mensagem de teste'], $payload),
            'status' => $status,
            'max_attempts' => 5,
            'available_at' => now(),
            'last_error' => $erro,
        ]);
    }
}
