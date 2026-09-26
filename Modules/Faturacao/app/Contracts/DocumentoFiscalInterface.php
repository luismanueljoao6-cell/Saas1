<?php

namespace Modules\Faturacao\Contracts;

/**
 * A AGT exige o mesmo tratamento (numeração sequencial sem lacunas +
 * assinatura RSA encadeada) para fatura, nota de crédito/débito, recibo e
 * outros documentos de transporte — não é uma regra exclusiva da fatura.
 * Este contrato deixa o NumeracaoService e o AssinaturaFiscalService
 * completamente agnósticos ao model concreto: hoje aplica-se a Fatura,
 * NotaCreditoDebito e Recibo; amanhã, a qualquer novo tipo de documento
 * que precises de acrescentar, basta implementar isto.
 */
interface DocumentoFiscalInterface
{
    public function tipoDocumentoFiscal(): string; // 'FT', 'NC', 'ND', 'RC'

    public function getSerieId(): int;

    public function getEmpresaId(): int;

    public function getNumeroSequencial(): ?int;

    public function getValorTotalDocumento(): float;

    public function getHashAnterior(): ?string;

    public function estaEmitido(): bool;

    /**
     * String canónica usada como entrada da assinatura RSA. Cada model
     * decide os seus próprios campos (data, número, valores, hash
     * anterior) — ver AssinaturaFiscalService::assinar() para a construção
     * exata e o aviso sobre confirmar o formato contra a AGT.
     */
    public function construirStringParaAssinar(): string;

    /**
     * Fase 1: define numeração, datas e o hash do documento anterior — o
     * suficiente para construirStringParaAssinar() já produzir a string
     * final. NÃO altera 'estado' ainda (o documento só passa a emitido
     * depois de confirmarEmissao(), já com o hash calculado).
     */
    public function prepararParaEmissao(
        int $numeroSequencial,
        string $numeroDocumento,
        \DateTimeInterface $dataHoraSistema,
        ?string $hashAnterior,
    ): void;

    /**
     * Fase 2: aplica o resultado da assinatura RSA e só agora marca o
     * documento como emitido. Chamado exclusivamente pelo
     * AssinaturaFiscalService.
     */
    public function confirmarEmissao(string $hash, int $chaveVersao): void;
}
