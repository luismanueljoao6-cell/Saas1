<?php

namespace Modules\Faturacao\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\FaturaLinha;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\NotaCreditoDebitoLinha;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Models\Recibo;
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
    private const NAMESPACE_SAFT = 'urn:OECD:StandardAuditFile-Tax:AO_1.01_01';

    public function gerar(Empresa $empresa, Carbon $inicio, Carbon $fim): string
    {
        $erroPeriodo = $this->validarPeriodo($inicio, $fim);

        if ($erroPeriodo !== null) {
            throw new \InvalidArgumentException($erroPeriodo);
        }

        if ($this->exportacaoBloqueada()) {
            throw new \RuntimeException('Exportação SAF-T bloqueada em produção: software sem número de certificação AGT configurado.');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $raiz = $doc->createElementNS(self::NAMESPACE_SAFT, 'AuditFile');
        $doc->appendChild($raiz);

        $raiz->appendChild($this->cabecalho($doc, $empresa, $inicio, $fim));
        $raiz->appendChild($this->tabelasMestras($doc, $empresa));
        $raiz->appendChild($this->documentosFonte($doc, $empresa, $inicio, $fim));

        return $doc->saveXML();
    }

    /**
     * Gera o ficheiro e só o devolve se passar o XSD versionado.
     *
     * Usar esta entrada nos fluxos que não podem aceitar um XML estruturalmente
     * inválido. O método gerar() mantém-se disponível para compatibilidade e
     * diagnóstico durante a migração; não deve ser tratado como exportação
     * SAF-T validada.
     *
     * @throws \RuntimeException quando o XML não passar a validação XSD
     */
    public function gerarValidado(Empresa $empresa, Carbon $inicio, Carbon $fim): string
    {
        $problemas = $this->validarConfiguracaoObrigatoria($empresa);
        if ($problemas !== []) {
            throw new \RuntimeException("Exportação SAF-T bloqueada por falta de dados fiscais obrigatórios:\n- ".implode("\n- ", $problemas));
        }

        $xml = $this->gerar($empresa, $inicio, $fim);
        $erros = $this->validarXmlContraXsd($xml);

        if ($erros !== []) {
            throw new \RuntimeException(
                "O XML SAF-T gerado não passou na validação XSD. A exportação foi interrompida.\n- "
                .implode("\n- ", $erros)
            );
        }

        return $xml;
    }

    /**
     * Falha fechada quando os dados de identificação não permitem construir um
     * ficheiro fiscal fiável. Não inventar NIF, conta contabilística, cidade
     * ou classificação de produtos para satisfazer o XSD.
     *
     * @return list<string>
     */
    protected function validarConfiguracaoObrigatoria(Empresa $empresa): array
    {
        $problemas = [];
        $obrigatorios = [
            'NIF do produtor' => config('faturacao.saft_nif_produtor'),
            'identificação do produto no formato Nome da aplicação/Produtor' => config('faturacao.saft_product_id'),
            'conta contabilística de clientes' => config('faturacao.saft_conta_clientes'),
        ];
        foreach ($obrigatorios as $campo => $valor) {
            if (! is_string($valor) || trim($valor) === '') $problemas[] = 'Configure '.$campo.' no ambiente.';
        }
        if (trim((string) ($empresa->nif ?? '')) === '') $problemas[] = 'A empresa não tem NIF.';
        if (trim((string) ($empresa->nome_legal ?: $empresa->nome_comercial)) === '') $problemas[] = 'A empresa não tem nome legal/comercial.';
        if (trim((string) ($empresa->morada ?? '')) === '') $problemas[] = 'A empresa não tem morada fiscal.';
        if (trim((string) ($empresa->municipio ?? '')) === '') $problemas[] = 'A empresa não tem município fiscal.';

        $clientes = Cliente::withoutGlobalScopes()->where('empresa_id', $empresa->id)->get(['id', 'morada', 'municipio']);
        foreach ($clientes as $cliente) {
            if (trim((string) $cliente->morada) === '') {
                $problemas[] = 'O cliente CLI-'.$cliente->id.' não tem morada.';
            }
            if (trim((string) $cliente->municipio) === '') {
                $problemas[] = 'O cliente CLI-'.$cliente->id.' não tem município.';
            }
        }

        $produtos = Produto::withoutGlobalScopes()->where('empresa_id', $empresa->id)->get(['id', 'tipo_saft']);
        foreach ($produtos as $produto) {
            $tipo = strtoupper(trim((string) ($produto->tipo_saft ?: config('faturacao.saft_tipo_produto_padrao', ''))));
            if (! in_array($tipo, ['P', 'S', 'O', 'E', 'I'], true)) {
                $problemas[] = 'O produto '.$produto->id.' não tem classificação SAF-T P/S/O/E/I.';
            }
        }

        return array_values(array_unique($problemas));
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

    /**
     * Valida um XML SAF-T contra o XSD versionado no módulo.
     *
     * Devolve uma lista vazia quando o XML é válido; caso contrário, devolve
     * os erros de parsing/validação para diagnóstico. Este método ainda não
     * é aplicado automaticamente a gerar(), porque o exportador está em
     * migração e a estrutura atual não cumpre o XSD.
     *
     * @return list<string>
     */
    public function validarXmlContraXsd(string $xml): array
    {
        $caminhoXsd = module_path('Faturacao', 'resources/saft/SAFTAO1.01_01.xsd');

        if (! is_file($caminhoXsd)) {
            throw new \RuntimeException('O esquema XSD SAF-T (AO) não foi encontrado: '.$caminhoXsd);
        }

        $documento = new DOMDocument('1.0', 'UTF-8');
        $errosAnteriores = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $carregado = $documento->loadXML($xml, LIBXML_NONET);

            if (! $carregado) {
                return array_values(array_map(
                    static fn (\LibXMLError $erro): string => trim($erro->message).' (linha '.$erro->line.')',
                    libxml_get_errors(),
                ));
            }

            $valido = $documento->schemaValidate($caminhoXsd);
            $erros = array_values(array_map(
                static fn (\LibXMLError $erro): string => trim($erro->message).' (linha '.$erro->line.')',
                libxml_get_errors(),
            ));

            // Nunca apresentar como válido um XML que o libxml rejeitou,
            // mesmo que a biblioteca não forneça detalhes do erro.
            if (! $valido && $erros === []) {
                $erros[] = 'O XML não passou na validação XSD, mas o libxml não forneceu detalhes.';
            }

            return $erros;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($errosAnteriores);
        }
    }

    /** Texto seguro: escapa automaticamente e remove caracteres inválidos em XML. */
    protected function no(DOMDocument $doc, string $nome, string|int|float|null $valor): DOMElement
    {
        $limpo = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $valor) ?? '';

        $no = $doc->createElementNS(self::NAMESPACE_SAFT, $nome);
        $no->appendChild($doc->createTextNode($limpo));

        return $no;
    }

    protected function cabecalho(DOMDocument $doc, Empresa $empresa, Carbon $inicio, Carbon $fim): DOMElement
    {
        $cab = $doc->createElementNS(self::NAMESPACE_SAFT, 'Header');

        // A sequência segue a ordem obrigatória do XSD SAF-T (AO) 1.01_01.
        $campos = [
            'AuditFileVersion' => '1.01_01',
            'CompanyID' => $empresa->nif,
            'TaxRegistrationNumber' => $empresa->nif,
            'TaxAccountingBasis' => 'F',
            'CompanyName' => $empresa->nome_legal ?: $empresa->nome_comercial,
        ];

        foreach ($campos as $nome => $valor) {
            $cab->appendChild($this->no($doc, $nome, $valor));
        }

        $endereco = $doc->createElementNS(self::NAMESPACE_SAFT, 'CompanyAddress');
        $endereco->appendChild($this->no($doc, 'AddressDetail', $empresa->morada));
        $endereco->appendChild($this->no($doc, 'City', $empresa->municipio));
        if (! empty($empresa->provincia)) {
            $endereco->appendChild($this->no($doc, 'Province', $empresa->provincia));
        }
        $endereco->appendChild($this->no($doc, 'Country', 'AO'));
        $cab->appendChild($endereco);

        $camposFinais = [
            'FiscalYear' => $inicio->year,
            'StartDate' => $inicio->format('Y-m-d'),
            'EndDate' => $fim->format('Y-m-d'),
            'CurrencyCode' => 'AOA',
            'DateCreated' => now()->format('Y-m-d'),
            'TaxEntity' => 'Global',
            // Estes dados pertencem ao produtor do software, não à empresa cliente.
            // O NIF fica vazio até ser configurado; nunca usar o NIF do cliente aqui.
            'ProductCompanyTaxID' => config('faturacao.saft_nif_produtor', ''),
            // O XSD define "0" quando não aplicável; não representa uma certificação.
            'SoftwareValidationNumber' => config('faturacao.saft_numero_validacao', '0'),
            'ProductID' => config('faturacao.saft_product_id', ''),
            'ProductVersion' => config('faturacao.saft_product_version', '1.0.0'),
        ];

        foreach ($camposFinais as $nome => $valor) {
            $cab->appendChild($this->no($doc, $nome, $valor));
        }

        return $cab;
    }

    protected function tabelasMestras(DOMDocument $doc, Empresa $empresa): DOMElement
    {
        $tabelas = $doc->createElementNS(self::NAMESPACE_SAFT, 'MasterFiles');
        $contaClientes = trim((string) config('faturacao.saft_conta_clientes', ''));

        $clientes = Cliente::withoutGlobalScopes()->where('empresa_id', $empresa->id)->orderBy('id')->lazy(200);
        foreach ($clientes as $cliente) {
            $c = $doc->createElementNS(self::NAMESPACE_SAFT, 'Customer');
            $c->appendChild($this->no($doc, 'CustomerID', 'CLI-'.$cliente->id));
            $c->appendChild($this->no($doc, 'AccountID', $contaClientes));
            $c->appendChild($this->no($doc, 'CustomerTaxID', $cliente->nif ?: '999999999'));
            $c->appendChild($this->no($doc, 'CompanyName', $cliente->nome ?: 'Consumidor final'));
            $morada = $doc->createElementNS(self::NAMESPACE_SAFT, 'BillingAddress');
            $morada->appendChild($this->no($doc, 'AddressDetail', $cliente->morada));
            $morada->appendChild($this->no($doc, 'City', $cliente->municipio));
            if (trim((string) $cliente->provincia) !== '') {
                $morada->appendChild($this->no($doc, 'Province', $cliente->provincia));
            }
            $morada->appendChild($this->no($doc, 'Country', 'AO'));
            $c->appendChild($morada);
            $c->appendChild($this->no($doc, 'SelfBillingIndicator', 0));
            if ($cliente->telefone) $c->appendChild($this->no($doc, 'Telephone', $cliente->telefone));
            if ($cliente->email) $c->appendChild($this->no($doc, 'Email', $cliente->email));
            $tabelas->appendChild($c);
        }

        $produtos = Produto::withoutGlobalScopes()->where('empresa_id', $empresa->id)->orderBy('id')->lazy(200);
        foreach ($produtos as $produto) {
            $codigo = trim((string) ($produto->codigo ?: 'PROD-'.$produto->id));
            $p = $doc->createElementNS(self::NAMESPACE_SAFT, 'Product');
            $p->appendChild($this->no($doc, 'ProductType', $this->tipoProdutoSafT($produto)));
            $p->appendChild($this->no($doc, 'ProductCode', $codigo));
            $p->appendChild($this->no($doc, 'ProductDescription', $produto->nome));
            $p->appendChild($this->no($doc, 'ProductNumberCode', $codigo));
            $tabelas->appendChild($p);
        }

        // Linhas manuais também precisam de existir na tabela Product do SAF-T.
        $idsFaturas = Fatura::withoutGlobalScopes()->where('empresa_id', $empresa->id)->select('id');
        $linhasManuais = FaturaLinha::whereIn('fatura_id', $idsFaturas)->whereNull('produto_id')->orderBy('id')->lazy(200);
        foreach ($linhasManuais as $linhaManual) {
            $codigo = 'SERVICO-LINHA-'.$linhaManual->id;
            $p = $doc->createElementNS(self::NAMESPACE_SAFT, 'Product');
            $p->appendChild($this->no($doc, 'ProductType', $this->tipoProdutoGenericoSafT()));
            $p->appendChild($this->no($doc, 'ProductCode', $codigo));
            $p->appendChild($this->no($doc, 'ProductDescription', $linhaManual->descricao));
            $p->appendChild($this->no($doc, 'ProductNumberCode', $codigo));
            $tabelas->appendChild($p);
        }
        $idsNotas = NotaCreditoDebito::withoutGlobalScopes()->where('empresa_id', $empresa->id)->select('id');
        $linhasNotas = NotaCreditoDebitoLinha::whereIn('nota_credito_debito_id', $idsNotas)->orderBy('id')->lazy(200);
        foreach ($linhasNotas as $linhaNota) {
            $codigo = 'NOTA-LINHA-'.$linhaNota->id;
            $p = $doc->createElementNS(self::NAMESPACE_SAFT, 'Product');
            $p->appendChild($this->no($doc, 'ProductType', $this->tipoProdutoGenericoSafT()));
            $p->appendChild($this->no($doc, 'ProductCode', $codigo));
            $p->appendChild($this->no($doc, 'ProductDescription', $linhaNota->descricao));
            $p->appendChild($this->no($doc, 'ProductNumberCode', $codigo));
            $tabelas->appendChild($p);
        }

        $taxasProdutos = Produto::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->whereNotNull('taxa_iva')->pluck('taxa_iva');
        $idsFaturas = Fatura::withoutGlobalScopes()->where('empresa_id', $empresa->id)->select('id');
        $idsNotas = NotaCreditoDebito::withoutGlobalScopes()->where('empresa_id', $empresa->id)->select('id');
        $taxasFaturas = FaturaLinha::whereIn('fatura_id', $idsFaturas)->pluck('taxa_iva');
        $taxasNotas = NotaCreditoDebitoLinha::whereIn('nota_credito_debito_id', $idsNotas)->pluck('taxa_iva');
        $taxas = $taxasProdutos->concat($taxasFaturas)->concat($taxasNotas)
            ->map(static fn ($taxa): string => number_format((float) $taxa, 2, '.', ''))->unique()->sort()->values();
        if ($taxas->isNotEmpty()) {
            $taxTable = $doc->createElementNS(self::NAMESPACE_SAFT, 'TaxTable');
            foreach ($taxas as $taxa) {
                $entrada = $doc->createElementNS(self::NAMESPACE_SAFT, 'TaxTableEntry');
                $entrada->appendChild($this->no($doc, 'TaxType', 'IVA'));
                $entrada->appendChild($this->no($doc, 'TaxCountryRegion', 'AO'));
                $entrada->appendChild($this->no($doc, 'TaxCode', $this->codigoTaxa((float) $taxa)));
                $entrada->appendChild($this->no($doc, 'Description', 'IVA '.$taxa.'%'));
                $entrada->appendChild($this->no($doc, 'TaxPercentage', $taxa));
                $taxTable->appendChild($entrada);
            }
            $tabelas->appendChild($taxTable);
        }
        return $tabelas;
    }

    protected function tipoProdutoSafT(Produto $produto): string
    {
        $tipo = strtoupper(trim((string) ($produto->tipo_saft ?: config('faturacao.saft_tipo_produto_padrao', ''))));
        if (! in_array($tipo, ['P', 'S', 'O', 'E', 'I'], true)) {
            throw new \RuntimeException('Configure FATURACAO_SAFT_TIPO_PRODUTO_PADRAO (P, S, O, E ou I) antes de exportar produtos SAF-T.');
        }
        return $tipo;
    }

    protected function tipoProdutoGenericoSafT(): string
    {
        $tipo = strtoupper(trim((string) config('faturacao.saft_tipo_produto_padrao', '')));
        if (! in_array($tipo, ['P', 'S', 'O', 'E', 'I'], true)) {
            throw new \RuntimeException('Configure FATURACAO_SAFT_TIPO_PRODUTO_PADRAO antes de exportar linhas sem produto associado.');
        }
        return $tipo;
    }

    protected function codigoTaxa(float $taxa): string
    {
        $codigos = config('faturacao.saft_codigos_taxa', []);
        $codigo = $codigos[(string) number_format($taxa, 2, '.', '')] ?? null;
        if ($taxa == 0.0) return 'ISE';
        return is_string($codigo) && $codigo !== '' ? $codigo : number_format($taxa, 2, '.', '');
    }

    protected function documentosFonte(DOMDocument $doc, Empresa $empresa, Carbon $inicio, Carbon $fim): DOMElement
    {
        $fonte = $doc->createElementNS(self::NAMESPACE_SAFT, 'SourceDocuments');
        $vendas = $doc->createElementNS(self::NAMESPACE_SAFT, 'SalesInvoices');

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
            ->with('linhas.produto')->orderBy('serie_id')->orderBy('numero_sequencial')->lazy(200);

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
        $pagamentos = $this->pagamentos($doc, $empresa, $inicio, $fim);
        if ($pagamentos !== null) {
            $fonte->appendChild($pagamentos);
        }

        return $fonte;
    }

    protected function pagamentos(DOMDocument $doc, Empresa $empresa, Carbon $inicio, Carbon $fim): ?DOMElement
    {
        $recibos = Recibo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('estado', 'emitido')
            ->whereBetween('data_emissao', [$inicio->toDateString(), $fim->toDateString()])
            ->orderBy('serie_id')->orderBy('numero_sequencial')->get();

        if ($recibos->isEmpty()) return null;

        $pagamentos = $doc->createElementNS(self::NAMESPACE_SAFT, 'Payments');
        $pagamentos->appendChild($this->no($doc, 'NumberOfEntries', $recibos->count()));
        $pagamentos->appendChild($this->no($doc, 'TotalDebit', '0.00'));
        $totalCredito = $recibos->sum(fn ($recibo) => Dinheiro::centimos($recibo->valor));
        $pagamentos->appendChild($this->no($doc, 'TotalCredit', Dinheiro::formatar($totalCredito)));

        foreach ($recibos as $recibo) {
            if (! $recibo->fatura_id) {
                throw new \RuntimeException('O recibo '.$recibo->numero_documento.' não está ligado a uma fatura; o SAF-T exige referência ao documento de origem.');
            }

            $fatura = Fatura::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)->find($recibo->fatura_id);
            $cliente = Cliente::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)->find($recibo->cliente_id);
            $serie = \Modules\Faturacao\Models\Serie::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)->find($recibo->serie_id);
            if (! $fatura || ! $cliente || ! $serie || ! $fatura->estaEmitido()) {
                throw new \RuntimeException('O recibo '.$recibo->numero_documento.' tem referências fiscais inválidas.');
            }

            $pagamento = $doc->createElementNS(self::NAMESPACE_SAFT, 'Payment');
            $pagamento->appendChild($this->no($doc, 'PaymentRefNo', 'RC '.$serie->prefixo.$serie->ano.'/'.$recibo->numero_sequencial));
            $pagamento->appendChild($this->no($doc, 'TransactionDate', $recibo->data_emissao->format('Y-m-d')));
            $pagamento->appendChild($this->no($doc, 'PaymentType', 'RC'));
            $pagamento->appendChild($this->no($doc, 'Description', 'Recibo '.$recibo->numero_documento));
            $pagamento->appendChild($this->no($doc, 'SystemID', config('faturacao.saft_product_id', 'Saas1')));
            $estado = $doc->createElementNS(self::NAMESPACE_SAFT, 'DocumentStatus');
            $estado->appendChild($this->no($doc, 'PaymentStatus', 'N'));
            $estado->appendChild($this->no($doc, 'PaymentStatusDate', $recibo->data_hora_sistema->format('Y-m-d\\TH:i:s')));
            $estado->appendChild($this->no($doc, 'SourceID', config('faturacao.saft_source_id', 'Sistema')));
            $estado->appendChild($this->no($doc, 'SourcePayment', 'P'));
            $pagamento->appendChild($estado);

            $mecanismos = ['dinheiro' => 'NU', 'transferencia' => 'TB', 'multicaixa' => 'MB', 'outro' => 'OU'];
            $metodo = $doc->createElementNS(self::NAMESPACE_SAFT, 'PaymentMethod');
            $metodo->appendChild($this->no($doc, 'PaymentMechanism', $mecanismos[$recibo->meio_pagamento] ?? 'OU'));
            $metodo->appendChild($this->no($doc, 'PaymentAmount', Dinheiro::formatar(Dinheiro::centimos($recibo->valor))));
            $metodo->appendChild($this->no($doc, 'PaymentDate', $recibo->data_emissao->format('Y-m-d')));
            $pagamento->appendChild($metodo);
            $pagamento->appendChild($this->no($doc, 'SourceID', config('faturacao.saft_source_id', 'Sistema')));
            $pagamento->appendChild($this->no($doc, 'SystemEntryDate', $recibo->data_hora_sistema->format('Y-m-d\\TH:i:s')));
            $pagamento->appendChild($this->no($doc, 'CustomerID', 'CLI-'.$cliente->id));

            $linha = $doc->createElementNS(self::NAMESPACE_SAFT, 'Line');
            $linha->appendChild($this->no($doc, 'LineNumber', 1));
            $origem = $doc->createElementNS(self::NAMESPACE_SAFT, 'SourceDocumentID');
            $origem->appendChild($this->no($doc, 'OriginatingON', $fatura->numero_documento));
            $origem->appendChild($this->no($doc, 'InvoiceDate', $fatura->data_emissao->format('Y-m-d')));
            $origem->appendChild($this->no($doc, 'Description', 'Liquidação da fatura '.$fatura->numero_documento));
            $linha->appendChild($origem);
            $linha->appendChild($this->no($doc, 'CreditAmount', Dinheiro::formatar(Dinheiro::centimos($recibo->valor))));
            $pagamento->appendChild($linha);

            $totais = $doc->createElementNS(self::NAMESPACE_SAFT, 'DocumentTotals');
            $totais->appendChild($this->no($doc, 'TaxPayable', '0.00'));
            $totais->appendChild($this->no($doc, 'NetTotal', Dinheiro::formatar(Dinheiro::centimos($recibo->valor))));
            $totais->appendChild($this->no($doc, 'GrossTotal', Dinheiro::formatar(Dinheiro::centimos($recibo->valor))));
            $pagamento->appendChild($totais);
            $pagamentos->appendChild($pagamento);
        }

        return $pagamentos;
    }

    protected function documento(DOMDocument $doc, string $tipo, Fatura|NotaCreditoDebito $m, string $campoValor): DOMElement
    {
        $no = $doc->createElementNS(self::NAMESPACE_SAFT, 'Invoice');
        $cliente = Cliente::withoutGlobalScopes()->where('empresa_id', $m->empresa_id)->find($m->cliente_id);
        if (! $cliente) {
            throw new \RuntimeException('O documento '.$m->numero_documento.' referencia um cliente inexistente na mesma empresa.');
        }
        if (trim((string) $m->hash) === '') {
            throw new \RuntimeException('O documento '.$m->numero_documento.' não tem hash fiscal; exportação interrompida.');
        }

        $no->appendChild($this->no($doc, 'InvoiceNo', $m->numero_documento));
        $status = $doc->createElementNS(self::NAMESPACE_SAFT, 'DocumentStatus');
        $status->appendChild($this->no($doc, 'InvoiceStatus', 'N'));
        $status->appendChild($this->no($doc, 'InvoiceStatusDate', $m->data_hora_sistema->format('Y-m-d\\TH:i:s')));
        $status->appendChild($this->no($doc, 'SourceID', config('faturacao.saft_source_id', 'Sistema')));
        $status->appendChild($this->no($doc, 'SourceBilling', 'P'));
        $no->appendChild($status);
        $no->appendChild($this->no($doc, 'Hash', $m->hash));
        $no->appendChild($this->no($doc, 'HashControl', $m->chave_versao ?: '0'));
        $no->appendChild($this->no($doc, 'InvoiceDate', $m->data_emissao->format('Y-m-d')));
        $no->appendChild($this->no($doc, 'InvoiceType', $tipo));
        $regimes = $doc->createElementNS(self::NAMESPACE_SAFT, 'SpecialRegimes');
        $regimes->appendChild($this->no($doc, 'SelfBillingIndicator', 0));
        $regimes->appendChild($this->no($doc, 'CashVATSchemeIndicator', 0));
        $regimes->appendChild($this->no($doc, 'ThirdPartiesBillingIndicator', 0));
        $no->appendChild($regimes);
        $no->appendChild($this->no($doc, 'SourceID', config('faturacao.saft_source_id', 'Sistema')));
        $no->appendChild($this->no($doc, 'SystemEntryDate', $m->data_hora_sistema->format('Y-m-d\\TH:i:s')));
        $no->appendChild($this->no($doc, 'CustomerID', 'CLI-'.$cliente->id));

        $linhas = $m->linhas;
        if ($linhas->isEmpty()) {
            throw new \RuntimeException('O documento '.$m->numero_documento.' não tem linhas para exportar.');
        }
        foreach ($linhas as $indice => $linha) {
            $l = $doc->createElementNS(self::NAMESPACE_SAFT, 'Line');
            $produto = $linha->relationLoaded('produto') ? $linha->produto : null;
            $codigo = $produto
                ? (string) ($produto->codigo ?: 'PROD-'.$produto->id)
                : ($m instanceof Fatura ? 'SERVICO-LINHA-'.$linha->id : 'NOTA-LINHA-'.$linha->id);
            $descricao = trim((string) $linha->descricao);
            $taxa = (float) $linha->taxa_iva;
            $l->appendChild($this->no($doc, 'LineNumber', $indice + 1));
            $l->appendChild($this->no($doc, 'ProductCode', $codigo));
            $l->appendChild($this->no($doc, 'ProductDescription', $descricao));
            $l->appendChild($this->no($doc, 'Quantity', $linha->quantidade));
            $l->appendChild($this->no($doc, 'UnitOfMeasure', $produto?->unidade ?: 'UN'));
            $l->appendChild($this->no($doc, 'UnitPrice', Dinheiro::formatar(Dinheiro::centimos($linha->preco_unitario))));
            $l->appendChild($this->no($doc, 'TaxBase', Dinheiro::formatar(Dinheiro::centimos($linha->valor_sem_iva))));
            $l->appendChild($this->no($doc, 'TaxPointDate', $m->data_emissao->format('Y-m-d')));
            $l->appendChild($this->no($doc, 'Description', $descricao));
            $l->appendChild($this->no($doc, $campoValor, Dinheiro::formatar(Dinheiro::centimos($linha->valor_sem_iva))));
            $tax = $doc->createElementNS(self::NAMESPACE_SAFT, 'Tax');
            $tax->appendChild($this->no($doc, 'TaxType', 'IVA'));
            $tax->appendChild($this->no($doc, 'TaxCountryRegion', 'AO'));
            $tax->appendChild($this->no($doc, 'TaxCode', $this->codigoTaxa($taxa)));
            $tax->appendChild($this->no($doc, 'TaxPercentage', number_format($taxa, 2, '.', '')));
            $l->appendChild($tax);
            if ($taxa == 0.0) {
                $codigoIsencao = trim((string) $linha->codigo_isencao_iva);
                $motivoIsencao = trim((string) $linha->motivo_isencao_iva);
                if (! preg_match('/^M[0-9]{2}$/', $codigoIsencao) || mb_strlen($motivoIsencao) < 6 || mb_strlen($motivoIsencao) > 60) {
                    throw new \RuntimeException('A linha '.($indice + 1).' do documento '.$m->numero_documento.' tem IVA 0%; informe um código legal M01–M99 e um motivo entre 6 e 60 caracteres.');
                }
                $l->appendChild($this->no($doc, 'TaxExemptionReason', $motivoIsencao));
                $l->appendChild($this->no($doc, 'TaxExemptionCode', $codigoIsencao));
            }
            $no->appendChild($l);
        }

        $totais = $doc->createElementNS(self::NAMESPACE_SAFT, 'DocumentTotals');
        $totais->appendChild($this->no($doc, 'TaxPayable', Dinheiro::formatar(Dinheiro::centimos($m->valor_iva))));
        $totais->appendChild($this->no($doc, 'NetTotal', Dinheiro::formatar(Dinheiro::centimos($m->valor_sem_iva))));
        $totais->appendChild($this->no($doc, 'GrossTotal', Dinheiro::formatar(Dinheiro::centimos($m->valor_total))));
        $no->appendChild($totais);
        return $no;
    }
}
