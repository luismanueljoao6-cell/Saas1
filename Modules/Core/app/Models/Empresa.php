<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

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
     * Estado 'trial'/'ativa' E data de fim ainda no futuro (ou sem data).
     * Não depende só do job diário: se o scheduler parar, o acesso pleno
     * acaba na mesma na data de fim.
     */
    public function subscricaoAtiva(): bool
    {
        if (! $this->estadoNominalAtivo()) {
            return false;
        }

        return $this->subscricao_expira_em === null || $this->subscricao_expira_em->isFuture();
    }

    /**
     * Acesso de leitura, sem poder emitir. Cobre:
     *  - empresa já suspensa pelo job (periodo_tolerancia_ate no futuro);
     *  - empresa ainda marcada trial/ativa cuja data passou mas o job ainda
     *    não correu: a tolerância conta a partir da data de fim.
     */
    public function emPeriodoDeTolerancia(): bool
    {
        if ($this->subscricaoAtiva()) {
            return false;
        }

        if ($this->periodo_tolerancia_ate !== null) {
            return $this->periodo_tolerancia_ate->isFuture();
        }

        if ($this->estadoNominalAtivo() && $this->subscricao_expira_em !== null) {
            $dias = (int) Config::get('core.periodo_tolerancia_dias', 5);

            return $this->subscricao_expira_em->copy()->addDays($dias)->isFuture();
        }

        return false;
    }

    public function temAcesso(): bool
    {
        return $this->subscricaoAtiva() || $this->emPeriodoDeTolerancia();
    }

    public function podeEmitirFaturas(): bool
    {
        return $this->subscricaoAtiva();
    }

    protected function estadoNominalAtivo(): bool
    {
        return in_array($this->estado_subscricao, ['trial', 'ativa'], true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nome_comercial', 'nif', 'estado_subscricao', 'subscricao_expira_em'])
            ->logOnlyDirty()
            ->useLogName('empresas');
    }
}
