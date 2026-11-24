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
        // A identificação explícita de uma empresa nunca substitui o contexto
        // autenticado. Sem tenant não há autorização para consultar adesões.
        $atual = $this->tenant->id();

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
