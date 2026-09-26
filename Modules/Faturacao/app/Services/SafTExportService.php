<?php

namespace Modules\Faturacao\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Produto;

/**
 * Gera a ESTRUTURA do ficheiro SAF-T (AO) — cabeçalho, tabelas mestres
 * (clientes, produtos) e os documentos de venda do período. Construída a
 * partir do que é publicamente descrito para o SAF-T (AO) (Decreto
 * Executivo n.º 317/20: "cabeçalho, tabelas mestres e movimentos
 * contabilísticos") e da estrutura comum à família SAF-T lusófona.
 *
 * NÃO tomes isto como validado contra o XSD oficial da AGT — não tive
 * acesso de rede neste ambiente para o descarregar e validar campo a
 * campo. Antes de submeteres um ficheiro real à AGT: (1) confirma cada
 * elemento/atributo contra a especificação técnica atual, (2) corre o
 * ficheiro gerado por um validador XSD, e (3) lembra-te de que, mesmo
 * estruturalmente correto, isto não substitui teres um software
 * certificado pela AGT — ver README.
 */
class SafTExportService
{
    public function gerar(Empresa $empresa, Carbon $inicio, Carbon $fim): string
    {
        $documento = new DOMDocument('1.0', 'UTF-8');
        $documento->formatOutput = true;

        $raiz = $documento->createElement('AuditFile');
        $documento->appendChild($raiz);

        $raiz->appendChild($this->construirCabecalho($documento, $empresa, $inicio, $fim));
        $raiz->appendChild($this->construirTabelasMestras($documento, $empresa));
        $raiz->appendChild($this->construirDocumentosFonte($documento, $empresa, $inicio, $fim));

        return $documento->saveXML();
    }

    protected function construirCabecalho(DOMDocument $doc, Empresa $empresa, Carbon $inicio, Carbon $fim): DOMElement
    {
        $cabecalho = $doc->createElement('Header');

        $campos = [
            'AuditFileVersion' => '1.0',
            'CompanyID' => $empresa->nif,
            'TaxRegistrationNumber' => $empresa->nif,
            'CompanyName' => $empresa->nome_legal ?: $empresa->nome_comercial,
            'CompanyAddress' => $empresa->morada,
            'FiscalYear' => (string) $inicio->year,
            'StartDate' => $inicio->format('Y-m-d'),
            'EndDate' => $fim->format('Y-m-d'),
            'CurrencyCode' => 'AOA',
            'DateCreated' => now()->format('Y-m-d'),
            'TaxAccountingBasis' => 'F', // faturação — confirmar valores válidos no XSD da AGT
            // TODO: preencher com o número de certificação AGT do software
            // assim que o processo de certificação estiver concluído.
            'SoftwareCertificateNumber' => (string) config('faturacao.saft_numero_certificado', '0'),
            'ProductID' => 'Plataforma de Gestão/1.0',
        ];

        foreach ($campos as $nome => $valor) {
            $cabecalho->appendChild($doc->createElement($nome, htmlspecialchars((string) $valor)));
        }

        return $cabecalho;
    }

    protected function construirTabelasMestras(DOMDocument $doc, Empresa $empresa): DOMElement
    {
        $tabelas = $doc->createElement('MasterFiles');

        Cliente::where('empresa_id', $empresa->id)->chunk(200, function ($clientes) use ($doc, $tabelas) {
            foreach ($clientes as $cliente) {
                $no = $doc->createElement('Customer');
                $no->appendChild($doc->createElement('CustomerID', (string) $cliente->id));
                $no->appendChild($doc->createElement('CustomerTaxID', htmlspecialchars($cliente->nif ?: '999999999')));
                $no->appendChild($doc->createElement('CompanyName', htmlspecialchars($cliente->nome)));
                $no->appendChild($doc->createElement('Address', htmlspecialchars($cliente->morada ?: '')));
                $tabelas->appendChild($no);
            }
        });

        Produto::where('empresa_id', $empresa->id)->chunk(200, function ($produtos) use ($doc, $tabelas) {
            foreach ($produtos as $produto) {
                $no = $doc->createElement('Product');
                $no->appendChild($doc->createElement('ProductCode', htmlspecialchars($produto->codigo ?: (string) $produto->id)));
                $no->appendChild($doc->createElement('ProductDescription', htmlspecialchars($produto->nome)));
                $tabelas->appendChild($no);
            }
        });

        return $tabelas;
    }

    protected function construirDocumentosFonte(DOMDocument $doc, Empresa $empresa, Carbon $inicio, Carbon $fim): DOMElement
    {
        $documentosFonte = $doc->createElement('SourceDocuments');
        $faturasNo = $doc->createElement('SalesInvoices');

        $faturas = Fatura::query()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'emitida')
            ->whereBetween('data_emissao', [$inicio->toDateString(), $fim->toDateString()])
            ->orderBy('numero_sequencial')
            ->with('linhas')
            ->get();

        $faturasNo->appendChild($doc->createElement('NumberOfEntries', (string) $faturas->count()));
        $faturasNo->appendChild($doc->createElement('TotalDebit', '0.00'));
        $faturasNo->appendChild($doc->createElement('TotalCredit', number_format((float) $faturas->sum('valor_total'), 2, '.', '')));

        foreach ($faturas as $fatura) {
            $faturasNo->appendChild($this->construirNoFatura($doc, $fatura));
        }

        $documentosFonte->appendChild($faturasNo);

        return $documentosFonte;
    }

    protected function construirNoFatura(DOMDocument $doc, Fatura $fatura): DOMElement
    {
        $no = $doc->createElement('Invoice');

        $no->appendChild($doc->createElement('InvoiceNo', htmlspecialchars($fatura->numero_documento)));
        $no->appendChild($doc->createElement('InvoiceDate', $fatura->data_emissao->format('Y-m-d')));
        $no->appendChild($doc->createElement('SystemEntryDate', $fatura->data_hora_sistema->format('Y-m-d\TH:i:s')));
        $no->appendChild($doc->createElement('CustomerID', (string) $fatura->cliente_id));

        // Hash e HashControl (versão da chave) são exatamente os campos
        // que permitem à AGT validar a cadeia de assinatura descrita em
        // AssinaturaFiscalService — não os omitas nem os simplifiques.
        $no->appendChild($doc->createElement('Hash', htmlspecialchars((string) $fatura->hash)));
        $no->appendChild($doc->createElement('HashControl', (string) $fatura->chave_versao));

        $no->appendChild($doc->createElement('GrossTotal', number_format((float) $fatura->valor_total, 2, '.', '')));
        $no->appendChild($doc->createElement('NetTotal', number_format((float) $fatura->valor_sem_iva, 2, '.', '')));
        $no->appendChild($doc->createElement('TaxPayable', number_format((float) $fatura->valor_iva, 2, '.', '')));

        foreach ($fatura->linhas as $linha) {
            $linhaNo = $doc->createElement('Line');
            $linhaNo->appendChild($doc->createElement('Description', htmlspecialchars($linha->descricao)));
            $linhaNo->appendChild($doc->createElement('Quantity', (string) $linha->quantidade));
            $linhaNo->appendChild($doc->createElement('UnitPrice', number_format((float) $linha->preco_unitario, 2, '.', '')));
            $linhaNo->appendChild($doc->createElement('CreditAmount', number_format((float) $linha->valor_sem_iva, 2, '.', '')));
            $no->appendChild($linhaNo);
        }

        return $no;
    }
}
