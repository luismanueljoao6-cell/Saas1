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


## 9. Achado crítico adicional — fluxo financeiro do Estúdio de Música

A revisão de `FaturacaoEstudioService` e `FaturacaoEstudioTest` confirmou um segundo padrão que precisa de ser alinhado com o domínio central:

1. `gerarFaturaSinal()` calcula o sinal do pacote e cria/emite uma FT normal, embora o comentário reconheça que o adiantamento deve ser FA. Com AGT activa, o método bloqueia a operação em vez de usar o suporte FA já existente em `FaturacaoGateway::criarRascunhoAdiantamento()`.
2. `gerarFaturaSaldoFinal()` emite uma segunda FT apenas pelo valor ainda não faturado, em vez de emitir a FT final integral e registar o abatimento do adiantamento recebido.
3. `registrarRecibo()` emite RC ligado à factura. Com AGT activa, `ReciboService` bloqueia RC enquanto não existir o payload estrutural correcto.
4. A lógica de saldo do projeto soma facturas, notas de crédito e recibos; não usa o razão de abatimentos FA→FT do módulo Faturação. Portanto, o conceito de “faturado”, “recebido” e “abatido” continua distribuído entre o módulo consumidor e a Faturação.

Os testes actuais confirmam a aritmética da divisão em duas facturas e a contabilização local do projeto, mas não demonstram o ciclo FA→recebimento→FT integral→abatimento→saldo reconciliado, nem o comportamento completo com AGT activa.

### Correcção exigida

- Distinguir no Estúdio documentos de adiantamento (FA), factura final (FT), pagamentos recebidos e abatimentos.
- A Faturação deve ser a fonte de verdade do saldo; o Estúdio não deve recalcular saldos fiscais a partir de somas locais de facturas.
- Usar a API pública para criar/emitar FA e FT e para registar abatimentos idempotentes.
- O fluxo por hora deve igualmente respeitar o tipo de recibo permitido e não reportar pagamento concluído quando a Faturação o bloqueia.
- Preservar a possibilidade de notas de crédito e reemissão sem duplicar cobranças nem reabrir documentos imutáveis.

### Testes de regressão obrigatórios

- Pacote: FA emitida, recebimento suportado, FT integral emitida, abatimento, pagamento do saldo e resumo da FT reconciliado.
- Pacote com sinal anulado por NC: saldo disponível da FA correctamente ajustado e sem abatimento de valor já creditado.
- Projeto por hora: várias sessões, pagamentos parciais, notas de crédito e saldo consultado pelo gateway.
- AGT activa: operação bloqueada com mensagem explícita até existir tipo de recibo homologado; após suporte, validar payload e assinatura.
- Repetição concorrente: não duplicar factura, recibo, abatimento ou utilização de cupão.
- Comparar o saldo apresentado pelo Estúdio com `FaturacaoGateway::resumoDaFatura()` para cada documento; não manter duas fontes de verdade.

Este achado também bloqueia a conclusão da integração Estúdio/Faturação na Fase 1.



## 10. Contrato público disponível versus lacunas para implementar FA e recebimentos

A revisão do contrato real `Modules/Faturacao/app/Contracts/FaturacaoGateway.php`, da fachada `FaturacaoGatewayService` e dos serviços de domínio confirma que já existem estas operações públicas:

- `criarRascunhoAdiantamento()` para criar uma FA em rascunho;
- `emitirFatura()` para emitir documentos através do serviço fiscal central;
- `emitirRecibo()`, que emite exclusivamente RC;
- `emitirRegularizacao()`, que tenta emitir RG e permanece globalmente bloqueado enquanto não existirem alocações fiscais por linha;
- `abaterAdiantamento()` para registar o abatimento idempotente FA→FT;
- `resumoDaFatura()` e `saldoEmCentimos()` para consultar o razão financeiro central.

### Lacuna crítica confirmada

O contrato não expõe uma operação para emitir AR. A operação de recebimento genérica só emite RC; a operação específica de regularização só emite RG. No serviço actual, RC é bloqueado com AGT activa e RG é bloqueado em qualquer ambiente enquanto não houver alocações fiscais persistidas por linha.

