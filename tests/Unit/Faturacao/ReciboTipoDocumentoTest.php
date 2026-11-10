<?php

namespace Tests\Unit\Faturacao;

use Modules\Faturacao\Models\Recibo;
use Tests\TestCase;

class ReciboTipoDocumentoTest extends TestCase
{
    public function test_recibo_sem_tipo_continua_a_ser_rc(): void
    {
        $this->assertSame('RC', (new Recibo)->tipoDocumentoFiscal());
    }

    public function test_recibo_de_regularizacao_devolve_rg(): void
    {
        $this->assertSame('RG', (new Recibo(['tipo_documento' => 'RG']))->tipoDocumentoFiscal());
    }

    public function test_config_declara_o_tipo_rg(): void
    {
        $this->assertArrayHasKey('RG', config('faturacao.tipos_documento'));
    }
}
