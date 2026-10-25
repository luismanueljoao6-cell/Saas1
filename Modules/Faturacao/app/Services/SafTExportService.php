<?php

namespace Modules\Faturacao\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Support\Dinheiro;

/**
 * ESTRUTURA PROVISÓRIA do SAF-T (AO): ainda NÃO validada contra o XSD oficial
 * da AGT (falta, por exemplo, o namespace e o tratamento dos recibos). Tem
 * TaxTable e DocumentTotals, mas só deixa de ser provisória quando o XSD oficial
 * (faturacao.saft_xsd_path) a aceitar — o GerarSafTJob valida com o SafTValidadorXsd e
 * só publica o que passou. Não submeter à AGT sem validação XSD e software
 * certificado — ver README.
 *
 * Todas as queries filtram explicitamente por empresa_id e ignoram a
 * TenantScope, por isso não dependem de tenant ambiente (jobs/comandos).
 */
class SafTExportService
{
    public function gerar(Empresa $empresa, Carbon $inicio, Carbon $fim): string
    {
        if ($this->exportacaoBloqueada()) {
            throw new \RuntimeException('Exportação SAF-T bloqueada em produção: software sem número de certificação AGT configurado.');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $doc->appendChild($doc->createComment(' ESTRUTURA PROVISÓRIA: não validada contra o XSD oficial da AGT. Não submeter sem validação e certificação. '));

        $raiz = $doc->createElement('AuditFile');
        $doc->appendChild($raiz);

        // Os documentos geram-se primeiro: a TaxTable lista os impostos efetivamente usados no período.
        $impostosUsados = [];
        $fonte = $this->documentosFonte($doc, $empresa, $inicio, $fim, $impostosUsados);

        $raiz->appendChild($this->cabecalho($doc, $empresa, $inicio, $fim));
        $raiz->appendChild($this->tabelasMestras($doc, $empresa, $impostosUsados));
        $raiz->appendChild($fonte);

        return $doc->saveXML();
    }

    /** Em produção, o SAF-T fica bloqueado enquanto não houver número de certificação AGT. */
    public function exportacaoBloqueada(): bool
    {
        return app()->isProduction()
            && (string) config('faturacao.saft_numero_certificado', '0') === '0';
    }

    /** Devolve a mensagem de erro do período, ou null se for válido. */
    public function validarPeriodo(Carbon $inicio, Carbon $fim): ?string
    {
        if ($fim->lt($inicio)) {
            return 'A data de fim não pode ser anterior à data de início.';
        }

        // O cabeçalho (FiscalYear) assenta no ano de início: um período a
        // cavalo de dois anos fiscais daria um ficheiro inconsistente.
        if ($inicio->year !== $fim->year) {
            return 'O período tem de estar contido no mesmo ano fiscal.';
        }

        return null;
    }

    /** Texto seguro: escapa automaticamente e remove caracteres inválidos em XML. */
    protected function no(DOMDocument $doc, string $nome, string|int|float|null $valor): DOMElement
    {
        $limpo = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $valor) ?? '';

        $no = $doc->createElement($nome);
        $no->appendChild($doc->createTextNode($limpo));

        return $no;
    }

    protected function cabecalho(DOMDocument $doc, Empresa $empresa, Carbon $inicio, Carbon $fim): DOMElement
    {
        $cab = $doc->createElement('Header');

        $campos = [
            'AuditFileVersion' => '1.0',
            'CompanyID' => $empresa->nif,
            'TaxRegistrationNumber' => $empresa->nif,
            'CompanyName' => $empresa->nome_legal ?: $empresa->nome_comercial,
            'CompanyAddress' => $empresa->morada,
            'FiscalYear' => $inicio->year,
            'StartDate' => $inicio->format('Y-m-d'),
            'EndDate' => $fim->format('Y-m-d'),
            'CurrencyCode' => 'AOA',
            'DateCreated' => now()->format('Y-m-d'),
            'TaxAccountingBasis' => 'F',
            'SoftwareCertificateNumber' => config('faturacao.saft_numero_certificado', '0'),
            'ProductID' => (string) config('faturacao.agt.software.product_id', 'Saas1'),
            'ProductVersion' => (string) config('faturacao.agt.software.product_version', '1.0.0'),
        ];

        foreach ($campos as $nome => $valor) {
            $cab->appendChild($this->no($doc, $nome, $valor));
        }

        return $cab;
    }

