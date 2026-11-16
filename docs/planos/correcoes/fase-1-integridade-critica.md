## Objetivo
Eliminar os riscos críticos de isolamento entre empresas e de inconsistência financeira/documental identificados na auditoria. Esta fase protege os dados e os saldos antes de alterações fiscais ou operacionais de maior alcance.

## Âmbito e correções especificadas
1. **Isolamento multiempresa (CORE-001, SEG-002, SEG-006, FIS-051):**
   - Rever consultas, relações, policies, controllers, Form Requests, jobs, commands e listeners que usam IDs fornecidos pelo cliente.
   - Garantir que empresa, utilizador, cliente, produto, fatura, armazém, pagamento e documento relacionado pertencem ao mesmo tenant.
   - Auditar caminhos com `withoutGlobalScopes()`, execução sem middleware e mudança/restauro do contexto TenantManager/Spatie.
   - Corrigir qualquer acesso cruzado confirmado e adicionar testes IDOR negativos para cada endpoint sensível.
2. **Adiantamentos Ateliê/Estúdio (FIS-047, FIS-052, FIS-053, EST-FLOW-003, EST-UI-002/003):**
   - Unificar o ciclo de sinal/adiantamento através do FaturacaoGateway e do modelo de Fatura de Adiantamento (FA), sem activar emissão de recibos RG com AGT até a semântica e o payload serem comprovados contra a especificação e homologação.
   - Garantir associação inequívoca do adiantamento ao cliente/empresa/projeto/pedido, sem depender exclusivamente de `recibos.fatura_id`.
   - No fecho, gerar FT e abater o adiantamento de forma atómica, idempotente e auditável; impedir dupla utilização do crédito.
3. **Reconciliação financeira (FIS-049, FIS-055, FIS-056, QA-002):**
   - Reconciliar cronologicamente FT, FA, NC, ND, recibos, abatimentos e saldos por cliente, inclusive quando ocorrem em períodos diferentes.
   - Definir regras para pagamentos parciais, várias FA/FT, NC após recibo, anulações e abatimentos parciais.
   - Não permitir saldo negativo ou crédito duplicado por concorrência.
4. **Notas de crédito (FIS-059):**
   - Verificar e corrigir atomicidade/locks no limite acumulado de NC face ao documento original; impedir que operações concorrentes ultrapassem o valor permitido.
5. **Stock e compras (FIS-050, ARZ-007, DB-001/006/010/011/016):**
   - Garantir coerência empresa-produto-armazém-movimento-compra-pagamento em constraints e serviços.
   - Proteger histórico financeiro de compras; rever cascades destrutivos e adotar restrição/soft delete ou arquivo conforme semântica existente.
   - Garantir idempotência e atomicidade quando emissão, receção ou pagamento falham parcialmente.

## Critérios de aceitação
- Nenhum teste de utilizador/empresa A consegue ler ou alterar dados da empresa B, incluindo jobs/commands e ficheiros.
- O ciclo FA → recibo → FT → abatimento → recibo de saldo → conta corrente é consistente nos cenários suportados; o caminho AGT só é aceite quando o tipo documental e o payload estiverem conformes e comprovados.
- Repetições e concorrência não duplicam recibos, abatimentos, movimentos, pagamentos ou notas de crédito.
- Documentos emitidos e histórico financeiro não são apagados por cascades indevidas.
- Todas as invariantes são verificadas no servidor e, quando possível, também por constraints da BD.

## Testes obrigatórios
- Testes HTTP IDOR por módulo e testes de isolamento em jobs/commands.
- Testes de integração Ateliê e Estúdio com AGT ativa/inativa.
- Testes de ciclo financeiro completo, períodos diferentes, pagamentos parciais, NC e anulações.
- Testes concorrentes reais em MySQL para abatimentos, NC, pagamentos e stock; SQLite não substitui estes testes.
- Testes de integridade referencial multiempresa e regressão das funcionalidades atuais.

