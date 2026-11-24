<?php

namespace Modules\Core\Services;

use Closure;
use LogicException;
use Modules\Core\Contracts\TenantProvisionerInterface;
use Throwable;

/**
 * Cada módulo regista aqui o que precisa de existir POR EMPRESA (papéis,
 * permissões, dados iniciais) sem o Core o conhecer.
 *
 * Convenção: a chave é o valor do serviço (Servico::Estudio->value = 'estudio'),
 * para o registry só correr o provisionador de serviços ATIVOS. Os
 * provisionadores têm de ser idempotentes (correm no registo, ao ativar o
 * serviço e no comando core:provisionar-servicos).
 *
 * A chave identifica o provisionador (é única) e, por omissão, também o serviço
 * que o aciona. Um módulo que acompanha o serviço de outro (ex.: o Armazém, que
 * acompanha a Faturação) regista-se com a SUA chave e indica o serviço no 3.º
 * argumento, em vez de ocupar a chave desse serviço.
 */
class TenantProvisionerRegistry implements TenantProvisionerInterface
{
    /**
     * @var array<string, Closure(int): void>
     */
    protected array $provisioners = [];

    /**
     * Serviço que aciona cada provisionador (por omissão, o próprio módulo).
     *
     * @var array<string, string>
     */
    protected array $servicos = [];

    public function register(string $module, Closure $provisioner, ?string $servico = null): void
    {
        if (isset($this->provisioners[$module])) {
            throw new LogicException("Já existe um provisionador registado para o módulo [{$module}].");
        }

        $this->provisioners[$module] = $provisioner;
        $this->servicos[$module] = $servico ?? $module;
    }

    /**
     * @param  array<int, string>|null  $servicosAtivos  null = corre todos (compatível com o comportamento antigo)
     *
     * @throws Throwable
     */
    public function provision(int $empresaId, ?array $servicosAtivos = null): void
    {
        foreach ($this->provisioners as $module => $provisioner) {
            if ($servicosAtivos !== null && ! in_array($this->servicos[$module], $servicosAtivos, true)) {
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

    /** Serviço que aciona o provisionador registado sob $module (null se não existir). */
    public function servicoDe(string $module): ?string
    {
        return $this->servicos[$module] ?? null;
    }
}
