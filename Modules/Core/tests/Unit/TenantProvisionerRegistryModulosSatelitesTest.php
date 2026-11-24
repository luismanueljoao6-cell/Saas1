<?php

namespace Modules\Core\Tests\Unit;

use DomainException;
use LogicException;
use Modules\Core\Services\TenantProvisionerRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TenantProvisionerRegistryModulosSatelitesTest extends TestCase
{
    public function test_dois_modulos_no_mesmo_servico_correm_ambos_pela_ordem_de_registo(): void
    {
        $registry = new TenantProvisionerRegistry;
        $chamadas = [];

        $registry->register('faturacao', function (int $id) use (&$chamadas) {
            $chamadas[] = "faturacao:{$id}";
        });
        $registry->register('faturacao', function (int $id) use (&$chamadas) {
            $chamadas[] = "armazem:{$id}";
        }, 'armazem');

        $registry->provision(7, ['faturacao']);

        $this->assertSame(['faturacao:7', 'armazem:7'], $chamadas);
        $this->assertSame(['faturacao'], $registry->modules());
        $this->assertSame(['faturacao' => ['faturacao', 'armazem']], $registry->proprietarios());
    }

    public function test_o_mesmo_modulo_duas_vezes_no_mesmo_servico_e_rejeitado(): void
    {
        $registry = new TenantProvisionerRegistry;
        $registry->register('faturacao', fn (int $id) => null, 'armazem');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('[armazem]');

        $registry->register('faturacao', fn (int $id) => null, 'armazem');
    }

    public function test_registo_simples_repetido_continua_a_ser_rejeitado(): void
    {
        $registry = new TenantProvisionerRegistry;
        $registry->register('estudio', fn (int $id) => null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Já existe um provisionador registado para o módulo [estudio].');

        $registry->register('estudio', fn (int $id) => null);
    }

    public function test_so_corre_os_provisionadores_dos_servicos_ativos(): void
    {
        $registry = new TenantProvisionerRegistry;
        $chamadas = [];

        $registry->register('faturacao', function (int $id) use (&$chamadas) {
            $chamadas[] = 'faturacao';
        }, 'armazem');
        $registry->register('estudio', function (int $id) use (&$chamadas) {
            $chamadas[] = 'estudio';
        });

        $registry->provision(1, ['estudio']);

        $this->assertSame(['estudio'], $chamadas);
    }

    public function test_sem_lista_de_servicos_correm_todos(): void
    {
        $registry = new TenantProvisionerRegistry;
        $chamadas = [];

        $registry->register('faturacao', function (int $id) use (&$chamadas) {
            $chamadas[] = 'faturacao';
        }, 'armazem');
        $registry->register('estudio', function (int $id) use (&$chamadas) {
            $chamadas[] = 'estudio';
        });

        $registry->provision(1);

        $this->assertSame(['faturacao', 'estudio'], $chamadas);
    }

    public function test_falha_de_um_satelite_identifica_o_modulo_e_o_servico_e_preserva_a_causa(): void
    {
        $registry = new TenantProvisionerRegistry;
        $registry->register('faturacao', fn (int $id) => throw new DomainException('boom'), 'armazem');

        try {
            $registry->provision(3, ['faturacao']);
            $this->fail('Era esperada uma RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[armazem]', $e->getMessage());
            $this->assertStringContainsString('[faturacao]', $e->getMessage());
            $this->assertStringContainsString('[3]', $e->getMessage());
            $this->assertInstanceOf(DomainException::class, $e->getPrevious());
        }
    }
}
