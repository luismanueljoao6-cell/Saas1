<?php

namespace Modules\Core\Contracts;

/**
 * Catálogo público e somente de leitura dos módulos instalados.
 * A ativação/desativação fica fora deste contrato para não permitir que
 * módulos de negócio alterem o bootstrap da aplicação.
 */
interface ModuleRegistryInterface
{
    /**
     * @return array<int, array{name: string, alias: string, description: string, priority: int, enabled: bool}>
     */
    public function all(): array;

    /**
     * Procura por nome ou alias; devolve null quando não existe.
     *
     * @return array{name: string, alias: string, description: string, priority: int, enabled: bool}|null
     */
    public function find(string $name): ?array;

    public function isEnabled(string $name): bool;
}
