<?php

namespace Modules\EstudioMusica\Support;

use Modules\Core\Services\RegistoEmpresaService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Papéis e permissões do Estúdio, por empresa. Com 'teams' ativo cada
 * empresa tem as SUAS linhas de role: o 'empresa_id' tem de ir SEMPRE
 * explícito no firstOrCreate (o Eloquent não passa pelo create() do
 * Spatie, que é quem preencheria a equipa sozinho).
 *
 * Idempotente: pode correr no registo, ao ativar o serviço e em reparação.
 */
final class PapeisEstudio
{
    public const RECECAO = 'Recepcao';

    public const ENGENHEIRO = 'Engenheiro';

    /** Papéis que o Administrador pode atribuir no ecrã de equipa. */
    public const ATRIBUIVEIS = [self::RECECAO, self::ENGENHEIRO];

    public const PERMISSOES = [
        'estudiomusica.gerir-salas',
        'estudiomusica.gerir-projetos',
        'estudiomusica.atualizar-estado-projeto',
        'estudiomusica.emitir-cobranca',
        'estudiomusica.gerir-sessoes',
        'estudiomusica.checkin-checkout',
        'estudiomusica.upload-audio',
        'estudiomusica.gerir-marketing',
    ];

    /** Agenda, check-in, atendimento ao artista, emissão de cobranças. */
    public const PERMISSOES_RECECAO = [
        'estudiomusica.gerir-projetos',
        'estudiomusica.emitir-cobranca',
        'estudiomusica.gerir-sessoes',
        'estudiomusica.checkin-checkout',
    ];

    /** Calendário de sessões, upload de áudio, estado dos projetos. */
    public const PERMISSOES_ENGENHEIRO = [
        'estudiomusica.atualizar-estado-projeto',
        'estudiomusica.upload-audio',
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
                fn (string $nome) => Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web'])
            );

            self::papel(RegistoEmpresaService::PAPEL_ADMINISTRADOR, $empresaId)->syncPermissions($permissoes);
            self::papel(self::RECECAO, $empresaId)->syncPermissions(self::PERMISSOES_RECECAO);
            self::papel(self::ENGENHEIRO, $empresaId)->syncPermissions(self::PERMISSOES_ENGENHEIRO);
        } finally {
            // Nunca deixa a equipa/cache de outra empresa "vazar" para o chamador.
            $registrar->setPermissionsTeamId($equipaAnterior);
            $registrar->forgetCachedPermissions();
        }
    }

    public static function papel(string $nome, int $empresaId): Role
    {
        return Role::firstOrCreate([
            'name' => $nome,
            'guard_name' => 'web',
            'empresa_id' => $empresaId,
        ]);
    }
}
