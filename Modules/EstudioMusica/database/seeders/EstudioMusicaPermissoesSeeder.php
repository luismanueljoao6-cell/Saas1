<?php

namespace Modules\EstudioMusica\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\RegistoEmpresaService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Preenche o que RegistoEmpresaService::atribuirPapelAdministrador() já
 * deixa como TODO explícito no seu próprio docblock: "as permissões
 * associadas a este papel devem ser definidas... por um módulo de gestão
 * de permissões". É esse módulo, para o domínio do EstudioMusica.
 *
 * Corre para TODAS as empresas (idempotente — firstOrCreate em tudo),
 * porque com 'teams' ativo (config/permission.php) o spatie/laravel-permission
 * guarda uma linha de role por empresa, não uma role global partilhada —
 * uma empresa só ganha os papéis "Recepcao"/"Engenheiro" e as permissões
 * do EstudioMusica quando este seeder correr para ela. Reaproveita a
 * mesma role "Administrador" que RegistoEmpresaService já cria no
 * registo da empresa (mesma constante, nunca uma string solta), só lhe
 * acrescenta as permissões deste módulo.
 *
 * Corre com:
 *   php artisan db:seed --class="Modules\EstudioMusica\Database\Seeders\EstudioMusicaPermissoesSeeder"
 */
class EstudioMusicaPermissoesSeeder extends Seeder
{
    /** @var array<int, string> */
    protected array $todasAsPermissoes = [
        'estudiomusica.gerir-salas',
        'estudiomusica.gerir-projetos',
        'estudiomusica.atualizar-estado-projeto',
        'estudiomusica.emitir-cobranca',
        'estudiomusica.gerir-sessoes',
        'estudiomusica.checkin-checkout',
        'estudiomusica.upload-audio',
        'estudiomusica.gerir-marketing',
    ];

    /** @var array<int, string> "Gestão de agenda, check-in, atendimento ao artista, emissão de cobranças" */
    protected array $permissoesRecepcao = [
        'estudiomusica.gerir-projetos',
        'estudiomusica.emitir-cobranca',
        'estudiomusica.gerir-sessoes',
        'estudiomusica.checkin-checkout',
    ];

    /** @var array<int, string> "Calendário de sessões, upload de áudio, atualização de estado dos projetos" */
    protected array $permissoesEngenheiro = [
        'estudiomusica.atualizar-estado-projeto',
        'estudiomusica.upload-audio',
    ];

    public function run(): void
    {
        if (! class_exists(PermissionRegistrar::class)) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);

        Empresa::query()->each(function (Empresa $empresa) use ($registrar): void {
            $registrar->setPermissionsTeamId($empresa->id);
            // O cache de permissões do spatie é global ao processo — ao
            // trocarmos de team dentro deste MESMO comando, sem limpar o
            // cache a 2ª empresa em diante reutilizaria as roles/permissões
            // "vistas" da 1ª. RegistoEmpresaService nunca precisou disto
            // porque só troca de team uma vez por pedido HTTP (um novo
            // processo PHP a cada vez).
            $registrar->forgetCachedPermissions();

            $permissoes = collect($this->todasAsPermissoes)->map(
                fn (string $nome) => Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web'])
            );

            Role::firstOrCreate([
                'name' => RegistoEmpresaService::PAPEL_ADMINISTRADOR,
                'guard_name' => 'web',
            ])->syncPermissions($permissoes);

            Role::firstOrCreate(['name' => 'Recepcao', 'guard_name' => 'web'])
                ->syncPermissions($this->permissoesRecepcao);

            Role::firstOrCreate(['name' => 'Engenheiro', 'guard_name' => 'web'])
                ->syncPermissions($this->permissoesEngenheiro);
        });
    }
}
