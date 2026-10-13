<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Contracts\DocumentoFiscalInterface;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Traits\AssinavelAgt;
use Modules\Faturacao\Traits\Imutavel;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Recibo extends Model implements DocumentoFiscalInterface
{
    use AssinavelAgt;
    use BelongsToTenant;
    use Imutavel;
    use LogsActivity;

    protected $fillable = [
        'empresa_id',
        'serie_id',
        'cliente_id',
        'fatura_id',
        'numero_sequencial',
        'numero_documento',
        'data_emissao',
        'data_hora_sistema',
        'estado',
        'valor',
        'moeda',
        'meio_pagamento',
        'hash',
        'hash_anterior',
        'chave_versao',
    ];

    protected function casts(): array
    {
        return [
            'data_emissao' => 'date',
            'data_hora_sistema' => 'datetime',
            'valor' => 'decimal:2',
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

    // --- DocumentoFiscalInterface -----------------------------------

    public function tipoDocumentoFiscal(): string
    {
        return 'RC';
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
        return (float) $this->valor;
    }

    public function getHashAnterior(): ?string
    {
        return $this->hash_anterior;
    }

    public function estaEmitido(): bool
    {
        return $this->estado === 'emitido';
    }

    /**
     * Objecto JSON canónico assinado em jwsDocumentSignature (API de Facturação
     * Electrónica da AGT). Num recibo, o imposto é o IVA proporcional da fatura
     * regularizada (arredondado por excesso, como o resto do IVA); sem fatura
     * (adiantamento), o valor é líquido e o imposto 0. A AGT apura este total
     * a partir dos documentos de origem — confirmar na homologação.
     */
    public function construirStringParaAssinar(): string
    {
        $bruto = Dinheiro::centimos($this->valor);
        $imposto = 0;

        $fatura = $this->fatura_id
            ? Fatura::withoutGlobalScopes()->where('empresa_id', $this->empresa_id)->find($this->fatura_id)
            : null;

        if ($fatura && Dinheiro::centimos($fatura->valor_total) > 0) {
            $totalFatura = Dinheiro::centimos($fatura->valor_total);
            $imposto = intdiv($bruto * Dinheiro::centimos($fatura->valor_iva) + $totalFatura - 1, $totalFatura);
        }

        return $this->payloadAgtJson($imposto, $bruto - $imposto, $bruto);
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
        $this->estado = 'emitido';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['estado', 'numero_documento', 'valor', 'meio_pagamento'])
            ->logOnlyDirty()
            ->useLogName('recibos');
    }
}
