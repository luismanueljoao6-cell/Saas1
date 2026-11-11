<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Services\CampanhaReconciliador;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Models\User;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Notifications\NotificationChannelRegistry;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Uma campanha "em processamento" nunca pode ficar presa: se o evento da
 * Outbox se perder, o reconciliador (job + comando agendado) corrige o registo.
 */
class ReconciliacaoCampanhasTest extends TestCase
{
    use RefreshDatabase;

    public function test_envio_pendente_com_outbox_aceite_passa_a_aceite_e_a_campanha_fecha(): void
    {
        [$campanha, $envio] = $this->campanhaComUmEnvio('500000901', NotificationOutbox::STATUS_ACCEPTED);

        app(CampanhaReconciliador::class)->reconciliar($campanha);

        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envio->fresh()->estado);
        $this->assertSame('enviada', $campanha->fresh()->estado);
        $this->assertNotNull($campanha->fresh()->enviada_em);
    }

    public function test_outbox_entregue_ou_lida_conta_como_aceite(): void
    {
        foreach ([NotificationOutbox::STATUS_DELIVERED, NotificationOutbox::STATUS_READ] as $i => $status) {
            [$campanha, $envio] = $this->campanhaComUmEnvio('50000091'.$i, $status);

            app(CampanhaReconciliador::class)->reconciliar($campanha);

            $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envio->fresh()->estado, $status);
            $this->assertSame('enviada', $campanha->fresh()->estado, $status);
        }
    }

    public function test_outbox_falhada_ou_cancelada_marca_o_envio_como_falhou_e_a_campanha_conclui_com_falhas(): void
    {
        foreach ([NotificationOutbox::STATUS_DEAD_LETTER, NotificationOutbox::STATUS_FAILED, NotificationOutbox::STATUS_CANCELLED] as $i => $status) {
            [$campanha, $envio] = $this->campanhaComUmEnvio('50000092'.$i, $status, 'Fornecedor indisponível.');

            app(CampanhaReconciliador::class)->reconciliar($campanha);

            $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $envio->fresh()->estado, $status);
            $this->assertSame('concluida_com_falhas', $campanha->fresh()->estado, $status);
            $this->assertNotNull($envio->fresh()->erro);
        }
    }

    public function test_outbox_ainda_a_processar_nao_altera_nada(): void
    {
        foreach ([NotificationOutbox::STATUS_PENDING, NotificationOutbox::STATUS_PROCESSING, NotificationOutbox::STATUS_RETRY_SCHEDULED] as $i => $status) {
            [$campanha, $envio] = $this->campanhaComUmEnvio('50000093'.$i, $status);

            app(CampanhaReconciliador::class)->reconciliar($campanha, abandonarOrfaos: true);

            $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $envio->fresh()->estado, $status);
            $this->assertSame('em_processamento', $campanha->fresh()->estado, $status);
        }
    }

    public function test_so_conta_a_outbox_da_propria_empresa(): void
    {
        [$campanha, $envio] = $this->campanhaComUmEnvio('500000941', NotificationOutbox::STATUS_PENDING);
        $outraEmpresa = $this->registarAtelier('500000942');
        $alheia = $this->outbox((int) $outraEmpresa->empresa_id, NotificationOutbox::STATUS_ACCEPTED);

        // O envio (de uma empresa) aponta, por engano ou corrupção, para a Outbox de outra.
        $envio->update(['notification_outbox_id' => $alheia->id]);

        app(CampanhaReconciliador::class)->reconciliar($campanha);

        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $envio->fresh()->estado);
        $this->assertSame('em_processamento', $campanha->fresh()->estado);
    }

    public function test_envio_orfao_so_e_abandonado_quando_pedido_e_depois_do_prazo(): void
    {
        $admin = $this->registarAtelier('500000951');
        $empresaId = (int) $admin->empresa_id;
        $campanha = $this->campanhaEmProcessamento($admin);

        $antigo = $this->envio($campanha, $this->criarCliente($empresaId, 'Antigo', '923456781'), CampanhaEnvio::ESTADO_PENDENTE);
        $recente = $this->envio($campanha, $this->criarCliente($empresaId, 'Recente', '923456782'), CampanhaEnvio::ESTADO_PENDENTE);
        $this->envelhecer($antigo, CampanhaReconciliador::ENVIO_ORFAO_APOS_MINUTOS + 10);
        $this->envelhecer($recente, 5);

        // Sem o pedido explícito (é o que faz o job a meio de um envio) nada é abandonado.
        app(CampanhaReconciliador::class)->reconciliar($campanha);
        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $antigo->fresh()->estado);

        app(CampanhaReconciliador::class)->reconciliar($campanha, abandonarOrfaos: true);

        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $antigo->fresh()->estado);
        $this->assertStringContainsString('Reenviar falhados', (string) $antigo->fresh()->erro);
        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $recente->fresh()->estado);
        // Ainda há um envio recente por resolver: a campanha continua em processamento.
        $this->assertSame('em_processamento', $campanha->fresh()->estado);

        $this->envelhecer($recente, CampanhaReconciliador::ENVIO_ORFAO_APOS_MINUTOS + 10);
        app(CampanhaReconciliador::class)->reconciliar($campanha, abandonarOrfaos: true);

        $this->assertSame('concluida_com_falhas', $campanha->fresh()->estado);
    }

    public function test_o_comando_reconcilia_campanhas_de_varias_empresas_sem_tenant(): void
    {
        [$campanhaA, $envioA] = $this->campanhaComUmEnvio('500000961', NotificationOutbox::STATUS_ACCEPTED);
        [$campanhaB, $envioB] = $this->campanhaComUmEnvio('500000962', NotificationOutbox::STATUS_DEAD_LETTER, 'Recusada.');

        $this->assertFalse(app(TenantManager::class)->has());

        $this->assertSame(0, Artisan::call('atelier:campanhas-reconciliar'));
        $this->assertStringContainsString('2', Artisan::output());

        $this->assertSame('enviada', $campanhaA->fresh()->estado);
        $this->assertSame('concluida_com_falhas', $campanhaB->fresh()->estado);
        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envioA->fresh()->estado);
        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $envioB->fresh()->estado);
    }

    public function test_o_comando_nao_mexe_numa_campanha_que_um_job_esta_a_enviar(): void
    {
        [$campanha, $envio] = $this->campanhaComUmEnvio('500000971', NotificationOutbox::STATUS_ACCEPTED);

        $lock = Cache::lock('atelier:campanha:'.$campanha->id, 60);
        $this->assertTrue($lock->get());

        try {
            Artisan::call('atelier:campanhas-reconciliar');
        } finally {
            $lock->release();
        }

        $this->assertSame('em_processamento', $campanha->fresh()->estado);
        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $envio->fresh()->estado);

        Artisan::call('atelier:campanhas-reconciliar');

        $this->assertSame('enviada', $campanha->fresh()->estado);
    }

    public function test_o_comando_esta_agendado_de_dez_em_dez_minutos(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => $e->description === 'atelier:campanhas-reconciliar');

        $this->assertNotNull($evento, 'O comando de reconciliação não está agendado.');
        $this->assertSame('*/10 * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
    }

    public function test_o_job_tambem_recupera_um_evento_aceite_que_se_perdeu(): void
    {
        [$campanha, $envio] = $this->campanhaComUmEnvio('500000981', NotificationOutbox::STATUS_ACCEPTED);

        app()->call([new DispararCampanhaJob($campanha->id), 'handle']);

        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envio->fresh()->estado);
        $this->assertSame('enviada', $campanha->fresh()->estado);
    }

    /**
     * Regressão da causa raiz: com a fila síncrona (ou um worker muito rápido) o
     * job da Outbox corria ANTES de o envio ficar ligado à Outbox, a listener não
     * encontrava o envio e a campanha ficava em 'em_processamento'. O envio e a
     * Outbox gravam-se agora na mesma transação, e o transporte só corre no commit.
     */
    public function test_o_envio_fica_ligado_a_outbox_antes_de_o_transporte_correr(): void
    {
        $admin = $this->registarAtelier('500000991');
        $empresaId = (int) $admin->empresa_id;
        $this->criarCliente($empresaId, 'Cliente ordem', '923456781');
        $campanha = Campanha::create([
            'empresa_id' => $empresaId,
            'nome' => 'Campanha ordem',
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'rascunho',
            'criada_por_id' => $admin->id,
        ]);

        $canal = new class implements NotificationChannel
        {
            public ?bool $envioJaLigado = null;

            public function identifier(): string
            {
                return 'atelier.whatsapp';
            }

            public function send(NotificationOutbox $notification): NotificationSendResult
            {
                $this->envioJaLigado = CampanhaEnvio::query()
                    ->where('notification_outbox_id', $notification->id)
                    ->exists();

                return NotificationSendResult::accepted('teste', 'mensagem-1', 202);
            }
        };
        app(NotificationChannelRegistry::class)->register($canal);

        // Fila síncrona (a dos testes): o job da Outbox corre no commit.
        app()->call([new DispararCampanhaJob($campanha->id), 'handle']);

        $this->assertTrue($canal->envioJaLigado, 'O transporte correu antes de o envio apontar para a Outbox.');
        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, CampanhaEnvio::query()->where('campanha_id', $campanha->id)->firstOrFail()->estado);
        $this->assertSame('enviada', $campanha->fresh()->estado);
    }

    // ---------------------------------------------------------------------
    // Preparação
    // ---------------------------------------------------------------------

    /** @return array{0: Campanha, 1: CampanhaEnvio} */
    protected function campanhaComUmEnvio(string $nif, string $statusOutbox, ?string $erro = null): array
    {
        $admin = $this->registarAtelier($nif);
        $empresaId = (int) $admin->empresa_id;

        $campanha = $this->campanhaEmProcessamento($admin);
        $cliente = $this->criarCliente($empresaId, 'Cliente '.$nif, '923456781');
        $envio = $this->envio($campanha, $cliente, CampanhaEnvio::ESTADO_PENDENTE, $this->outbox($empresaId, $statusOutbox, $erro));

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

    protected function criarCliente(int $empresaId, string $nome, string $telefone): Cliente
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

    protected function campanhaEmProcessamento(User $admin): Campanha
    {
        return Campanha::create([
            'empresa_id' => (int) $admin->empresa_id,
            'nome' => 'Campanha em processamento',
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'em_processamento',
            'criada_por_id' => $admin->id,
        ]);
    }

    protected function outbox(int $empresaId, string $status, ?string $erro = null): NotificationOutbox
    {
        return NotificationOutbox::withoutGlobalScopes()->create([
            'empresa_id' => $empresaId,
            'idempotency_key' => 'teste-'.Str::uuid(),
            'channel' => 'atelier.whatsapp',
            'recipient' => '244923456781',
            'payload' => ['mensagem' => 'Mensagem de teste'],
            'status' => $status,
            'max_attempts' => 5,
            'available_at' => now(),
            'last_error' => $erro,
        ]);
    }

    protected function envio(Campanha $campanha, Cliente $cliente, string $estado, ?NotificationOutbox $outbox = null): CampanhaEnvio
    {
        return CampanhaEnvio::create([
            'campanha_id' => $campanha->id,
            'cliente_id' => $cliente->id,
            'canal' => $campanha->canal,
            'estado' => $estado,
            'tentativa' => 1,
            'notification_outbox_id' => $outbox?->id,
        ]);
    }

    /** Recua o updated_at (o Eloquent sobrepõe-no, por isso vai pelo query builder). */
    protected function envelhecer(CampanhaEnvio $envio, int $minutos): void
    {
        DB::table('atelier_campanha_envios')
            ->where('id', $envio->id)
            ->update(['updated_at' => now()->subMinutes($minutos)]);
    }
}
