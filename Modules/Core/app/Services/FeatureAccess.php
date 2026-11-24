<?php

namespace Modules\Core\Services;

use Modules\Core\Contracts\FeatureAccessInterface;
use Modules\Core\Contracts\TenantContextInterface;
use Modules\Core\Repositories\Contracts\EmpresaRepositoryInterface;
use Modules\Core\Support\Servico;

class FeatureAccess implements FeatureAccessInterface
{
    public function __construct(
        private readonly TenantContextInterface $tenant,
        private readonly EmpresaRepositoryInterface $empresas,
    ) {}

    public function servicoAtivo(Servico|string $servico, ?int $empresaId = null): bool
    {
        $atual = $this->tenant->id();

        // O parâmetro explícito nunca pode substituir a identidade do contexto.
        // Sem tenant, a consulta tem de falhar fechada, mesmo que seja indicado
        // um empresaId; assim evita-se consultar serviços de outra empresa
        // através de contextos globais ou de sistema.
        if ($atual === null || $atual < 1) {
            return false;
        }

        if ($empresaId !== null && $empresaId !== $atual) {
            return false;
        }

        $empresa = $this->empresas->encontrarPorId($atual);

        return $empresa !== null && $empresa->temServico($servico);
    }
}
