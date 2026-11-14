<?php

namespace Modules\Faturacao\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\AbatimentoAdiantamentoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\ReciboService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Recibos pela interface: emissão só de Administrador, saldo à vista e erros sem 500. */
class RecibosInterfaceTest extends TestCase
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

        config(['faturacao.chave_privada_path' => $caminho, 'faturacao.agt.ativo' => false]);

        $this->empresa = $this->criarEmpresa('Loja Recibos', '5001636863');
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Recibos', 'nif' => null]);
        $this->admin = $this->criarUtilizador($this->empresa, 'admin@recibos.ao', true);
        $this->operador = $this->criarUtilizador($this->empresa, 'operador@recibos.ao', false);
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

    /** Fatura de 1000 + 14% = 1140. */
    protected function faturaEmitida(): Fatura
    {
        $faturas = app(FaturaService::class);

        return $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));
    }

    /** @return array<string, mixed> */
    protected function pedido(Fatura $fatura, string $valor, string $meio = 'transferencia'): array
    {
        return ['fatura_id' => $fatura->id, 'valor' => $valor, 'meio_pagamento' => $meio];
    }

    public function test_formulario_mostra_o_valor_em_divida_como_sugestao(): void
    {
        $fatura = $this->faturaEmitida();

        $this->actingAs($this->admin)->get(route('faturacao.recibos.criar', ['fatura' => $fatura->id]))
            ->assertOk()
            ->assertSee('Registar recibo')
            ->assertSee($fatura->numero_documento)
            ->assertSee('value="1140.00"', false); // input number: ponto decimal, sem separador de milhares
    }

    public function test_emite_o_recibo_e_a_fatura_mostra_o_recebido(): void
    {
        $fatura = $this->faturaEmitida();

        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '400.00'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $recibo = Recibo::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('emitido', $recibo->estado);
        $this->assertStringStartsWith('RC ', $recibo->numero_documento);
        $this->assertSame('400.00', $recibo->valor);
        $this->assertSame($fatura->id, $recibo->fatura_id);
        $this->assertSame($this->cliente->id, $recibo->cliente_id);

        $this->actingAs($this->admin)->get(route('faturacao.recibos.mostrar', $recibo->id))
            ->assertOk()->assertSee($recibo->numero_documento)->assertSee('Transferência bancária');

        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
            ->assertOk()->assertSee($recibo->numero_documento)->assertSee('Em dívida')->assertSee('740,00');
    }

    public function test_valor_acima_do_que_esta_em_divida_volta_com_mensagem(): void
    {
        $fatura = $this->faturaEmitida();
        $volta = route('faturacao.recibos.criar', ['fatura' => $fatura->id]);

        $this->actingAs($this->admin)->from($volta)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '1140.01'))
            ->assertRedirect($volta)
            ->assertSessionHasErrors(['recibo']);

        $this->assertStringContainsString('excede', session('errors')->first('recibo'));
        $this->assertSame(0, Recibo::withoutGlobalScopes()->count());
    }

    public function test_notas_de_credito_emitidas_reduzem_o_que_se_pode_receber(): void
    {
        $fatura = $this->faturaEmitida();
        $notas = app(NotaCreditoDebitoService::class);
        $notas->emitir($notas->criarRascunho($this->empresa->id, $this->cliente->id, 'credito', 'Devolução', [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '500.00', 'taxa_iva' => 14],
        ], $fatura->id)); // crédito de 570: restam 570

        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '570.01'))
            ->assertSessionHasErrors(['recibo']);

        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '570.00', 'dinheiro'))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Recibo::withoutGlobalScopes()->count());
    }

    public function test_notas_de_debito_emitidas_aumentam_o_saldo_a_receber(): void
    {
        $fatura = $this->faturaEmitida();
        $notas = app(NotaCreditoDebitoService::class);
        $nota = $notas->emitir($notas->criarRascunho(
            $this->empresa->id,
            $this->cliente->id,
            'debito',
            'Acerto adicional',
            [['descricao' => 'Serviço adicional', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 0]],
            $fatura->id,
        ));

        $this->assertSame('emitida', $nota->estado);
        $this->assertSame(124000, app(ReciboService::class)->saldoEmCentimos($fatura));
        $this->assertSame(['recebido' => 0, 'creditado' => 0, 'abatido' => 0, 'saldo' => 124000], app(ReciboService::class)->resumoDaFatura($fatura));

        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '1240.00'))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '0.01'))
            ->assertSessionHasErrors(['recibo']);
    }

    public function test_fatura_liquidada_nao_aceita_novo_recibo(): void
    {
        $fatura = $this->faturaEmitida();
        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '1140.00'))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('faturacao.recibos.criar', ['fatura' => $fatura->id]))
            ->assertRedirect(route('faturacao.faturas.mostrar', $fatura->id))
            ->assertSessionHas('erro');

        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
            ->assertOk()->assertDontSee('Registar recibo');
    }

    public function test_fatura_em_rascunho_nao_tem_recibo(): void
    {
        $rascunho = app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Rascunho', 'quantidade' => 1, 'preco_unitario' => '10.00', 'taxa_iva' => 14],
        ]);

        $this->actingAs($this->admin)->get(route('faturacao.recibos.criar', ['fatura' => $rascunho->id]))
            ->assertRedirect(route('faturacao.faturas.index'))
            ->assertSessionHas('erro');
    }

    public function test_servico_recusa_meio_de_pagamento_fora_do_contrato_da_base_de_dados(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Meio de pagamento inválido.');

        app(ReciboService::class)->emitir(
            (int) $this->empresa->id,
            (int) $this->cliente->id,
            '10.00',
            'bitcoin',
        );
    }


    public function test_servico_de_recibos_recusa_empresa_fora_do_tenant_ativo(): void
    {
        $tenant = app(TenantManager::class);
        $tenant->set((int) $this->empresa->id + 1000);

        try {
            app(ReciboService::class)->emitir(
                (int) $this->empresa->id,
                (int) $this->cliente->id,
                '10.00',
                'dinheiro',
            );

            $this->fail('O serviço devia rejeitar uma empresa diferente do tenant ativo.');
        } catch (\\DomainException $exception) {
            $this->assertSame('A operação não pertence à empresa do contexto atual.', $exception->getMessage());
        } finally {
            $tenant->clear();
        }
    }

    public function test_servico_de_notas_recusa_criar_rascunho_fora_do_tenant_ativo(): void
    {
        $tenant = app(TenantManager::class);
        $tenant->set((int) $this->empresa->id + 1000);

        try {
            app(NotaCreditoDebitoService::class)->criarRascunho(
                (int) $this->empresa->id,
                (int) $this->cliente->id,
                'debito',
                'Teste de isolamento',
                [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '10.00', 'taxa_iva' => 0]],
            );

            $this->fail('O serviço devia rejeitar uma empresa diferente do tenant ativo.');
        } catch (\\DomainException $exception) {
            $this->assertSame('A operação não pertence à empresa do contexto atual.', $exception->getMessage());
        } finally {
            $tenant->clear();
        }
    }

    public function test_meio_de_pagamento_e_valor_sao_validados(): void
    {
        $fatura = $this->faturaEmitida();

        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '10.00', 'bitcoin'))
            ->assertSessionHasErrors(['meio_pagamento']);

        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '0'))
            ->assertSessionHasErrors(['valor']);

        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, 'abc'))
            ->assertSessionHasErrors(['valor']);
    }

    public function test_so_o_administrador_emite_recibos(): void
    {
        $fatura = $this->faturaEmitida();

        $this->actingAs($this->operador)->get(route('faturacao.recibos.criar', ['fatura' => $fatura->id]))->assertForbidden();
        $this->actingAs($this->operador)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '10.00'))->assertForbidden();

        $this->assertSame(0, Recibo::withoutGlobalScopes()->count());
    }

    public function test_outra_empresa_nao_ve_nem_usa_faturas_alheias(): void
    {
        $fatura = $this->faturaEmitida();
        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '100.00'));
        $recibo = Recibo::withoutGlobalScopes()->firstOrFail();

        $outra = $this->criarEmpresa('Outra Loja', '5001636864');
        $intruso = $this->criarUtilizador($outra, 'admin@outra.ao', true);

        $this->actingAs($intruso)->get(route('faturacao.recibos.mostrar', $recibo->id))->assertNotFound();
        $this->actingAs($intruso)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '10.00'))->assertSessionHasErrors(['fatura_id']);
        $this->actingAs($intruso)->get(route('faturacao.recibos.criar', ['fatura' => $fatura->id]))->assertRedirect(route('faturacao.faturas.index'));
        $this->assertSame(1, Recibo::withoutGlobalScopes()->count());
    }

    public function test_lista_de_recibos(): void
    {
        $fatura = $this->faturaEmitida();
        $this->actingAs($this->admin)->post(route('faturacao.recibos.guardar'), $this->pedido($fatura, '100.00', 'multicaixa'));

        $this->actingAs($this->admin)->get(route('faturacao.recibos.index'))
            ->assertOk()->assertSee('Cliente Recibos')->assertSee('Multicaixa');
    }

    public function test_conta_corrente_calcula_saldo_de_abertura_e_saldo_acumulado(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');

        try {
            $faturaAnterior = $this->faturaEmitida();

            app(ReciboService::class)->emitir(
                (int) $this->empresa->id,
                (int) $this->cliente->id,
                '200.00',
                'dinheiro',
                (int) $faturaAnterior->id,
            );

            Carbon::setTestNow('2026-11-12 10:00:00');
            $faturaAtual = $this->faturaEmitida();

            $this->actingAs($this->admin)
                ->get(route('faturacao.clientes.conta-corrente', [
                    'cliente' => $this->cliente->id,
                    'inicio' => '2026-11-11',
                    'fim' => '2026-11-12',
                ]))
                ->assertOk()
                ->assertSee('Saldo de abertura')
                ->assertSee('940,00 Kz')
                ->assertSee($faturaAtual->numero_documento)
                ->assertSee('2.080,00 Kz');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_conta_corrente_nao_duplica_recebimento_ao_abater_adiantamento(): void
    {
        $faturas = app(FaturaService::class);
        $adiantamento = $faturas->emitir($faturas->criarRascunhoAdiantamento(
            $this->empresa,
            $this->cliente,
            [['descricao' => 'Adiantamento', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14]],
        ));
        $faturaFinal = $this->faturaEmitida();

        app(ReciboService::class)->emitir(
            (int) $this->empresa->id,
            (int) $this->cliente->id,
            '500.00',
            'dinheiro',
            (int) $adiantamento->id,
        );

        app(AbatimentoAdiantamentoService::class)->abater(
            (int) $this->empresa->id,
            (int) $adiantamento->id,
            (int) $faturaFinal->id,
            '200.00',
            'teste-abatimento-001',
        );

        $this->actingAs($this->admin)
            ->get(route('faturacao.clientes.conta-corrente', [
                'cliente' => $this->cliente->id,
                'inicio' => today()->toDateString(),
                'fim' => today()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('2.280,00 Kz')
            ->assertSee('700,00 Kz')
            ->assertSee('1.580,00 Kz');
    }

    public function test_cliente_de_outra_empresa_nao_pode_ser_aberto_na_conta_corrente(): void
    {
        $outra = $this->criarEmpresa('Outra Loja Conta', '5001636865');
        $clienteAlheio = Cliente::create([
            'empresa_id' => $outra->id,
            'nome' => 'Cliente de outra empresa',
            'nif' => null,
        ]);

        $this->actingAs($this->admin)
            ->get(route('faturacao.clientes.conta-corrente', $clienteAlheio->id))
            ->assertNotFound();
    }
}
