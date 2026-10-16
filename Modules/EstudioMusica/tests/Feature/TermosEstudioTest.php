<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\AceiteTermos;
use Modules\EstudioMusica\Models\TermosEstudio;
use Modules\EstudioMusica\Services\TermosEstudioService;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

class TermosEstudioTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected TermosEstudioService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Termos', 'nif' => '400000005']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Artista Teste']);
        $this->servico = app(TermosEstudioService::class);
    }

    public function test_primeira_consulta_cria_a_versao_1_a_partir_do_modelo_e_nao_duplica(): void
    {
        $a = $this->servico->atual($this->empresa->id, 'Estúdio Termos');
        $b = $this->servico->atual($this->empresa->id, 'Estúdio Termos');

        $this->assertSame(1, $a->versao);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, TermosEstudio::query()->count());
        $this->assertStringContainsString('Direitos de autor', $a->conteudo);
        $this->assertStringContainsString('Estúdio Termos', $a->conteudo);
    }

    public function test_aceite_e_idempotente(): void
    {
        $termos = $this->servico->atual($this->empresa->id);

        $this->servico->registarAceite($this->cliente, $termos, Request::create('/'));
        $this->servico->registarAceite($this->cliente, $termos, Request::create('/'));

        $this->assertTrue($this->servico->aceitou($this->cliente, $termos));
        $this->assertSame(1, AceiteTermos::query()->count());
    }

    public function test_nova_versao_obriga_a_novo_aceite(): void
    {
        $v1 = $this->servico->atual($this->empresa->id);
        $this->servico->registarAceite($this->cliente, $v1, Request::create('/'));

        $v2 = $this->servico->publicar($this->empresa->id, 'Termos 2', str_repeat('Cláusula. ', 20));

        $this->assertSame(2, $v2->versao);
        $this->assertSame($v2->id, $this->servico->atual($this->empresa->id)->id);
        $this->assertFalse($this->servico->aceitou($this->cliente, $v2));
        $this->assertTrue($this->servico->aceitou($this->cliente, $v1), 'o aceite antigo fica como prova');
    }

    public function test_o_ip_nunca_e_guardado_em_claro(): void
    {
        $termos = $this->servico->atual($this->empresa->id);
        $pedido = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.7']);

        $aceite = $this->servico->registarAceite($this->cliente, $termos, $pedido);

        $this->assertNotNull($aceite->ip_hash);
        $this->assertStringNotContainsString('203.0.113.7', $aceite->ip_hash);
        $this->assertSame(64, strlen($aceite->ip_hash));
    }
}
