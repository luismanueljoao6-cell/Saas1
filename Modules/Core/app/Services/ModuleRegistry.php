<?php

namespace Modules\\Core\\Services;

use Modules\\Core\\Contracts\\ModuleRegistryInterface;
use Nwidart\\Modules\\Facades\\Module as Modules;

/**
 * Adaptador de leitura para o Nwidart Laravel Modules.
 * Não expõe métodos de ativação, desativação ou remoção de módulos.
 */
class ModuleRegistry implements ModuleRegistryInterface
{
    public function all(): array
    {
        return collect(Modules::all())
            ->map(fn ($module): array => $this->describe($module))
            ->values()
            ->all();
    }

    public function find(string $name): ?array
    {
        foreach (Modules::all() as $module) {
            if (
                strcasecmp($module->getName(), $name) === 0
                || strcasecmp((string) $module->get('alias'), $name) === 0
            ) {
                return $this->describe($module);
            }
        }

        return null;
    }

    public function isEnabled(string $name): bool
    {
        return $this->find($name)['enabled'] ?? false;
    }

    /**
     * @return array{name: string, alias: string, description: string, priority: int, enabled: bool}
     */
    private function describe(object $module): array
    {
        return [
            'name' => $module->getName(),
            'alias' => (string) $module->get('alias', ''),
            'description' => $module->getDescription(),
            'priority' => (int) $module->getPriority(),
            'enabled' => $module->isEnabled(),
        ];
    }
}
