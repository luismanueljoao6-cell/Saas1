<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\FaturaService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Isolamento entre empresas e papéis restritos na Faturação: o administrador
 * de uma empresa nunca alcança documentos, recibos ou ficheiros SAF-T de outra,
 * e papéis especializados (Costureira, Engenheiro) não entram na Faturação.
 */
class IsolamentoEPermissoesFaturacaoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresaA;

    protected Empresa $empresaB;

    protected Cliente $clienteA;

    protected User $adminA;

    protected User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        config(['faturacao.chave_privada_path' => $caminho, 'faturacao.agt.ativo' => false]);

        $this->empresaA = $this->criarEmpresa('Loja A', '5001636863');
        $this->empresaB = $this->criarEmpresa('Loja B', '5001636864');
        $this->clienteA = Cliente::create(['empresa_id' => $this->empresaA->id, 'nome' => 'Cliente A', 'nif' => null]);
        $this->adminA = $this->criarUtilizador($this->empresaA, 'admin@a.ao', 'Administrador');
        $this->adminB = $this->criarUtilizador($this->empresaB, 'admin@b.ao', 'Administrador');
    }

    protected function criarEmpresa(string $nome, string $nif): Empresa
    {
        $empresa = Empresa::create(['nome_comercial' => $nome, 'nif' => $nif]);
        $empresa->aderirServicos([Servico::Faturacao]);

        return $empresa;
    }

    protected function criarUtilizador(Empresa $empresa, string $email, ?string $papel): User
    {
        $utilizador = User::create(['empresa_id' => $empresa->id, 'name' => $email, 'email' => $email, 'password' => 'Senha-Forte-123!']);

        if ($papel !== null) {
            $registrar = app(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($empresa->id);
            $utilizador->assignRole(Role::firstOrCreate(['name' => $papel, 'guard_name' => 'web', 'empresa_id' => $empresa->id]));
            $registrar->setPermissionsTeamId(null);
            $registrar->forgetCachedPermissions();
        }

        return $utilizador;
    }

    protected function rascunhoDaEmpresaA(): Fatura
    {
        return app(FaturaService::class)->criarRascunho($this->empresaA, $this->clienteA, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]);
    }

    public function test_administrador_de_outra_empresa_nao_alcanca_a_fatura(): void
    {
        $fatura = $this->rascunhoDaEmpresaA();

        $this->actingAs($this->adminB)->get(route('faturacao.faturas.mostrar', $fatura->id))->assertNotFound();
        $this->actingAs($this->adminB)->get(route('faturacao.faturas.editar', $fatura->id))->assertNotFound();
        $this->actingAs($this->adminB)->delete(route('faturacao.faturas.eliminar', $fatura->id))->assertNotFound();
        $this->actingAs($this->adminB)->post(route('faturacao.faturas.emitir', $fatura->id))->assertNotFound();

        $fatura->refresh();
        $this->assertSame('rascunho', $fatura->estado);
    }

    public function test_nao_se_cria_fatura_com_cliente_de_outra_empresa(): void
    {
        $this->actingAs($this->adminB)
            ->post(route('faturacao.faturas.guardar'), [
                'cliente_id' => $this->clienteA->id,
                'linhas' => [['descricao' => 'X', 'quantidade' => 1, 'preco_unitario' => '10.00', 'taxa_iva' => 14]],
            ])
            ->assertSessionHasErrors(['cliente_id']);

        $this->assertSame(0, Fatura::withoutGlobalScopes()->where('empresa_id', $this->empresaB->id)->count());
    }

    public function test_recibo_de_outra_empresa_nao_e_visivel_nem_se_regista_sobre_fatura_alheia(): void
    {
        $faturas = app(FaturaService::class);
        $fatura = $faturas->emitir($this->rascunhoDaEmpresaA());

        $this->actingAs($this->adminA)
            ->post(route('faturacao.recibos.guardar'), ['fatura_id' => $fatura->id, 'valor' => '100.00', 'meio_pagamento' => 'transferencia'])
            ->assertSessionHasNoErrors();

        $recibo = Recibo::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->adminB)->get(route('faturacao.recibos.mostrar', $recibo->id))->assertNotFound();

        $this->actingAs($this->adminB)
            ->post(route('faturacao.recibos.guardar'), ['fatura_id' => $fatura->id, 'valor' => '100.00', 'meio_pagamento' => 'transferencia'])
            ->assertSessionHasErrors(['fatura_id']);

        $this->assertSame(1, Recibo::withoutGlobalScopes()->count());
    }

    public function test_ficheiro_saft_de_outra_empresa_nao_se_descarrega(): void
    {
        Storage::fake('local');
        $nome = 'SAFT_2026-01-01_2026-01-31.xml';
        Storage::disk('local')->put("faturacao/saft/{$this->empresaA->id}/{$nome}", '<AuditFile/>');

        $this->actingAs($this->adminB)->get(route('faturacao.saft.descarregar', $nome))->assertNotFound();
        $this->actingAs($this->adminA)->get(route('faturacao.saft.descarregar', $nome))->assertOk();
    }

    public function test_papeis_restritos_nao_entram_na_faturacao_mas_os_restantes_sim(): void
    {
        $costureira = $this->criarUtilizador($this->empresaA, 'costureira@a.ao', 'Costureira');
        $engenheiro = $this->criarUtilizador($this->empresaA, 'engenheiro@a.ao', 'Engenheiro');
        $operador = $this->criarUtilizador($this->empresaA, 'operador@a.ao', null);

        $this->actingAs($costureira)->get(route('faturacao.clientes.index'))->assertForbidden();
        $this->actingAs($costureira)->get(route('faturacao.faturas.index'))->assertForbidden();
        $this->actingAs($engenheiro)->get(route('faturacao.recibos.index'))->assertForbidden();

        $this->actingAs($operador)->get(route('faturacao.clientes.index'))->assertOk();
        $this->actingAs($this->adminA)->get(route('faturacao.clientes.index'))->assertOk();
    }

    public function test_administrador_com_papel_restrito_mantem_o_acesso(): void
    {
        $misto = $this->criarUtilizador($this->empresaA, 'misto@a.ao', 'Administrador');
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->empresaA->id);
        $misto->assignRole(Role::firstOrCreate(['name' => 'Costureira', 'guard_name' => 'web', 'empresa_id' => $this->empresaA->id]));
        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();

        $this->actingAs($misto)->get(route('faturacao.clientes.index'))->assertOk();
    }
}
