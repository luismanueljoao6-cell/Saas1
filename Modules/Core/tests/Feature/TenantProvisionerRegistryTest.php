<?php

namespace Modules\Core\Tests\Feature;

use LogicException;
use Modules\Core\Services\TenantProvisionerRegistry;
use RuntimeException;
use Tests\TestCase;

/**
 * Mecânica do registry com provisionadores de teste (sem base de dados).
 * A idempotência dos provisionadores REAIS está em ProvisionamentoIdempotenteTest.
 */
class TenantProvisionerRegistryTest extends TestCase
{
    public function test_so_corre_os_provisionadores_dos_servicos_ativos(): void
    {
        $corridos = [];
        $registry = new TenantProvisionerRegistry;
        $registry->register('a', function (int $id) use (&$corridos): void {
            $corridos[] = "a:$id";
        });
        $registry->register('b', function (int $id) use (&$corridos): void {
            $corridos[] = "b:$id";
        });

        $registry->provision(7, ['a']);

        $this->assertSame(['a:7'], $corridos);
    }

    public function test_sem_lista_de_servicos_corre_todos(): void
    {
        $corridos = [];
        $registry = new TenantProvisionerRegistry;
        $registry->register('a', function (int $id) use (&$corridos): void {
            $corridos[] = "a:$id";
        });
        $registry->register('b', function (int $id) use (&$corridos): void {
            $corridos[] = "b:$id";
        });

        $registry->provision(3);

        $this->assertSame(['a:3', 'b:3'], $corridos);
    }

    public function test_a_falha_diz_o_modulo_e_a_empresa_preserva_a_causa_e_interrompe(): void
    {
        $seguinteCorreu = false;
        $registry = new TenantProvisionerRegistry;
        $registry->register('a', function (int $id): void {
            throw new RuntimeException('causa real');
        });
        $registry->register('b', function (int $id) use (&$seguinteCorreu): void {
            $seguinteCorreu = true;
        });

        $apanhada = null;

        try {
            $registry->provision(5);
        } catch (RuntimeException $e) {
            $apanhada = $e;
        }

        $this->assertNotNull($apanhada);
        $this->assertSame('Falha ao provisionar o módulo [a] para a empresa [5].', $apanhada->getMessage());
        $this->assertSame('causa real', $apanhada->getPrevious()?->getMessage());
        $this->assertFalse($seguinteCorreu);
    }

    public function test_registo_duplicado_falha_sem_substituir_o_provisionador_original(): void
    {
        $corridos = [];
        $registry = new TenantProvisionerRegistry;

        $registry->register('a', function () use (&$corridos): void {
            $corridos[] = 'primeiro';
        });

        try {
            $registry->register('a', function () use (&$corridos): void {
                $corridos[] = 'segundo';
            });
            $this->fail('Uma chave duplicada não pode substituir o provisionador original.');
        } catch (LogicException $e) {
            $this->assertSame('Já existe um provisionador registado para o módulo [a].', $e->getMessage());
        }

        $registry->provision(1);

        $this->assertSame(['primeiro'], $corridos);
        $this->assertSame(['a'], $registry->modules());
    }
}
