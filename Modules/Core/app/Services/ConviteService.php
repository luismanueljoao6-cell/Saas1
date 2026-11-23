<?php

namespace Modules\Core\Services;

use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Core\Contracts\LimitesDaEmpresa;
use Modules\Core\Models\Convite;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Notifications\ConviteDeUtilizador;
use Spatie\Permission\Models\Role;

/**
 * Convites de utilizador por e-mail. O token em claro só existe no e-mail;
 * na BD fica o hash. Cada operação define o contexto da empresa por si
 * (comEmpresa/semTenant), por isso funciona em HTTP, jobs e comandos.
 */
class ConviteService
{
    public const MENSAGEM_INVALIDO = 'Este convite é inválido ou expirou. Pede um novo ao administrador da empresa.';

    public function __construct(
        protected TenantManager $tenants,
        protected LimitesDaEmpresa $limites,
    ) {}

    /**
     * Emite (ou reemite) um convite. Convidar o mesmo e-mail outra vez
     * revoga o convite pendente anterior: nunca há dois pendentes.
     * A autorização (ser administrador) é do chamador.
     */
    public function convidar(User $admin, string $email, ?string $nome, bool $administrador): Convite
    {
        $email = mb_strtolower(trim($email));
        $token = Str::random(64);
        $validadeDias = (int) config('core.convites.validade_dias', 7);

        $convite = $this->tenants->comEmpresa((int) $admin->empresa_id, function () use ($admin, $email, $nome, $administrador, $token, $validadeDias) {
            return DB::transaction(function () use ($admin, $email, $nome, $administrador, $token, $validadeDias) {
                // O bloqueio da empresa serializa pedidos simultâneos (última vaga do plano).
                $empresa = Empresa::query()->lockForUpdate()->findOrFail($admin->empresa_id);

                if (User::query()->where('email', $email)->exists()) {
                    throw new DomainException('Já existe uma conta registada com este e-mail.');
                }

                // Se a validação a seguir falhar, a transação desfaz esta revogação.
                Convite::query()->where('email', $email)->pendentes()->update(['revogado_em' => now()]);

                $limite = $this->limites->limite($empresa, 'max_utilizadores');

                if ($limite !== null && $this->lugaresOcupados($empresa) >= $limite) {
                    throw new DomainException(
                        "O plano atual permite no máximo {$limite} utilizador(es) ativo(s), contando convites pendentes. "
                        .'Revoga um convite, desativa alguém ou passa para um plano superior.'
                    );
                }

                return Convite::create([
                    'empresa_id' => $empresa->id,
                    'email' => $email,
                    'name' => $nome,
                    'administrador' => $administrador,
                    'token_hash' => hash('sha256', $token),
                    'convidado_por' => $admin->id,
                    'expires_at' => now()->addDays($validadeDias),
                ]);
            });
        });

        // Fora da transação: um rollback nunca deixa um e-mail enviado.
        Notification::route('mail', $convite->email)->notify(
            new ConviteDeUtilizador($token, (string) $convite->empresa->nome_comercial, $validadeDias)
        );

        Log::info('Convite de utilizador emitido.', [
            'empresa_id' => $convite->empresa_id,
            'convite_id' => $convite->id,
            'convidado_por' => $admin->id,
        ]);

        return $convite;
    }

    /**
     * Procura o convite pelo token SEM contexto de empresa (quem aceita é
     * um visitante). Só devolve convites pendentes.
     */
    public function pendentePorToken(string $token): Convite
    {
        $convite = $this->tenants->semTenant(fn () => Convite::query()
            ->with('empresa')
            ->where('token_hash', hash('sha256', $token))
            ->pendentes()
            ->first());

        return $convite ?? throw new DomainException(self::MENSAGEM_INVALIDO);
    }

    /**
     * Cria o utilizador a partir do convite. O e-mail é o do convite (não
     * vem do formulário) e fica verificado: quem usou a ligação controla a caixa.
     */
    public function aceitar(string $token, string $nome, string $palavraPasse): User
    {
        $pendente = $this->pendentePorToken($token);

        try {
            return $this->tenants->comEmpresa((int) $pendente->empresa_id, function () use ($pendente, $nome, $palavraPasse) {
                return DB::transaction(function () use ($pendente, $nome, $palavraPasse) {
                    $empresa = Empresa::query()->lockForUpdate()->findOrFail($pendente->empresa_id);

                    // Relê com bloqueio: dois pedidos com o mesmo token não passam ambos.
                    $convite = Convite::query()->pendentes()->lockForUpdate()->find($pendente->id);

                    if ($convite === null || User::query()->where('email', $convite->email)->exists()) {
                        throw new DomainException(self::MENSAGEM_INVALIDO);
                    }

                    $limite = $this->limites->limite($empresa, 'max_utilizadores');

                    if ($limite !== null && $empresa->utilizadores()->where('ativo', true)->count() >= $limite) {
                        throw new DomainException(
                            'A empresa atingiu o limite de utilizadores do plano. Pede ao administrador para libertar uma vaga.'
                        );
                    }

                    $utilizador = User::create([
                        'empresa_id' => $empresa->id,
                        'name' => $nome,
                        'email' => $convite->email,
                        'password' => $palavraPasse,
                    ]);
                    $utilizador->markEmailAsVerified();

                    $this->atribuirPapel($utilizador, (int) $empresa->id, (bool) $convite->administrador);

                    $convite->forceFill(['aceite_em' => now()])->save();

                    Log::info('Convite aceite.', [
                        'empresa_id' => $empresa->id,
                        'convite_id' => $convite->id,
                        'utilizador_id' => $utilizador->id,
                    ]);

                    return $utilizador;
                });
            });
        } catch (UniqueConstraintViolationException) {
            // Corrida: o mesmo e-mail foi registado entre a verificação e o insert.
            throw new DomainException(self::MENSAGEM_INVALIDO);
        }
    }

    /** Idempotente: revogar um convite que já não está pendente não faz nada. */
    public function revogar(User $admin, Convite $convite): void
    {
        if ((int) $convite->empresa_id !== (int) $admin->empresa_id) {
            throw new DomainException('O convite não pertence à tua empresa.');
        }

        $this->tenants->comEmpresa((int) $admin->empresa_id, function () use ($convite): void {
            Convite::query()->whereKey($convite->id)->pendentes()->update(['revogado_em' => now()]);
        });
    }

    protected function lugaresOcupados(Empresa $empresa): int
    {
        return $empresa->utilizadores()->where('ativo', true)->count()
            + Convite::query()->pendentes()->count();
    }

    protected function atribuirPapel(User $utilizador, int $empresaId, bool $administrador): void
    {
        // 'empresa_id' explícito: sem ele o firstOrCreate reutilizaria o papel de outra empresa.
        $papel = Role::firstOrCreate([
            'name' => $administrador ? 'Administrador' : 'Utilizador',
            'guard_name' => 'web',
            'empresa_id' => $empresaId,
        ]);

        // O team do Spatie já está definido por comEmpresa() e é reposto no fim.
        $utilizador->assignRole($papel);
    }
}