    /** @param  array<string, array{0: string, 1: string, 2: string}>  $impostosUsados */
    protected function tabelasMestras(DOMDocument $doc, Empresa $empresa, array $impostosUsados = []): DOMElement
    {
        $tabelas = $doc->createElement('MasterFiles');

        $clientes = Cliente::withoutGlobalScopes()->where('empresa_id', $empresa->id)->orderBy('id')->lazy(200);
        foreach ($clientes as $cliente) {
            $c = $doc->createElement('Customer');
            $c->appendChild($this->no($doc, 'CustomerID', $cliente->id));
            $c->appendChild($this->no($doc, 'CustomerTaxID', $cliente->nif ?: '999999999'));
            $c->appendChild($this->no($doc, 'CompanyName', $cliente->nome));
            $c->appendChild($this->no($doc, 'Address', $cliente->morada));
            $tabelas->appendChild($c);
        }

        $produtos = Produto::withoutGlobalScopes()->where('empresa_id', $empresa->id)->orderBy('id')->lazy(200);
        foreach ($produtos as $produto) {
            $p = $doc->createElement('Product');
            $p->appendChild($this->no($doc, 'ProductCode', $produto->codigo ?: $produto->id));
            $p->appendChild($this->no($doc, 'ProductDescription', $produto->nome));
            $tabelas->appendChild($p);
        }

        $tabelas->appendChild($this->tabelaDeImpostos($doc, $impostosUsados));

        return $tabelas;
    }

    /** @param  array<string, array{0: string, 1: string, 2: string}>  $impostosUsados  [tipo, código, taxa] por chave */
    protected function tabelaDeImpostos(DOMDocument $doc, array $impostosUsados): DOMElement
    {
        $descricoes = ['NOR' => 'Taxa normal', 'INT' => 'Taxa intermédia', 'RED' => 'Taxa reduzida', 'ISE' => 'Isento', 'OUT' => 'Outra taxa', 'NS' => 'Não sujeito'];
        $tabela = $doc->createElement('TaxTable');

        ksort($impostosUsados);

        foreach ($impostosUsados as [$tipo, $codigo, $taxa]) {
            $entrada = $doc->createElement('TaxTableEntry');
            $entrada->appendChild($this->no($doc, 'TaxType', $tipo));
            $entrada->appendChild($this->no($doc, 'TaxCountryRegion', 'AO'));
            $entrada->appendChild($this->no($doc, 'TaxCode', $codigo));
            $entrada->appendChild($this->no($doc, 'Description', $descricoes[$codigo] ?? $codigo));
            $entrada->appendChild($this->no($doc, 'TaxPercentage', $taxa));
            $tabela->appendChild($entrada);
        }

        return $tabela;
    }

    /** @param  array<string, array{0: string, 1: string, 2: string}>  $impostosUsados */
    protected function documentosFonte(DOMDocument $doc, Empresa $empresa, Carbon $inicio, Carbon $fim, array &$impostosUsados): DOMElement
    {
        $fonte = $doc->createElement('SourceDocuments');
        $vendas = $doc->createElement('SalesInvoices');

        $noEntradas = $this->no($doc, 'NumberOfEntries', 0);
        $noDebito = $this->no($doc, 'TotalDebit', '0.00');
        $noCredito = $this->no($doc, 'TotalCredit', '0.00');
        $vendas->appendChild($noEntradas);
        $vendas->appendChild($noDebito);
        $vendas->appendChild($noCredito);

        $entradas = 0;
        $totalDebito = 0;   // linhas de NC (DebitAmount, valores líquidos)
        $totalCredito = 0;  // linhas de FT/ND (CreditAmount, valores líquidos)

        $de = $inicio->toDateString();
        $ate = $fim->toDateString();

        $faturas = Fatura::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->where('estado', 'emitida')
            ->whereBetween('data_emissao', [$de, $ate])
            ->with('linhas')->orderBy('serie_id')->orderBy('numero_sequencial')->lazy(200);

        foreach ($faturas as $f) {
            $vendas->appendChild($this->documento($doc, 'FT', $f, 'CreditAmount', $impostosUsados));
            $totalCredito += Dinheiro::centimos($f->valor_sem_iva);
            $entradas++;
        }

        $notas = NotaCreditoDebito::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->where('estado', 'emitida')
            ->whereBetween('data_emissao', [$de, $ate])
            ->with('linhas')->orderBy('serie_id')->orderBy('numero_sequencial')->lazy(200);

        foreach ($notas as $n) {
            $ehCredito = $n->tipo === 'credito';
            $vendas->appendChild($this->documento($doc, $n->tipoDocumentoFiscal(), $n, $ehCredito ? 'DebitAmount' : 'CreditAmount', $impostosUsados));
            $ehCredito
                ? $totalDebito += Dinheiro::centimos($n->valor_sem_iva)
                : $totalCredito += Dinheiro::centimos($n->valor_sem_iva);
            $entradas++;
        }

        $noEntradas->textContent = (string) $entradas;
        $noDebito->textContent = Dinheiro::formatar($totalDebito);
        $noCredito->textContent = Dinheiro::formatar($totalCredito);

        $fonte->appendChild($vendas);

        return $fonte;
    }

