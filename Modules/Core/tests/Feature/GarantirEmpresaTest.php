<?php

namespace Modules\Core\Tests\Feature;

use DomainException;
use Modules\Core\Services\TenantManager;
use PHPUnit\Framework\TestCase;

class GarantirEmpresaTest extends TestCase
{
    public function test_sem_tenant_nao_restringe(): void
    {
        (new TenantManager)->garantirEmpresa(1);

        $this->addToAssertionCount(1);
    }

    public function test_tenant_igual_passa(): void
    {
        $tenant = new TenantManager;
        $tenant->set(7);
        $tenant->garantirEmpresa(7);

        $this->addToAssertionCount(1);
    }

    public function test_tenant_diferente_e_recusado_com_a_mensagem_indicada(): void
    {
        $tenant = new TenantManager;
        $tenant->set(7);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('mensagem propria');

        $tenant->garantirEmpresa(8, 'mensagem propria');
    }

    public function test_dentro_de_sem_tenant_nao_restringe_e_depois_volta_a_restringir(): void
    {
        $tenant = new TenantManager;
        $tenant->set(7);

        $tenant->semTenant(fn () => $tenant->garantirEmpresa(8));

        $this->expectException(DomainException::class);
        $tenant->garantirEmpresa(8);
    }
}
