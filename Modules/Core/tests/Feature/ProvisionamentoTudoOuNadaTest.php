<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantProvisionerRegistry;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * O provisionamento de uma empresa é tudo ou nada: se um módulo falha, nada do
 * que os outros criaram para essa empresa fica para trás, e a causa fica visível.
 */
class ProvisionamentoTudoOuNadaTest extends TestCase
{
    use RefreshDatabase;

    private static int $nif = 610000000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** @param  array<int, string>  $servicos */
    protected function empresa(array $servicos): Empresa
    {
        $nif = (string) self::$nif++;

        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos($servicos);

        return $empresa;
    }

    protected function administrador(Empresa $empresa): User
    {
        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Administrador '.$empresa->nif,
            'email' => 'admin'.$empresa->nif.'@teste.test',
            'password' => 'Senha-Forte-123!',
            'ativo' => true,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
        $utilizador->assignRole(Role::firstOrCreate([
            'name' => 'Administrador',
            'guard_name' => 'web',
            'empresa_id' => $empresa->id,
        ]));

        return $utilizador;
    }

    /**
     * Registry de teste. 'faturacao' cria um papel (efeito observável) e corre
     * primeiro; 'estudio' falha para as empresas indicadas.
     *
     * @param  array<int, int>  $empresasQueFalham
     */
    protected function registryComFalha(array $empresasQueFalham): void
    {
        $registry = new TenantProvisionerRegistry;

        $registry->register('faturacao', function (int $empresaId): void {
            Role::firstOrCreate([
                'name' => 'Papel de teste',
                'guard_name' => 'web',
                'empresa_id' => $empresaId,
            ]);
        });

        $registry->register('estudio', function (int $empresaId) use ($empresasQueFalham): void {
            if (in_array($empresaId, $empresasQueFalham, true)) {
                throw new RuntimeException('provisionador avariado');
            }
        });

        $this->app->instance(TenantProvisionerRegistry::class, $registry);
    }

    public function test_falha_a_provisionar_nao_altera_os_servicos_e_mostra_erro(): void
    {
        $empresa = $this->empresa(['faturacao']);
        $admin = $this->administrador($empresa);
        $this->registryComFalha([$empresa->id]);

        $this->actingAs($admin)
            ->put(route('core.servicos.atualizar'), ['servicos' => ['faturacao', 'estudio']])
            ->assertRedirect()
            ->assertSessionHas('erro');

        $this->assertFalse($empresa->fresh()->temServico('estudio'));
        $this->assertDatabaseMissing('empresa_servicos', ['empresa_id' => $empresa->id, 'servico' => 'estudio']);
        $this->assertDatabaseMissing('roles', ['name' => 'Papel de teste', 'empresa_id' => $empresa->id]);
    }

    public function test_sem_falhas_os_servicos_sao_guardados_e_provisionados(): void
    {
        $empresa = $this->empresa(['faturacao']);
        $admin = $this->administrador($empresa);
        $this->registryComFalha([]);

        $this->actingAs($admin)
            ->put(route('core.servicos.atualizar'), ['servicos' => ['faturacao', 'estudio']])
            ->assertRedirect(route('core.servicos.index'))
            ->assertSessionHas('sucesso');

        $this->assertTrue($empresa->fresh()->temServico('estudio'));
        $this->assertDatabaseHas('roles', ['name' => 'Papel de teste', 'empresa_id' => $empresa->id]);
    }

    public function test_comando_desfaz_o_que_ja_provisionou_na_empresa_que_falha(): void
    {
        $empresa = $this->empresa(['estudio']); // traz a Faturação como dependência
        $this->registryComFalha([$empresa->id]);

        $this->artisan('core:provisionar-servicos')->assertExitCode(1);

        $this->assertDatabaseMissing('roles', ['name' => 'Papel de teste', 'empresa_id' => $empresa->id]);
    }

    public function test_comando_continua_nas_outras_empresas_e_mostra_a_causa(): void
    {
        $avariada = $this->empresa(['estudio']);
        $saudavel = $this->empresa(['estudio']);
        $this->registryComFalha([$avariada->id]);

        $this->artisan('core:provisionar-servicos')
            ->expectsOutputToContain('provisionador avariado')
            ->assertExitCode(1);

        $this->assertDatabaseHas('roles', ['name' => 'Papel de teste', 'empresa_id' => $saudavel->id]);
        $this->assertDatabaseMissing('roles', ['name' => 'Papel de teste', 'empresa_id' => $avariada->id]);
    }

    public function test_comando_sem_falhas_termina_com_sucesso(): void
    {
        $empresa = $this->empresa(['estudio']);
        $this->registryComFalha([]);

        $this->artisan('core:provisionar-servicos')->assertExitCode(0);

        $this->assertDatabaseHas('roles', ['name' => 'Papel de teste', 'empresa_id' => $empresa->id]);
    }
}
