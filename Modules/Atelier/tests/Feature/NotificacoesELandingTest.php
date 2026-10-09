<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Listeners\EnviarNotificacaoMudancaEstado;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoOrcamento;
use Modules\Atelier\Models\Perfil;
use Modules\Atelier\Notifications\ClienteAtelierNotification;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

class NotificacoesELandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_notifica_o_cliente_mesmo_sem_tenant_definido(): void
    {
        Notification::fake();
        config(['atelier.canais_notificacao' => ['mail']]);

        $empresa = Empresa::create(['nome_comercial' => 'Atelier Fila', 'nif' => '400000101']);
        $tenant = app(TenantManager::class);
        $tenant->set($empresa->id);

        $cliente = Cliente::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Maria',
            'email' => 'maria@example.test',
        ]);

        $pedido = Pedido::create([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Vestido de teste',
            'valor_orcamento' => 10000,
            'taxa_iva_aplicada' => 14,
            'status' => 'primeira_prova',
        ]);

        $evento = new PedidoMudouEstado($pedido->fresh(), 'em_costura');

        // Como num worker da fila: nenhum tenant definido.
        $tenant->clear();

        app(EnviarNotificacaoMudancaEstado::class)->handle($evento);

        Notification::assertSentOnDemand(
            ClienteAtelierNotification::class,
            fn ($notificacao, $canais, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'maria@example.test'
        );
    }

    public function test_formulario_de_orcamento_de_atelier_nao_publicado_devolve_404(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier Privado', 'nif' => '400000102']);
        $empresa->forceFill(['slug' => 'atelier-privado'])->save();
        Perfil::create(['empresa_id' => $empresa->id, 'publicado' => false]);

        $this->post(route('atelier.landing.solicitar-orcamento', ['empresa' => 'atelier-privado']), [
            'nome' => 'Visitante',
            'contacto' => '900000000',
        ])->assertNotFound();

        $this->assertSame(0, PedidoOrcamento::withoutGlobalScopes()->count());
    }

    public function test_formulario_de_orcamento_de_atelier_publicado_regista_o_pedido(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier Publico', 'nif' => '400000103']);
        $empresa->forceFill(['slug' => 'atelier-publico'])->save();
        Perfil::create(['empresa_id' => $empresa->id, 'publicado' => true]);

        $this->post(route('atelier.landing.solicitar-orcamento', ['empresa' => 'atelier-publico']), [
            'nome' => 'Visitante',
            'contacto' => '900000000',
        ])->assertRedirect();

        $this->assertSame(1, PedidoOrcamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->count());
    }
}
