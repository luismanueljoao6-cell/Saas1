<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Services\FaturaService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Notas de crédito e de débito pela interface: rascunho, emissão só de Administrador, erros sem 500. */
class NotasInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected User $admin;

    protected User $operador;

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
            'faturacao.nota_debito_liquida_iva' => false,
        ]);

        $this->empresa = $this->criarEmpresa('Loja Notas', '5001636863');
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Notas', 'nif' => null]);
        $this->admin = $this->criarUtilizador($this->empresa, 'admin@notas.ao', true);
        $this->operador = $this->criarUtilizador($this->empresa, 'operador@notas.ao', false);
    }

    protected function criarEmpresa(string $nome, string $nif): Empresa
    {
        $empresa = Empresa::create(['nome_comercial' => $nome, 'nif' => $nif]);
        $empresa->aderirServicos([Servico::Faturacao]);

        return $empresa;
    }

    protected function criarUtilizador(Empresa $empresa, string $email, bool $administrador): User
    {
        $utilizador = User::create(['empresa_id' => $empresa->id, 'name' => $email, 'email' => $email, 'password' => 'Senha-Forte-123!']);

        if ($administrador) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($empresa->id);
            $utilizador->assignRole(Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $empresa->id]));
        }

        return $utilizador;
    }

    protected function faturaEmitida(): Fatura
    {
        $faturas = app(FaturaService::class);

        return $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço Alfa', 'quantidade' => 2, 'preco_unitario' => '500.00', 'taxa_iva' => 14],
        ]));
    }

    /** @return array<string, mixed> */
    protected function pedidoCredito(Fatura $fatura, array $linha = []): array
    {
        return [
            'tipo' => 'credito',
            'fatura_id' => $fatura->id,
            'motivo' => 'Devolução parcial',
            'linhas' => [array_merge(['descricao' => 'Serviço Alfa', 'quantidade' => 1, 'preco_unitario' => '500.00', 'taxa_iva' => 14], $linha)],
        ];
    }

    public function test_formulario_da_nota_de_credito_parte_das_linhas_da_fatura(): void
    {
        $fatura = $this->faturaEmitida();

        $this->actingAs($this->admin)->get(route('faturacao.notas.criar', ['tipo' => 'credito', 'fatura' => $fatura->id]))
            ->assertOk()
            ->assertSee('Nova nota de crédito')
            ->assertSee($fatura->numero_documento)
            ->assertSee('Serviço Alfa');
    }

    public function test_nota_de_credito_exige_uma_fatura_emitida(): void
    {
        $this->actingAs($this->admin)->get(route('faturacao.notas.criar', ['tipo' => 'credito']))
            ->assertRedirect(route('faturacao.faturas.index'))
            ->assertSessionHas('erro');

        $rascunho = app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Ainda rascunho', 'quantidade' => 1, 'preco_unitario' => '10.00', 'taxa_iva' => 14],
        ]);

        $this->actingAs($this->admin)->get(route('faturacao.notas.criar', ['tipo' => 'credito', 'fatura' => $rascunho->id]))
            ->assertRedirect(route('faturacao.faturas.index'))
            ->assertSessionHas('erro');
    }

    public function test_credito_parcial_guarda_rascunho_e_o_administrador_emite(): void
    {
        $fatura = $this->faturaEmitida();

        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $nota = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('rascunho', $nota->estado);
        $this->assertSame('credito', $nota->tipo);
        $this->assertSame('570.00', $nota->valor_total); // 500 + 14% de IVA
        $this->assertSame($fatura->cliente_id, $nota->cliente_id);

        $this->actingAs($this->admin)->get(route('faturacao.notas.mostrar', $nota->id))
            ->assertOk()->assertSee('Emitir nota')->assertSee('Devolução parcial');

        $this->actingAs($this->admin)->post(route('faturacao.notas.emitir', $nota->id))
            ->assertRedirect(route('faturacao.notas.mostrar', $nota->id))
            ->assertSessionHas('sucesso');

        $nota->refresh();
        $this->assertSame('emitida', $nota->estado);
        $this->assertStringStartsWith('NC ', $nota->numero_documento);

        $this->actingAs($this->admin)->get(route('faturacao.notas.mostrar', $nota->id))
            ->assertOk()->assertSee($nota->numero_documento)->assertDontSee('Emitir nota');

        // A fatura passa a ligar à nota e o recibo já só vê o que resta.
        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
            ->assertOk()->assertSee($nota->numero_documento);
    }

    public function test_credito_acima_do_faturado_fica_em_rascunho_com_mensagem(): void
    {
        $fatura = $this->faturaEmitida();

        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura, ['quantidade' => 3]))->assertRedirect();
        $nota = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->admin)->post(route('faturacao.notas.emitir', $nota->id))->assertSessionHas('erro');

        $this->assertSame('rascunho', $nota->fresh()->estado);
    }

    public function test_erro_de_regra_volta_ao_formulario_com_a_mensagem_e_as_linhas(): void
    {
        $fatura = $this->faturaEmitida();
        $volta = route('faturacao.notas.criar', ['tipo' => 'credito', 'fatura' => $fatura->id]);

        // Linha a 0% sem motivo de isenção: regra de negócio, não de formato.
        $resposta = $this->actingAs($this->admin)->from($volta)
            ->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura, ['descricao' => 'Linha-a-reter-XYZ', 'taxa_iva' => 0]));

        $resposta->assertRedirect($volta)->assertSessionHasErrors(['nota']);
        $this->assertStringContainsString('exige o motivo de isenção', session('errors')->first('nota'));
        $this->assertSame(0, NotaCreditoDebito::withoutGlobalScopes()->count());

        $this->actingAs($this->admin)->get($volta)->assertOk()->assertSee('Linha-a-reter-XYZ');
    }

    public function test_validacao_de_formato_motivo_curto(): void
    {
        $fatura = $this->faturaEmitida();

        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), [...$this->pedidoCredito($fatura), 'motivo' => 'x'])
            ->assertSessionHasErrors(['motivo']);
    }

    public function test_nota_de_debito_sem_fatura_nao_leva_iva(): void
    {
        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), [
            'tipo' => 'debito',
            'cliente_id' => $this->cliente->id,
            'motivo' => 'Repasse de despesas',
            'linhas' => [['descricao' => 'Despesas de deslocação', 'quantidade' => 1, 'preco_unitario' => '250.00']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $nota = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('debito', $nota->tipo);
        $this->assertSame('0.00', $nota->valor_iva);
        $this->assertSame('250.00', $nota->valor_total);
        $this->assertNull($nota->fatura_id);

        $this->actingAs($this->admin)->post(route('faturacao.notas.emitir', $nota->id))->assertSessionHas('sucesso');
        $this->assertStringStartsWith('ND ', $nota->fresh()->numero_documento);
    }

    public function test_nota_de_debito_com_iva_enviado_no_pedido_nao_o_liquida(): void
    {
        // Mesmo que alguém force uma taxa no pedido, a ND sem IVA grava sempre taxa 0.
        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), [
            'tipo' => 'debito',
            'cliente_id' => $this->cliente->id,
            'motivo' => 'Repasse',
            'linhas' => [['descricao' => 'Despesa', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 14]],
        ])->assertSessionHasNoErrors();

        $this->assertSame('0.00', NotaCreditoDebito::withoutGlobalScopes()->firstOrFail()->valor_iva);
    }

    public function test_so_o_administrador_emite(): void
    {
        $fatura = $this->faturaEmitida();
        $this->actingAs($this->operador)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura))->assertRedirect();
        $nota = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->operador)->post(route('faturacao.notas.emitir', $nota->id))->assertForbidden();

        $this->assertSame('rascunho', $nota->fresh()->estado);
    }

    public function test_rascunho_pode_ser_eliminado_mas_nota_emitida_nao(): void
    {
        $fatura = $this->faturaEmitida();
        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura));
        $rascunho = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->admin)->delete(route('faturacao.notas.eliminar', $rascunho->id))
            ->assertRedirect(route('faturacao.notas.index'));
        $this->assertSame(0, NotaCreditoDebito::withoutGlobalScopes()->count());

        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura));
        $emitida = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();
        $this->actingAs($this->admin)->post(route('faturacao.notas.emitir', $emitida->id));

        $this->actingAs($this->admin)->delete(route('faturacao.notas.eliminar', $emitida->id))->assertSessionHas('erro');
        $this->assertSame(1, NotaCreditoDebito::withoutGlobalScopes()->count());
    }

    public function test_outra_empresa_nao_ve_nem_usa_documentos_alheios(): void
    {
        $fatura = $this->faturaEmitida();
        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura));
        $nota = NotaCreditoDebito::withoutGlobalScopes()->firstOrFail();

        $outra = $this->criarEmpresa('Outra Loja', '5001636864');
        $intruso = $this->criarUtilizador($outra, 'admin@outra.ao', true);

        $this->actingAs($intruso)->get(route('faturacao.notas.mostrar', $nota->id))->assertNotFound();
        $this->actingAs($intruso)->post(route('faturacao.notas.emitir', $nota->id))->assertNotFound();
        $this->actingAs($intruso)->delete(route('faturacao.notas.eliminar', $nota->id))->assertNotFound();
        $this->actingAs($intruso)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura))->assertSessionHasErrors(['fatura_id']);
        $this->actingAs($intruso)->get(route('faturacao.notas.criar', ['tipo' => 'credito', 'fatura' => $fatura->id]))
            ->assertRedirect(route('faturacao.faturas.index'));
    }

    public function test_lista_de_notas(): void
    {
        $fatura = $this->faturaEmitida();
        $this->actingAs($this->admin)->post(route('faturacao.notas.guardar'), $this->pedidoCredito($fatura));

        $this->actingAs($this->admin)->get(route('faturacao.notas.index'))
            ->assertOk()->assertSee('Devolução parcial')->assertSee('Cliente Notas');
    }
}
