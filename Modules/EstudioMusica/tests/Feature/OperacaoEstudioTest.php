<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Jobs\EnviarLembretesSessaoJob;
use Modules\EstudioMusica\Jobs\EnviarMensagemClienteJob;
use Modules\EstudioMusica\Models\CupomDesconto;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\SalaEstudio;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\EstudioMusica\Models\SolicitacaoOrcamento;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
use Modules\EstudioMusica\Services\CampanhaMarketingService;
use Modules\EstudioMusica\Services\NotificacaoEstudioService;
use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
use Modules\Faturacao\Models\Cliente;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/../Support/ComEstudioAutenticado.php';

/** Salas, formulários, conversão de orçamentos e os jobs agendados — o dia a dia do estúdio. */
class OperacaoEstudioTest extends TestCase
{
    use ComEstudioAutenticado;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->registarEstudio('510000301');
        $this->noTenantDe($this->admin);
    }

    protected function cliente(array $extra = []): Cliente
    {
        $cliente = (new Cliente)->forceFill(array_merge([
            'empresa_id' => $this->admin->empresa_id,
            'nome' => 'Artista Operação',
        ], $extra));
        $cliente->save();

        return $cliente;
    }

    protected function canal(string $interface, \Closure $enviar): object
    {
        return new class($enviar) implements CanalSmsInterface, CanalWhatsAppInterface
        {
            public function __construct(protected \Closure $enviar) {}

            public function identificador(): string
            {
                return 'falso';
            }

            public function enviar(string $destino, string $mensagem): bool
            {
                return ($this->enviar)($destino, $mensagem);
            }
        };
    }

    // ---------------------------------------------------------------- salas

    public function test_preco_por_hora_acima_do_que_a_coluna_aguenta_e_recusado_com_mensagem(): void
    {
        $this->actingAs($this->admin)
            ->post(route('estudiomusica.salas.store'), ['nome' => 'Sala Cara', 'preco_hora' => 100000000])
            ->assertSessionHasErrors('preco_hora');

        $this->noTenantDe($this->admin);
        $this->assertSame(0, SalaEstudio::query()->count());
    }

    public function test_sala_edita_se_e_desativa_se_pela_interface(): void
    {
        $sala = SalaEstudio::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Sala A', 'preco_hora' => 5000]);

        $this->actingAs($this->admin)->get(route('estudiomusica.salas.index'))
            ->assertOk()->assertSee('Editar')->assertSee('name="ativa"', false);

        // Um formulário que não envia 'ativa' não pode desativar a sala por engano.
        $this->actingAs($this->admin)
            ->put(route('estudiomusica.salas.update', $sala->id), ['nome' => 'Sala A2', 'preco_hora' => 6000])
            ->assertSessionHas('sucesso');

        $this->noTenantDe($this->admin);
        $this->assertTrue($sala->fresh()->ativa);
        $this->assertSame('6000.00', $sala->fresh()->preco_hora);

        $this->actingAs($this->admin)
            ->put(route('estudiomusica.salas.update', $sala->id), ['nome' => 'Sala A2', 'preco_hora' => 6000, 'ativa' => 0])
            ->assertSessionHas('sucesso');

        $this->noTenantDe($this->admin);
        $this->assertFalse($sala->fresh()->ativa);
    }

    // ------------------------------------------------ erros de validação visíveis

    public function test_erros_de_validacao_aparecem_no_ecra_do_staff(): void
    {
        $this->actingAs($this->admin)
            ->from(route('estudiomusica.salas.index'))
            ->followingRedirects()
            ->post(route('estudiomusica.salas.store'), [])
            ->assertOk()
            ->assertSee(trans('validation.required', ['attribute' => 'nome']));
    }

    public function test_comentario_invalido_volta_ao_portal_com_o_erro_visivel(): void
    {
        $token = 'token-erros-portal-0123456789abcdef0123456789abcdef';
        $cliente = $this->cliente(['token_portal' => hash('sha256', $token), 'token_portal_expira_em' => now()->addDay()]);
        $projeto = ProjetoMusical::create(['empresa_id' => $cliente->empresa_id, 'cliente_id' => $cliente->id, 'nome' => 'P', 'tipo_cobranca' => 'hora']);
        $faixa = $projeto->faixas()->create(['empresa_id' => $cliente->empresa_id, 'nome' => 'F', 'ordem' => 1]);
        $versao = $faixa->versoes()->create(['empresa_id' => $cliente->empresa_id, 'rotulo' => 'v1', 'caminho_ficheiro' => 'x.mp3', 'estado' => 'pendente_aprovacao', 'enviada_em' => now()]);

        $this->get(route('estudiomusica.portal.mostrar', ['token' => $token]))->assertOk();
        $this->get(route('estudiomusica.portal.versoes.reproduzir', ['token' => $token, 'versao' => $versao->id]));

        $this->post(route('estudiomusica.portal.versoes.comentar', ['token' => $token, 'versao' => $versao->id]), ['comentario' => ''])
            ->assertRedirect(route('estudiomusica.portal.mostrar', ['token' => $token]))
            ->assertSessionHasErrors(['tempo_segundos', 'comentario']);
    }

    // ------------------------------------------------------------- faixas

    public function test_nao_se_acrescentam_faixas_a_projetos_concluidos_ou_cancelados(): void
    {
        $cliente = $this->cliente();
        $normal = ProjetoMusical::create(['empresa_id' => $cliente->empresa_id, 'cliente_id' => $cliente->id, 'nome' => 'Normal', 'tipo_cobranca' => 'hora']);
        $cancelado = ProjetoMusical::create(['empresa_id' => $cliente->empresa_id, 'cliente_id' => $cliente->id, 'nome' => 'Cancelado', 'tipo_cobranca' => 'hora', 'estado' => 'cancelado']);

        $this->actingAs($this->admin)->post(route('estudiomusica.projetos.faixas.store', $normal->id), ['nome' => 'Faixa 1'])
            ->assertSessionHas('sucesso');
        $this->actingAs($this->admin)->post(route('estudiomusica.projetos.faixas.store', $cancelado->id), ['nome' => 'Faixa 1'])
            ->assertSessionHas('erro');

        $this->noTenantDe($this->admin);
        $this->assertSame(1, $normal->faixas()->count());
        $this->assertSame(0, $cancelado->faixas()->count());
    }

    // ------------------------------------------------- orçamento → cliente

    protected function pedido(array $extra): SolicitacaoOrcamento
    {
        return SolicitacaoOrcamento::create(array_merge([
            'empresa_id' => $this->admin->empresa_id,
            'nome' => 'Lead',
            'tipo_servico' => 'mixagem',
            'aceita_marketing' => true,
            'consentimento_dados_em' => now(),
        ], $extra));
    }

    public function test_conversao_por_email_herda_o_consentimento_mas_por_telefone_nao(): void
    {
        $existente = $this->cliente(['nome' => 'Existente', 'email' => 'ele@example.test', 'telefone' => '923111111']);
        $outro = $this->cliente(['nome' => 'Outro', 'email' => 'outro@example.test', 'telefone' => '923222222']);

        $porEmail = $this->pedido(['email' => 'ele@example.test', 'telefone' => '900000000']);
        $porTelefone = $this->pedido(['email' => 'novo@example.test', 'telefone' => '923222222']);

        $this->actingAs($this->admin)->post(route('estudiomusica.solicitacoes.converter', $porEmail->id))->assertSessionHas('sucesso');
        $this->actingAs($this->admin)->post(route('estudiomusica.solicitacoes.converter', $porTelefone->id))->assertSessionHas('sucesso');

        $this->noTenantDe($this->admin);
        $this->assertTrue((bool) $existente->fresh()->aceita_marketing, 'Mesmo e-mail = mesma pessoa: o consentimento passa.');
        $this->assertFalse((bool) $outro->fresh()->aceita_marketing, 'Telefone igual pode ser de outra pessoa: não herda consentimento.');
        $this->assertSame(2, Cliente::query()->count(), 'Nenhum cliente novo: os dois pedidos acharam um cliente existente.');
    }

    public function test_converter_duas_vezes_o_mesmo_pedido_so_cria_um_cliente(): void
    {
        $pedido = $this->pedido(['email' => 'lead@example.test']);

        $this->actingAs($this->admin)->post(route('estudiomusica.solicitacoes.converter', $pedido->id))->assertSessionHas('sucesso');
        $this->actingAs($this->admin)->post(route('estudiomusica.solicitacoes.converter', $pedido->id))->assertSessionHas('erro');

        $this->noTenantDe($this->admin);
        $this->assertSame(1, Cliente::query()->count());
    }

    // ------------------------------------------------------------- jobs

    protected function empresaSemServico(string $nif): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Ex-cliente '.$nif, 'nif' => $nif]);
    }

    protected function sessaoDaqui24Horas(int $empresaId): SessaoEstudio
    {
        // Fora do tenant: com o tenant do admin ativo, o BelongsToTenant sobrepõe o empresa_id dado.
        return app(TenantManager::class)->semTenant(fn () => $this->criarSessaoDaqui24Horas($empresaId));
    }

    protected function criarSessaoDaqui24Horas(int $empresaId): SessaoEstudio
    {
        $sala = SalaEstudio::withoutGlobalScopes()->create(['empresa_id' => $empresaId, 'nome' => 'Sala', 'preco_hora' => 1000]);
        $cliente = (new Cliente)->forceFill(['empresa_id' => $empresaId, 'nome' => 'Cliente '.$empresaId, 'email' => "c{$empresaId}@example.test"]);
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
    }

    public function test_lembretes_so_vao_para_estudios_com_o_servico_ativo(): void
    {
        Queue::fake();

        $deles = $this->sessaoDaqui24Horas((int) $this->admin->empresa_id);
        $exCliente = $this->empresaSemServico('510000302');
        $dele = $this->sessaoDaqui24Horas($exCliente->id);

        (new EnviarLembretesSessaoJob)->handle(app(TenantManager::class), app(NotificacaoEstudioService::class));

        Queue::assertPushed(EnviarMensagemClienteJob::class, 1);

        app(TenantManager::class)->semTenant(function () use ($deles, $dele): void {
            $this->assertNotNull($deles->fresh()->lembrete_24h_enviado_em);
            $this->assertNull($dele->fresh()->lembrete_24h_enviado_em);
        });
    }

    public function test_aniversario_so_para_estudios_ativos_e_com_a_percentagem_legivel(): void
    {
        Queue::fake();
        config(['estudiomusica.percentual_desconto_aniversario' => 15]);

        $nascimento = now()->subYears(30)->toDateString();
        $meu = $this->cliente(['data_nascimento' => $nascimento, 'aceita_marketing' => true]);

        $exCliente = $this->empresaSemServico('510000303');
        app(TenantManager::class)->semTenant(fn () => (new Cliente)
            ->forceFill(['empresa_id' => $exCliente->id, 'nome' => 'Fantasma', 'data_nascimento' => $nascimento, 'aceita_marketing' => true])
            ->save());

        $enviados = app(CampanhaMarketingService::class)->enviarAniversarios(app(TenantManager::class));

        $this->assertSame(1, $enviados);
        Queue::assertPushed(EnviarMensagemClienteJob::class, function (EnviarMensagemClienteJob $job) {
            return str_contains($job->mensagem, 'Ganhaste 15% de desconto') && ! str_contains($job->mensagem, '15.00');
        });

        $this->noTenantDe($this->admin);
        $this->assertSame(1, CupomDesconto::query()->count());
        $this->assertSame($meu->id, CupomDesconto::query()->sole()->cliente_id);
    }

    public function test_excecao_do_whatsapp_nao_impede_o_envio_do_email(): void
    {
        config(['mail.default' => 'array', 'estudiomusica.exigir_autorizacao_apd' => false]);

        $whatsapp = $this->canal(CanalWhatsAppInterface::class, fn () => throw new RuntimeException('fornecedor em baixo'));
        $sms = $this->canal(CanalSmsInterface::class, fn () => false);

        (new EnviarMensagemClienteJob('923000000', 'artista@example.test', 'Assunto', 'Mensagem de teste'))
            ->handle($whatsapp, $sms);

        // (o registo da empresa, no setUp, também pode ter enviado e-mails: filtra-se pelo texto.)
        $enviadas = app('mailer')->getSymfonyTransport()->messages()
            ->filter(fn ($mensagem) => str_contains($mensagem->toString(), 'Mensagem de teste'));

        $this->assertCount(1, $enviadas);
    }

    public function test_sem_nenhum_canal_a_entregar_o_job_falha_para_a_queue_repetir(): void
    {
        config(['estudiomusica.exigir_autorizacao_apd' => false]);

        $whatsapp = $this->canal(CanalWhatsAppInterface::class, fn () => throw new RuntimeException('fornecedor em baixo'));
        $sms = $this->canal(CanalSmsInterface::class, fn () => false);

        $this->expectException(RuntimeException::class);

        (new EnviarMensagemClienteJob('923000000', null, 'Assunto', 'Mensagem'))->handle($whatsapp, $sms);
    }

    public function test_o_sms_entrega_quando_o_whatsapp_falha(): void
    {
        config(['estudiomusica.exigir_autorizacao_apd' => false]);

        $recebidos = [];
        $whatsapp = $this->canal(CanalWhatsAppInterface::class, fn () => throw new RuntimeException('fornecedor em baixo'));
        $sms = $this->canal(CanalSmsInterface::class, function (string $destino, string $mensagem) use (&$recebidos): bool {
            $recebidos[] = [$destino, $mensagem];

            return true;
        });

        (new EnviarMensagemClienteJob('923000000', null, 'Assunto', 'Olá'))->handle($whatsapp, $sms);

        $this->assertSame([['923000000', 'Olá']], $recebidos);
    }

    public function test_a_tabela_de_sessoes_tem_o_indice_dos_lembretes(): void
    {
        $this->assertTrue(Schema::hasIndex('sessoes_estudio', 'sessoes_estudio_estado_inicio_idx'));
    }
}
