<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Jobs\GerarSafTJob;
use Modules\Faturacao\Services\SafTExportService;
use Modules\Faturacao\Services\SafTValidadorXsd;
use Tests\TestCase;

class GerarSafTJobTest extends TestCase
{
    use RefreshDatabase;

    protected string $inicio;

    protected string $fim;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->inicio = now()->startOfMonth()->toDateString();
        $this->fim = now()->toDateString();
    }

    protected function xsd(string $elementoRaiz, string $tipo): string
    {
        $caminho = sys_get_temp_dir().'/saft-job-'.uniqid().'.xsd';
        file_put_contents($caminho, '<?xml version="1.0"?>'
            .'<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">'
            .'<xs:element name="'.$elementoRaiz.'" type="'.$tipo.'"/>'
            .'</xs:schema>');
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        return $caminho;
    }

    protected function empresa(): Empresa
    {
        return Empresa::create(['nome_comercial' => 'Empresa SAF-T Job', 'nif' => '620000001']);
    }

    protected function executar(Empresa $empresa): void
    {
        app(TenantManager::class)->semTenant(function () use ($empresa) {
            (new GerarSafTJob($empresa, $this->inicio, $this->fim))
                ->handle(app(SafTExportService::class), app(SafTValidadorXsd::class));
        });
    }

    protected function principal(Empresa $empresa): string
    {
        return "faturacao/saft/{$empresa->id}/SAFT_{$this->inicio}_{$this->fim}.xml";
    }

    public function test_fora_de_producao_sem_xsd_publica_como_gerado_sem_validacao(): void
    {
        config(['faturacao.saft_xsd_path' => sys_get_temp_dir().'/nao-existe-'.uniqid().'.xsd']);
        $empresa = $this->empresa();

        $this->executar($empresa);

        Storage::disk('local')->assertExists($this->principal($empresa));
        $meta = json_decode(Storage::disk('local')->get("faturacao/saft/{$empresa->id}/meta/SAFT_{$this->inicio}_{$this->fim}.json"), true);
        $this->assertSame(GerarSafTJob::ESTADO_SEM_VALIDACAO, $meta['estado']);
    }

    public function test_xsd_valido_publica_e_preserva_a_versao_anterior(): void
    {
        config(['faturacao.saft_xsd_path' => $this->xsd('AuditFile', 'xs:anyType')]);
        $empresa = $this->empresa();

        $this->executar($empresa);
        $this->executar($empresa);

        Storage::disk('local')->assertExists($this->principal($empresa));
        $this->assertCount(2, Storage::disk('local')->files("faturacao/saft/{$empresa->id}/historico"));

        $meta = json_decode(Storage::disk('local')->get("faturacao/saft/{$empresa->id}/meta/SAFT_{$this->inicio}_{$this->fim}.json"), true);
        $this->assertSame(GerarSafTJob::ESTADO_VALIDADO, $meta['estado']);
    }

    public function test_xml_que_nao_cumpre_o_xsd_nao_e_publicado(): void
    {
        config(['faturacao.saft_xsd_path' => $this->xsd('Outro', 'xs:string')]);
        $empresa = $this->empresa();

        try {
            $this->executar($empresa);
            $this->fail('O job devia falhar com um XML não conforme ao XSD.');
        } catch (\RuntimeException) {
            // esperado
        }

        Storage::disk('local')->assertMissing($this->principal($empresa));
        $this->assertNotEmpty(Storage::disk('local')->files("faturacao/saft/{$empresa->id}/rejeitados"));
    }

    public function test_em_producao_sem_xsd_nao_publica(): void
    {
        $this->app['env'] = 'production';
        config([
            'faturacao.saft_numero_certificado' => '12345',
            'faturacao.saft_xsd_path' => sys_get_temp_dir().'/nao-existe-'.uniqid().'.xsd',
        ]);
        $empresa = $this->empresa();

        try {
            $this->executar($empresa);
            $this->fail('O job devia falhar em produção sem o XSD oficial.');
        } catch (\RuntimeException) {
            // esperado
        }

        Storage::disk('local')->assertMissing($this->principal($empresa));
    }
}