    /** @param  array<string, array{0: string, 1: string, 2: string}>  $impostosUsados */
    protected function documento(DOMDocument $doc, string $tipo, Fatura|NotaCreditoDebito $m, string $campoValor, array &$impostosUsados): DOMElement
    {
        $no = $doc->createElement('Invoice');

        $no->appendChild($this->no($doc, 'InvoiceNo', $m->numero_documento));
        $no->appendChild($this->no($doc, 'InvoiceType', $tipo));
        $no->appendChild($this->no($doc, 'InvoiceStatus', 'N'));
        $no->appendChild($this->no($doc, 'InvoiceDate', $m->data_emissao->format('Y-m-d')));
        $no->appendChild($this->no($doc, 'SystemEntryDate', $m->data_hora_sistema->format('Y-m-d\TH:i:s')));
        $no->appendChild($this->no($doc, 'CustomerID', $m->cliente_id));
        $no->appendChild($this->no($doc, 'Hash', $m->hash));
        $no->appendChild($this->no($doc, 'HashControl', $m->chave_versao));

        foreach ($m->linhas as $linha) {
            [$tipoImposto, $codigoImposto, $motivo] = $this->classificarImposto($m, $linha);
            $taxa = number_format((float) $linha->taxa_iva, 2, '.', '');
            $impostosUsados["{$tipoImposto}|{$codigoImposto}|{$taxa}"] = [$tipoImposto, $codigoImposto, $taxa];

            $l = $doc->createElement('Line');
            $l->appendChild($this->no($doc, 'Description', $linha->descricao));
            $l->appendChild($this->no($doc, 'Quantity', $linha->quantidade));
            $l->appendChild($this->no($doc, 'UnitPrice', Dinheiro::formatar(Dinheiro::centimos($linha->preco_unitario))));
            $l->appendChild($this->no($doc, $campoValor, Dinheiro::formatar(Dinheiro::centimos($linha->valor_sem_iva))));

            $imposto = $doc->createElement('Tax');
            $imposto->appendChild($this->no($doc, 'TaxType', $tipoImposto));
            $imposto->appendChild($this->no($doc, 'TaxCountryRegion', 'AO'));
            $imposto->appendChild($this->no($doc, 'TaxCode', $codigoImposto));
            $imposto->appendChild($this->no($doc, 'TaxPercentage', $taxa));
            $l->appendChild($imposto);

            if ($motivo !== null) {
                $l->appendChild($this->no($doc, 'TaxExemptionCode', $motivo));
            }

            $no->appendChild($l);
        }

        $totais = $doc->createElement('DocumentTotals');
        $totais->appendChild($this->no($doc, 'TaxPayable', Dinheiro::formatar(Dinheiro::centimos($m->valor_iva))));
        $totais->appendChild($this->no($doc, 'NetTotal', Dinheiro::formatar(Dinheiro::centimos($m->valor_sem_iva))));
        $totais->appendChild($this->no($doc, 'GrossTotal', Dinheiro::formatar(Dinheiro::centimos($m->valor_total))));
        $no->appendChild($totais);

        return $no;
    }

    /**
     * Mesma classificação que segue para a AGT (AgtDocumentoBuilder): IVA com código da
     * taxa, isento (IVA/ISE) ou não sujeito (NS); nota de débito é sempre não sujeita.
     *
     * @return array{0: string, 1: string, 2: ?string} [taxType, taxCode, taxExemptionCode]
     */
    protected function classificarImposto(Fatura|NotaCreditoDebito $documento, object $linha): array
    {
        $taxa = (float) $linha->taxa_iva;

        if ($taxa > 0) {
            $codigos = (array) config('faturacao.agt.codigos_taxa', []);

            return ['IVA', (string) ($codigos[number_format($taxa, 2, '.', '')] ?? 'OUT'), null];
        }

        if ($documento instanceof NotaCreditoDebito && $documento->tipo === 'debito') {
            $codigo = strtoupper(trim((string) config('faturacao.agt.nd_codigo_nao_sujeicao')));

            return ['NS', 'NS', $codigo !== '' ? $codigo : null];
        }

        $motivo = strtoupper(trim((string) ($linha->motivo_isencao ?? '')));
        $entrada = ((array) config('faturacao.motivos_isencao', []))[$motivo] ?? null;

        if (is_array($entrada) && ($entrada['tipo'] ?? 'ISE') === 'NS') {
            return ['NS', 'NS', $motivo];
        }

        return ['IVA', 'ISE', $motivo !== '' ? $motivo : null];
    }
}
