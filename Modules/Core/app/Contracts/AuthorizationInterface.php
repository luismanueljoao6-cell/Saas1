<?php

namespace Modules\Core\Contracts;

use Illuminate\Auth\Access\Response;

/**
 * Contrato público de autorização. Os módulos consultam capacidades através
 * do Core, que delega no Gate do Laravel e nas políticas registadas.
 */
interface AuthorizationInterface
{
    /**
     * Verifica uma capacidade sem lançar exceção.
     *
     * @param  mixed  $arguments  Modelo, ID ou argumentos da política.
     */
    public function allows(string $ability, mixed $arguments = []): bool;

    /**
     * Autoriza uma capacidade ou devolve a resposta de negação do Laravel.
     *
     * @param  mixed  $arguments
     */
    public function authorize(string $ability, mixed $arguments = []): Response;
}
