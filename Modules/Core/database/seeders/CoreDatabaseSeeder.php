<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Semeia as permissões base do Core. Como o spatie/laravel-permission está
 * configurado com 'teams' (isolamento por empresa_id), estas permissões são
 * globais (não dependem de team_id), mas os PAPÉIS (roles) são criados por
 * empresa — nesta seed apenas garantimos que as permissões em si existem;
 * o papel "Administrador" de cada empresa é criado automaticamente no
 * registo (ver RegistoEmpresaService).
 *
 * Corre com: php artisan module:seed Core
 */
class CoreDatabaseSeeder extends Seeder
{
    protected array $permissoesBase = [
        'empresa.ver',
        'empresa.editar',
        'utilizadores.ver',
        'utilizadores.convidar',
        'utilizadores.gerir',
    ];

    public function run(): void
    {
        if (! class_exists(Permission::class)) {
            $this->command?->warn('spatie/laravel-permission não está instalado — seeder ignorado. Ver README do módulo Core.');

            return;
        }

        try {
            foreach ($this->permissoesBase as $permissao) {
                Permission::firstOrCreate(['name' => $permissao, 'guard_name' => 'web']);
            }

            $this->command?->info('Permissões base do Core criadas/confirmadas: '.implode(', ', $this->permissoesBase));
        } catch (Throwable $e) {
            Log::error('Falha ao semear permissões base do Core', ['erro' => $e->getMessage()]);

            throw $e;
        }
    }
}
