# Revisão estrutural do exportador SAF-T (AO)

Data da revisão: 2026-10-11

## Estado

O XML gerado por `SafTExportService` é provisório e **não deve ser tratado como validado**. O XSD de referência encontra-se neste diretório em `SAFTAO1.01_01.xsd`. A existência do esquema no repositório não significa que o exportador passe na validação.

## Diferenças verificadas entre o serviço atual e o XSD

### Raiz e cabeçalho

- O serviço cria `AuditFile` sem o namespace `urn:OECD:StandardAuditFile-Tax:AO_1.01_01`.
- O valor atual de `AuditFileVersion` é `1.0`; o esquema de referência identifica a versão `1.01_01`.
- `CompanyAddress` é emitido como texto simples, mas o XSD exige uma estrutura com `AddressDetail`, `City` e `Country` (e campos opcionais na ordem definida).
- O cabeçalho não emite `TaxEntity`, `ProductCompanyTaxID`, `SoftwareValidationNumber` e `ProductVersion`.
- `ProductID` deve identificar aplicação e empresa produtora no formato `Nome da aplicação/Nome da empresa produtora`; o valor atual não identifica a empresa produtora.
- A configuração atual `saft_numero_certificado` é usada como `SoftwareCertificateNumber`, mas o cabeçalho XSD da versão consultada pede `SoftwareValidationNumber`. O significado destes campos não deve ser confundido nem preenchido com um número inventado.

### Tabelas mestras

- Cada `Customer` exige `CustomerID`, `AccountID`, `CustomerTaxID`, `CompanyName`, `BillingAddress` e `SelfBillingIndicator`, na ordem definida. O serviço atual emite apenas quatro campos e usa `Address` em vez de `BillingAddress`.
- O XSD documenta o NIF `999999999` para o cliente genérico “Consumidor final”. Esse valor só deve ser usado para esse cliente genérico, não como substituto universal para qualquer cliente sem NIF.
- Cada `Product` exige `ProductType`, `ProductCode`, `ProductDescription` e `ProductNumberCode`; o serviço atual emite apenas código e descrição.
- O XSD admite `TaxTable` na tabela mestre e o serviço não a gera. A tabela deve ser coerente com os impostos realmente usados nos documentos.

### Documentos de venda

A sequência do XSD para cada `Invoice` começa com `InvoiceNo`, `DocumentStatus`, `Hash`, `HashControl`, `InvoiceDate`, `InvoiceType`, `SpecialRegimes`, `SourceID`, `SystemEntryDate` e `CustomerID`, antes das linhas. O serviço atual emite uma sequência diferente e coloca campos de totais diretamente no documento.

Cada `Line` exige pelo menos `LineNumber`, `ProductCode`, `ProductDescription`, `Quantity`, `UnitOfMeasure`, `UnitPrice`, `TaxPointDate`, `Description`, um de `DebitAmount`/`CreditAmount` e uma estrutura `Tax`. O serviço atual não emite vários destes campos e usa `TaxPercentage` diretamente, em vez da estrutura `Tax`.

Os totais devem estar agrupados dentro de `DocumentTotals`, na ordem `TaxPayable`, `NetTotal`, `GrossTotal`. O serviço atual não cria esse agrupamento.

### Âmbito dos documentos

- O serviço exporta faturas e notas de crédito/débito sob `SalesInvoices`.
- A consulta atual não inclui recibos emitidos. A especificação SAF-T consultada menciona documentos de conferência e recibos emitidos quando existam; o âmbito final e a forma de os exportar devem ser decididos com base no tipo de SAF-T exigido e nos dados de pagamentos do módulo.
- Este serviço não gera `GeneralLedgerEntries` nem o plano de contas. Portanto, não é um exportador completo de SAF-T de contabilidade.

## Plano seguro de implementação

1. Confirmar o tipo de SAF-T a entregar e os requisitos aplicáveis junto da AGT.
2. Adicionar configuração explícita para os dados reais do produtor de software; não fabricar NIF nem número de validação.
3. Construir nós XML com namespace consistente e estruturas de endereço e cabeçalho na ordem do XSD.
4. Mapear clientes, produtos, códigos/unidades e impostos sem inventar dados fiscais.
5. Corrigir o mapeamento de estado/origem, linhas, impostos e totais dos documentos.
6. Decidir e implementar a exportação de recibos/pagamentos conforme o âmbito confirmado.
7. Acrescentar testes que validem XML de cenários reais contra este XSD e verificar os erros com libxml; executar toda a suite antes de integrar na `main`.

## Limitação da revisão

Esta nota é uma comparação estática do serviço e do XSD versionado no repositório. Ainda não foi executado `DOMDocument::schemaValidate()` no XML gerado, nem a suite de testes foi executada nesta branch. A validade final depende também de dados fiscais corretos e da confirmação de que esta versão do XSD é a aplicável à submissão pretendida.
