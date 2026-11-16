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


## 7. Precisão da identidade da linha e assinatura

A auditoria do construtor confirmou que as linhas da fatura são consultadas pela relação ordenada por `ordem`, e o payload AGT atribui `lineNumber = índice + 1`. A alocação futura deve guardar exactamente esse número fiscal enviado à AGT (base 1), e não copiar cegamente `fatura_linhas.ordem` (base 0). A identidade técnica `fatura_linha_id` e o número fiscal original devem ambos ser preservados.

Foi também confirmado que `Recibo::construirStringParaAssinar()` calcula actualmente o imposto do recibo por proporção entre o valor bruto recebido e os totais agregados de IVA/bruto da fatura. Essa operação não constitui uma alocação por linha nem resolve o rateio entre linhas com taxas diferentes. Não reutilizar esse valor proporcional como prova de alocação AGT. A futura emissão RG deve gerar o payload assinado a partir do mesmo snapshot de alocações explícitas que alimenta `paymentReceipt`; divergências devem impedir a emissão.

A implementação deve ainda separar claramente:
- **pagamento recebido** (valor bruto liquidado pelo cliente);
- **valor fiscal alocado à linha** (líquido, imposto e bruto segundo regra validada);
- **montante ainda disponível** em cada linha/documento origem.

A soma dos valores brutos alocados tem de reconciliar com o valor do recibo; os valores líquidos e impostos têm de reconciliar com os totais fiscais assinados, sem arredondamentos ocultos.


## 8. Achado crítico adicional — fluxo de sinal no Atelier não reconcilia com a Faturação

A leitura conjunta de `PedidoFaturacaoService`, `ReciboService`, `AbatimentoAdiantamentoService`, `Pedido` e dos testes de integração confirmou uma divergência financeira:

1. `registarSinal()` emite um RC com `fatura_id = null` e guarda apenas `valor_sinal` no pedido.
2. `gerarFaturaFinalRascunho()` cria uma FT pelo valor integral do pedido; não cria FA nem associa o sinal a um documento fiscal de adiantamento.
3. `registarPagamentoFinal()` calcula manualmente `FT.valor_total - pedido.valor_sinal` e emite esse valor como recibo ligado à FT.
4. `ReciboService::resumoDaFatura()` soma apenas recibos emitidos ligados à FT, notas de crédito/débito e abatimentos registados em `adiantamento_abatimentos`. O RC de sinal, por ter `fatura_id = null`, não entra nesse resumo; também não existe abatimento FA→FT.

Consequência: o recibo final pode reflectir o saldo manualmente calculado, mas o razão interno da FT continua a apresentar o valor do sinal como dívida. Os testes actuais validam o valor do segundo recibo, mas não verificam que `resumoDaFatura()` fica liquidado. A cobertura actual, portanto, não detecta a divergência.

Existe ainda uma incompatibilidade operacional: com AGT activa, `ReciboService` bloqueia a emissão de RC e RG. Como o Atelier só tenta emitir RC sem FA e não possui um caminho alternativo suportado, o registo do sinal não funciona nesse modo fiscal.

### Correcção exigida (não implementar por suposição)

O fluxo-alvo deve ser centralizado no contrato público da Faturação e usar o tipo documental e a sequência confirmados para o cenário fiscal:
- criar e emitir FA para o adiantamento, quando legalmente aplicável;
- registar o recebimento associado à FA por um tipo de recibo que a especificação AGT e a homologação permitam;
- criar e emitir FT pelo valor integral da venda;
- abater o saldo efectivamente recebido da FA à FT através de `abaterAdiantamento()`, com chave idempotente estável;
- emitir recibo apenas pelo saldo ainda em dívida da FT;
- reconciliar `resumoDaFatura(FT)` para saldo zero (ou para o saldo remanescente real, se pagamento parcial).

O pedido operacional deve preservar referências separadas à FA, ao documento de recebimento do adiantamento e à FT. Não reutilizar `valor_sinal` como substituto de um movimento financeiro/fiscal registado na Faturação.

### Critérios de regressão obrigatórios

- Teste de integração com AGT inactiva: sinal → FA/recibo suportado → FT → abatimento → recibo de saldo; validar os totais e `resumoDaFatura(FT)`.
- Teste com sinal parcial e total, e com materiais/linhas múltiplas.
- Teste de repetição/duplo clique: não duplicar FA, recibo, abatimento nem pagamento final.
- Teste de falha entre cada passo: transacção ou recuperação idempotente sem deixar referências parciais.
- Teste com AGT activa: não apresentar sucesso se o tipo de recibo necessário ainda estiver bloqueado ou não homologado.
- Teste de migração compatível com pedidos antigos que só têm `recibo_sinal_id` e `valor_sinal`; preservar as referências históricas e não inventar FA retroactiva.

Este achado é bloqueador da conclusão da integração Atelier/Faturação na Fase 1. A mesma revisão deve ser aplicada ao fluxo equivalente do Estúdio de Música antes de declarar os módulos consumidores reconciliados.
