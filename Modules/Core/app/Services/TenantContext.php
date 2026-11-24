<?php

namespace Modules\Core\Services;

use Illuminate\Contracts\Container\Container;
use Modules\Core\Contracts\TenantContextInterface;
use Modules\Core\Exceptions\TenantNaoEncontradoException;

/**
 * Adaptador sem estado sobre o TenantManager. Resolve o TenantManager (scoped)
 * a cada chamada, para nunca reter o tenant de um pedido/job anterior em
 * workers persistentes.
 */
class TenantContext implements TenantContextInterface
{
    public function __construct(private readonly Container $container) {}

    public function id(): ?int
    {
        return $this->manager()->id();
    }

    public function hasTenant(): bool
    {
        return $this->manager()->has();
    }

    public function requireTenant(): int
    {
        $id = $this->manager()->id();

        if ($id === null) {
            throw new TenantNaoEncontradoException;
        }

        return $id;
    }

    private function manager(): TenantManager
    {
        return $this->container->make(TenantManager::class);
    }
}
