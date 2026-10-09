<?php
 
namespace Modules\Core\Services;

use Closure;
use Throwable;

class TenantProvisionerRegistry
{
    /**
     * @var array<string, Closure(int): void>
     */
    protected array $provisioners = [];

    /**
     * Regista um provisionador fornecido por um módulo.
     *
     * O módulo permanece desacoplado do Core:
     * apenas fornece uma operação que recebe o ID da empresa.
     */
    public function register(string $module, Closure $provisioner): void
    {
        $this->provisioners[$module] = $provisioner;
    }

    /**
     * Executa os provisionadores de todos os módulos ativos.
     *
     * @throws Throwable
     */
    public function provision(int $empresaId): void
    {
        foreach ($this->provisioners as $module => $provisioner) {
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
     * Retorna os módulos que possuem provisionador registado.
     *
     * @return array<int, string>
     */
    public function modules(): array
    {
        return array_keys($this->provisioners);
    }
}
