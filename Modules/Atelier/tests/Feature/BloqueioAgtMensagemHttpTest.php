<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Atelier\Models\Pedido;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/** O bloqueio deliberado de RC com AGT ativa não pode parecer um erro transitório. */
class BloqueioAgtMensagemHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_sinal_com_agt_ativa_mostra_o_motivo_do_bloqueio_e_nao_tenta_novamente(): void
    {
        $this->withoutVite();
        config(['faturacao.agt.ativo' => true]);

        $admin = app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Atelier AGT', 'nif' => '400000503'],
            ['name' => 'Admin AGT', 'email' => 'admin.agt@example.test', 'password' => 'Senha-segura-123'],
            ['atelier'],
        );
        app(TenantManager::class)->set((int) $admin->empresa_id);

        $cliente = Cliente::create(['empresa_id' => $admin->empresa_id, 'nome' => 'Cliente AGT']);
        $pedido = Pedido::create([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Vestido',
            'valor_orcamento' => 10000,
            'taxa_iva_aplicada' => 14,
        ]);

        $this->actingAs($admin)
            ->post(route('atelier.pedidos.registar-sinal', $pedido->id), ['valor' => 1000, 'meio_pagamento' => 'dinheiro'])
            ->assertRedirect()
            ->assertSessionHas('erro', fn (string $m) => str_contains($m, 'AGT') && ! str_contains($m, 'Tenta novamente'));
    }
}
