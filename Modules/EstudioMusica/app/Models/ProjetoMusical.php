<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Support\Dinheiro;

class ProjetoMusical extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $table = 'projetos_musicais';

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'produtor_user_id',
        'engenheiro_user_id',
        'nome',
        'estilo',
        'prazo_entrega',
        'bpm',
        'tom_base',
        'estado',
        'tipo_cobranca',
        'valor_pacote',
        'percentual_sinal',
        'observacoes',
        'concluido_em',
        'destaque_portfolio',
        'link_publico',
    ];

    protected function casts(): array
    {
        return [
            'prazo_entrega' => 'date',
            'valor_pacote' => 'decimal:2',
            'percentual_sinal' => 'decimal:2',
            'concluido_em' => 'datetime',
            'destaque_portfolio' => 'boolean',
        ];
    }

    /**
     * Galeria pública da landing page (secção G) — só projetos que o
     * Administrador marcou explicitamente (a "autorização prévia dos
     * artistas" do requisito) E que têm um link para mostrar.
     */
    public function scopeDestaquesPortfolio($query)
    {
        return $query->where('destaque_portfolio', true)->whereNotNull('link_publico');
    }

    /**
     * Como mostrar `link_publico` na landing, de forma segura: só https,
     * só domínios conhecidos para incorporar (iframe) e qualquer outro
     * endereço https vira uma ligação simples. Devolve null para
     * qualquer coisa inválida (ex.: `javascript:`), que a landing ignora.
     *
     * @return array{tipo: 'spotify'|'youtube'|'link', url: string}|null
     */
    public function incorporacaoPublica(): ?array
    {
        $partes = parse_url(trim((string) $this->link_publico));

        if (! is_array($partes) || ($partes['scheme'] ?? '') !== 'https' || empty($partes['host'])) {
            return null;
        }

        $host = strtolower($partes['host']);
        $caminho = $partes['path'] ?? '';

        if ($host === 'open.spotify.com'
            && preg_match('#^/(track|album|playlist|episode|show|artist)/([A-Za-z0-9]+)#', $caminho, $m)) {
            return ['tipo' => 'spotify', 'url' => "https://open.spotify.com/embed/{$m[1]}/{$m[2]}"];
        }

        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true) && $caminho === '/watch') {
            parse_str($partes['query'] ?? '', $query);

            if (is_string($query['v'] ?? null) && preg_match('/^[A-Za-z0-9_-]{11}$/', $query['v'])) {
                return ['tipo' => 'youtube', 'url' => 'https://www.youtube.com/embed/'.$query['v']];
            }
        }

        if ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{11})$#', $caminho, $m)) {
            return ['tipo' => 'youtube', 'url' => 'https://www.youtube.com/embed/'.$m[1]];
        }

        return ['tipo' => 'link', 'url' => trim((string) $this->link_publico)];
    }

    /**
     * Único ponto de escrita de `estado` — mantém concluido_em coerente com
     * o estado, para o follow-up de 30 dias (secção F) ter uma data de
     * referência fiável: é definido quando o projeto ENTRA em "concluido"
     * e limpo quando sai (um projeto reaberto e concluído outra vez conta
     * os 30 dias a partir da nova conclusão). Nunca faças
     * $projeto->update(['estado' => ...]) diretamente num controller;
     * chama este método.
     */
    public function atualizarEstado(string $novoEstado): void
    {
        $concluidoEm = match (true) {
            $novoEstado === 'concluido' && $this->estado !== 'concluido' => now(),
            $novoEstado !== 'concluido' => null,
            default => $this->concluido_em,
        };

        $this->update([
            'estado' => $novoEstado,
            'concluido_em' => $concluidoEm,
        ]);
    }

    /**
     * withTrashed(): um cliente apagado (soft delete) em Faturação não deve
     * fazer rebentar as páginas dos projetos/sessões antigos — o histórico
     * mantém-se legível. Quem ENVIA mensagens ignora clientes apagados (ver
     * NotificacaoEstudioService::enviar()).
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function produtor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'produtor_user_id');
    }

    public function engenheiro(): BelongsTo
    {
        return $this->belongsTo(User::class, 'engenheiro_user_id');
    }

    public function faixas(): HasMany
    {
        return $this->hasMany(FaixaMusical::class)->orderBy('ordem');
    }

    public function sessoes(): HasMany
    {
        return $this->hasMany(SessaoEstudio::class);
    }

    /**
     * Faturas emitidas para este projeto (sinal e/ou saldo final — ver
     * Services\FaturacaoEstudioService). A tabela pivot é propriedade
     * deste módulo, não do Faturacao — ver a migration correspondente.
     */
    public function faturas(): BelongsToMany
    {
        return $this->belongsToMany(Fatura::class, 'projeto_musical_fatura')
            ->withPivot('tipo')
            ->withTimestamps();
    }

    public function recibos(): BelongsToMany
    {
        return $this->belongsToMany(Recibo::class, 'projeto_musical_recibo')
            ->withTimestamps();
    }

    /**
     * "Projeto aprovado" (secção E: faturação automática "ao aprovar
     * projetos"): tem pelo menos uma faixa e a versão MAIS RECENTE de cada
     * faixa está aprovada pelo cliente. Uma faixa sem nenhuma versão, ou
     * cuja última versão ainda está pendente / com ajustes pedidos, impede
     * a aprovação do projeto todo.
     */
    public function todasAsFaixasAprovadas(): bool
    {
        $faixas = $this->faixas()->with('versoes')->get();

        return $faixas->isNotEmpty()
            && $faixas->every(fn (FaixaMusical $faixa) => $faixa->versoes->first()?->aprovada() === true);
    }

    public function rotuloEstado(): string
    {
        return config('estudiomusica.estados_projeto.'.$this->estado, $this->estado);
    }

    public function percentualSinalEfetivo(): float
    {
        return (float) ($this->percentual_sinal ?? config('estudiomusica.percentual_sinal_padrao'));
    }

    /**
     * Valor total do projeto (SEM IVA) para efeitos de sinal/saldo: o pacote fechado,
     * quando aplicável, ou a soma do que já foi faturado por hora até
     * agora (que só cresce à medida que sessões são cobradas — ver
     * FaturacaoEstudioService::cobrarSessao()).
     */
    public function valorTotalEstimado(): float
    {
        if ($this->tipo_cobranca === 'pacote') {
            return (float) $this->valor_pacote;
        }

        return $this->valorTotalFaturado();
    }

    /**
     * Faturado LÍQUIDO, com IVA: faturas emitidas menos as notas de crédito emitidas sobre
     * elas. Uma fatura emitida nunca muda de estado — corrige-se/anula-se com nota de crédito
     * (ver NotaCreditoDebitoService) — por isso ignorar os créditos deixava o projeto com uma
     * "dívida fantasma" (e sem poder refaturar o que foi anulado).
     */
    public function valorTotalFaturado(): float
    {
        return $this->emKwanzas($this->faturadoCentimos());
    }

    /**
     * Soma do que já foi faturado SEM IVA (líquido de notas de crédito). `valor_pacote` é
     * sempre um valor sem IVA (é o preço unitário das faturas de sinal/saldo, onde o IVA é
     * acrescentado), por isso o saldo a faturar tem de ser calculado nesta mesma base —
     * nunca contra `valor_total` (que já inclui IVA).
     */
    public function valorTotalFaturadoSemIva(): float
    {
        return $this->emKwanzas($this->faturadoSemIvaCentimos());
    }

    /** Total (com IVA) já creditado por notas de crédito emitidas sobre as faturas do projeto. */
    public function valorCreditado(): float
    {
        return $this->emKwanzas($this->resumoDasFaturas()->sum('creditado'));
    }

    /**
     * Recebido: recibos emitidos ligados ao projeto pelo Estúdio (tabela própria) E os
     * recibos emitidos diretamente no Faturação contra faturas do projeto — um pagamento
     * registado no ecrã de Recibos do Faturação também quita o projeto.
     */
    public function valorTotalRecebido(): float
    {
        return $this->emKwanzas($this->recebidoCentimos());
    }

    /**
     * O que o cliente tem de pagar no total, para efeitos de saldo e de
     * quitação. Num projeto por PACOTE é o valor fechado do pacote (com IVA), mesmo
     * que ainda só o sinal esteja faturado — senão um sinal de 40% pago
     * daria "saldo zero" e libertaria os ficheiros com 60% por pagar.
     * Menos o que foi creditado (um desconto dado por nota de crédito baixa o preço), mas
     * nunca menos do que já está faturado (ex.: horas extra). Num projeto por HORA não há
     * total fixo: é o que já foi faturado, sessão a sessão, líquido de créditos.
     */
    public function valorDevido(): float
    {
        return $this->emKwanzas($this->devidoCentimos());
    }

    public function saldoDevedor(): float
    {
        return $this->emKwanzas(max(0, $this->devidoCentimos() - $this->recebidoCentimos()));
    }

    /**
     * "Download de arquivos liberado apenas após a quitação financeira do
     * saldo do projeto" (secção C). Exige que haja algo devido — um projeto
     * sem nada faturado (por hora) ou sem valor de pacote definido não está
     * "quitado", está por cobrar.
     */
    public function financeiramenteQuitado(): bool
    {
        $devido = $this->devidoCentimos();

        return $devido > 0 && $devido - $this->recebidoCentimos() <= 0;
    }

    /**
     * Faturas "vivas": emitidas e NÃO totalmente creditadas. É o que conta para saber se o
     * sinal / o saldo já foram faturados — um sinal anulado por nota de crédito pode (e tem de
     * poder) ser emitido outra vez.
     *
     * @return Collection<int, object{id: int, tipo: ?string, total: int, sem_iva: int, creditado: int, creditado_sem_iva: int}>
     */
    public function faturasAtivas(?string $tipo = null): Collection
    {
        return $this->resumoDasFaturas()
            ->filter(fn (object $f) => $f->creditado < $f->total && ($tipo === null || $f->tipo === $tipo))
            ->values();
    }

    /** Quanto falta receber de UMA fatura (cêntimos): total − notas de crédito − recibos emitidos. */
    public function saldoDaFaturaCentimos(Fatura $fatura): int
    {
        $creditado = NotaCreditoDebito::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->where('fatura_id', $fatura->id)
            ->where('tipo', 'credito')
            ->where('estado', 'emitida')
            ->get(['valor_total'])
            ->sum(fn ($nota) => Dinheiro::centimos($nota->valor_total));

        $recebido = Recibo::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->where('fatura_id', $fatura->id)
            ->where('estado', 'emitido')
            ->get(['valor'])
            ->sum(fn ($recibo) => Dinheiro::centimos($recibo->valor));

        return max(0, Dinheiro::centimos($fatura->valor_total) - $creditado - $recebido);
    }

    /**
     * Situação de cada fatura emitida do projeto, para o ecrã: total, creditado, recebido e
     * o que falta (em Kz). Uma fatura totalmente creditada fica com `em_falta` 0.
     *
     * @return Collection<int, object{fatura: Fatura, tipo: ?string, total: float, creditado: float, recebido: float, em_falta: float}>
     */
    public function situacaoDasFaturas(): Collection
    {
        $faturas = $this->faturas()
            ->where('faturas.estado', 'emitida')
            ->orderBy('faturas.id')
            ->get();

        if ($faturas->isEmpty()) {
            return collect();
        }

        $creditos = NotaCreditoDebito::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->whereIn('fatura_id', $faturas->pluck('id'))
            ->where('tipo', 'credito')
            ->where('estado', 'emitida')
            ->get(['fatura_id', 'valor_total'])
            ->groupBy('fatura_id');

        $recibos = Recibo::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->whereIn('fatura_id', $faturas->pluck('id'))
            ->where('estado', 'emitido')
            ->get(['fatura_id', 'valor'])
            ->groupBy('fatura_id');

        return $faturas->map(function (Fatura $fatura) use ($creditos, $recibos) {
            $total = Dinheiro::centimos($fatura->valor_total);
            $creditado = $creditos->get($fatura->id, collect())->sum(fn ($n) => Dinheiro::centimos($n->valor_total));
            $recebido = $recibos->get($fatura->id, collect())->sum(fn ($r) => Dinheiro::centimos($r->valor));

            return (object) [
                'fatura' => $fatura,
                'tipo' => $fatura->pivot->tipo,
                'total' => $this->emKwanzas($total),
                'creditado' => $this->emKwanzas($creditado),
                'recebido' => $this->emKwanzas($recebido),
                'em_falta' => $this->emKwanzas(max(0, $total - $creditado - $recebido)),
            ];
        })->values();
    }

    /**
     * Faturas EMITIDAS do projeto com o que já foi creditado sobre cada uma (cêntimos).
     *
     * @return Collection<int, object{id: int, tipo: ?string, total: int, sem_iva: int, creditado: int, creditado_sem_iva: int}>
     */
    protected function resumoDasFaturas(): Collection
    {
        $faturas = $this->faturas()
            ->where('faturas.estado', 'emitida')
            ->get(['faturas.id', 'faturas.valor_total', 'faturas.valor_sem_iva']);

        if ($faturas->isEmpty()) {
            return collect();
        }

        $creditos = NotaCreditoDebito::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa_id)
            ->whereIn('fatura_id', $faturas->pluck('id'))
            ->where('tipo', 'credito')
            ->where('estado', 'emitida')
            ->get(['fatura_id', 'valor_total', 'valor_sem_iva'])
            ->groupBy('fatura_id');

        return $faturas->map(function (Fatura $fatura) use ($creditos) {
            $notas = $creditos->get($fatura->id, collect());

            return (object) [
                'id' => (int) $fatura->id,
                'tipo' => $fatura->pivot->tipo,
                'total' => Dinheiro::centimos($fatura->valor_total),
                'sem_iva' => Dinheiro::centimos($fatura->valor_sem_iva),
                'creditado' => $notas->sum(fn ($n) => Dinheiro::centimos($n->valor_total)),
                'creditado_sem_iva' => $notas->sum(fn ($n) => Dinheiro::centimos($n->valor_sem_iva)),
            ];
        })->values();
    }

    public function faturadoCentimos(): int
    {
        return $this->resumoDasFaturas()->sum(fn (object $f) => max(0, $f->total - $f->creditado));
    }

    public function faturadoSemIvaCentimos(): int
    {
        return $this->resumoDasFaturas()->sum(fn (object $f) => max(0, $f->sem_iva - $f->creditado_sem_iva));
    }

    public function recebidoCentimos(): int
    {
        $viaProjeto = $this->recibos()
            ->where('recibos.estado', 'emitido')
            ->get(['recibos.id', 'recibos.valor']);

        $idsFaturas = $this->faturas()->pluck('faturas.id');

        $viaFatura = $idsFaturas->isEmpty()
            ? collect()
            : Recibo::withoutGlobalScopes()
                ->where('empresa_id', $this->empresa_id)
                ->whereIn('fatura_id', $idsFaturas)
                ->where('estado', 'emitido')
                ->get(['id', 'valor']);

        // Um recibo ligado pelas duas vias conta uma só vez.
        return $viaProjeto->concat($viaFatura)
            ->unique('id')
            ->sum(fn ($recibo) => Dinheiro::centimos($recibo->valor));
    }

    public function devidoCentimos(): int
    {
        if ($this->tipo_cobranca === 'pacote') {
            $creditado = $this->resumoDasFaturas()->sum('creditado');

            return max(0, $this->pacoteComIvaCentimos() - $creditado, $this->faturadoCentimos());
        }

        return $this->faturadoCentimos();
    }

    /** O pacote COM IVA, calculado como uma única fatura o calcularia (mesma aritmética do Faturação). */
    public function pacoteComIvaCentimos(): int
    {
        $linha = Dinheiro::calcularLinha(1, (string) ($this->valor_pacote ?? 0), (string) config('faturacao.taxa_iva_geral'));

        return Dinheiro::centimos($linha['total']);
    }

    protected function emKwanzas(int $centimos): float
    {
        return round($centimos / 100, 2);
    }
}
