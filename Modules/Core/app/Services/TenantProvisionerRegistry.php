<?php

namespace Modules\Core\Services;

use Closure;
use LogicException;
use Modules\Core\Contracts\TenantProvisionerInterface;
use RuntimeException;
use Throwable;

/**
 * Cada módulo regista aqui o que precisa de existir POR EMPRESA (papéis,
 * permissões, dados iniciais) sem o Core o conhecer.
 *
 * Convenção: a chave é o valor do serviço (Servico::Estudio->value = 'estudio'),
 * para o registry só correr o provisionador de serviços ATIVOS. Um módulo que
 * acompanha um serviço sem ser um Servico (o Armazém acompanha a Faturação)
 * regista-se na chave do serviço com o seu nome como terceiro argumento.
 * Os provisionadores têm de ser idempotentes (correm no registo, ao ativar o
 * serviço e no comando core:provisionar-servicos).
 */
class TenantProvisionerRegistry implements TenantProvisionerInterface
{
    /**
     * Serviço => [módulo => provisionador].
     *
     * @var array<string, array<string, Closure(int): void>>
     */
    protected array $provisioners = [];

    /**
     * @param  string  $module  Valor do serviço a que o provisionador se associa.
     * @param  string|null  $proprietario  Módulo que regista, quando difere do serviço
     *                                     (por omissão, o próprio serviço).
     *
     * @throws LogicException se esse módulo já registou um provisionador para o serviço
     */
    public function register(string $module, Closure $provisioner, ?string $proprietario = null): void
    {
        $proprietario ??= $module;

        if (isset($this->provisioners[$module][$proprietario])) {
            throw new LogicException(
                $proprietario === $module
                    ? "Já existe um provisionador registado para o módulo [{$module}]."
                    : "Já existe um provisionador do módulo [{$proprietario}] registado para o serviço [{$module}]."
            );
        }

        $this->provisioners[$module][$proprietario] = $provisioner;
    }

    /**
     * @param  array<int, string>|null  $servicosAtivos  null = corre todos (compatível com o comportamento antigo)
     *
     * @throws Throwable
     */
    public function provision(int $empresaId, ?array $servicosAtivos = null): void
    {
        foreach ($this->provisioners as $module => $doServico) {
            if ($servicosAtivos !== null && ! in_array($module, $servicosAtivos, true)) {
                continue;
            }

            foreach ($doServico as $proprietario => $provisioner) {
                try {
                    $provisioner($empresaId);
                } catch (Throwable $e) {
                    $rotulo = $proprietario === $module
                        ? "[{$module}]"
                        : "[{$proprietario}] (serviço [{$module}])";

                    throw new RuntimeException(
                        "Falha ao provisionar o módulo {$rotulo} para a empresa [{$empresaId}].",
                        previous: $e,
                    );
                }
            }
        }
    }

    /**
     * Serviços com pelo menos um provisionador.
     *
     * @return array<int, string>
     */
    public function modules(): array
    {
        return array_keys($this->provisioners);
    }

    /**
     * Serviço => módulos que o provisionam, pela ordem de registo.
     *
     * @return array<string, array<int, string>>
     */
    public function proprietarios(): array
    {
        return array_map(fn (array $doServico) => array_keys($doServico), $this->provisioners);
    }
}