A documentação AGT consultada descreve `paymentReceipt` para AR/RG. Portanto, não é correcto afirmar que o fluxo FA está pronto apenas porque existe `criarRascunhoAdiantamento()` e `abaterAdiantamento()`: falta definir e implementar o documento de recebimento permitido, o seu payload AGT, a numeração, a assinatura e a ligação imutável à FA. Não criar um novo tipo documental nem permitir RC/RG por tentativa sem confirmação normativa/homologação.

### Regras do serviço de abatimento que já estão centralizadas

`AbatimentoAdiantamentoService::abater()` já:
- exige empresa válida e aplica `GuardaEmissao`;
- bloqueia FA e FT por ordem de ID dentro da transacção;
- exige FA e FT emitidas e do mesmo cliente/empresa;
- calcula o disponível da FA a partir de recebimentos, abatimentos anteriores e notas de crédito;
- impede que o abatimento ultrapasse o saldo da FT;
- protege a chave idempotente contra reutilização com dados diferentes.

A integração dos módulos consumidores deve chamar esta API, sem replicar as validações. Antes de a usar em produção, os testes têm de provar que o recebimento permitido da FA é contado por `resumoDaFatura(FA)` e que a operação continua correcta perante NC, reexecução e concorrência MySQL.

### Sequência de implementação segura

1. Confirmar com a especificação integral/AGT qual o tipo de documento de recebimento aplicável à FA e se é AR, RG ou outro fluxo autorizado; registar a evidência normativa e a homologação.
2. Implementar no módulo Faturação o modelo, serviço, contrato e builder desse recebimento, incluindo payload, assinatura, sequência, ligação à FA e testes. Não remover os bloqueios RC/RG como atalho.
3. Adicionar testes do ciclo FA→recebimento permitido→FT integral→abatimento→recibo do saldo, primeiro no módulo Faturação e depois nos consumidores.
4. Só então migrar Atelier e Estúdio para o gateway e tornar o resumo da Faturação a fonte de verdade.
5. Preservar os documentos antigos; não gerar FA retroactivas nem alterar recibos assinados.

Esta lacuna é um bloqueador anterior à migração dos fluxos do Atelier e do Estúdio. A Fase 1 não pode ser marcada como concluída enquanto a sequência fiscal de recebimento de FA não estiver demonstrada.



## 11. Precisão adicional da documentação AGT — AR existe no schema, mas o fluxo de adiantamento não está demonstrado

A consulta directa e actual à página oficial **Registar Factura** confirma que a AGT enumera:
- `FA` — Factura de Adiantamento;
- `AR` — Aviso de Cobrança/Recibo;
- `RG` — Recibo (na página de registo; a página de listagem usa a designação Recibo Geral).

A mesma página estabelece que `AR` e `RG` não levam `lines` e têm obrigatoriamente `paymentReceipt`; os restantes tipos, incluindo `FA`, levam `lines` e não levam `paymentReceipt`. O `paymentReceipt.sourceDocuments` identifica o documento regularizado e as respectivas linhas, com `lineNo` a começar em 1. Os totais de AR/RG são agregados a partir dos documentos origem.

**Limite da evidência:** a documentação de estrutura enumera os tipos e os campos, mas não afirma expressamente que AR é o documento correcto para comprovar o recebimento de uma FA, nem descreve toda a sequência fiscal FA→recebimento→FT final/regularização. Logo, a existência do tipo AR no schema não autoriza o sistema a escolhê-lo automaticamente para sinais/adiantamentos. A regra tem de ser confirmada na documentação normativa completa e em homologação AGT, incluindo o tratamento de IVA e a referência da FA como documento origem.

### Divergências concretas encontradas no repositório

