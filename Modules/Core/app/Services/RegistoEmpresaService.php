<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Notifications\BoasVindasNotification;
use Modules\Core\Support\Servico;
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
     * @param  array<int, string>|null  $servicos  Serviços a que a empresa adere (null = todos; Atelier/Estúdio trazem a Faturação).
     *
     * @throws Throwable Relança qualquer falha após registar o erro, para
     *                   que o controller decida como responder ao utilizador.
     */
    public function registar(array $dadosEmpresa, array $dadosAdmin, ?array $servicos = null): User
    {
        try {
            [$utilizador, $empresa] = DB::transaction(function () use ($dadosEmpresa, $dadosAdmin, $servicos) {
                $diasTrial = (int) config('core.trial_dias', 14);

                $empresa = Empresa::create([
                    ...$dadosEmpresa,
                    'estado_subscricao' => 'trial',
                    'subscricao_expira_em' => $diasTrial > 0 ? now()->addDays($diasTrial) : null,
                ]);

                $utilizador = User::create([
                    'empresa_id' => $empresa->id,
                    'name' => $dadosAdmin['name'],
                    'email' => $dadosAdmin['email'],
                    'password' => Hash::make($dadosAdmin['password']),
                ]);

                $this->atribuirPapelAdministrador($utilizador, $empresa->id);

                $empresa->aderirServicos($servicos ?? Servico::valores());

                Log::info('Nova empresa registada', [
                    'empresa_id' => $empresa->id,
                    'nif' => $empresa->nif,
                    'utilizador_id' => $utilizador->id,
                ]);

                return [$utilizador, $empresa];
            });
        } catch (Throwable $e) {
            Log::error('Falha ao registar nova empresa', [
                'nif' => $dadosEmpresa['nif'] ?? null,
                'email_admin' => $dadosAdmin['email'] ?? null,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Fora da transação: uma falha de e-mail (SMTP em baixo) não pode
        // desfazer o registo de uma empresa já criada.
        try {
            $utilizador->notify(new BoasVindasNotification($empresa));
        } catch (Throwable $e) {
            Log::warning('Registo concluído, mas o e-mail de boas-vindas falhou', [
                'empresa_id' => $empresa->id,
                'erro' => $e->getMessage(),
            ]);
        }

        return $utilizador;
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

        // 'empresa_id' explícito: sem ele o firstOrCreate procura o papel
        // pelo nome em TODAS as empresas e reutilizaria o de outra.
        $papel = Role::firstOrCreate([
            'name' => self::PAPEL_ADMINISTRADOR,
            'guard_name' => 'web',
            'empresa_id' => $empresaId,
        ]);

        $utilizador->assignRole($papel);
    }
}
