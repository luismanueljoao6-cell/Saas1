<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\FaturaService;
use Tests\TestCase;

class PainelFaturacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function criarEmpresaComUtilizador(string $nif, string $email): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->aderirServicos([Servico::Faturacao]);

        $utilizador = User::create([
            'empresa_id' => $empresa->id,
            'name' => $email,
            'email' => $email,
            'password' => 'Senha-Forte-123!',
        ]);

        return [$empresa, $utilizador];
    }

    protected function criarRascunho(Empresa $empresa, string $nomeCliente): void
    {
        $cliente = Cliente::create([
            'empresa_id' => $empresa->id,
            'nome' => $nomeCliente,
            'nif' => null,
        ]);

        app(FaturaService::class)->criarRascunho($empresa, $cliente, [[
            'descricao' => 'Serviço de teste',
            'quantidade' => 1,
            'preco_unitario' => '1000.00',
            'taxa_iva' => 14,
        ]]);
    }

    public function test_painel_mostra_resumo_e_apenas_documentos_da_empresa_autenticada(): void
    {
        [$empresaA, $utilizadorA] = $this->criarEmpresaComUtilizador('630000101', 'painel-a@example.test');
        [$empresaB] = $this->criarEmpresaComUtilizador('630000102', 'painel-b@example.test');

        $this->criarRascunho($empresaA, 'Cliente A 1');
        $this->criarRascunho($empresaA, 'Cliente A 2');
        $this->criarRascunho($empresaB, 'Cliente B');

        $resposta = $this->actingAs($utilizadorA)->get(route('faturacao.painel'));

        $resposta->assertOk()
            ->assertViewHas('resumo', function (array $resumo): bool {
                return $resumo['rascunhos'] === 2
                    && $resumo['documentos_emitidos_mes'] === 0
                    && (float) $resumo['total_faturado_mes'] === 0.0
                    && (float) $resumo['recebido_mes'] === 0.0;
            });

        $resposta->assertSee('Cliente A 1')
            ->assertSee('Cliente A 2')
            ->assertDontSee('Cliente B');
    }
}
