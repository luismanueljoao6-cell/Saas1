<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** O formulário de nova fatura: isenções com motivo e erros de regra de negócio sem páginas 500. */
class FormularioFaturaIsencaoTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        config([
            'faturacao.chave_privada_path' => $caminho,
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
        ]);

        $empresa = Empresa::create(['nome_comercial' => 'Loja Formulário', 'nif' => '5001636863']);
        $empresa->aderirServicos([Servico::Faturacao]);
        $this->cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);

        $this->admin = User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Administrador',
            'email' => 'admin@formulario.ao',
            'password' => 'Senha-Forte-123!',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $empresa->id]));
    }

    /** @return array<string, mixed> */
    protected function pedido(array $linha): array
    {
        return [
            'cliente_id' => $this->cliente->id,
            'linhas' => [array_merge(['descricao' => 'Livro', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 0], $linha)],
        ];
    }

    public function test_guarda_uma_linha_isenta_com_motivo(): void
    {
        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido(['motivo_isencao' => 'M13']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fatura = Fatura::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('M13', $fatura->linhas()->first()->motivo_isencao);
        $this->assertSame('0.00', $fatura->valor_iva);
    }

    public function test_linha_isenta_sem_motivo_volta_ao_formulario_com_a_mensagem(): void
    {
        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido([]))
            ->assertStatus(302)
            ->assertSessionHasErrors(['fatura']);

        $this->assertStringContainsString('exige o motivo de isenção', session('errors')->first('fatura'));
        $this->assertSame(0, Fatura::withoutGlobalScopes()->count());
    }

    public function test_motivo_numa_linha_com_iva_e_recusado_com_mensagem(): void
    {
        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido(['taxa_iva' => 14, 'motivo_isencao' => 'M13']))
            ->assertSessionHasErrors(['fatura']);

        $this->assertStringContainsString('só as linhas isentas', session('errors')->first('fatura'));
    }

    public function test_motivo_fora_da_tabela_e_recusado_na_validacao(): void
    {
        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido(['motivo_isencao' => 'ZZZ']))
            ->assertSessionHasErrors(['linhas.0.motivo_isencao']);
    }

    public function test_fatura_normal_a_14_continua_a_funcionar_sem_motivo(): void
    {
        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido(['taxa_iva' => 14]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('114.00', Fatura::withoutGlobalScopes()->firstOrFail()->valor_total);
    }

    public function test_formulario_so_oferece_motivo_de_isencao_quando_a_taxa_zero_e_permitida(): void
    {
        $this->actingAs($this->admin)->get(route('faturacao.faturas.criar'))
            ->assertOk()
            ->assertSee('Motivo de isenção...')
            ->assertSee('M13');

        config(['faturacao.taxas_iva_permitidas' => [14.0]]);

        $this->actingAs($this->admin)->get(route('faturacao.faturas.criar'))
            ->assertOk()
            ->assertDontSee('Motivo de isenção...');
    }

    public function test_edicao_do_rascunho_traz_o_motivo_de_isencao_das_linhas(): void
    {
        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido(['motivo_isencao' => 'M13']))->assertRedirect();
        $fatura = Fatura::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->admin)->get(route('faturacao.faturas.editar', $fatura->id))
            ->assertOk()
            ->assertSee('\u0022motivo_isencao\u0022:\u0022M13\u0022', false); // @js() escapa as aspas como \u0022
    }

    public function test_atualizar_o_rascunho_guarda_o_novo_motivo_de_isencao(): void
    {
        $this->actingAs($this->admin)->post(route('faturacao.faturas.guardar'), $this->pedido(['motivo_isencao' => 'M13']))->assertRedirect();
        $fatura = Fatura::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->admin)->put(route('faturacao.faturas.atualizar', $fatura->id), $this->pedido(['motivo_isencao' => 'M10']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(['M10'], $fatura->linhas()->pluck('motivo_isencao')->all());

        // E sem motivo volta com a mensagem, sem alterar nada.
        $this->actingAs($this->admin)->put(route('faturacao.faturas.atualizar', $fatura->id), $this->pedido([]))
            ->assertSessionHasErrors(['fatura']);

        $this->assertSame(['M10'], $fatura->linhas()->pluck('motivo_isencao')->all());
    }
}
