<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Subscricoes\Database\Seeders\PlanosSeeder;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\Plano;
use Modules\Subscricoes\Services\Gateways\Contracts\GatewayPagamentoInterface;
use Modules\Subscricoes\Services\PagamentoService;
use Modules\Subscricoes\Services\SubscricaoService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PacotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Gateway falso: nenhuma chamada HTTP real nos testes.
        $this->app->singleton(GatewayPagamentoInterface::class, fn () => new class implements GatewayPagamentoInterface
        {
            private int $contador = 0;

            public function identificador(): string
            {
                return 'gateway-teste';
            }

            public function gerarReferencia(Pagamento $pagamento): array
            {
                $this->contador++;

                return ['referencia_externa' => 'REF-'.$this->contador, 'payload' => ['ok' => true]];
            }

            public function validarPedidoWebhook(Request $request): bool
            {
                return true;
            }

            public function interpretarNotificacao(Request $request): array
            {
                return ['referencia_externa' => $request->input('reference'), 'estado' => 'confirmado', 'payload' => []];
            }
        });
    }

    protected function criarEmpresa(string $nif, array $servicos): Empresa
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos($servicos);

        return $empresa->fresh();
    }

    protected function pagar(Empresa $empresa, Plano $plano): void
    {
        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);
        app(PagamentoService::class)->confirmar($pagamento);
    }

    public function test_seeder_cria_os_quatro_pacotes_e_desativa_os_antigos(): void
    {
        Plano::create(['nome' => 'Básico', 'slug' => 'basico', 'preco' => 9900]);

        $this->seed(PlanosSeeder::class);

        $ativos = Plano::ativos()->get()->keyBy('slug');

        $this->assertEqualsCanonicalizing(
            ['faturacao', 'faturacao-atelier', 'faturacao-estudio', 'completo'],
            $ativos->keys()->all()
        );
        $this->assertSame(['faturacao'], $ativos['faturacao']->servicos);
        $this->assertEqualsCanonicalizing(['faturacao', 'atelier', 'estudio'], $ativos['completo']->servicos);
        $this->assertFalse((bool) Plano::where('slug', 'basico')->value('ativo'));
    }

    public function test_repetir_o_seeder_nao_repoe_precos_alterados(): void
    {
        $this->seed(PlanosSeeder::class);
        Plano::where('slug', 'faturacao')->update(['preco' => 4000]);

        $this->seed(PlanosSeeder::class);

        $this->assertEquals(4000, (float) Plano::where('slug', 'faturacao')->value('preco'));
    }

    public function test_pagar_um_pacote_menor_desativa_os_servicos_que_ficam_de_fora(): void
    {
        $this->seed(PlanosSeeder::class);
        $empresa = $this->criarEmpresa('800000001', ['atelier']);

        $this->pagar($empresa, Plano::where('slug', 'faturacao')->firstOrFail());

        $empresa = $empresa->fresh();
        $this->assertTrue($empresa->temServico('faturacao'));
        $this->assertFalse($empresa->temServico('atelier'));
        $this->assertDatabaseHas('empresa_servicos', [
            'empresa_id' => $empresa->id,
            'servico' => 'atelier',
            'ativo' => false,
        ]);
    }

    public function test_pagar_o_pacote_completo_ativa_todos_os_servicos(): void
    {
        $this->seed(PlanosSeeder::class);
        $empresa = $this->criarEmpresa('800000002', ['faturacao']);

        $this->pagar($empresa, Plano::where('slug', 'completo')->firstOrFail());

        $empresa = $empresa->fresh();
        $this->assertTrue($empresa->temServico('faturacao'));
        $this->assertTrue($empresa->temServico('atelier'));
        $this->assertTrue($empresa->temServico('estudio'));
    }

    public function test_plano_sem_servicos_nao_altera_os_servicos_da_empresa(): void
    {
        $plano = Plano::create(['nome' => 'Antigo', 'slug' => 'antigo', 'preco' => 9900]);
        $empresa = $this->criarEmpresa('800000003', ['atelier']);

        $this->pagar($empresa, $plano);

        $this->assertTrue($empresa->fresh()->temServico('atelier'));
    }

    public function test_comando_altera_o_preco(): void
    {
        $this->seed(PlanosSeeder::class);

        $this->artisan('planos:preco', ['slug' => 'faturacao', 'preco' => '6500'])->assertExitCode(0);

        $this->assertEquals(6500, (float) Plano::where('slug', 'faturacao')->value('preco'));
    }

    public function test_comando_rejeita_preco_invalido_e_plano_inexistente(): void
    {
        $this->seed(PlanosSeeder::class);

        $this->artisan('planos:preco', ['slug' => 'faturacao', 'preco' => 'abc'])->assertExitCode(1);
        $this->artisan('planos:preco', ['slug' => 'faturacao', 'preco' => '-10'])->assertExitCode(1);
        $this->artisan('planos:preco', ['slug' => 'nao-existe', 'preco' => '100'])->assertExitCode(1);

        $this->assertEquals(5500, (float) Plano::where('slug', 'faturacao')->value('preco'));
    }

    public function test_alterar_o_preco_nao_muda_um_pagamento_ja_gerado(): void
    {
        $this->seed(PlanosSeeder::class);
        $empresa = $this->criarEmpresa('800000004', ['faturacao']);
        $plano = Plano::where('slug', 'faturacao')->firstOrFail();

        $pagamento = app(SubscricaoService::class)->iniciar($empresa, $plano);

        $this->artisan('planos:preco', ['slug' => 'faturacao', 'preco' => '7000'])->assertExitCode(0);

        $this->assertEquals(5500, (float) Pagamento::withoutGlobalScopes()->findOrFail($pagamento->id)->valor);
    }

    public function test_pagina_de_planos_mostra_os_servicos_de_cada_pacote(): void
    {
        $this->seed(PlanosSeeder::class);
        $empresa = $this->criarEmpresa('800000005', ['faturacao']);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Administrador',
            'email' => 'admin800000005@teste.test',
            'password' => 'Senha-Forte-123!',
            'ativo' => true,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
        $utilizador->assignRole(Role::firstOrCreate([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'empresa_id' => $empresa->id,
        ]));

        $this->actingAs($utilizador)
            ->get('/planos')
            ->assertOk()
            ->assertSee('Faturação + Atelier')
            ->assertSee('Atelier de Costura')
            ->assertSee('Estúdio de Música');
    }
}
