<?php

namespace Modules\Faturacao\Tests\Feature;

use Modules\Faturacao\Services\SafTValidadorXsd;
use Tests\TestCase;

class SafTValidadorXsdTest extends TestCase
{
    protected function xsd(string $elementoRaiz, string $tipo): string
    {
        $caminho = sys_get_temp_dir().'/saft-teste-'.uniqid().'.xsd';
        file_put_contents($caminho, '<?xml version="1.0"?>'
            .'<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">'
            .'<xs:element name="'.$elementoRaiz.'" type="'.$tipo.'"/>'
            .'</xs:schema>');
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        return $caminho;
    }

    public function test_sem_xsd_a_validacao_recusa_em_vez_de_dar_como_valido(): void
    {
        config(['faturacao.saft_xsd_path' => sys_get_temp_dir().'/nao-existe-'.uniqid().'.xsd']);
        $validador = app(SafTValidadorXsd::class);

        $this->assertFalse($validador->disponivel());

        $this->expectException(\RuntimeException::class);
        $validador->validar('<AuditFile/>');
    }

    public function test_xml_conforme_ao_xsd_nao_tem_erros(): void
    {
        config(['faturacao.saft_xsd_path' => $this->xsd('AuditFile', 'xs:anyType')]);

        $this->assertSame([], app(SafTValidadorXsd::class)->validar('<AuditFile><Header/></AuditFile>'));
    }

    public function test_xml_nao_conforme_devolve_erros(): void
    {
        config(['faturacao.saft_xsd_path' => $this->xsd('Outro', 'xs:string')]);

        $this->assertNotEmpty(app(SafTValidadorXsd::class)->validar('<AuditFile/>'));
    }

    public function test_xml_mal_formado_devolve_erros(): void
    {
        config(['faturacao.saft_xsd_path' => $this->xsd('AuditFile', 'xs:anyType')]);

        $this->assertNotEmpty(app(SafTValidadorXsd::class)->validar('<AuditFile>'));
    }
}
