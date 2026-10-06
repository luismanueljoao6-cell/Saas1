<?php

namespace Modules\Atelier\Services;

use Modules\Core\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Atribui os papéis 'Secretária'/'Costureira' (requisito G) a utilizadores
 * JÁ EXISTENTES da empresa. Não cria contas novas — o Core, hoje, só sabe
 * criar o primeiro utilizador (Administrador) no registo da empresa; convite
 * de novos membros de equipa é uma funcionalidade do Core que ainda não
 * existe (ver README). Entretanto, cria o utilizador via tinker/seeder e usa
 * este serviço só para atribuir o papel.
 *
 * Não chama PermissionRegistrar::setPermissionsTeamId() — IdentificarTenant
 * já o faz para todo o pedido autenticado, antes deste serviço correr.
 */
class EquipaService
{
    public function atribuirPapel(User $utilizador, string $papel): void
    {
        $this->garantirPapelGerido($papel);

        $role = Role::firstOrCreate(['name' => $papel, 'guard_name' => 'web']);

        $utilizador->assignRole($role);
    }

    public function removerPapel(User $utilizador, string $papel): void
    {
        $this->garantirPapelGerido($papel);

        $utilizador->removeRole($papel);
    }

    protected function garantirPapelGerido(string $papel): void
    {
        $geridos = [config('atelier.papel_secretaria'), config('atelier.papel_costureira')];

        if (! in_array($papel, $geridos, true)) {
            throw new \InvalidArgumentException("O papel \"{$papel}\" não é gerido pelo Atelier (só: ".implode(', ', $geridos).').');
        }
    }
}
