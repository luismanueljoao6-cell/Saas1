# Decisão técnica — alocações de recibos para paymentReceipt AGT

**Estado:** decisão de arquitectura para orientar implementação; não constitui homologação fiscal.  
**Fase:** 1 — integridade fiscal e financeira.  
**Data:** 2026-11-16.

## 1. Evidência normativa consultada

Foi consultada a documentação oficial do serviço **Registar Factura**, disponibilizada no Quiosque AGT / Ministério das Finanças:

- https://quiosqueagt.minfin.gov.ao/doc-agt/doc-agt/2/servicos/servicos_solicitar_registo.html

A documentação consultada indica:

1. Os tipos RG e AR não enviam o array `lines`; devem enviar `paymentReceipt`.
2. `paymentReceipt.sourceDocuments` é obrigatório.
3. Cada elemento de `sourceDocuments` exige `lineNo`, `sourceDocumentID` e apenas um dos campos `debitAmount` ou `creditAmount`.
4. `lineNo` identifica a linha do documento de facturação origem incluída no recibo, começando em 1 e incrementando por cada linha incluída.
5. `creditAmount`/ `debitAmount` representam o valor sem impostos e descontos da linha regularizada.
6. Em RG/AR, `documentTotals.taxPayable` e `netTotal` são agregados a partir dos documentos origem; notas de crédito entram com sinal negativo. `grossTotal` é a soma de líquido e imposto.

A especificação também apresenta a regra E41 para impedir que a soma dos montantes regularizados ultrapasse o remanescente do documento origem.

## 2. Problema confirmado no código existente

O modelo `Recibo` guarda apenas uma referência opcional `fatura_id` e o valor global do recibo. Não existe persistência das linhas da fatura efectivamente regularizadas por cada recibo.

O `AgtDocumentoBuilder` legado gera um único `sourceDocuments` com `lineNo = 1` e usa o `netTotal` global assinado. Isto não representa correctamente recibos parciais ou faturas com várias linhas.

Por isso, o envio de RG com este modelo permanece bloqueado. O bloqueio preventivo não deve ser removido até a implementação e os testes cobrirem as alocações reais.

## 3. Decisão de arquitectura

A Faturação será a única autoridade de alocação. Módulos consumidores não podem calcular nem persistir directamente alocações fiscais.

A estrutura a implementar deve, no mínimo, preservar por cada alocação:

- empresa proprietária;
- recibo emitido;
- documento de origem (fatura/nota rectificativa suportada);
- linha exacta do documento de origem;
- número da linha fiscal original, imutável;
- montante líquido regularizado em cêntimos;
- imposto regularizado em cêntimos;
- montante bruto correspondente em cêntimos;
- timestamps e chave idempotente da operação, quando aplicável.

A persistência deve permitir reconstruir o payload AGT sem consultar valores mutáveis nem inferir a linha com base na ordem actual da consulta. Deve validar que recibo, documento e linha pertencem à mesma empresa e que a linha pertence ao documento indicado.

A operação de alocação deve ser transaccional, bloquear os documentos/linhas envolvidos, impedir sobrealocação e ser idempotente. Os registos fiscais emitidos devem ser imutáveis; correcções posteriores devem seguir documentos correctivos, nunca editar ou apagar alocações já assinadas.

## 4. Questões que não serão adivinhadas

A documentação consultada define os campos e a agregação, mas não estabelece por si só toda a política interna de rateio do pagamento bruto parcial entre líquido e IVA de cada linha, sobretudo perante arredondamentos, descontos, múltiplas taxas e notas rectificativas.

Antes de activar RG em produção, essa regra deve ser validada contra a especificação integral aplicável e confirmada em homologação AGT com casos de:
- uma linha e pagamento parcial;
- várias linhas com a mesma taxa;
- várias linhas com taxas diferentes;
- linha isenta/não sujeita;
- descontos por linha e globais;
- nota de crédito/debito;
- pagamentos sucessivos até liquidação;
- concorrência e repetição da mesma requisição.

Não usar floats. Todos os cálculos internos devem operar em cêntimos inteiros e a conversão para JSON deve ocorrer apenas na fronteira AGT.

## 5. Compatibilidade e migração

- Não alterar documentos históricos emitidos nem reescrever assinaturas.
- Preservar `recibos.fatura_id` como referência legada enquanto a migração gradual decorre.
- A nova estrutura deve suportar múltiplas linhas de origem; a possibilidade de múltiplos documentos origem no mesmo recibo deve ser modelada sem pressupor que o campo legado `fatura_id` a representa.
- A migration deve ser aditiva e reversível sem apagar dados novos em produção; avaliar rollback destrutivo separadamente.
- Garantir paridade SQLite/MySQL e integridade multiempresa na própria base de dados, não apenas no controller.

## 6. Critérios de conclusão

- Serviço público no contrato `FaturacaoGateway`; nenhum módulo consumidor chama serviços internos da Faturação.
- Alocações imutáveis, tenant-safe, idempotentes e protegidas contra concorrência.
- Assinatura JWS, `documentTotals` e `paymentReceipt.sourceDocuments` reconstruídos da mesma fotografia fiscal persistida.
- Testes de unidade, feature, isolamento, regressão, SQLite e concorrência MySQL.
- Homologação AGT bem-sucedida antes de remover o bloqueio preventivo de RG.
