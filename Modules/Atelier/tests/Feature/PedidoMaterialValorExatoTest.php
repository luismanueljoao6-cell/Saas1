<?php

namespace Modules\Atelier\Tests\Feature;

use Modules\Atelier\Models\PedidoMaterial;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O valor total de um material (quantidade × valor unitário) é guardado em
 * aritmética exata, com arredondamento half up ao cêntimo — o mesmo da fatura.
 * Com float, vários produtos «a meio cêntimo» saíam um cêntimo abaixo do
 * correto (ex.: 47,25 × 72 060,70 = 3 404 868,075 saía 3 404 868,07).
 */
class PedidoMaterialValorExatoTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function produtos(): array
    {
        return [
            'meio cêntimo, arredonda para cima (float dava .07)' => ['47.25', '72060.70', '3404868.08'],
            'outro meio cêntimo (float dava .44)' => ['66.55', '78341.90', '5213653.45'],
            'meio cêntimo pequeno' => ['1.50', '33.33', '50.00'],
            'valor exato sem arredondamento' => ['3.00', '0.10', '0.30'],
            'abaixo de meio cêntimo' => ['0.01', '0.01', '0.00'],
            'preço zero (oferta)' => ['2.00', '0.00', '0.00'],
            'quantidade zero não rebenta' => ['0.00', '10.00', '0.00'],
        ];
    }

    #[DataProvider('produtos')]
    public function test_valor_total_do_material_e_exato(string $quantidade, string $valorUnitario, string $esperado): void
    {
        $material = new PedidoMaterial(['quantidade' => $quantidade, 'valor_unitario' => $valorUnitario]);

        $this->assertSame($esperado, (string) $material->calcularValores()->valor_total);
    }
}
