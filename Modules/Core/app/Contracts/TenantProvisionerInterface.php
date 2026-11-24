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
     * @param  string  $module  identifica o provisionador (único)
     * @param  Closure(int): void  $provisioner
     * @param  string|null  $servico  serviço cuja ativação o aciona; por omissão, o próprio $module
     */
    public function register(string $module, Closure $provisioner, ?string $servico = null): void;

    /**
     * @param  array<int, string>|null  $servicosAtivos  null = corre todos
     */
    public function provision(int $empresaId, ?array $servicosAtivos = null): void;

    /**
     * @return array<int, string>
     */
    public function modules(): array;

    /** Serviço que aciona o provisionador registado sob $module (null se não existir). */
    public function servicoDe(string $module): ?string;
}
