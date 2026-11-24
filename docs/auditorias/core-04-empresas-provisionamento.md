# CORE-04 — Empresas, utilizadores e provisionamento

Auditoria sobre a `main` depois da integração do CORE-01, CORE-02 e CORE-03 (PR #87).
PR de trabalho: #89 (`core/04-empresas-provisionamento`).

## Estado encontrado e o que foi feito

| Requisito | Estado inicial | Estado atual (passo) |
|---|---|---|
| Ativar/desativar utilizador | Era um toggle (`ativo = !ativo`): repetir o pedido invertia o estado | O pedido declara o estado final (`ativo=0\|1`); repetir é idempotente; reativar respeita o limite do plano sob bloqueio da empresa (1, reaplica o #66) |
| Desativar quando dois administradores se desativam em simultâneo | O 2.º pedido (já desativado) desativava o outro e a empresa ficava sem administrador | O administrador que atua é relido sob o bloqueio da empresa; se já não for administrador ativo, 403 (5) |
| Credenciais de API de um utilizador desativado | Mantinham-se | Revogadas ao desativar (5) |
| Provisionamento ao ativar serviços | Falha de um provisionador desfazia tudo mas o administrador via um erro 500 | Falha registada com contexto e mensagem clara; serviços inalterados (2) |
| `core:provisionar-servicos` | Módulo a módulo sem transação: falha no módulo B deixava o que o A criou | Tudo-ou-nada por empresa; continua nas restantes; mostra a causa real (2) |
| Provisionadores idempotentes | Declarado, sem prova | Testado com os provisionadores reais; o registry avisa no log se uma chave é registada duas vezes (4) |
| Eliminação lógica e restauro da empresa | `SoftDeletes` no model, mas nada eliminava/restaurava e os utilizadores de uma empresa eliminada continuavam a entrar | `CicloDeVidaEmpresaService` (idempotente, linha bloqueada, só mexe em `deleted_at`); comandos `core:empresa-eliminar --confirmar` e `core:empresa-restaurar`; o middleware `tenant` termina a sessão (web) ou responde 403 (API); o login recusa a palavra-passe certa de empresa eliminada (3) |
| Convites de uma empresa eliminada | A página `/convite/{token}` dava erro 500 (a relação `empresa()` vem a `null`) | 404 com a mensagem única de convite inválido; aceitação recusada; voltam a valer ao restaurar a empresa (5) |
| Registo de empresa | Transação única (empresa, administrador, papel, serviços, provisionadores) | Sem alterações; registo repetido com o mesmo NIF falha sem deixar nada para trás (teste, 4) |
| NIF e nome legal com documentos fiscais emitidos | Bloqueados | Sem alterações |

## Decisões

- **Eliminar é lógico** (`deleted_at`) e nunca apaga dados: retenção de dados fiscais e NIF reservado.
- **Eliminar e restaurar só por linha de comandos.** Sem interface (fica para o CORE-09).
- **Não há "suspender" separado:** o estado comercial continua em `estado_subscricao` (módulo Subscrição).
- **Os provisionadores continuam a ser registados por módulo** no `TenantProvisionerRegistry`, com a chave igual ao valor do serviço.

## Riscos residuais

- Os provisionadores têm de ser idempotentes. Está provado para os existentes; o registry só avisa quando uma chave é registada duas vezes, não valida o comportamento dos provisionadores.
- Pedidos antigos a `alternar-ativo` sem o campo `ativo` falham a validação (a única interface conhecida foi atualizada).
- Quem eliminou ou restaurou uma empresa fica só em `Log::warning`; eventos de auditoria com o autor ficam para o CORE-08.
- Jobs e Outbox pendentes de uma empresa eliminada não foram tratados aqui (CORE-07).
- Convidar continua a revelar ao administrador se um e-mail já tem conta (herdado do CORE-03).

## Pendente / decisão

- **ModuleRegistry** (descoberta, dependências e ativação de módulos): o contrato previsto no CORE-01 ainda não existe. Hoje há o enum `Servico` (Atelier e Estúdio trazem a Faturação), o `TenantProvisionerRegistry` e o `modules_statuses.json` do Nwidart. Decidir se entra neste PR ou no CORE-06.
