<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Contracts\DocumentoFiscalInterface;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Traits\AssinavelAgt;
use Modules\Faturacao\Traits\Imutavel;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class NotaCreditoDebito extends Model implements DocumentoFiscalInterface
{
    use AssinavelAgt;
    use BelongsToTenant;
    use Imutavel;
    use LogsActivity;

    protected $table = 'notas_credito_debito';

    protected $fillable = [
        'empresa_id',
        'serie_id',
        'cliente_id',
        'fatura_id',
        'tipo',
        'motivo',
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

    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Fatura::class);
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(NotaCreditoDebitoLinha::class)->orderBy('ordem');
    }

    // --- DocumentoFiscalInterface -----------------------------------

    public function tipoDocumentoFiscal(): string
    {
        return $this->tipo === 'debito' ? 'ND' : 'NC';
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
     * Objecto JSON canónico assinado em jwsDocumentSignature (API de Facturação
     * Electrónica da AGT): documentNo, taxRegistrationNumber, documentType,
     * documentDate, customerTaxID, customerCountry, companyName e
     * documentTotals. Ver Support/PayloadAssinaturaAgt.
     */
    public function construirStringParaAssinar(): string
    {
        return $this->payloadAgtJson(
            Dinheiro::centimos($this->valor_iva),
            Dinheiro::centimos($this->valor_sem_iva),
            Dinheiro::centimos($this->valor_total),
        );
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
            ->logOnly(['estado', 'tipo', 'numero_documento', 'valor_total', 'fatura_id', 'motivo'])
            ->logOnlyDirty()
            ->useLogName('notas_credito_debito');
    }
}