1. `Modules/Faturacao/config/config.php` declara FT, FA, NC, ND, RC e RG, mas não declara AR em `tipos_documento`.
2. `ReciboService` só emite RC ou RG. Não existe método público para AR no contrato `FaturacaoGateway` nem no serviço de domínio.
3. O `AgtDocumentoBuilder` bloqueia RG; para outros recibos só aceita AR/RG e chama o construtor de `paymentReceipt`. Assim, o caminho AR não está ligado ao emissor actual.
4. O método legado `AgtDocumentoBuilder::recibo()` gera um único `sourceDocuments` com `lineNo = 1` e `creditAmount` derivado do `netTotal` agregado assinado. Isso não representa alocações reais por linha para pagamentos parciais ou documentos com várias linhas.
5. `Recibo::construirStringParaAssinar()` calcula IVA proporcional a partir do total bruto da fatura e do IVA agregado. Esse cálculo não substitui uma distribuição fiscal persistida por linha.
6. A documentação AGT exige que o número de linha referenciado corresponda à linha do documento origem; portanto, a utilização fixa de `lineNo = 1` não pode ser considerada válida para qualquer documento com várias linhas.

### Decisão corrigida

- AR é um tipo previsto pela AGT e deve ser considerado na análise de implementação, mas não deve ser habilitado como recebimento de FA sem confirmação normativa/homologação.
- Não basta adicionar AR ao array de configuração. É necessário suportar série própria autorizada, persistência do tipo, assinatura, payload `paymentReceipt`, alocações imutáveis por linha, totais reconciliados e integração com o saldo.
- A implementação não pode usar `lineNo = 1` nem rateio proporcional agregado como substituto das alocações.
- Manter os bloqueios actuais de RC/RG até cada tipo documental suportado ter estrutura e testes completos. Não criar recibos assinados que a aplicação não consiga submeter à AGT.
- A homologação deve incluir FA como documento origem e testar AR e RG separadamente, apenas se a AGT confirmar que cada tipo se aplica ao cenário.

Esta precisão substitui qualquer leitura da secção 10 que pudesse sugerir que AR não existe na especificação AGT. A lacuna real é a ausência de suporte no produto e, sobretudo, a falta de evidência suficiente para decidir se AR é o documento fiscal correcto para liquidar uma FA.



## 12. Reconciliação adicional — os módulos consumidores ainda mantêm saldos paralelos

A leitura integral dos serviços e do modelo `ProjetoMusical` confirmou que a divergência não se limita aos sinais/adiantamentos.

### Atelier

Em `PedidoFaturacaoService::registarPagamentoFinal()`, o saldo é calculado manualmente como:

`fatura.valor_total - pedido.valor_sinal`

Esse cálculo não consulta `FaturacaoGateway::resumoDaFatura()` nem `saldoEmCentimos()`. Consequentemente, não considera de forma centralizada notas de crédito/débito, recibos parciais anteriores ou abatimentos registados no razão da Faturação. Mesmo depois de existir um fluxo fiscal correcto para o sinal, esta fórmula pode emitir um recibo de valor errado ou ser recusada pelo serviço central.

### Estúdio de Música

O modelo `ProjetoMusical` implementa a sua própria reconciliação consultando directamente `NotaCreditoDebito` e `Recibo`:
- `saldoDaFaturaCentimos()` subtrai notas de crédito e recibos, mas não inclui notas de débito nem abatimentos FA→FT;
- `situacaoDasFaturas()` repete a mesma fórmula local para cada fatura;
- `recebidoCentimos()` soma recibos ligados ao projecto e às faturas, sem considerar o registo central de abatimentos;
- `devidoCentimos()` calcula o valor global a partir das notas de crédito, sem incorporar os movimentos de abatimento da Faturação.

A Faturação, por sua vez, calcula o saldo como total + notas de débito − recibos − notas de crédito − abatimentos. As duas fórmulas não são equivalentes. O Estúdio pode apresentar um saldo diferente do módulo fiscal e libertar indevidamente uma entrega, ou impedir uma operação já liquidada.

### Contrato de valor também inconsistente

`FaturacaoEstudioService::registrarRecibo()` aceita `float` no parâmetro `$valor`, mas o contrato `FaturacaoGateway::emitirRecibo()` aceita apenas `string|int`. Um chamador que forneça float pode chegar a uma incompatibilidade de tipo em runtime. O contrato monetário do projecto deve ser decimal textual ou cêntimos inteiros, sem float.

### Correcção obrigatória

