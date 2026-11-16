<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Modules\Armazem\Support\QuantidadeDecimal;
use PHPUnit\Framework\TestCase;

class ArmazemQuantidadeDecimalTest extends TestCase
{
    public function test_compara_quantidades_proximas_ao_limite_decimal_sem_perder_precisao(): void
    {
        $this->assertSame(
            -1,
            QuantidadeDecimal::comparar('99999999999.998', '99999999999.999'),
        );
        $this->assertSame(
            0,
            QuantidadeDecimal::comparar('99999999999.999', '99999999999.999'),
        );
    }

    public function test_formata_quantidades_grandes_sem_converter_para_float(): void
    {
        $this->assertSame(
            '99.999.999.999,999',
            QuantidadeDecimal::formatar('99999999999.999', ',', '.'),
        );
        $this->assertSame(
            '99999999999.999',
            QuantidadeDecimal::formatar('99999999999.999'),
        );
    }

    public function test_formata_variacoes_negativas_e_zeros(): void
    {
        $this->assertSame('-1.234,125', QuantidadeDecimal::formatar('-1234.125', ',', '.'));
        $this->assertSame('0,000', QuantidadeDecimal::formatar('0.000', ','));
    }

    public function test_rejeita_precisao_ou_intervalo_fora_do_contrato(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QuantidadeDecimal::formatar('100000000000.000');
    }
}
