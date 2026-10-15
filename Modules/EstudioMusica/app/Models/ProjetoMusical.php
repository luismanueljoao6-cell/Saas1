<?php

namespace Modules\EstudioMusica\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;

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

    public function valorTotalFaturado(): float
    {
        return (float) $this->faturas()
            ->where('faturas.estado', 'emitida')
            ->sum('faturas.valor_total');
    }

    /**
     * Soma do que já foi faturado SEM IVA. `valor_pacote` é sempre um valor
     * sem IVA (é o preço unitário das faturas de sinal/saldo, onde o IVA é
     * acrescentado), por isso o saldo a faturar tem de ser calculado nesta
     * mesma base — nunca contra `valor_total` (que já inclui IVA).
     */
    public function valorTotalFaturadoSemIva(): float
    {
        return (float) $this->faturas()
            ->where('faturas.estado', 'emitida')
            ->sum('faturas.valor_sem_iva');
    }

    public function valorTotalRecebido(): float
    {
        return (float) $this->recibos()
            ->where('recibos.estado', 'emitido')
            ->sum('recibos.valor');
    }

    /**
     * O que o cliente tem de pagar no total, para efeitos de saldo e de
     * quitação. Num projeto por PACOTE é o valor fechado do pacote, mesmo
     * que ainda só o sinal esteja faturado — senão um sinal de 40% pago
     * daria "saldo zero" e libertaria os ficheiros com 60% por pagar.
     * (max() cobre o caso de se ter faturado mais do que o pacote, ex.:
     * horas extra.) Num projeto por HORA não há total fixo: é o que já foi
     * faturado, sessão a sessão.
     */
    public function valorDevido(): float
    {
        if ($this->tipo_cobranca === 'pacote') {
            // O cliente paga o pacote COM IVA (valor_pacote é sem IVA).
            $taxa = (float) config('faturacao.taxa_iva_geral');
            $pacoteComIva = round((float) $this->valor_pacote * (1 + $taxa / 100), 2);

            return max($pacoteComIva, $this->valorTotalFaturado());
        }

        return $this->valorTotalFaturado();
    }

    public function saldoDevedor(): float
    {
        return max(0.0, round($this->valorDevido() - $this->valorTotalRecebido(), 2));
    }

    /**
     * "Download de arquivos liberado apenas após a quitação financeira do
     * saldo do projeto" (secção C). Exige que haja algo devido — um projeto
     * sem nada faturado (por hora) ou sem valor de pacote definido não está
     * "quitado", está por cobrar.
     */
    public function financeiramenteQuitado(): bool
    {
        return $this->valorDevido() > 0.0 && $this->saldoDevedor() <= 0.0;
    }
}
