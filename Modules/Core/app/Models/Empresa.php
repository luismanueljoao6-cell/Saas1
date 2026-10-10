<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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

    /**
     * Espelha o default da migration — ver Plano::$attributes no módulo
     * Subscrições para a explicação completa de por que isto importa.
     */
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
     * Acesso normal (fora do grace period). Avaliado EM TEMPO REAL: uma
     * subscrição/trial com data de fim já passada deixa de contar como ativa
     * no próprio instante, mesmo que o job diário ainda não tenha corrido.
     * `subscricao_expira_em` nulo = sem data de fim (trial sem limite).
     */
    public function subscricaoAtiva(): bool
    {
        if (! in_array($this->estado_subscricao, ['trial', 'ativa'], true)) {
            return false;
        }

        return $this->subscricao_expira_em === null
            || $this->subscricao_expira_em->isFuture();
    }

    /**
     * Dentro do período de tolerância (leitura sim, emissão não)?
     */
    public function emPeriodoDeTolerancia(): bool
    {
        if ($this->subscricaoAtiva()) {
            return false;
        }

        // Vencida, mas o job diário ainda não a suspendeu: a tolerância
        // conta a partir da data de fim.
        if (in_array($this->estado_subscricao, ['trial', 'ativa'], true) && $this->subscricao_expira_em !== null) {
            $dias = (int) config('core.periodo_tolerancia_dias', 5);

            return $this->subscricao_expira_em->copy()->addDays($dias)->isFuture();
        }

        // Só uma empresa 'suspensa' tem tolerância; 'pendente'/'expirada' não.
        return $this->estado_subscricao === 'suspensa'
            && $this->periodo_tolerancia_ate !== null
            && $this->periodo_tolerancia_ate->isFuture();
    }

    /**
     * Tem, neste momento, qualquer tipo de acesso (ativa ou em tolerância)?
     */
    public function temAcesso(): bool
    {
        return $this->subscricaoAtiva() || $this->emPeriodoDeTolerancia();
    }

    /**
     * Pode emitir novas faturas, recibos, notas de crédito/débito, etc.?
     * Durante o grace period isto é false mesmo que temAcesso() seja true.
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
