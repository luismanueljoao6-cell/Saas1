<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Http\Requests\GuardarFaturaRequest;
use Modules\Faturacao\Services\SafTExportService;
use Tests\TestCase;

class SafTRegrasEValidacaoTest extends TestCase
{
    public function test_periodo_que_atravessa_anos_e_rejeitado(): void
    {
        $erro = app(SafTExportService::class)->validarPeriodo(Carbon::parse('2025-12-15'), Carbon::parse('2026-01-15'));

        $this->assertNotNull($erro);
    }

    public function test_periodo_dentro_do_mesmo_ano_e_aceite(): void
    {
        $this->assertNull(app(SafTExportService::class)->validarPeriodo(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')));
    }

    public function test_fim_anterior_ao_inicio_e_rejeitado(): void
    {
        $this->assertNotNull(app(SafTExportService::class)->validarPeriodo(Carbon::parse('2026-05-10'), Carbon::parse('2026-05-01')));
    }

    public function test_saft_bloqueado_em_producao_sem_certificado(): void
    {
        $this->app['env'] = 'production';
        config(['faturacao.saft_numero_certificado' => '0']);
        $this->assertTrue(app(SafTExportService::class)->exportacaoBloqueada());

        config(['faturacao.saft_numero_certificado' => '12345']);
        $this->assertFalse(app(SafTExportService::class)->exportacaoBloqueada());

        $this->app['env'] = 'testing';
        config(['faturacao.saft_numero_certificado' => '0']);
        $this->assertFalse(app(SafTExportService::class)->exportacaoBloqueada());
    }

    public function test_gerar_continua_a_recusar_em_producao_sem_certificado(): void
    {
        $this->app['env'] = 'production';
        config(['faturacao.saft_numero_certificado' => '0']);

        $this->expectException(\RuntimeException::class);
        app(SafTExportService::class)->gerar(new Empresa, now()->startOfMonth(), now());
    }

    public function test_pedido_de_fatura_limita_casas_decimais(): void
    {
        $request = new GuardarFaturaRequest;
        $request->setUserResolver(fn () => (object) ['empresa_id' => 1]);
        $regras = $request->rules();

        $falha = fn (string $campo, string $valor) => Validator::make(['v' => $valor], ['v' => $regras[$campo]])->fails();

        $this->assertTrue($falha('linhas.*.quantidade', '1.2345'));
        $this->assertFalse($falha('linhas.*.quantidade', '1.234'));
        $this->assertTrue($falha('linhas.*.preco_unitario', '10.005'));
        $this->assertFalse($falha('linhas.*.preco_unitario', '10.50'));
    }
}
