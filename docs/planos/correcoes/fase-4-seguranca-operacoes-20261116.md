## Objetivo
Tornar os processos assíncronos e a operação de produção previsíveis, recuperáveis, auditáveis e seguros perante falhas, concorrência e indisponibilidade de fornecedores.

## Âmbito e correções especificadas
1. **Filas e eventos de subscrição (SUB-001 a SUB-010):**
   - Garantir reserva atómica/idempotente de eventos antes do despacho e unicidade por gateway/evento.
   - Definir tratamento de eventos duplicados, fora de ordem, falhados e presos; retries com backoff e limite.
   - Resolver corrida confirmação ↔ expiração e garantir recuperação de eventos pendentes sem dupla cobrança/ativação.
   - Verificar limites de plano sob concorrência e bloqueio de faturação após expiração.
2. **Campanhas, notificações e Outbox:**
   - Auditar consentimento, exclusões, segmentação e isolamento tenant.
   - Garantir Outbox transacional, idempotência por campanha/tentativa/destinatário/canal, reenvio manual controlado e histórico imutável.
   - Cobrir falhas de fornecedores, throttling, limites e recuperação; nunca reenviar campanha concluída automaticamente.
3. **Segurança de rotas e ficheiros (SEG-001 a SEG-006):**
   - Fazer matriz de autorização por ação e recurso, não depender apenas de nomes de papéis.
   - Cobrir IDOR, mass assignment, manipulação de payload, rate limiting e download de SAF-T.
   - Rever segredos, cookies, sessões, CORS/CSRF e cabeçalhos de segurança sem quebrar portais.
4. **Scheduler, workers e observabilidade (OPS-013 a OPS-019):**
   - Confirmar agendamento real de reconciliação de pagamentos e rotinas de saúde; corrigir comandos sem schedule.
   - Separar filas fiscal, SAF-T, financeiro, stock e notificações quando operacionalmente justificado.
   - Definir retries/timeouts alinhados com retry_after, locks distribuídos e supervisão de workers.
   - Implementar correlation/request IDs, métricas e alertas para AGT, pagamentos, SAF-T, filas e webhooks.
5. **Produção e recuperação (OPS-002 a OPS-012):**
   - Definir perfis de produção MySQL/Redis/storage partilhado/sessões distribuídas.
   - Separar setup de desenvolvimento, instalação inicial e deploy; impedir `composer setup` de executar migrações destrutivas em ambiente existente.
   - Documentar deploy, rollback, backup/restauro, RPO/RTO, rotação de chaves e testes de recuperação.
   - Criar verificação de saúde e alertas de scheduler/workers efetivamente ativos.

## Critérios de aceitação
- Eventos duplicados ou concorrentes não causam efeitos duplicados.
- Todas as rotinas críticas têm recuperação e observabilidade.
- Produção usa cache/locks/sessões/filas compatíveis com múltiplas instâncias.
- Deploy e rollback são repetíveis e não executam setup destrutivo.
- Backups têm prova de restauro, não apenas existência de ficheiros.

## Testes obrigatórios
- Testes concorrentes de webhooks, pagamentos e expiração.
- Testes de falha/retry de Outbox, campanhas e fornecedores.
- Testes de permissões, IDOR, downloads e rate limits.
- Testes scheduler/workers, locks distribuídos e recuperação de jobs presos.
- Ensaio de deploy/rollback e restauro de backup num ambiente de teste.

## Dependências
A Fase 1 deve estabilizar invariantes financeiras e a Fase 2 os contratos fiscais antes de fechar a operação de filas fiscais.