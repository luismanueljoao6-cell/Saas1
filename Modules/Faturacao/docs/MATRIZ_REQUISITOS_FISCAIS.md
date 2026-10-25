# Matriz de requisitos fiscais (rascunho a validar)

Cada requisito aponta para o campo/regra no modelo, a validação, o que é assinado, o
SAF-T e o teste. **Estados:** ✅ implementado · 🟡 parcial · ⛔ em falta · ❔ por confirmar na lei.
Fonte dos campos AGT: documentação oficial "Registar Factura" (quiosque AGT, Facturação
Electrónica). Esta matriz tem de ser revista contra o texto legal em vigor (Decreto
Presidencial 71/25, Decreto Executivo 683/25) antes de se afirmar conformidade.

## Campos do documento enviado à AGT

| Requisito | Campo / regra | Validação | Assinado | SAF-T | Teste | Estado |
|---|---|---|---|---|---|---|
| Identificação única do documento (`documentNo`) | `numero_documento` (`NumeracaoService`) | série AGT por estabelecimento, sem lacunas | ✅ | `InvoiceNo` | AgtSerieTest, SerieEstabelecimentoTest | ✅ |
| Estado N/C e `rejectedDocumentNo` | `faturas.rejeitado_documento_no` | só correção de inválidas/rejeitadas | — | ⛔ | CorrecaoDocumentoRejeitadoTest | 🟡 só faturas |
| Assinatura do documento | `hash` (JWS RS256) | chave da empresa, RSA ≥ 2048 | — | `Hash` (>172 car.) | AssinaturaJwsTest, ChavesVerificacaoTest | 🟡 |
| Data, tipo, NIF/país/nome do cliente | campos congelados na emissão | cliente da própria empresa | ✅ | sim | ClienteCongeladoTest | ✅ |
| `systemEntryDate` | `data_hora_sistema` | — | — | `SystemEntryDate` | CadeiaFiscalTest | ✅ |
| Linhas: número, produto, descrição, quantidade, unidade, preço | `*_linhas` | `ValidacaoLinhas` | — | `Line` | ValidacaoLinhasTest | ✅ |
| Descontos (`unitPrice` ≠ `unitPriceBase`, `settlementAmount`) | — (preço = base; desconto 0) | — | — | — | — | ⛔ |
| `operationType` por linha | `SG` fixo (config) | — | — | — | AgtSubmissaoTest | 🟡 |
| `operationDate` (obrigatório em FG/GF) | — | — | — | — | — | ⛔ (tipos não suportados) |
| IVA por linha (`taxes`) | taxa → código (`agt.codigos_taxa`) | taxa permitida + código AGT | — | `Tax` / `TaxTable` | NotaDebitoEIsencaoAgtTest, SafTEstruturaEVersoesTest | 🟡 só 14% mapeado |
| Isenção / não sujeição (`taxExemptionCode`) | `motivo_isencao` (tabela `motivos_isencao`) | motivo da tabela; tabela por confirmar | — | `TaxExemptionCode` | IsencaoIvaTest | 🟡 tabela por confirmar |
| Nota de débito sem imposto | taxa 0; sem `taxes` (ou NS com `nd_codigo_nao_sujeicao`) | recusa IVA | — | NS | NotaDebitoSemIvaTest, NotaDebitoEIsencaoAgtTest | 🟡 formato por confirmar na AGT |
| Referência da NC à fatura (`referenceInfo`) | `fatura_id` obrigatório | origem da empresa; limite por taxa | — | — | RegrasDeNegocioTest, LimiteCreditoPorTaxaTest | ✅ |
| Totais (`documentTotals`) | `valor_*` | IVA por excesso por linha | ✅ | `DocumentTotals` | DinheiroValidacaoTest | ✅ |
| Divisa / exportação (`currency`) | — | recusa não-AOA | — | — | — | ⛔ |
| Retenções na fonte (`withholdingTaxList`) | — | — | — | — | — | ⛔ |
| Recibos (`paymentReceipt`) | `Recibo` + fatura | saldo = total − recibos − NC | — | ⛔ (não exportados) | EmissaoSegurancaTest, LimiteCreditoPorTaxaTest | 🟡 só com fatura |
| Recibos de adiantamento / `FA` | — | recusa com AGT ativa | — | — | AgtSubmissaoTest | ⛔ |
| Tipos FR, FA, FG, GF, AC, AR, TV, RG, RE, AF, RP, RA, CS, LD | — | — | — | — | — | ⛔ |
| QR Code | `QrCodeAgt` | — | — | — | QrCodeAgtTest | ✅ |

## Requisitos do Regime Jurídico das Facturas ainda por mapear

A auditoria apontou como não demonstrados (não como infrações): data da operação quando
diferente da emissão; local de disponibilização dos bens ou da prestação dos serviços;
motivo e fundamento legal da isenção na própria fatura impressa; descontos e a sua
distribuição fiscal; menções específicas por tipo de documento. ❔ Para cada um: ler o
artigo aplicável, acrescentar uma linha a esta matriz com campo, validação, elemento
assinado, elemento SAF-T e teste, e só então marcar ✅.
