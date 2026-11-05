<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\Atelier\Http\Controllers\Admin\CampanhaController;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Models\CampanhaReenvio;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Services\PortalLinkService;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use ReflectionProperty;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Reenvio manual de campanhas: dois pedidos simultâneos, jobs antigos e
 * histórico das tentativas.
 */
class ReenvioCampanhaConcorrenciaTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected User $admin;

    protected Campanha $campanha;

    protected CampanhaEnvio $envio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::create(['nome_comercial' => 'Atelier Reenvio', 'nif' => '400000501']);
        $this->empresa->aderirServicos(['atelier']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->admin = User::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Admin Reenvio',
            'email' => 'admin.reenvio@example.test',
            'password' => bcrypt('segredo-123'),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->empresa->id);
        Role::create(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $this->empresa->id]);
        $this->admin->assignRole('Administrador');

        $cliente = Cliente::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Cliente Reenvio',
            'telefone' => '923456789',
        ]);
        ClienteCrm::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $cliente->id,
            'aceita_marketing' => true,
            'consentimento_em' => now()->subDay(),
        ]);

        $this->campanha = Campanha::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Campanha de reenvio',
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'concluida_com_falhas',
            'enviada_em' => now()->subHour(),
        ]);
        $this->envio = CampanhaEnvio::create([
            'campanha_id' => $this->campanha->id,
            'cliente_id' => $cliente->id,
            'canal' => 'whatsapp',
            'estado' => CampanhaEnvio::ESTADO_FALHOU,
            'erro' => 'Falha anterior',
        ]);
    }

    protected function pedirReenvio(): void
    {
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $this->admin);

        app(CampanhaController::class)->enviarAgora($request, $this->campanha->id);
    }

    protected function executar(?string $token): void
    {
        (new DispararCampanhaJob($this->campanha->id, $token))->handle(
            app(TenantManager::class),
            app(CrmService::class),
            app(NotificadorClienteService::class),
            app(PortalLinkService::class),
        );
    }

    protected function tokenDoJob(DispararCampanhaJob $job): ?string
    {
        $propriedade = new ReflectionProperty($job, 'reenvioToken');

        return $propriedade->getValue($job);
    }

    /** O token do pedido aberto, tal como o job despachado o recebeu. */
    protected function tokenDespachado(): string
    {
        $token = null;
        Queue::assertPushed(DispararCampanhaJob::class, function (DispararCampanhaJob $job) use (&$token): bool {
            $token = $this->tokenDoJob($job);

            return true;
        });

        return (string) $token;
    }

    /** Simula o fornecedor a recusar definitivamente a notificação criada pelo reenvio. */
    protected function falharOutboxDoEnvio(): void
    {
        $envio = $this->envio->fresh();
        NotificationOutbox::withoutGlobalScopes()
            ->whereKey($envio->notification_outbox_id)
            ->update(['status' => NotificationOutbox::STATUS_DEAD_LETTER, 'last_error' => 'Recusada']);
    }

    public function test_dois_pedidos_simultaneos_aceitam_apenas_o_primeiro(): void
    {
        Queue::fake();

        $this->pedirReenvio();

        try {
            $this->pedirReenvio();
            $this->fail('O segundo pedido tinha de ser recusado enquanto o primeiro está aberto.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        Queue::assertPushed(DispararCampanhaJob::class, 1);
        $this->assertSame(1, CampanhaReenvio::query()->where('campanha_id', $this->campanha->id)->count());
        $this->assertTrue(CampanhaReenvio::query()->firstOrFail()->aberto);
    }

    public function test_a_base_de_dados_impede_dois_pedidos_abertos_na_mesma_campanha(): void
    {
        $dados = [
            'empresa_id' => $this->empresa->id,
            'campanha_id' => $this->campanha->id,
            'estado' => CampanhaReenvio::ACEITE,
            'aberto' => true,
            'solicitado_em' => now(),
        ];

        CampanhaReenvio::create($dados + ['token' => '11111111-1111-4111-8111-111111111111']);

        $this->expectException(UniqueConstraintViolationException::class);

        CampanhaReenvio::create($dados + ['token' => '22222222-2222-4222-8222-222222222222']);
    }

    public function test_pedido_aceite_reenvia_uma_vez_e_fica_no_historico(): void
    {
        Queue::fake();
        $this->pedirReenvio();
        $token = $this->tokenDespachado();

        $this->executar($token);

        // Sem tentativa anterior na Outbox, o reenvio usa a tentativa 1.
        $envio = $this->envio->fresh();
        $this->assertSame(1, (int) $envio->tentativa);
        $this->assertNotNull($envio->notification_outbox_id);
        $this->assertContains($envio->estado, [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE]);

        $pedido = CampanhaReenvio::query()->where('token', $token)->firstOrFail();
        $this->assertSame(CampanhaReenvio::CONCLUIDO, $pedido->estado);
        $this->assertNull($pedido->aberto);
        $this->assertNotNull($pedido->iniciado_em);
        $this->assertNotNull($pedido->concluido_em);
        $this->assertSame($this->admin->id, $pedido->solicitado_por_id);
    }

    public function test_job_antigo_ou_duplicado_nunca_faz_um_segundo_reenvio(): void
    {
        Queue::fake();
        $this->pedirReenvio();
        $token = $this->tokenDespachado();

        $this->executar($token);
        $this->falharOutboxDoEnvio();

        // A campanha volta a ficar concluída com falhas quando reconciliada.
        $this->executar(null);
        $this->assertSame('concluida_com_falhas', $this->campanha->fresh()->estado);
        $outboxDepoisDoPrimeiro = $this->envio->fresh()->notification_outbox_id;
        $tentativasDepoisDoPrimeiro = (int) $this->envio->fresh()->tentativa;

        // Mesmo token, executado outra vez (job duplicado/antigo): nada acontece.
        $this->executar($token);

        $envio = $this->envio->fresh();
        $this->assertSame($tentativasDepoisDoPrimeiro, (int) $envio->tentativa);
        $this->assertSame($outboxDepoisDoPrimeiro, $envio->notification_outbox_id);
        $this->assertSame(1, NotificationOutbox::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count());
    }

    public function test_job_sem_token_ou_com_token_inexistente_nao_reenvia_campanha_concluida(): void
    {
        $this->executar(null);
        $this->executar('99999999-9999-4999-8999-999999999999');

        $envio = $this->envio->fresh();
        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $envio->estado);
        $this->assertSame(1, (int) $envio->tentativa);
        $this->assertNull($envio->notification_outbox_id);
        $this->assertSame(0, NotificationOutbox::withoutGlobalScopes()->count());
    }

    public function test_novo_pedido_depois_de_concluir_e_aceite_e_o_historico_acumula(): void
    {
        Queue::fake();
        $this->pedirReenvio();
        $this->executar($this->tokenDespachado());
        $this->falharOutboxDoEnvio();
        $this->executar(null);

        $this->pedirReenvio();

        $this->assertSame(2, CampanhaReenvio::query()->where('campanha_id', $this->campanha->id)->count());
        $this->assertSame(1, CampanhaReenvio::query()->where('aberto', true)->count());

        // O segundo pedido reenvia com uma nova chave idempotente (tentativa 2).
        $this->executar($this->tokenDespachado());

        $this->assertSame(2, (int) $this->envio->fresh()->tentativa);
        $this->assertSame(2, NotificationOutbox::withoutGlobalScopes()->where('empresa_id', $this->empresa->id)->count());
        $this->assertSame(0, CampanhaReenvio::query()->where('aberto', true)->count());
    }

    public function test_pedido_aberto_e_abandonado_expira_e_deixa_pedir_de_novo(): void
    {
        Queue::fake();
        $this->pedirReenvio();

        CampanhaReenvio::query()->update([
            'solicitado_em' => now()->subMinutes(CampanhaReenvio::VALIDADE_MINUTOS + 5),
        ]);

        $this->pedirReenvio();

        $this->assertSame(1, CampanhaReenvio::query()->where('estado', CampanhaReenvio::EXPIRADO)->count());
        $this->assertSame(1, CampanhaReenvio::query()->where('aberto', true)->count());
    }

    public function test_pedido_expirado_nao_pode_ser_executado_por_um_job_atrasado(): void
    {
        Queue::fake();
        $this->pedirReenvio();
        $token = $this->tokenDespachado();

        CampanhaReenvio::query()->update([
            'solicitado_em' => now()->subMinutes(CampanhaReenvio::VALIDADE_MINUTOS + 5),
        ]);
        $this->pedirReenvio(); // expira o antigo e abre um novo

        $this->executar($token);

        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $this->envio->fresh()->estado);
        $this->assertNull($this->envio->fresh()->notification_outbox_id);
    }

    public function test_falha_definitiva_do_job_fecha_o_pedido_para_nao_bloquear_novos_reenvios(): void
    {
        Queue::fake();
        $this->pedirReenvio();
        $token = $this->tokenDespachado();

        (new DispararCampanhaJob($this->campanha->id, $token))->failed(new RuntimeException('Falha de teste'));

        $pedido = CampanhaReenvio::query()->where('token', $token)->firstOrFail();
        $this->assertSame(CampanhaReenvio::FALHADO, $pedido->estado);
        $this->assertNull($pedido->aberto);
        $this->assertSame('Falha de teste', $pedido->motivo);

        $this->pedirReenvio();
        $this->assertSame(1, CampanhaReenvio::query()->where('aberto', true)->count());
    }

    public function test_campanha_ocupada_por_outro_job_fecha_o_pedido_como_ignorado(): void
    {
        Queue::fake();
        $this->pedirReenvio();
        $token = $this->tokenDespachado();

        $lock = Cache::lock('atelier:campanha:'.$this->campanha->id, 60);
        $this->assertTrue($lock->get());

        try {
            $this->executar($token);
        } finally {
            $lock->release();
        }

        $pedido = CampanhaReenvio::query()->where('token', $token)->firstOrFail();
        $this->assertSame(CampanhaReenvio::IGNORADO, $pedido->estado);
        $this->assertNull($pedido->aberto);
        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $this->envio->fresh()->estado);

        // O pedido não fica a bloquear: o utilizador pode voltar a pedir.
        $this->pedirReenvio();
        $this->assertSame(1, CampanhaReenvio::query()->where('aberto', true)->count());
    }

    public function test_campanha_sem_envios_falhados_nao_aceita_reenvio(): void
    {
        Queue::fake();
        $this->envio->update(['estado' => CampanhaEnvio::ESTADO_ENVIADO]);

        try {
            $this->pedirReenvio();
            $this->fail('Sem envios falhados não há nada para repetir.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, CampanhaReenvio::query()->count());
    }
}
