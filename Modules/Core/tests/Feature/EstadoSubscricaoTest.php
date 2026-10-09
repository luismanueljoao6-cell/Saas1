<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Tests\TestCase;

class EstadoSubscricaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['core.periodo_tolerancia_dias' => 5]);
    }

    protected function empresa(string $nif, array $atributos): Empresa
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);
        $empresa->update($atributos);

        return $empresa->fresh();
    }

    public function test_trial_vencido_perde_emissao_mas_mantem_tolerancia(): void
    {
        $e = $this->empresa('800000001', ['estado_subscricao' => 'trial', 'subscricao_expira_em' => now()->subDay()]);

        $this->assertFalse($e->subscricaoAtiva());
        $this->assertTrue($e->emPeriodoDeTolerancia());
        $this->assertTrue($e->temAcesso());
        $this->assertFalse($e->podeEmitirFaturas());
    }

    public function test_trial_vencido_alem_da_tolerancia_fica_sem_acesso_sem_depender_do_job(): void
    {
        $e = $this->empresa('800000002', ['estado_subscricao' => 'trial', 'subscricao_expira_em' => now()->subDays(30)]);

        $this->assertFalse($e->temAcesso());
    }

    public function test_subscricao_ativa_vencida_perde_acesso_depois_da_tolerancia(): void
    {
        $e = $this->empresa('800000003', ['estado_subscricao' => 'ativa', 'subscricao_expira_em' => now()->subDays(10)]);

        $this->assertFalse($e->temAcesso());
    }

    public function test_estado_expirado_nao_tem_acesso_mesmo_com_tolerancia_futura(): void
    {
        $e = $this->empresa('800000004', ['estado_subscricao' => 'expirada', 'periodo_tolerancia_ate' => now()->addDays(3)]);

        $this->assertFalse($e->temAcesso());
    }

    public function test_suspensa_dentro_da_tolerancia_tem_acesso_sem_emitir(): void
    {
        $e = $this->empresa('800000005', ['estado_subscricao' => 'suspensa', 'periodo_tolerancia_ate' => now()->addDays(3)]);

        $this->assertTrue($e->temAcesso());
        $this->assertFalse($e->podeEmitirFaturas());
    }

    public function test_trial_sem_data_de_fim_continua_ativo(): void
    {
        $e = $this->empresa('800000006', ['estado_subscricao' => 'trial', 'subscricao_expira_em' => null]);

        $this->assertTrue($e->subscricaoAtiva());
        $this->assertTrue($e->podeEmitirFaturas());
    }
}
