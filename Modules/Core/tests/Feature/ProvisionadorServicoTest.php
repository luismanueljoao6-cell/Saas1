<?php

namespace Modules\Core\Tests\Feature;

use LogicException;
use Modules\Core\Services\TenantProvisionerRegistry;
use Tests\TestCase;

class ProvisionadorServicoTest extends TestCase
{
    public function test_por_omissao_o_servico_e_o_proprio_modulo(): void
    {
        $registry = new TenantProvisionerRegistry;
        $registry->register('estudio', fn (int $id) => null);

        $this->assertSame('estudio', $registry->servicoDe('estudio'));
        $this->assertNull($registry->servicoDe('inexistente'));
    }

    public function test_provisionador_e_acionado_pelo_servico_indicado_e_nao_pela_chave(): void
    {
        $corridos = [];
        $registry = new TenantProvisionerRegistry;
        $registry->register('armazem', function (int $id) use (&$corridos): void {
            $corridos[] = "armazem:$id";
        }, servico: 'faturacao');

        $registry->provision(7, ['atelier']);
        $this->assertSame([], $corridos);

        $registry->provision(7, ['faturacao']);
        $this->assertSame(['armazem:7'], $corridos);
    }

    public function test_dois_modulos_podem_acompanhar_o_mesmo_servico(): void
    {
        $corridos = [];
        $registry = new TenantProvisionerRegistry;
        $registry->register('faturacao', function (int $id) use (&$corridos): void {
            $corridos[] = 'faturacao';
        });
        $registry->register('armazem', function (int $id) use (&$corridos): void {
            $corridos[] = 'armazem';
        }, servico: 'faturacao');

        $registry->provision(1, ['faturacao']);

        $this->assertSame(['faturacao', 'armazem'], $corridos);
    }

    public function test_a_mesma_chave_continua_a_ser_recusada(): void
    {
        $registry = new TenantProvisionerRegistry;
        $registry->register('armazem', fn (int $id) => null, servico: 'faturacao');

        $this->expectException(LogicException::class);
        $registry->register('armazem', fn (int $id) => null, servico: 'faturacao');
    }
}