1. Eliminar fórmulas locais de saldo por documento e delegar na API pública `resumoDaFatura()`/`saldoEmCentimos()`.
2. Para saldos agregados do projecto, criar uma operação de consulta no gateway que devolva os resumos dos documentos autorizados do mesmo cliente/empresa, ou compor os resumos centrais no serviço de aplicação do Estúdio sem consultar directamente tabelas fiscais.
3. Incluir notas de débito, notas de crédito, recibos e abatimentos em todos os estados financeiros apresentados e nas regras de libertação de entregáveis.
4. Fazer o Atelier calcular o pagamento final pelo saldo central actual da FT, não pelo campo histórico `valor_sinal`.
5. Remover `float` das assinaturas de valores monetários e normalizar entradas com `Dinheiro`.
6. Testar paridade entre os totais mostrados nos módulos e os resumos centrais, incluindo NC, ND, recibos parciais, abatimentos, reexecução e concorrência.

Estes pontos devem ser corrigidos na Fase 1 antes de considerar a reconciliação financeira dos módulos consumidores concluída.



## 13. Cruzamento com a matriz de homologação existente — distinguir fluxo interno de conformidade AGT

A revisão de `docs/homologacao-agt.md` revelou evidência importante que deve ser preservada na auditoria: o projecto já documenta testes automáticos para o ciclo interno FA → recibo → abatimento → FT → recibo → NC → SAF-T, incluindo idempotência, concorrência, isolamento por empresa e reconciliação de saldos.

Isto significa que a conclusão correcta não é que o módulo Faturação não possui qualquer fluxo interno de adiantamentos. O serviço de abatimento e a respectiva cobertura de testes já existem. O bloqueio identificado nesta decisão é mais específico:

- a sequência interna de movimentos não demonstra, por si só, que os documentos de recebimento usados são os tipos correctos na facturação electrónica;
- a configuração actual não inclui AR;
- o serviço de recibos só emite RC/RG, com bloqueios explícitos;
- o builder ainda não reconstrói `paymentReceipt` a partir de alocações fiscais persistidas por linha;
- o ponto H3 da própria matriz de homologação continua aberto: representação de FA/abatimentos na FT, comunicação AGT e SAF-T;
- o ponto H7 também continua aberto: ensaio integral no ambiente de homologação AGT.

Os testes automáticos citados na matriz provam coerência interna conforme os cenários implementados; não provam aceitação do payload pela AGT nem a regra fiscal aplicável ao recebimento de uma FA.

### Acção de auditoria

Ao implementar a correcção, reutilizar os serviços e testes já existentes em vez de criar uma segunda contabilidade de adiantamentos. Acrescentar a camada documental AGT e os testes de payload/assinatura/alocações que faltam. Actualizar `docs/homologacao-agt.md` apenas quando existir evidência reproduzível: versão da especificação, payload enviado, resposta da homologação e commit correspondente.

Até H3 e H7 estarem fechados com evidência externa, manter `FATURACAO_ABATIMENTOS_ADIANTAMENTO_CONFIRMADOS=false` em produção e manter o bloqueio de exportação SAF-T de períodos com abatimentos, conforme a salvaguarda já documentada.

## 14. Salvaguarda implementada no Atelier — sinal legado

Na continuação da Fase 1 foi corrigido o comportamento de `PedidoFaturacaoService::registarPagamentoFinal()`:

- Antes, o serviço calculava o recibo final por `FT.valor_total - pedido.valor_sinal`. Esse sinal é um RC sem `fatura_id` e não aparece no resumo central da FT.
- Agora, se o pedido tiver `recibo_sinal_id`, o pagamento final é recusado com uma mensagem explícita. Não se cria um segundo recibo com valor inferior ao saldo fiscal registado.
- Quando não existe sinal legado, o montante do recibo é obtido por `FaturacaoGateway::saldoEmCentimos($fatura)`, respeitando o resumo oficial da Faturação (recibos, NC, ND e abatimentos).
- Foram acrescentados testes para o bloqueio do sinal legado e para a utilização do saldo central quando não existe sinal.

Esta é uma salvaguarda transitória e deliberadamente conservadora. Não converte recibos antigos em FA, não cria abatimentos retroactivos e não resolve o fluxo fiscal do sinal. O desbloqueio depende da implementação completa e validada de FA → recebimento permitido → FT integral → abatimento idempotente → recibo do saldo, incluindo homologação AGT aplicável.
