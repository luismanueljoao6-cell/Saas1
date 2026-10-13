# Revisão estrutural do exportador SAF-T (AO)

Data da revisão: 2026-10-12

## Alterações implementadas nesta branch

- Cabeçalho e raiz XML usam o namespace e a versão declarados pelo XSD versionado.
- Endereços de empresa e cliente são estruturas; clientes têm campos de município e província.
- Clientes incluem AccountID, CustomerTaxID, BillingAddress e SelfBillingIndicator.
- Produtos incluem ProductType, ProductCode, ProductDescription e ProductNumberCode; a classificação pode ser registada por produto na página Produtos.
- TaxTable é construída a partir das taxas presentes em produtos e linhas de faturas/notas.
- Documentos de venda incluem DocumentStatus, SpecialRegimes, linhas com estrutura Tax e DocumentTotals na sequência do esquema.
- Notas de crédito/débito preservam a distinção entre DebitAmount e CreditAmount.
- Recibos emitidos são incluídos na secção Payments. Um recibo sem fatura de origem, com referências inconsistentes ou sem assinatura bloqueia a exportação.
- IVA a zero exige motivo legal e código de isenção com formato Mnn; os serviços de criação validam as taxas permitidas e não aceitam dados de isenção incompletos.
- O job de fila usa gerarValidado(), que faz pré-validação dos dados fiscais e valida o XML contra o XSD antes de guardar o ficheiro. gerar() permanece apenas para compatibilidade/diagnóstico.

## Dados que não devem ser inventados

A exportação validada falha fechada se faltarem dados reais do produtor do software, ProductID, conta contabilística de clientes, NIF/morada/município da empresa, morada/município do cliente ou classificação fiscal do produto. Configurações de produtor e conta contabilística devem ser valores reais e confirmados. Não usar um NIF do cliente como NIF do produtor.

## Limitações ainda abertas

1. O XSD neste repositório está marcado como Development. A validação contra este ficheiro não prova que a AGT o aceite nem substitui certificação.
2. O código da taxa, regime fiscal, motivos de isenção e semântica contabilística têm de ser confirmados para o regime efetivo de cada contribuinte.
3. Recibos de adiantamento sem fatura de origem são permitidos pelo modelo de dados, mas não podem ser exportados com a estrutura atual de Payments; a exportação falha em vez de omiti-los.
4. A suite CI desta branch passou: Pint, Composer validate, testes PHP, Composer audit e testes MySQL. O teste de exportação valida XML contra o XSD versionado. Isto confirma os testes automatizados atuais, não a conformidade fiscal completa nem aceitação pela AGT.
5. Esta entrega exporta faturas, notas de crédito/débito e recibos; não gera GeneralLedgerEntries nem o plano de contas, pelo que não deve ser apresentada como SAF-T de contabilidade completo.

## Verificação local obrigatória

Executar na branch de trabalho:

```bash
php artisan migrate
php artisan test --filter=ExportacaoSafTTest
php artisan test --filter=SafTRegrasEValidacaoTest
php artisan test --filter=RegrasDeNegocioTest
php artisan test
```

A suite CI passou nesta branch. Antes de integrar na main, confirmar a versão oficial aplicável do XSD, validar os códigos fiscais e a configuração real, e rever os casos de negócio que não são comprovados apenas pelo CI.
