<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Notifications\BoasVindasNotification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Nome do papel atribuído automaticamente ao primeiro utilizador de uma
 * empresa nova. As permissões associadas a este papel devem ser definidas
 * pelo CoreDatabaseSeeder ou por um módulo de gestão de permissões.
 */
class RegistoEmpresaService
{
    public const PAPEL_ADMINISTRADOR = 'Administrador';

    /**
     * @param  array{nome_comercial: string, nif: string, email?: string|null, telefone?: string|null}  $dadosEmpresa
     * @param  array{name: string, email: string, password: string}  $dadosAdmin
     *
     * @throws Throwable Relança qualquer falha após registar o erro, para
     *                    que o controller decida como responder ao utilizador.
     */
    public function registar(array $dadosEmpresa, array $dadosAdmin): User
    {
        try {
            return DB::transaction(function () use ($dadosEmpresa, $dadosAdmin) {
                $empresa = Empresa::create([
                    ...$dadosEmpresa,
                    'estado_subscricao' => 'trial',
                ]);

                $utilizador = User::create([
                    'empresa_id' => $empresa->id,
                    'name' => $dadosAdmin['name'],
                    'email' => $dadosAdmin['email'],
                    'password' => Hash::make($dadosAdmin['password']),
                ]);

                $this->atribuirPapelAdministrador($utilizador, $empresa->id);

                $utilizador->notify(new BoasVindasNotification($empresa));

                Log::info('Nova empresa registada', [
                    'empresa_id' => $empresa->id,
                    'nif' => $empresa->nif,
                    'utilizador_id' => $utilizador->id,
                ]);

                return $utilizador;
            });
        } catch (Throwable $e) {
            Log::error('Falha ao registar nova empresa', [
                'nif' => $dadosEmpresa['nif'] ?? null,
                'email_admin' => $dadosAdmin['email'] ?? null,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Cria o papel "Administrador" para esta empresa (se ainda não existir
     * neste tenant, já que spatie/laravel-permission com teams isola papéis
     * por empresa_id) e atribui-o ao utilizador.
     */
    protected function atribuirPapelAdministrador(User $utilizador, int $empresaId): void
    {
        if (! class_exists(PermissionRegistrar::class)) {
            // spatie/laravel-permission ainda não foi instalado — ver README.
            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($empresaId);

        $papel = Role::firstOrCreate([
            'name' => self::PAPEL_ADMINISTRADOR,
            'guard_name' => 'web',
        ]);

        $utilizador->assignRole($papel);
    }
}
