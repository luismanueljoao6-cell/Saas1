<?php

namespace Modules\Core\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * Este módulo assume que substitui o model App\Models\User predefinido de
 * uma instalação Laravel nova. Depois de instalar o módulo:
 *
 *   1. Atualiza config/auth.php -> providers.users.model para
 *      Modules\Core\Models\User::class
 *   2. Remove (ou aliasa) o App\Models\User original para evitar conflitos.
 *
 * IMPORTANTE — porque é que este model NÃO usa a trait BelongsToTenant:
 * o login precisa de encontrar um utilizador pelo e-mail ANTES de sabermos
 * qual é a empresa atual (é o próprio utilizador que nos diz isso). Se
 * aplicássemos a TenantScope aqui, a query de login seria filtrada por um
 * tenant que ainda não existe e falharia sempre (fail-closed). Por isso o
 * isolamento de utilizadores por empresa é feito explicitamente nos
 * controllers/repositories (ex.: $empresa->utilizadores()), nunca de forma
 * automática. Todos os RESTANTES models de negócio (faturas, clientes,
 * encomendas...) devem usar BelongsToTenant normalmente.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;
    use HasRoles;
    use LogsActivity;
    use Notifiable;

    /**
     * Espelha os defaults da migration. Sem isto, um User acabado de criar
     * (ex.: Auth::login($novo) no registo, ou actingAs() nos testes) fica
     * com `ativo` a null em memória, e o IdentificarTenant tratá-lo-ia
     * como desativado.
     */
    protected $attributes = [
        'ativo' => true,
        'is_super_admin' => false,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'empresa_id',
        'is_super_admin',
        'ativo',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'ativo' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function temDoisFatoresAtivos(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'ativo'])
            ->logOnlyDirty()
            ->useLogName('utilizadores');
    }
}
