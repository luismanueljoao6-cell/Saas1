<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Models\CampanhaReenvio;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/** O botão "Reenviar falhados" pela rota real (auth + tenant + subscrição + serviço + papel). */
class ReenvioCampanhaHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_clique_duplo_na_rota_real_aceita_um_pedido_e_recusa_o_segundo(): void
    {
        $this->withoutVite();
        Queue::fake();

        $admin = app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Atelier HTTP', 'nif' => '400000502'],
            ['name' => 'Admin HTTP', 'email' => 'admin.http@example.test', 'password' => 'Senha-segura-123'],
            ['atelier'],
        );
        app(TenantManager::class)->set((int) $admin->empresa_id);

        $cliente = Cliente::create(['empresa_id' => $admin->empresa_id, 'nome' => 'Cliente HTTP', 'telefone' => '923456789']);
        $campanha = Campanha::create([
            'empresa_id' => $admin->empresa_id,
            'nome' => 'Campanha HTTP',
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'concluida_com_falhas',
            'enviada_em' => now()->subHour(),
        ]);
        CampanhaEnvio::create([
            'campanha_id' => $campanha->id,
            'cliente_id' => $cliente->id,
            'canal' => 'whatsapp',
            'estado' => CampanhaEnvio::ESTADO_FALHOU,
        ]);

        $rota = route('atelier.campanhas.enviar-agora', $campanha->id);

        $this->actingAs($admin)->post($rota)->assertRedirect()->assertSessionHas('sucesso');
        $this->actingAs($admin)->post($rota)->assertStatus(409);

        app(TenantManager::class)->set((int) $admin->empresa_id);
        Queue::assertPushed(DispararCampanhaJob::class, 1);
        $this->assertSame(1, CampanhaReenvio::query()->where('campanha_id', $campanha->id)->count());
    }
}
