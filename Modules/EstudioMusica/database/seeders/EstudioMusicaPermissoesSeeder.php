<?php

namespace Modules\EstudioMusica\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Empresa;
use Modules\EstudioMusica\Support\PapeisEstudio;

/**
 * Repara/cria os papéis e permissões do Estúdio para TODAS as empresas.
 * A lógica vive em PapeisEstudio (a mesma que corre no registo e ao ativar
 * o serviço); o equivalente por comando é `php artisan core:provisionar-servicos`.
 *
 *   php artisan db:seed --class="Modules\EstudioMusica\Database\Seeders\EstudioMusicaPermissoesSeeder"
 */
class EstudioMusicaPermissoesSeeder extends Seeder
{
    public function run(): void
    {
        Empresa::query()->each(fn (Empresa $empresa) => PapeisEstudio::provisionar($empresa->id));
    }
}