## Fora do âmbito
- Novas funcionalidades comerciais não relacionadas com integridade.
- Alterações visuais não necessárias para tornar estados financeiros claros.
- Certificação/homologação AGT, tratada na Fase 2.

## Condição de conclusão
PR só pode sair de draft quando testes críticos passam, migrations são seguras e reversíveis/operacionalmente documentadas, e os resultados MySQL e SQLite aplicáveis estão anexados. Não declarar segurança absoluta apenas por inspeção estática.

## Decisão técnica adicional — recibos RG e alocação por linha (2026-11-16)

A revisão do código e da documentação oficial AGT identificou uma lacuna que bloqueia a activação segura de RG:

- `Recibo` persiste apenas `fatura_id` e valor global; não persiste a distribuição do pagamento pelas linhas do documento de origem.
- `AgtDocumentoBuilder::recibo()` gera actualmente um único `sourceDocuments` com `lineNo = 1`. A especificação AGT define `lineNo` como o número da linha do documento de origem incluída no recibo.
- `creditAmount` representa o valor regularizado da factura/documento rectificativo sem impostos e descontos. O RG apura `netTotal` e `taxPayable` pela soma dos totais dos documentos de origem regularizados; NC é considerada com sinal negativo. A regra E41 limita a regularização ao remanescente do documento.
- O cálculo proporcional do imposto a partir do valor bruto recebido não é prova suficiente de conformidade para pagamentos parciais, várias linhas ou taxas diferentes.
- `adiantamento_abatimentos` já é o registo imutável e idempotente da utilização contabilística de uma FA numa FT. Deve ser reutilizado para essa finalidade, não duplicado nem confundido com a alocação fiscal de um recibo.

### Modelo a implementar antes de activar RG

Criar no módulo Faturação uma entidade própria de alocação de recibos a linhas de documentos de origem. A entidade deverá permitir reconstruir o payload sem inferências e auditar, no mínimo: empresa, recibo, documento de origem, linha de origem, número de linha AGT, montante regularizado e dados fiscais necessários à reconciliação. A modelação final deve respeitar as regras de FK e isolamento multiempresa existentes.

O serviço central deverá:
1. bloquear o documento de origem e as alocações relevantes durante a validação;
2. validar empresa, cliente, estado, tipo documental e pertença da linha ao documento;
3. impedir sobrealocação por linha/documento e pagamentos concorrentes;
4. persistir as alocações na mesma transacção da emissão;
5. aplicar idempotência sem permitir reutilização de chave com dados diferentes;
6. construir e verificar assinatura/payload exclusivamente a partir dos dados persistidos;
7. tratar documentos rectificativos e sinais de NC de acordo com a especificação AGT.

### Condição fiscal de bloqueio

Não assumir que a distribuição proporcional do IVA resolve pagamentos parciais. A documentação define os totais de RG em função dos documentos de origem regularizados, mas a regra exacta de arredondamento e de regularização parcial por linha deve ser comprovada com casos oficiais e homologação. Até existir essa evidência, manter o RG com AGT activa bloqueado. Não substituir RC por RG apenas alterando `tipo_documento`.

### Matriz mínima de testes

- Pagamento parcial e pagamentos sucessivos da mesma factura;
- factura com várias linhas e taxas de IVA diferentes;
- linha isenta/não sujeita;
- nota de crédito/documento rectificativo como origem;
- tentativa de exceder o remanescente (incluindo concorrência MySQL);
- linha inexistente ou pertencente a outra factura/empresa;
- repetição idempotente e reutilização de chave com conteúdo diferente;
- consistência exacta entre alocações, totais assinados e JSON enviado à AGT.

Fontes de especificação consultadas:
- AGT, serviço Registar Factura: https://quiosqueagt.minfin.gov.ao/doc-agt/doc-agt/2/servicos/servicos_solicitar_registo.html
- AGT, serviço Consultar Factura: https://quiosqueagt.minfin.gov.ao/doc-agt/doc-agt/2/servicos/servicos_consultar.html

Esta decisão complementa o âmbito da Fase 1 e não declara implementação funcional nem homologação concluídas.
