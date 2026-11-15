# Fase 4 — Auditoria das regras fiscais e contabilísticas (AGT)

Base legal verificada: Decreto Presidencial n.º 71/25, de 20 de Março (Regime Jurídico das Facturas, RJF),
texto integral consultado em lex.ao. Código do IVA e Decretos Executivos (683/25) **não** foram
transcritos aqui: onde dependem deles, o ponto fica marcado como "a confirmar".

Esta auditoria **não declara conformidade fiscal**. Só a validação/certificação da AGT o faz.

## Corrigido nesta PR (com teste de regressão)

| # | Prioridade | Problema | Base | Correção | Teste |
|---|-----------|----------|------|----------|-------|
| 1 | P0 | Nenhum bloqueio de emissão quando a comunicação à AGT falha há mais de 60 dias | RJF art. 17.º n.º 5 | `GuardaEmissao::garantirComunicacaoAgtEmDia()`; configurável em `faturacao.agt.bloqueio_dias_sem_comunicacao` (60; 0 desliga). Conta `pendente`, `erro` e `aguarda_intervencao`; `inválido`/`rejeitado` são respostas da AGT | `BloqueioComunicacaoAgtTest` |
| 2 | P1 | Notas de crédito parciais sucessivas sobre a mesma linha eram recusadas por 1 cêntimo (IVA arredondado por excesso em cada nota), impedindo anular o valor todo | RJF art. 3.º l), art. 8.º n.º 4 | Folga de 1 cêntimo por linha creditada **só** no total bruto; o valor líquido por taxa continua estritamente limitado ao faturado | `CreditoParcialArredondamentoIvaTest` |
| 3 | P1 | A nota de crédito não mostrava a expressão «anulação»/«rectificação» nem a fatura identificada | RJF art. 8.º n.º 5 | `NotaCreditoDebito::expressaoFiscal()` (anulação = credita o total da fatura; senão rectificação) e apresentação na vista | `NotaCreditoExpressaoFiscalTest` |
| 4 | P2 | `ReciboService` aceitava qualquer meio de pagamento (a barreira estava só nos FormRequests / enum da BD) | Integridade | Validação no serviço contra `faturacao.meios_pagamento`, antes de consumir número | `ReciboMeioPagamentoServicoTest` |
| 5 | P2 | `DocumentoFiscalInterface::getValorTotalDocumento(): float` — valor monetário em float | Auditoria geral (Dinheiro) | Substituído por `getValorTotalCentimos(): int` (sem outros chamadores no repositório) | cobertura existente |
| 6 | P3 | `OrcamentoService::criar()` aceitava validade no passado (editar/duplicar já recusavam) | Coerência | Mesma regra em `criar()` | `ReciboMeioPagamentoServicoTest` |

## Confirmado conforme (sem alteração)

- Nota de débito sem liquidação de IVA (`nota_debito_liquida_iva = false`): coincide com o art. 3.º m) do RJF.
- Notas de crédito para anular/rectificar facturas e regularizar adiantamentos (art. 8.º n.os 4 e 10): suportado.
- Pró-forma e orçamento não são facturas (art. 4.º n.º 9): `Orcamento` usa numeração própria `ORC-…`, fora das séries fiscais, cadeia de hash, AGT e SAF-T.
- Duas assinaturas AGT (chave do contribuinte por NIF e chave do software): ambas implementadas (`AssinaturaFiscalService`, `AgtAssinaturaService`).
- Aritmética monetária em inteiros (`Dinheiro`), IVA por excesso, numeração sem lacunas com lock, cadeia de hash.

## Pendente — precisa de decisão ou da especificação oficial (não alterado de propósito)

| Prioridade | Ponto | Base | Porque não foi alterado |
|-----------|-------|------|-------------------------|
| P0 | `regime_iva` (geral/simplificado/exclusão/transitório) é só uma preferência guardada: a emissão não impede IVA a 14% num contribuinte que não seja do regime geral. Fonte secundária refere que a liquidação na factura compete ao regime geral — **a confirmar no Código do IVA** | Código do IVA | O código M00 (regime simplificado) está classificado como "enquadramento" e não pode ser motivo de linha: aplicar o bloqueio sem decidir isto deixaria esses contribuintes sem forma de emitir |
| P0 | Representação fiscal do abatimento de adiantamentos (FA → FT) | RJF art. 8.º n.º 10; `HOMOLOGACAO_AGT.md` H3 | Depende da AGT |
| P1 | Prova de conhecimento da nota de crédito pelo adquirente | RJF art. 8.º n.os 5–7 e art. 21.º | Funcionalidade nova (fluxo de confirmação) |
| P1 | Menções do documento impresso/PDF: identificação do emitente (nome, NIF, morada), nº de validação do software, texto por extenso do total, «Original»/«2.ª via» | RJF art. 7.º n.os 4–5 e art. 10.º n.º 1 a), d), j) | Não existe layout de impressão; pertence à Fase 9. A regra do excerto de hash a imprimir para JWS tem de vir da AGT |
| P1 | Data/hora/local da operação (≠ data de emissão) e prazo de 5 dias úteis | RJF art. 8.º n.º 1; art. 10.º n.º 1 g) | Novo campo/regra de negócio |
| P1 | Recibos: indicação de impostos retidos | RJF art. 6.º n.º 3 | Modelo de retenções inexistente |
| P1 | `PaymentMechanism` do SAF-T exporta o valor interno (`dinheiro`, `multicaixa`…) | XSD SAF-T (AO) | Depende do XSD oficial — Fase 5 |
| P2 | Nota de crédito não valida a quantidade por linha da fatura, só valor líquido por taxa e total | Boa prática | Mudança de regra com impacto nos fluxos do Atelier/Estúdio |
| P2 | `FaturacaoEstudioService::registrarRecibo` aceita `float` em `$valor` (o `Dinheiro` recusa float) | Dinheiro | Módulo Estúdio — Fase 6 |
| P2 | Saldo de uma fatura pode ficar −0,01 após créditos parciais sucessivos (consequência do ponto 2) | Arredondamento | Mostrar saldo negativo como crédito do cliente é decisão de interface (Fase 7/9) |
