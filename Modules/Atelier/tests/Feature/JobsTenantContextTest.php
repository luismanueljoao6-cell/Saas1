<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Jobs\EnviarCampanhasAgendadasJob;
use Modules\Atelier\Jobs\LembretesProvaJob;
use Modules\Atelier\Jobs\VerificarAniversariosJob;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\Prova;
use Modules\Atelier\Notifications\ClienteAtelierNotification;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Services\PortalLinkService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use ReflectionProperty;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class JobsTenantContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
        Queue::fake();
    }

    public function test_lembretes_processam_cada_prova_no_tenant_proprietario_e_ignoram_relacao_cruzada(): void
    {
        config(['atelier.lembrete_prova_horas_antes' => 24]);

        $empresaA = $this->novaEmpresa('Atelier A', '400000201');
        $empresaB = $this->novaEmpresa('Atelier B', '400000202');
        $dadosA = $this->criarProva($empresaA, 'Cliente A');
        $dadosB = $this->criarProva($empresaB, 'Cliente B');

        // A FK simples aceita este vínculo inconsistente. O scope de Pedido
        // deve ocultá-lo quando a prova da empresa A for recarregada.
        $tenant = app(TenantManager::class);
        $provaCruzada = $tenant->comEmpresa((int) $empresaA->id, function () use ($dadosB): Prova {
            return Prova::create([
                'pedido_id' => $dadosB['pedido']->id,
                'tipo' => 'primeira',
                'data_hora_agendada' => now()->addHour(),
                'estado' => 'agendada',
            ]);
        });

        $tenant->set(999991);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(999991);

        $empresasNotificadas = [];
        $notificador = Mockery::mock(NotificadorClienteService::class);
        $notificador->shouldReceive('notificar')
            ->twice()
            ->withArgs(function ($cliente, $assunto, $mensagem) use ($tenant, $registrar, &$empresasNotificadas): bool {
                $this->assertInstanceOf(Cliente::class, $cliente);
                $this->assertSame((int) $cliente->empresa_id, $tenant->id());
                $this->assertSame($tenant->id(), $registrar->getPermissionsTeamId());
                $this->assertFalse($tenant->emBypass());

                $empresasNotificadas[] = (int) $cliente->empresa_id;

                return true;
            })
            ->andReturn(true);

        (new LembretesProvaJob)->handle($tenant, $notificador);

        sort($empresasNotificadas);
        $esperadas = [(int) $empresaA->id, (int) $empresaB->id];
        sort($esperadas);

        $this->assertSame($esperadas, $empresasNotificadas);
        $this->assertSame(999991, $tenant->id());
        $this->assertSame(999991, $registrar->getPermissionsTeamId());
        $this->assertFalse($tenant->emBypass());

        $this->assertNotNull(DB::table('atelier_provas')->where('id', $dadosA['prova']->id)->value('lembrete_enviado_em'));
        $this->assertNotNull(DB::table('atelier_provas')->where('id', $dadosB['prova']->id)->value('lembrete_enviado_em'));
        $this->assertNull(DB::table('atelier_provas')->where('id', $provaCruzada->id)->value('lembrete_enviado_em'));
    }

    public function test_aniversarios_sao_processados_com_tenant_e_team_corretos(): void
    {
        $empresaA = $this->novaEmpresa('Atelier Aniversários A', '400000203');
        $empresaB = $this->novaEmpresa('Atelier Aniversários B', '400000204');
        $clienteA = $this->criarAniversariante($empresaA, 'Aniversariante A');
        $clienteB = $this->criarAniversariante($empresaB, 'Aniversariante B');

        $tenant = app(TenantManager::class);
        $tenant->set(999992);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(999992);

        $empresasNotificadas = [];
        $notificador = Mockery::mock(NotificadorClienteService::class);
        $notificador->shouldReceive('notificar')
            ->twice()
            ->withArgs(function ($cliente, $assunto, $mensagem) use ($tenant, $registrar, &$empresasNotificadas): bool {
                $this->assertInstanceOf(Cliente::class, $cliente);
                $this->assertSame((int) $cliente->empresa_id, $tenant->id());
                $this->assertSame($tenant->id(), $registrar->getPermissionsTeamId());
                $this->assertFalse($tenant->emBypass());

                $empresasNotificadas[] = (int) $cliente->empresa_id;

                return true;
            })
            ->andReturn(true);

        $portal = Mockery::mock(PortalLinkService::class);
        $portal->shouldReceive('gerarLinkCancelarMarketing')
            ->twice()
            ->andReturn('https://example.test/marketing/cancelar');

        (new VerificarAniversariosJob)->handle(
            $tenant,
            app(CrmService::class),
            $notificador,
            $portal,
        );

        sort($empresasNotificadas);
        $esperadas = [(int) $empresaA->id, (int) $empresaB->id];
        sort($esperadas);

        $this->assertSame($esperadas, $empresasNotificadas);
        $this->assertSame(999992, $tenant->id());
        $this->assertSame(999992, $registrar->getPermissionsTeamId());
        $this->assertFalse($tenant->emBypass());
        $this->assertSame((int) $empresaA->id, (int) $clienteA->empresa_id);
        $this->assertSame((int) $empresaB->id, (int) $clienteB->empresa_id);
    }

    public function test_agendador_descobre_apenas_ids_e_despacha_campanhas_de_varias_empresas(): void
    {
        $empresaA = $this->novaEmpresa('Atelier Campanhas A', '400000205');
        $empresaB = $this->novaEmpresa('Atelier Campanhas B', '400000206');
        $tenant = app(TenantManager::class);

        $campanhaA = $tenant->comEmpresa((int) $empresaA->id, fn (): Campanha => $this->criarCampanhaAgendada());
        $campanhaB = $tenant->comEmpresa((int) $empresaB->id, fn (): Campanha => $this->criarCampanhaAgendada());

        $tenant->set(999993);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(999993);

        (new EnviarCampanhasAgendadasJob)->handle();

        Queue::assertPushed(DispararCampanhaJob::class, 2);

        $idsDespachados = [];
        $propriedade = new ReflectionProperty(DispararCampanhaJob::class, 'campanhaId');
        $propriedade->setAccessible(true);

        foreach (Queue::pushed(DispararCampanhaJob::class) as $job) {
            $idsDespachados[] = (int) $propriedade->getValue($job);
        }

        sort($idsDespachados);
        $esperados = [(int) $campanhaA->id, (int) $campanhaB->id];
        sort($esperados);

        $this->assertSame($esperados, $idsDespachados);
        $this->assertSame(999993, $tenant->id());
        $this->assertSame(999993, $registrar->getPermissionsTeamId());
        $this->assertFalse($tenant->emBypass());
    }

    public function test_lembrete_sem_canal_aceite_nao_e_marcado_e_pode_ser_retentado(): void
    {
        $empresa = $this->novaEmpresa('Atelier Retry Prova', '400000207');
        $dados = $this->criarProva($empresa, 'Cliente Retry Prova');
        $tenant = app(TenantManager::class);

        $notificador = Mockery::mock(NotificadorClienteService::class);
        $notificador->shouldReceive('notificar')
            ->twice()
            ->andReturn(false, true);

        $job = new LembretesProvaJob;

        $job->handle($tenant, $notificador);

        $this->assertNull(DB::table('atelier_provas')->where('id', $dados['prova']->id)->value('lembrete_enviado_em'));

        $job->handle($tenant, $notificador);

        $this->assertNotNull(DB::table('atelier_provas')->where('id', $dados['prova']->id)->value('lembrete_enviado_em'));
    }

    public function test_aniversario_sem_canal_aceite_liberta_chave_para_nova_tentativa(): void
    {
        $empresa = $this->novaEmpresa('Atelier Retry Aniversário', '400000208');
        $this->criarAniversariante($empresa, 'Cliente Retry Aniversário');

        $tenant = app(TenantManager::class);
        $notificador = Mockery::mock(NotificadorClienteService::class);
        $notificador->shouldReceive('notificar')
            ->twice()
            ->andReturn(false, true);

        $portal = Mockery::mock(PortalLinkService::class);
        $portal->shouldReceive('gerarLinkCancelarMarketing')
            ->twice()
            ->andReturn('https://example.test/marketing/cancelar');

        $job = new VerificarAniversariosJob;
        $argumentos = [$tenant, app(CrmService::class), $notificador, $portal];

        $job->handle(...$argumentos);
        $job->handle(...$argumentos);
    }

    public function test_notificador_indica_se_algum_canal_aceitou_a_mensagem(): void
    {
        Notification::fake();
        config(['atelier.canais_notificacao' => ['mail']]);

        $empresa = $this->novaEmpresa('Atelier Notificador', '400000209');
        $tenant = app(TenantManager::class);

        [$semEmail, $comEmail] = $tenant->comEmpresa((int) $empresa->id, function (): array {
            return [
                Cliente::create(['nome' => 'Sem email']),
                Cliente::create(['nome' => 'Com email', 'email' => 'cliente@example.test']),
            ];
        });

        $notificador = app(NotificadorClienteService::class);

        $this->assertFalse($notificador->notificar($semEmail, 'Assunto', 'Mensagem'));
        $this->assertTrue($notificador->notificar($comEmail, 'Assunto', 'Mensagem'));

        Notification::assertSentOnDemand(
            ClienteAtelierNotification::class,
            fn ($notificacao, $canais, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'cliente@example.test'
        );
    }

    protected function novaEmpresa(string $nome, string $nif): Empresa
    {
        $empresa = Empresa::create([
            'nome_comercial' => $nome,
            'nif' => $nif,
        ]);

        $empresa->aderirServicos(['atelier']);

        return $empresa;
    }

    /**
     * @return array{cliente: Cliente, pedido: Pedido, prova: Prova}
     */
    protected function criarProva(Empresa $empresa, string $nomeCliente): array
    {
        return app(TenantManager::class)->comEmpresa((int) $empresa->id, function () use ($nomeCliente): array {
            $cliente = Cliente::create(['nome' => $nomeCliente, 'email' => strtolower(str_replace(' ', '.', $nomeCliente)).'@example.test']);

            $pedido = Pedido::create([
                'cliente_id' => $cliente->id,
                'tipo_servico' => 'confecao_medida',
                'descricao' => 'Pedido para teste de isolamento',
                'valor_orcamento' => 10000,
                'taxa_iva_aplicada' => 14,
                'status' => 'primeira_prova',
            ]);

            $prova = Prova::create([
                'pedido_id' => $pedido->id,
                'tipo' => 'primeira',
                'data_hora_agendada' => now()->addHour(),
                'estado' => 'agendada',
            ]);

            return compact('cliente', 'pedido', 'prova');
        });
    }

    protected function criarAniversariante(Empresa $empresa, string $nome): Cliente
    {
        return app(TenantManager::class)->comEmpresa((int) $empresa->id, function () use ($nome): Cliente {
            $cliente = Cliente::create([
                'nome' => $nome,
                'email' => strtolower(str_replace(' ', '.', $nome)).'@example.test',
            ]);

            ClienteCrm::create([
                'cliente_id' => $cliente->id,
                'data_nascimento' => now()->toDateString(),
                'aceita_marketing' => true,
                'consentimento_em' => now(),
                'consentimento_origem' => 'teste',
            ]);

            return $cliente;
        });
    }

    protected function criarCampanhaAgendada(): Campanha
    {
        return Campanha::create([
            'nome' => 'Campanha agendada',
            'canal' => 'mail',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'agendada',
            'agendada_para' => now()->subMinute(),
        ]);
    }
}
