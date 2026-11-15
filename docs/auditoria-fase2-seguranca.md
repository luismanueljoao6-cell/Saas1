# Auditoria — Fase 2: Segurança e multi-tenancy

## Já correto (verificado no código)

- `TenantScope` é fail-closed; `TenantManager` é `scoped` e `semTenant()` restaura o estado em `finally`.
- `IdentificarTenant` limpa o estado, verifica `ativo` a cada pedido e trata super admins sem tenant; corre antes do route model binding.
- Jobs: `ProcessNotificationJob` leva o `empresaId`; o tenant e o team do Spatie são limpos entre jobs.
- `UtilizadorController`, `EmpresaController` e os FormRequests verificam empresa e papel; `is_super_admin` não é atribuível em massa.
- Webhook ProxyPay: HMAC-SHA256 do corpo original, `hash_equals`, falha fechado sem API key, evento guardado antes de responder 2xx, idempotente por hash do corpo.
- Serviços da Faturação auditados (`NumeracaoService`, `SafTExportService`) filtram sempre por `empresa_id` explícito.
- Models com `empresa_id` sem `BelongsToTenant` (decisão deliberada, agora registada e coberta pelo teste): `Perfil` e `PortfolioItem` (Atelier; lidos sem tenant pela landing pública — a área admin filtra por `empresa_id` do utilizador, exige Administrador e limita o upload a jpg/png/webp até 4 MB) e `AgtSubmissao` (acedida pela relação `agtSubmissao()` dos documentos, que já têm TenantScope, e por comandos/jobs entre empresas).

## Corrigido neste PR

| # | Prio | Problema | Correção |
|---|------|----------|----------|
| 1 | P2 | `config/queue.php`: `after_commit` a `false` (jobs podiam correr antes do commit) | `QUEUE_AFTER_COMMIT`, por omissão `true` |
| 2 | P2 | `config/session.php`: cookie `secure` e sessão cifrada desligados por omissão | ligados por omissão em `APP_ENV=production` (override por env) |
| 3 | P2 | `LoginRequest`: conta desativada com palavra-passe certa não contava para o limitador | `RateLimiter::hit` nesse caminho + teste |
| 4 | — | Nada impedia um model novo com `empresa_id` de esquecer `BelongsToTenant` | teste `ModelosDeNegocioUsamTenantTest` (exceções: `User`, `EmpresaServico`, justificadas) |

## Pendente (não alterado de propósito)

- **P1 — `BelongsToTenant`**: criar/atualizar/eliminar são permitidos sem tenant e sem bypass.
  Apertar à cegas parte o registo público (`RegistoEmpresaService` corre sem tenant) e os testes.
  Plano: envolver o registo e os provisioners em `semTenant()`/tenant da nova empresa, depois
  lançar `LogicException` em escritas sem contexto, com a suite completa a validar.
- **P2 — Login**: a mensagem para conta desativada ainda distingue o motivo (só depois de acertar a palavra-passe). Decisão de produto: manter a mensagem útil ou torná-la genérica.
- **P2 — `NumeracaoService::hashDoDocumentoAnterior`**: consulta só por `serie_id` em `semTenant()`. Seguro hoje (a série vem filtrada por empresa); passar `empresaId` como defesa em profundidade.
- **P3 — `AtualizarEmpresaRequest`**: o bloqueio do NIF só considera faturas `emitida` (não recibos, notas, anuladas).
- **P3 — `TenantManager::clear()`** não repõe a flag de bypass (mitigado pelo `finally`).
- **P3 — Docblock de `AgtSubmissao`** diz que nunca é exposta em pedidos HTTP, mas o estado AGT parece ser mostrado na interface através de `agtSubmissao()`; o isolamento vem do documento. Não carregar essa relação em `semTenant()` sem filtrar por empresa, e corrigir o comentário.
- **P3 — Comentários desatualizados** em `Subscricoes/routes/web.php` e `SubscricaoController` dizem que o binding corre antes do tenant; `bootstrap/app.php` já corrige a ordem.
- **P3 — `ProcessarPagamentoConfirmadoJob`** leva o payload do gateway sem `ShouldBeEncrypted`.

## Ainda por auditar

Controllers/uploads/jobs de Atelier, Estúdio e Armazém; restantes usos de `withoutGlobalScopes()` na Faturação; seeds de permissões Spatie; concorrência nas operações financeiras.

## Produção

Definir `SESSION_SECURE_COOKIE=true` (já é o padrão em production), servir só por HTTPS e `TRUSTED_PROXIES` com os IPs do proxy. Em ambientes de staging sem HTTPS, `SESSION_SECURE_COOKIE=false` explícito.
