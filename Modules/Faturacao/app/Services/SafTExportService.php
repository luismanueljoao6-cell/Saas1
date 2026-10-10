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
 * da AGT (faltam namespace, TaxTable, DocumentTotals, etc.). Não submeter à AGT
 * sem validação XSD e software certificado — ver README.
 *
 * Todas as queries filtram explicitamente por empresa_id e ignoram a
 * TenantScope, por isso não dependem de tenant ambiente (jobs/comandos).
 */
class SafTExportService
{
    public function gerar(Empresa $empresa, Carbon $inicio, Carbon $fim): string
    {
        if (app()->isProduction() && (string) config('faturacao.saft_numero_certificado', '0') === '0') {
            throw new \RuntimeException('Exportação SAF-T bloqueada em produção: software sem número de certificação AGT configurado.');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $raiz = $doc->createElement('AuditFile');
        $doc->appendChild($raiz);

        $raiz->appendChild($this->cabecalho($doc, $empresa, $inicio, $fim));
        $raiz->appendChild($this->tabelasMestras($doc, $empresa));
        $raiz->appendChild($this->documentosFonte($doc, $empresa, $inicio, $fim));

        return $doc->saveXML();
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
            'ProductID' => 'Plataforma de Gestão/1.0',
        ];

        foreach ($campos as $nome => $valor) {
            $cab->appendChild($this->no($doc, $nome, $valor));
        }

        return $cab;
    }

    protected function tabelasMestras(DOMDocument $doc, Empresa $empresa): DOMElement
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

        return $tabelas;
    }

    protected function documentosFonte(DOMDocument $doc, Empresa $empresa, Carbon $inicio, Carbon $fim): DOMElement
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
            $vendas->appendChild($this->documento($doc, 'FT', $f, 'CreditAmount'));
            $totalCredito += Dinheiro::centimos($f->valor_sem_iva);
            $entradas++;
        }

        $notas = NotaCreditoDebito::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->where('estado', 'emitida')
            ->whereBetween('data_emissao', [$de, $ate])
            ->with('linhas')->orderBy('serie_id')->orderBy('numero_sequencial')->lazy(200);

        foreach ($notas as $n) {
            $ehCredito = $n->tipo === 'credito';
            $vendas->appendChild($this->documento($doc, $n->tipoDocumentoFiscal(), $n, $ehCredito ? 'DebitAmount' : 'CreditAmount'));
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

    protected function documento(DOMDocument $doc, string $tipo, Fatura|NotaCreditoDebito $m, string $campoValor): DOMElement
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
        $no->appendChild($this->no($doc, 'GrossTotal', Dinheiro::formatar(Dinheiro::centimos($m->valor_total))));
        $no->appendChild($this->no($doc, 'NetTotal', Dinheiro::formatar(Dinheiro::centimos($m->valor_sem_iva))));
        $no->appendChild($this->no($doc, 'TaxPayable', Dinheiro::formatar(Dinheiro::centimos($m->valor_iva))));

        foreach ($m->linhas as $linha) {
            $l = $doc->createElement('Line');
            $l->appendChild($this->no($doc, 'Description', $linha->descricao));
            $l->appendChild($this->no($doc, 'Quantity', $linha->quantidade));
            $l->appendChild($this->no($doc, 'UnitPrice', Dinheiro::formatar(Dinheiro::centimos($linha->preco_unitario))));
            $l->appendChild($this->no($doc, $campoValor, Dinheiro::formatar(Dinheiro::centimos($linha->valor_sem_iva))));
            $l->appendChild($this->no($doc, 'TaxPercentage', $linha->taxa_iva));
            $no->appendChild($l);
        }

        return $no;
    }
}
