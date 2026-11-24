<?php

namespace Modules\Core\Contracts;

use Modules\Core\Exceptions\TenantNaoEncontradoException;

/**
 * Contrato público para saber qual é a empresa (tenant) do contexto atual.
 * Os módulos devem injetar ESTA interface em vez do TenantManager, que é o
 * mecanismo interno do Core (scopes, middleware, jobs).
 */
interface TenantContextInterface
{
    /** Id da empresa atual, ou null se não houver contexto de empresa. */
    public function id(): ?int;

    public function hasTenant(): bool;

    /**
     * Id da empresa atual; falha em segurança (fail-closed) se não existir.
     *
     * @throws TenantNaoEncontradoException
     */
    public function requireTenant(): int;
}
