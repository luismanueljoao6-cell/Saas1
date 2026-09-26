<?php

namespace Modules\Faturacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\BelongsToTenant;
use Modules\Faturacao\Contracts\DocumentoFiscalInterface;
use Modules\Faturacao\Traits\Imutavel;

class Recibo extends Model implements DocumentoFiscalInterface
{
    use BelongsToTenant;
    use Imutavel;

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

    public function construirStringParaAssinar(): string
    {
        return implode(';', [
            $this->data_emissao->format('Y-m-d'),
            $this->data_hora_sistema->format('Y-m-d\TH:i:s'),
            $this->numero_documento,
            number_format((float) $this->valor, 2, '.', ''),
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
        $this->estado = 'emitido';
    }
}
