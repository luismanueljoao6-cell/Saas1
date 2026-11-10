<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\Reclamacao;
use Modules\EstudioMusica\Models\SolicitacaoOrcamento;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Landing pública: consentimento (Lei n.º 22/11), anti-robôs, canal de
 * reclamações, política de privacidade e saída do marketing.
 */
class LandingConformidadeTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Landing', 'nif' => '400000006']);
        $this->empresa->aderirServicos(['estudio']);
    }

    protected function orcamento(array $extra = []): TestResponse
    {
        return $this->post(route('estudiomusica.landing.orcamento', ['empresa' => $this->empresa->id]), array_merge([
            'nome' => 'Lead Teste',
            'email' => 'lead@example.test',
        ], $extra));
    }

    public function test_pedido_sem_consentimento_e_recusado(): void
    {
        $this->orcamento()->assertSessionHasErrors('aceita_politica');

        $this->assertSame(0, SolicitacaoOrcamento::withoutGlobalScopes()->count());
    }

    public function test_pedido_com_consentimento_guarda_a_prova_e_o_marketing_so_se_marcado(): void
    {
        $this->orcamento(['aceita_politica' => '1'])->assertSessionHasNoErrors();

        $pedido = SolicitacaoOrcamento::withoutGlobalScopes()->firstOrFail();
        $this->assertNotNull($pedido->consentimento_dados_em);
        $this->assertFalse((bool) $pedido->aceita_marketing);

        $this->orcamento(['aceita_politica' => '1', 'aceita_marketing' => '1', 'email' => 'outro@example.test']);

        $this->assertSame(1, SolicitacaoOrcamento::withoutGlobalScopes()->where('aceita_marketing', true)->count());
    }

    public function test_pedido_exige_um_meio_de_contacto(): void
    {
        $this->post(route('estudiomusica.landing.orcamento', ['empresa' => $this->empresa->id]), [
            'nome' => 'Sem contacto',
            'aceita_politica' => '1',
        ])->assertSessionHasErrors(['email', 'telefone']);
    }

    public function test_robo_que_preenche_o_campo_escondido_nao_cria_pedido(): void
    {
        $this->orcamento(['aceita_politica' => '1', 'website' => 'http://spam.example'])->assertSessionHasNoErrors();

        $this->assertSame(0, SolicitacaoOrcamento::withoutGlobalScopes()->count());
    }

    public function test_reclamacao_valida_e_registada(): void
    {
        $this->post(route('estudiomusica.landing.reclamacao', ['empresa' => $this->empresa->id]), [
            'nome' => 'Cliente Chateado',
            'email' => 'cliente@example.test',
            'assunto' => 'Atraso na entrega',
            'descricao' => 'A mistura devia ter sido entregue na semana passada.',
            'aceita_politica' => '1',
        ])->assertSessionHasNoErrors();

        $r = Reclamacao::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('recebida', $r->estado);
        $this->assertNotNull($r->consentimento_dados_em);
    }

    public function test_reclamacao_sem_consentimento_falha_no_bag_proprio(): void
    {
        $this->post(route('estudiomusica.landing.reclamacao', ['empresa' => $this->empresa->id]), [
            'nome' => 'Cliente',
            'email' => 'cliente@example.test',
            'assunto' => 'Assunto',
            'descricao' => 'Descrição suficientemente longa.',
        ])->assertSessionHasErrors('aceita_politica', null, 'reclamacao');

        $this->assertSame(0, Reclamacao::withoutGlobalScopes()->count());
    }

    public function test_politica_de_privacidade_e_publica(): void
    {
        $this->get(route('estudiomusica.landing.privacidade', ['empresa' => $this->empresa->id]))
            ->assertOk()
            ->assertSee('Política de Privacidade')
            ->assertSee('Estúdio Landing');
    }

    public function test_ligacao_de_saida_do_marketing_funciona_assinada_e_mesmo_sem_o_servico(): void
    {
        // Empresa SEM o serviço do estúdio: o direito de oposição tem de funcionar na mesma.
        $outra = Empresa::create(['nome_comercial' => 'Sem Estúdio', 'nif' => '400000007']);
        app(TenantManager::class)->set($outra->id);
        $cliente = (new Cliente)->forceFill([
            'empresa_id' => $outra->id,
            'nome' => 'Artista',
            'aceita_marketing' => true,
            'aceita_marketing_em' => now(),
        ]);
        $cliente->save();

        $parametros = ['empresa' => $outra->id, 'cliente' => $cliente->id];

        $this->get(route('estudiomusica.landing.marketing-sair', $parametros))->assertForbidden();
        $this->assertTrue((bool) Cliente::withoutGlobalScopes()->find($cliente->id)->aceita_marketing);

        $this->get(URL::signedRoute('estudiomusica.landing.marketing-sair', $parametros))->assertOk();
        $this->assertFalse((bool) Cliente::withoutGlobalScopes()->find($cliente->id)->aceita_marketing);
    }
}
