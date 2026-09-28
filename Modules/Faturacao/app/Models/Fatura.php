<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Contracts\DocumentoFiscalInterface;
use Modules\Faturacao\Traits\Imutavel;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Fatura extends Model implements DocumentoFiscalInterface
{
    use BelongsToTenant;
    use Imutavel;
    use LogsActivity;

    protected $fillable = [
        'empresa_id',
        'serie_id',
        'cliente_id',
        'numero_sequencial',
        'numero_documento',
        'data_emissao',
        'data_hora_sistema',
        'estado',
        'valor_sem_iva',
        'valor_iva',
        'valor_total',
        'moeda',
        'hash',
        'hash_anterior',
        'chave_versao',
        'observacoes',
    ];

    protected function casts(): array
    {
        return [
            'data_emissao' => 'date',
            'data_hora_sistema' => 'datetime',
            'valor_sem_iva' => 'decimal:2',
            'valor_iva' => 'decimal:2',
            'valor_total' => 'decimal:2',
        ];
    }

    public function serie(): BelongsTo
    {
        return $this->belongsTo(Serie::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(FaturaLinha::class)->orderBy('ordem');
    }

    public function notasCreditoDebito(): HasMany
    {
        return $this->hasMany(NotaCreditoDebito::class);
    }

    // --- DocumentoFiscalInterface -----------------------------------

    public function tipoDocumentoFiscal(): string
    {
        return 'FT';
    }

    public function getSerieId(): int
    {
        return $this->serie_id;
    }

    public function getEmpresaId(): int
    {
        return $this->empresa_id;
    }

    public function getNumeroSequencial(): ?int
    {
        return $this->numero_sequencial;
    }

    public function getValorTotalDocumento(): float
    {
        return (float) $this->valor_total;
    }

    public function getHashAnterior(): ?string
    {
        return $this->hash_anterior;
    }

    public function estaEmitido(): bool
    {
        return $this->estado === 'emitida';
    }

    /**
     * Formato reconstruído a partir da família de especificações SAF-T
     * lusófona a que o mecanismo da AGT está alinhado (Portaria 363/2010):
     * data;data-hora-sistema;identificador-documento;valor-total;hash-anterior.
     * CONFIRMA isto contra a documentação técnica atual da AGT antes de
     * assinares um único documento real com esta chave — ver README.
     */
    public function construirStringParaAssinar(): string
    {
        return implode(';', [
            $this->data_emissao->format('Y-m-d'),
            $this->data_hora_sistema->format('Y-m-d\TH:i:s'),
            $this->numero_documento,
            number_format((float) $this->valor_total, 2, '.', ''),
            $this->hash_anterior ?? '',
        ]);
    }

    public function prepararParaEmissao(
        int $numeroSequencial,
        string $numeroDocumento,
        \DateTimeInterface $dataHoraSistema,
        ?string $hashAnterior,
    ): void {
        $this->numero_sequencial = $numeroSequencial;
        $this->numero_documento = $numeroDocumento;
        $this->data_emissao = $dataHoraSistema;
        $this->data_hora_sistema = $dataHoraSistema;
        $this->hash_anterior = $hashAnterior;
    }

    public function confirmarEmissao(string $hash, int $chaveVersao): void
    {
        $this->hash = $hash;
        $this->chave_versao = $chaveVersao;
        $this->estado = 'emitida';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estado', 'numero_documento', 'valor_total', 'cliente_id'])
            ->logOnlyDirty()
            ->useLogName('faturas');
    }
}
