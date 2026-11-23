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

        if ($empresaId !== null && $atual !== null && $empresaId !== $atual) {
            return false;
        }

        $id = $empresaId ?? $atual;

        if ($id === null || $id < 1) {
            return false;
        }

        $empresa = $this->empresas->encontrarPorId($id);

        return $empresa !== null && $empresa->temServico($servico);
    }
}
