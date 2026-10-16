<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Jobs\PurgarSolicitacoesAntigasJob;
use Modules\EstudioMusica\Models\SolicitacaoOrcamento;
use Modules\EstudioMusica\Services\DadosPessoaisService;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Direitos do titular (Lei n.º 22/11): exportação, eliminação e retenção.
 */
class DadosPessoaisTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Dados', 'nif' => '400000004']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->cliente = (new Cliente)->forceFill([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Artista Teste',
            'email' => 'artista@example.test',
            'telefone' => '923456789',
            'nome_artistico' => 'MC Teste',
            'data_nascimento' => '1995-07-14',
            'aceita_marketing' => true,
            'aceita_marketing_em' => now(),
            'token_portal' => hash('sha256', 'segredo'),
            'token_portal_expira_em' => now()->addDay(),
        ]);
        $this->cliente->save();
    }

    protected function pedido(string $email, ?string $telefone = null): SolicitacaoOrcamento
    {
        return SolicitacaoOrcamento::create([
            'empresa_id' => $this->empresa->id,
            'nome' => 'Lead',
            'email' => $email,
            'telefone' => $telefone,
        ]);
    }

    public function test_exportacao_inclui_a_ficha_mas_nunca_o_token_do_portal(): void
    {
        $this->pedido('artista@example.test');

        $dados = app(DadosPessoaisService::class)->exportar($this->cliente);

        $this->assertSame('MC Teste', $dados['cliente']['nome_artistico']);
        $this->assertArrayNotHasKey('token_portal', $dados['cliente']);
        $this->assertCount(1, $dados['pedidos_orcamento']);
    }

    public function test_limpeza_apaga_ficha_consentimento_e_acesso_ao_portal_mas_mantem_o_cliente(): void
    {
        $this->pedido('artista@example.test');
        $outro = $this->pedido('outra.pessoa@example.test');

        $r = app(DadosPessoaisService::class)->limpar($this->cliente);

        $cliente = Cliente::find($this->cliente->id);
        $this->assertNotNull($cliente);
        $this->assertSame('Artista Teste', $cliente->nome);
        $this->assertNull($cliente->nome_artistico);
        $this->assertNull($cliente->data_nascimento);
        $this->assertNull($cliente->token_portal);
        $this->assertFalse((bool) $cliente->aceita_marketing);
        $this->assertSame(1, $r['pedidos_orcamento']);
        $this->assertNotNull(SolicitacaoOrcamento::find($outro->id), 'pedidos de outras pessoas ficam intactos');
    }

    public function test_cliente_sem_email_nem_telefone_nao_apaga_pedidos_de_ninguem(): void
    {
        $sem = (new Cliente)->forceFill(['empresa_id' => $this->empresa->id, 'nome' => 'Sem contactos']);
        $sem->save();
        $pedido = $this->pedido('alguem@example.test');

        $r = app(DadosPessoaisService::class)->limpar($sem);

        $this->assertSame(0, $r['pedidos_orcamento']);
        $this->assertNotNull(SolicitacaoOrcamento::find($pedido->id));
    }

    public function test_job_de_retencao_apaga_so_os_pedidos_antigos(): void
    {
        config(['estudiomusica.retencao_solicitacoes_meses' => 12]);

        $antigo = $this->pedido('antigo@example.test');
        $antigo->forceFill(['created_at' => now()->subMonths(13)])->save();
        $recente = $this->pedido('recente@example.test');

        (new PurgarSolicitacoesAntigasJob)->handle(app(TenantManager::class));

        $this->assertNull(SolicitacaoOrcamento::withoutGlobalScopes()->find($antigo->id));
        $this->assertNotNull(SolicitacaoOrcamento::withoutGlobalScopes()->find($recente->id));
    }
}
