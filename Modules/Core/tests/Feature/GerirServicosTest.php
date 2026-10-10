<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GerirServicosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** @return array{0: Empresa, 1: User} */
    protected function empresaComUtilizador(string $nif, array $servicos, ?string $papel = 'Administrador'): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos($servicos);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador '.$nif,
            'email' => 'u'.$nif.'@teste.test',
            'password' => 'Senha-Forte-123!',
            'ativo' => true,
        ]);

        if ($papel) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
            $utilizador->assignRole(Role::firstOrCreate([
                'name' => $papel,
                'guard_name' => 'web',
                'empresa_id' => $empresa->id,
            ]));
        }

        return [$empresa, $utilizador];
    }

    public function test_administrador_ve_a_pagina_de_servicos(): void
    {
        [, $admin] = $this->empresaComUtilizador('600000001', ['faturacao']);

        $this->actingAs($admin)
            ->get(route('core.servicos.index'))
            ->assertOk()
            ->assertSee('Os meus serviços')
            ->assertSee('Atelier de Costura');
    }

    public function test_administrador_acrescenta_um_servico(): void
    {
        [$empresa, $admin] = $this->empresaComUtilizador('600000002', ['faturacao']);

        $this->actingAs($admin)
            ->put(route('core.servicos.atualizar'), ['servicos' => ['faturacao', 'atelier']])
            ->assertRedirect(route('core.servicos.index'))
            ->assertSessionHas('sucesso');

        $this->assertTrue($empresa->fresh()->temServico('atelier'));
    }

    public function test_remover_um_servico_desativa_sem_apagar_os_dados(): void
    {
        [$empresa, $admin] = $this->empresaComUtilizador('600000003', ['estudio']);

        $this->actingAs($admin)
            ->put(route('core.servicos.atualizar'), ['servicos' => ['faturacao']])
            ->assertRedirect(route('core.servicos.index'));

        $this->assertFalse($empresa->fresh()->temServico('estudio'));
        $this->assertDatabaseHas('empresa_servicos', [
            'empresa_id' => $empresa->id,
            'servico' => 'estudio',
            'ativo' => false,
        ]);
    }

    public function test_voltar_a_aderir_reativa_o_servico_sem_duplicar(): void
    {
        [$empresa] = $this->empresaComUtilizador('600000004', ['estudio']);

        $empresa->definirServicos(['faturacao']);
        $empresa->definirServicos(['estudio']);

        $this->assertTrue($empresa->fresh()->temServico('estudio'));
        $this->assertSame(1, $empresa->servicos()->where('servico', 'estudio')->count());
    }

    public function test_a_faturacao_nunca_e_removida(): void
    {
        [$empresa, $admin] = $this->empresaComUtilizador('600000005', ['atelier']);

        $this->actingAs($admin)
            ->put(route('core.servicos.atualizar'), ['servicos' => ['atelier']]);

        $this->assertTrue($empresa->fresh()->temServico('faturacao'));
    }

    public function test_nao_se_pode_ficar_sem_servicos(): void
    {
        [$empresa] = $this->empresaComUtilizador('600000006', ['faturacao']);

        $this->expectException(InvalidArgumentException::class);

        $empresa->definirServicos([]);
    }

    public function test_pedido_sem_servicos_e_rejeitado(): void
    {
        [$empresa, $admin] = $this->empresaComUtilizador('600000007', ['atelier']);

        $this->actingAs($admin)
            ->put(route('core.servicos.atualizar'), [])
            ->assertSessionHasErrors('servicos');

        $this->assertTrue($empresa->fresh()->temServico('atelier'));
    }

    public function test_utilizador_sem_papel_de_administrador_nao_acede(): void
    {
        [$empresa, $normal] = $this->empresaComUtilizador('600000008', ['faturacao'], 'Utilizador');

        $this->actingAs($normal)->get(route('core.servicos.index'))->assertForbidden();

        $this->actingAs($normal)
            ->put(route('core.servicos.atualizar'), ['servicos' => ['faturacao', 'estudio']])
            ->assertForbidden();

        $this->assertFalse($empresa->fresh()->temServico('estudio'));
    }

    public function test_alterar_servicos_nao_afeta_outra_empresa(): void
    {
        [, $adminA] = $this->empresaComUtilizador('600000009', ['faturacao']);
        [$empresaB] = $this->empresaComUtilizador('600000010', ['atelier']);

        $this->actingAs($adminA)
            ->put(route('core.servicos.atualizar'), ['servicos' => ['faturacao', 'estudio']]);

        $this->assertTrue($empresaB->fresh()->temServico('atelier'));
        $this->assertFalse($empresaB->fresh()->temServico('estudio'));
    }
}
