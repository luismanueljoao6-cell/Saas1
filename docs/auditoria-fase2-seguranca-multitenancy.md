# Auditoria — Fase 2: Segurança e multi-tenancy

Método: leitura do código dos módulos Core, Subscrições, Faturação (serviços de numeração e SAF-T), Armazém, Estúdio e partes do Atelier, sem ferramenta de pesquisa global. Não foi executada em MySQL. O que não foi lido está listado em «Ainda por auditar».

## Já correto (verificado no código)

- `TenantScope` é fail-closed; `TenantManager` é `scoped` e `semTenant()` restaura o estado em `finally`.
- `IdentificarTenant` limpa o estado, verifica `ativo` a cada pedido e trata super admins sem tenant; corre antes do route model binding.
- Jobs: `ProcessNotificationJob` leva o `empresaId`; o tenant e o team do Spatie são limpos entre jobs.
- `UtilizadorController`, `EmpresaController` e os FormRequests verificam empresa e papel; `is_super_admin` não é atribuível em massa.
- Webhook ProxyPay: HMAC-SHA256 do corpo original, `hash_equals`, falha fechado sem API key, evento guardado antes de responder 2xx, idempotente por hash do corpo.
- `NumeracaoService` e `SafTExportService` filtram sempre por `empresa_id` explícito.
- Armazém: inventários e stock filtram por `empresa_id` explícito, usam `lockForUpdate`, validam que a linha pertence ao inventário e à empresa; rotas com `permission:` e `throttle`.
- Portal do Estúdio: token guardado em SHA-256 com expiração, lido só da rota, cabeçalhos `no-store`/`no-referrer`/`noindex`, tenant limpo em `finally`.
- Models com `empresa_id` sem `BelongsToTenant` por decisão deliberada, agora registados e cobertos por teste: `User`, `EmpresaServico`, `Perfil` e `PortfolioItem` (Atelier; lidos sem tenant pela landing pública — a área admin filtra por `empresa_id` do utilizador, exige Administrador e limita o upload a jpg/png/webp até 4 MB) e `AgtSubmissao` (acedida pela relação `agtSubmissao()` dos documentos, que já têm TenantScope, e por comandos/jobs entre empresas).

## Corrigido neste PR

| # | Prio | Problema | Correção |
|---|------|----------|----------|
| 1 | P2 | `config/queue.php`: `after_commit` a `false` (jobs podiam correr antes do commit) | `QUEUE_AFTER_COMMIT`, por omissão `true` |
| 2 | P2 | `config/session.php`: cookie `secure` e sessão cifrada desligados por omissão | ligados por omissão em `APP_ENV=production` (override por env) |
| 3 | P2 | `LoginRequest`: conta desativada com palavra-passe certa não contava para o limitador | `RateLimiter::hit` nesse caminho + teste |
| 4 | — | Nada impedia um model novo com `empresa_id` de esquecer `BelongsToTenant` | teste `ModelosDeNegocioUsamTenantTest` (exceções justificadas) |
| 5 | P1 | `BelongsToTenant`: criar/atualizar/eliminar sem tenant nem bypass eram sempre permitidos | `core.tenant.escritas_sem_contexto` = `permitir` (testes) / `avisar` (omissão) / `bloquear` (fail-closed); testes em `EscritasSemContextoTest`, incluindo o registo público em modo `bloquear` |

## Rollout do modo de escritas sem contexto

1. Deploy com o padrão (`avisar`): nada muda para os utilizadores; os logs passam a ter «Escrita sem tenant identificado nem bypass explícito».
2. Durante uns dias, rever esses avisos (model, operação, consola/HTTP). Cada um é um fluxo legítimo a envolver em `TenantManager::semTenant()` ou a correr com tenant, ou um bug. Atenção especial: landing pública do Estúdio, webhook ProxyPay, Atelier público e jobs.
3. Sem avisos legítimos: `CORE_TENANT_ESCRITAS_SEM_CONTEXTO=bloquear` em produção.
4. Para ver o que os testes cobrem: `CORE_TENANT_ESCRITAS_SEM_CONTEXTO=bloquear php -d memory_limit=1G artisan test` (esperam-se falhas em factories que escrevem sem tenant — são informativas, não bugs de produção).

## Pendente (não alterado)

- **P2 — E-mail não verificado**: `User` implementa `MustVerifyEmail`, mas o registo autentica logo e não encontrei o middleware `verified` nas rotas lidas. Confirmar se é intencional.
- **P2 — Login**: a mensagem para conta desativada ainda distingue o motivo (só depois de acertar a palavra-passe). Decisão de produto: manter a mensagem útil ou torná-la genérica.
- **P2 — `NumeracaoService::hashDoDocumentoAnterior`**: consulta só por `serie_id` em `semTenant()`. Seguro hoje (a série vem filtrada por empresa); passar `empresaId` como defesa em profundidade.
- **P3 — `core.super_admin_emails`**: documentado como promoção a super admin no registo, mas não encontrei consumidor nos ficheiros lidos. Se existir, tem de exigir e-mail verificado; se não, remover a chave.
- **P3 — Armazém sem `servico:`**: as rotas só têm `auth`, `tenant`, `subscricao.ativa` e `permission:`; o Armazém acompanha a Faturação (que nunca se remove). Seguro hoje; acrescentar `servico:faturacao` por coerência exige adaptar os testes do Armazém.
- **P3 — Portal do Estúdio** não verifica se a empresa ainda tem o serviço `estudio` nem a subscrição ativa.
- **P3 — Landing do Estúdio** usa o `id` numérico da empresa no URL (enumerável); o Atelier usa slug.
- **P3 — Docblock de `AgtSubmissao`** diz que nunca é exposta em pedidos HTTP, mas o estado AGT parece ser mostrado na interface através de `agtSubmissao()`; o isolamento vem do documento. Não carregar essa relação em `semTenant()` sem filtrar por empresa, e corrigir o comentário.
- **P3 — `AtualizarEmpresaRequest`**: o bloqueio do NIF só considera faturas `emitida` (não recibos, notas, anuladas).
- **P3 — `TenantManager::clear()`** não repõe a flag de bypass (mitigado pelo `finally`).
- **P3 — Comentários desatualizados** em `Subscricoes/routes/web.php` e `SubscricaoController` dizem que o binding corre antes do tenant; `bootstrap/app.php` já corrige a ordem.
- **P3 — `ProcessarPagamentoConfirmadoJob`** leva o payload do gateway sem `ShouldBeEncrypted`.

## Ainda por auditar

Controllers e jobs do Atelier (além de perfil e portfólio), restantes usos de `withoutGlobalScopes()` na Faturação, seeds de permissões Spatie e concorrência nas operações financeiras fora do Armazém e da numeração.

## Produção

Servir só por HTTPS e configurar `TRUSTED_PROXIES` com os IPs do proxy. `SESSION_SECURE_COOKIE` e `SESSION_ENCRYPT` passam a `true` por omissão em `APP_ENV=production`; em staging sem HTTPS definir `SESSION_SECURE_COOKIE=false` explicitamente.
