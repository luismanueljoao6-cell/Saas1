<?php

namespace Modules\Core\Services;

use Closure;
use Throwable;

/**
 * Cada módulo regista aqui o que precisa de existir POR EMPRESA (papéis,
 * permissões, dados iniciais) sem o Core o conhecer.
 *
 * Convenção: a chave é o valor do serviço (Servico::Estudio->value = 'estudio'),
 * para o registry só correr o provisionador de serviços ATIVOS. Os
 * provisionadores têm de ser idempotentes (correm no registo, ao ativar o
 * serviço e no comando core:provisionar-servicos).
 */
class TenantProvisionerRegistry
{
    /**
     * @var array<string, Closure(int): void>
     */
    protected array $provisioners = [];

    public function register(string $module, Closure $provisioner): void
    {
        $this->provisioners[$module] = $provisioner;
    }

    /**
     * @param  array<int, string>|null  $servicosAtivos  null = corre todos (compatível com o comportamento antigo)
     *
     * @throws Throwable
     */
    public function provision(int $empresaId, ?array $servicosAtivos = null): void
    {
        foreach ($this->provisioners as $module => $provisioner) {
            if ($servicosAtivos !== null && ! in_array($module, $servicosAtivos, true)) {
                continue;
            }

            try {
                $provisioner($empresaId);
            } catch (Throwable $e) {
                throw new \RuntimeException(
                    "Falha ao provisionar o módulo [{$module}] para a empresa [{$empresaId}].",
                    previous: $e,
                );
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function modules(): array
    {
        return array_keys($this->provisioners);
    }
}
