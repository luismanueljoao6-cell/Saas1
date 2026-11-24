<?php

namespace Modules\Core\Services;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Modules\Core\Contracts\AuthorizationInterface;

/** Adaptador do mecanismo de políticas e permissões do Laravel. */
class Authorization implements AuthorizationInterface
{
    public function __construct(private readonly Gate $gate) {}

    public function allows(string $ability, mixed $arguments = []): bool
    {
        return $this->gate->allows($ability, $arguments);
    }

    public function authorize(string $ability, mixed $arguments = []): Response
    {
        return $this->gate->authorize($ability, $arguments);
    }
}
