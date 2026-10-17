<?php

namespace Modules\Faturacao\Tests\Unit;

use Modules\Faturacao\Support\Dinheiro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DinheiroValidacaoTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: mixed, 2: mixed}> */
    public static function entradasInvalidas(): array
    {
        return [
            'quantidade texto' => ['abc', '10.00', 14],
            'preço texto' => [1, 'abc', 14],
            'taxa texto' => [1, '10.00', 'x'],
            'virgula decimal' => [1, '10,50', 14],
            'notacao cientifica' => [1, '1e3', 14],
            'quantidade zero' => [0, '10.00', 14],
            'quantidade negativa' => [-1, '10.00', 14],
            'preço negativo' => [1, '-10.00', 14],
            'taxa negativa' => [1, '10.00', -1],
            'taxa acima de 100' => [1, '10.00', 101],
            'numero gigante' => [1, '99999999999999999999.00', 14],
            'linha acima do limite' => ['999999999', '999999999999.99', 14],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_entradas_invalidas_sao_rejeitadas(mixed $quantidade, mixed $preco, mixed $taxa): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dinheiro::calcularLinha($quantidade, $preco, $taxa);
    }

    public function test_entradas_validas_continuam_a_funcionar(): void
    {
        $this->assertSame('14.00', Dinheiro::calcularLinha(1, '100.00', 14)['iva']);
        $this->assertSame('0.00', Dinheiro::calcularLinha(1, '0.00', 14)['total']);
        $this->assertSame('100.00', Dinheiro::calcularLinha(1, '100.00', 0)['total']);
    }
}
