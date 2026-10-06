<?php

namespace Modules\Atelier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;

/**
 * O Pedido de Costura/Peça. Ao contrário de Fatura/Recibo, este model NÃO
 * usa a trait Imutavel — é um registo operacional que precisa de ser
 * editável ao longo do fluxo (mudar de estado, reatribuir responsável,
 * ajustar prazos). A única imutabilidade real que importa aqui é a dos
 * DOCUMENTOS FISCAIS que este pedido gera (fatura_id, recibo_sinal_id,
 * recibo_saldo_final_id) — esses sim, protegidos pela Imutavel dentro do
 * próprio módulo Faturacao; o Pedido só guarda a referência.
 *
 * Transições de estado são validadas em PedidoStatusService, não aqui —
 * mantém o model simples e a regra de negócio num único sítio testável.
 */
class Pedido extends Model
{
    use BelongsToTenant;

    protected $table = 'atelier_pedidos';

    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'medida_id',
        'responsavel_id',
        'tipo_servico',
        'descricao',
        'tecido_cor',
        'aviamentos_necessarios',
        'status',
        'motivo_cancelamento',
        'data_prevista_entrega',
        'prazo_interno',
        'valor_orcamento',
        'taxa_iva_aplicada',
        'percentual_sinal',
        'valor_sinal',
        'recibo_sinal_id',
        'fatura_id',
        'recibo_saldo_final_id',
    ];

    protected $attributes = [
        'status' => 'pendente',
    ];

    protected function casts(): array
    {
        return [
            'data_prevista_entrega' => 'date',
            'prazo_interno' => 'date',
            'valor_orcamento' => 'decimal:2',
            'taxa_iva_aplicada' => 'decimal:2',
            'percentual_sinal' => 'decimal:2',
            'valor_sinal' => 'decimal:2',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function medida(): BelongsTo
    {
        return $this->belongsTo(Medida::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(PedidoFoto::class);
    }

    public function materiais(): HasMany
    {
        return $this->hasMany(PedidoMaterial::class)->orderBy('ordem');
    }

    public function provas(): HasMany
    {
        return $this->hasMany(Prova::class);
    }

    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Fatura::class);
    }

    public function reciboSinal(): BelongsTo
    {
        return $this->belongsTo(Recibo::class, 'recibo_sinal_id');
    }

    public function reciboSaldoFinal(): BelongsTo
    {
        return $this->belongsTo(Recibo::class, 'recibo_saldo_final_id');
    }

    public function estaFinalizado(): bool
    {
        return in_array($this->status, config('atelier.estados_finais'), true);
    }

    public function jaTemSinalRegistado(): bool
    {
        return $this->recibo_sinal_id !== null;
    }

    public function jaTemFaturaFinal(): bool
    {
        return $this->fatura_id !== null;
    }

    /**
     * Saldo em falta para a entrega, depois de descontado o sinal já pago.
     * Usado tanto na view do pedido como por PedidoFaturacaoService ao
     * emitir o recibo do saldo final.
     */
    public function valorTotalComIva(): float
    {
        $semIva = (float) $this->valor_orcamento + (float) $this->materiais->sum('valor_total');

        return round($semIva * (1 + ((float) $this->taxa_iva_aplicada / 100)), 2);
    }

    public function saldoEmFalta(): float
    {
        return round($this->valorTotalComIva() - (float) ($this->valor_sinal ?? 0), 2);
    }

    public function tipoServicoRotulo(): string
    {
        return config("atelier.tipos_servico.{$this->tipo_servico}", $this->tipo_servico);
    }

    public function statusRotulo(): string
    {
        return self::rotuloParaStatus($this->status);
    }

    public static function rotuloParaStatus(string $status): string
    {
        return match ($status) {
            'pendente' => 'Pendente',
            'em_corte' => 'Em Corte',
            'em_costura' => 'Em Costura',
            'primeira_prova' => 'Primeira Prova',
            'ajustes' => 'Ajustes',
            'pronto_para_retirada' => 'Pronto para Retirada',
            'entregue' => 'Entregue',
            'cancelado' => 'Cancelado',
            default => $status,
        };
    }
}
