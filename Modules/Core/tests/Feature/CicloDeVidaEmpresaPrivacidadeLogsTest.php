<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\CicloDeVidaEmpresaService;
use Tests\TestCase;

/**
 * Os logs operacionais do ciclo de vida não devem duplicar identificadores fiscais.
 */
class CicloDeVidaEmpresaPrivacidadeLogsTest extends TestCase
{
    use RefreshDatabase;

    private static int $nif = 630000000;

    protected function empresa(): Empresa
    {
        $nif = (string) self::$nif++;

        return Empresa::create([
            'nome_comercial' => 'Empresa de teste '.$nif,
            'nif' => $nif,
        ]);
    }

    public function test_eliminacao_regista_apenas_o_identificador_interno_da_empresa(): void
    {
        $empresa = $this->empresa();

        Log::spy();

        app(CicloDeVidaEmpresaService::class)->eliminar($empresa->id);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Empresa eliminada logicamente.', [
                'empresa_id' => $empresa->id,
            ]);
    }

    public function test_restauro_regista_apenas_o_identificador_interno_da_empresa(): void
    {
        $empresa = $this->empresa();
        $servico = app(CicloDeVidaEmpresaService::class);
        $servico->eliminar($empresa->id);

        Log::spy();

        $servico->restaurar($empresa->id);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Empresa restaurada.', [
                'empresa_id' => $empresa->id,
            ]);
    }
}
