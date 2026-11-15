<?php

namespace Modules\Faturacao\Tests\Feature;

use InvalidArgumentException;
use Modules\Faturacao\Support\Dinheiro;
use PHPUnit\Framework\TestCase;

class DinheiroPercentagemMinutosTest extends TestCase
{
    public function test_percentagem_arredonda_a_meio_para_cima(): void
    {
        $this->assertSame(40000, Dinheiro::percentagem(100000, '40'));
        $this->assertSame(1, Dinheiro::percentagem(1, '50'));
        $this->assertSame(0, Dinheiro::percentagem(1, '49.99'));
        $this->assertSame(3333, Dinheiro::percentagem(10000, '33.33'));
    }

    public function test_percentagem_acima_de_100_e_valores_negativos_nao_falham(): void
    {
        $this->assertSame(150, Dinheiro::percentagem(100, '150'));
        $this->assertSame(0, Dinheiro::percentagem(-5, '10'));
    }

    public function test_percentagem_recusa_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::percentagem(PHP_INT_MAX, '100');
    }

    public function test_por_minutos_calcula_ao_centimo_meio_para_cima(): void
    {
        $this->assertSame(2000, Dinheiro::porMinutos(20, 6000));
        $this->assertSame(1, Dinheiro::porMinutos(30, 1));
        $this->assertSame(0, Dinheiro::porMinutos(29, 1));
        $this->assertSame(0, Dinheiro::porMinutos(-10, 6000));
    }

    public function test_por_minutos_recusa_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::porMinutos(PHP_INT_MAX, 2);
    }
}
