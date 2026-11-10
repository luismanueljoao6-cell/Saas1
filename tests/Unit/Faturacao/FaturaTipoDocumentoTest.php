<?php

namespace Tests\Unit\Faturacao;

use Modules\Faturacao\Models\Fatura;
use Tests\TestCase;

class FaturaTipoDocumentoTest extends TestCase
{
    public function test_fatura_sem_tipo_continua_a_ser_ft(): void
    {
        $this->assertSame('FT', (new Fatura)->tipoDocumentoFiscal());
    }

    public function test_fatura_de_adiantamento_devolve_fa(): void
    {
        $this->assertSame('FA', (new Fatura(['tipo_documento' => 'FA']))->tipoDocumentoFiscal());
    }

    public function test_config_declara_o_tipo_fa(): void
    {
        $this->assertArrayHasKey('FA', config('faturacao.tipos_documento'));
    }
}
