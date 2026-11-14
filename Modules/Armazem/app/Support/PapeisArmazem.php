<?php

namespace Modules\\Armazem\\Support;

use Modules\\Core\\Services\\RegistoEmpresaService;
use Spatie\\Permission\\Models\\Permission;
use Spatie\\Permission\\Models\\Role;
use Spatie\\Permission\\PermissionRegistrar;

/**
 * Permissões do Armazém. As permissões são globais; os papéis são
 * provisionados por empresa através do team_id empresa_id do Spatie.
 */
final class PapeisArmazem
{
    public const PERMISSOES = [
        'armazem.ver',
        'armazem.gerir',
        'armazem.inventarios.ver',
        'armazem.inventarios.criar',
        'armazem.inventarios.contar',
        'armazem.inventarios.concluir',
        'armazem.inventarios.cancelar',
        'armazem.transferencias.ver',
        'armazem.transferencias.criar',
        'armazem.transferencias.expedir',
        'armazem.transferencias.receber',
        'armazem.transferencias.cancelar',
    ];

    public static function provisionar(int $empresaId): void
    {
        if (! class_exists(PermissionRegistrar::class)) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $equipaAnterior = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($empresaId);
            $registrar->forgetCachedPermissions();

            $permissoes = collect(self::PERMISSOES)->map(
                fn (string $nome) => Permission::firstOrCreate([
                    'name' => $nome,
                    'guard_name' => 'web',
                ])
            );

            $papel = Role::firstOrCreate([
                'name' => RegistoEmpresaService::PAPEL_ADMINISTRADOR,
                'guard_name' => 'web',
                'empresa_id' => $empresaId,
            ]);

            // Garante que Administradores existentes recebem também as novas permissões.
            $papel->syncPermissions($permissoes);
        } finally {
            $registrar->setPermissionsTeamId($equipaAnterior);
            $registrar->forgetCachedPermissions();
        }
    }
}
