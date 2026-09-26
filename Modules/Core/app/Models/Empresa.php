<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Representa uma empresa/inquilino (tenant) da plataforma.
 *
 * Nota: este model NÃO usa BelongsToTenant — seria circular (a empresa não
 * pertence a si própria). É o "topo" da hierarquia de tenancy.
 */
class Empresa extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'nome_comercial',
        'nome_legal',
        'nif',
        'regime_fiscal',
        'morada',
        'municipio',
        'provincia',
        'telefone',
        'email',
        'logotipo_path',
        'estado_subscricao',
        'subscricao_expira_em',
        'periodo_tolerancia_ate',
        'configuracoes',
    ];

    protected $attributes = [
        'estado_subscricao' => 'trial',
    ];

    protected function casts(): array
    {
        return [
            'subscricao_expira_em' => 'datetime',
            'periodo_tolerancia_ate' => 'datetime',
            'configuracoes' => 'array',
        ];
    }

    public function utilizadores(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * A empresa tem acesso normal à aplicação (fora do grace period)?
     */
    public function subscricaoAtiva(): bool
    {
        return in_array($this->estado_subscricao, ['trial', 'ativa'], true);
    }

    /**
     * Está dentro do período de tolerância (acesso de leitura, sem poder
     * emitir novas faturas)?
     */
    public function emPeriodoDeTolerancia(): bool
    {
        if ($this->subscricaoAtiva()) {
            return false;
        }

        return $this->periodo_tolerancia_ate !== null
            && $this->periodo_tolerancia_ate->isFuture();
    }

    /**
     * A empresa tem, neste momento, qualquer tipo de acesso à aplicação
     * (ativa ou dentro do grace period)? Usado pelo middleware
     * VerificarSubscricaoAtiva para decidir bloquear ou não o pedido.
     */
    public function temAcesso(): bool
    {
        return $this->subscricaoAtiva() || $this->emPeriodoDeTolerancia();
    }

    /**
     * Pode emitir novas faturas, recibos, notas de crédito/débito, etc.?
     * Módulos como o de Faturação devem chamar isto (via Policy/Gate) antes
     * de qualquer operação de escrita crítica — durante o grace period isto
     * é false mesmo que temAcesso() seja true.
     */
    public function podeEmitirFaturas(): bool
    {
        return $this->subscricaoAtiva();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nome_comercial', 'nif', 'estado_subscricao', 'subscricao_expira_em'])
            ->logOnlyDirty()
            ->useLogName('empresas');
    }
}
