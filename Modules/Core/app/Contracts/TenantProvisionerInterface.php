<?php

namespace Modules\Core\Contracts;

use Closure;

/**
 * Registo e execução dos provisionadores por empresa. Cada módulo regista o
 * que precisa de existir POR EMPRESA (papéis, permissões, dados iniciais).
 * Os provisionadores registados têm de ser idempotentes.
 */
interface TenantProvisionerInterface
{
    /**
     * @param  Closure(int): void  $provisioner
     */
    public function register(string $module, Closure $provisioner): void;

    /**
     * @param  array<int, string>|null  $servicosAtivos  null = corre todos
     */
    public function provision(int $empresaId, ?array $servicosAtivos = null): void;

    /**
     * @return array<int, string>
     */
    public function modules(): array;
}
