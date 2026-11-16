## Objetivo
Eliminar os riscos críticos de isolamento entre empresas e de inconsistência financeira/documental identificados na auditoria. Esta fase protege os dados e os saldos antes de alterações fiscais ou operacionais de maior alcance.

## Âmbito e correções especificadas
1. **Isolamento multiempresa (CORE-001, SEG-002, SEG-006, FIS-051):**
   - Rever consultas, relações, policies, controllers, Form Requests, jobs, commands e listeners que usam IDs fornecidos pelo cliente.
   - Garantir que empresa, utilizador, cliente, produto, fatura, armazém, pagamento e documento relacionado pertencem ao mesmo tenant.
   - Auditar caminhos com `withoutGlobalScopes()`, execução sem middleware e mudança/restauro do contexto TenantManager/Spatie.
   - Corrigir qualquer acesso cruzado confirmado e adicionar testes IDOR negativos para cada endpoint sensível.
2. **Adiantamentos Ateliê/Estúdio (FIS-047, FIS-052, FIS-053, EST-FLOW-003, EST-UI-002/003):**
   - Unificar o ciclo de sinal/adiantamento através do FaturacaoGateway e do modelo de Fatura de Adiantamento (FA), incluindo cenário AGT ativo.
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
- O ciclo FA → recibo → FT → abatimento → recibo de saldo → conta corrente é consistente, incluindo AGT ativa e períodos diferentes.
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