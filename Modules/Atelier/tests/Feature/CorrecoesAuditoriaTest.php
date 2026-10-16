<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoOrcamento;
use Modules\Atelier\Models\Perfil;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\PedidoStatusService;
use Modules\Atelier\Services\PortalLinkService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Support\Dinheiro;
use Tests\TestCase;

/**
 * Regressões das correções da auditoria ao módulo Atelier: regras
 * financeiras do estado do pedido, total do IVA coerente com a fatura,
 * consentimento de marketing (Lei n.º 22/11) e expiração dos links do portal.
 */
class CorrecoesAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function criarEmpresa(string $nif): Empresa
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        app(TenantManager::class)->set($empresa->id);

        return $empresa;
    }

    protected function criarPedido(string $nif, array $extra = []): Pedido
    {
        $empresa = $this->criarEmpresa($nif);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif]);

        return Pedido::create(array_merge([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Vestido de teste',
            'valor_orcamento' => 10000,
            'taxa_iva_aplicada' => 14,
        ], $extra));
    }

    public function test_entrega_exige_a_fatura_final_emitida(): void
    {
        Event::fake([PedidoMudouEstado::class]);
        $pedido = $this->criarPedido('400000201', ['status' => 'pronto_para_retirada']);

        try {
            app(PedidoStatusService::class)->avancarPara($pedido, 'entregue');
            $this->fail('A entrega sem fatura final emitida devia ser recusada.');
        } catch (PedidoException $e) {
            $this->assertStringContainsString('fatura', $e->getMessage());
        }

        $this->assertSame('pronto_para_retirada', $pedido->fresh()->status);
        Event::assertNotDispatched(PedidoMudouEstado::class);
    }

    public function test_cancelar_pedido_sem_fatura_continua_permitido(): void
    {
        Event::fake([PedidoMudouEstado::class]);
        $pedido = $this->criarPedido('400000202');

        app(PedidoStatusService::class)->avancarPara($pedido, 'cancelado', 'Cliente desistiu');

        $this->assertSame('cancelado', $pedido->fresh()->status);
        Event::assertDispatched(PedidoMudouEstado::class);
    }

    public function test_total_do_pedido_usa_o_mesmo_arredondamento_por_linha_da_fatura(): void
    {
        $pedido = $this->criarPedido('400000203', ['valor_orcamento' => '100.01']);

        $esperado = Dinheiro::centimos(Dinheiro::calcularLinha(1, '100.01', '14.00')['total']);

        $this->assertSame($esperado, $pedido->fresh()->totalComIvaEmCentimos());
        $this->assertEqualsWithDelta($esperado / 100, $pedido->fresh()->valorTotalComIva(), 0.0001);
    }

    public function test_marketing_so_chega_a_clientes_com_consentimento_registado(): void
    {
        $empresa = $this->criarEmpresa('400000204');

        $semCrm = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Sem CRM']);
        $consentido = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Consentido']);
        $semData = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Sem data de consentimento']);
        $revogado = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Revogado']);

        ClienteCrm::create([
            'cliente_id' => $consentido->id,
            'aceita_marketing' => true,
            'consentimento_em' => now(),
            'consentimento_origem' => 'ficha em papel',
        ]);
        ClienteCrm::create(['cliente_id' => $semData->id, 'aceita_marketing' => true]);
        ClienteCrm::create([
            'cliente_id' => $revogado->id,
            'aceita_marketing' => true,
            'consentimento_em' => now()->subDay(),
            'consentimento_revogado_em' => now(),
        ]);

        $campanha = (new Campanha)->forceFill(['empresa_id' => $empresa->id, 'segmento' => 'todos']);

        $ids = app(CrmService::class)->resolverSegmento($campanha)->pluck('id')->all();

        $this->assertSame([$consentido->id], $ids);
        $this->assertNotContains($semCrm->id, $ids);
    }

    public function test_mensagem_de_aniversario_so_para_quem_consentiu(): void
    {
        $empresa = $this->criarEmpresa('400000205');
        $nascimento = now()->subYears(30)->format('Y-m-d');

        $consentido = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Consentido']);
        $semConsentimento = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Sem consentimento']);

        ClienteCrm::create([
            'cliente_id' => $consentido->id,
            'data_nascimento' => $nascimento,
            'aceita_marketing' => true,
            'consentimento_em' => now(),
        ]);
        ClienteCrm::create([
            'cliente_id' => $semConsentimento->id,
            'data_nascimento' => $nascimento,
            'aceita_marketing' => true,
        ]);

        $ids = app(CrmService::class)->aniversariantesHoje($empresa->id)->pluck('id')->all();

        $this->assertSame([$consentido->id], $ids);
    }

    public function test_link_do_portal_nunca_expira_depois_do_link_com_que_se_chegou(): void
    {
        config(['atelier.portal_link_validade_dias' => 7]);
        $pedido = $this->criarPedido('400000206');

        $limite = now()->addDay()->getTimestamp();
        request()->query->set('expires', (string) $limite);

        try {
            $link = app(PortalLinkService::class)->gerarLinkAcesso($pedido->cliente);
        } finally {
            request()->query->remove('expires');
        }

        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->assertSame($limite, (int) $query['expires']);
    }

    public function test_formulario_publico_exige_consentimento_e_guarda_a_prova_dele(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier Consentimento', 'nif' => '400000207']);
        $empresa->aderirServicos(['atelier']);
        $empresa->forceFill(['slug' => 'atelier-consentimento'])->save();
        Perfil::create(['empresa_id' => $empresa->id, 'publicado' => true]);

        $url = route('atelier.landing.solicitar-orcamento', ['empresa' => 'atelier-consentimento']);

        $this->post($url, ['nome' => 'Visitante', 'contacto' => '900000000'])
            ->assertSessionHasErrors('consentimento');
        $this->assertSame(0, PedidoOrcamento::withoutGlobalScopes()->count());

        $this->post($url, ['nome' => 'Visitante', 'contacto' => '900000000', 'consentimento' => '1'])
            ->assertSessionHasNoErrors();

        $lead = PedidoOrcamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->first();
        $this->assertNotNull($lead?->consentimento_em);
    }
}
