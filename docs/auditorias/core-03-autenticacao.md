# CORE-03 — Autenticação e identidade

Auditoria feita sobre a branch `core/02-multitenancy` (Laravel 13.17, PHP ^8.4,
Sanctum 4, Spatie Permission 8 com Teams, Activitylog 5; sem Fortify).

## Estado encontrado

| Requisito | Estado inicial | Estado após a Parte A |
|---|---|---|
| Login, logout, registo | Existem; limitador por e-mail+IP e por IP | Sem alterações |
| Recuperação de palavra-passe | **Não existia** (rotas, controllers, views, e-mail) | Implementado, resposta anti-enumeração |
| Verificação de e-mail | Model implementa `MustVerifyEmail`, mas **sem rotas nem envio** | Implementado; exigência configurável |
| Reenvio de verificação | Não existia | Implementado (`throttle:6,1`) |
| Sessões ao mudar a palavra-passe | Sessões antigas continuavam válidas | `AuthenticateSession` no grupo `web` |
| Tokens Sanctum ao mudar a palavra-passe | Mantinham-se | Revogados na redefinição |
| Convites | Admin define palavra-passe temporária e comunica por fora | **Pendente (Parte B)** |
| 2FA | Só colunas na BD (sem UI nem fluxo); segredo sem cifra no model | **Pendente (Parte C)** |

## Decisões

- **Sem Fortify.** O Core já tem fluxos próprios (registo de empresa, tenant,
  serviços). O Fortify registaria rotas e ações paralelas e a compatibilidade com
  Laravel 13 não foi verificada. Reaproveitam-se os brokers e notificações do
  próprio Laravel.
- **Verificação de e-mail desligada por omissão** (`CORE_EXIGIR_EMAIL_VERIFICADO=false`),
  para não bloquear contas existentes nem os testes dos outros módulos. Quando
  ligada, aplica-se no middleware `tenant`, ou seja, a todos os módulos.
- **Rotas de verificação sem `tenant`**, para evitar ciclo de redirecionamento.
- **Contas desativadas** não recebem ligação de recuperação nem conseguem redefinir.
- Redefinir a palavra-passe marca o e-mail como verificado (a ligação provou o controlo da caixa).

## Riscos residuais da Parte A

- O envio de e-mail só acontece para contas existentes; em SMTP síncrono o tempo
  de resposta pode distinguir contas existentes. Mitigação futura: enviar em fila.
- `MAIL_MAILER=log` (desenvolvimento) escreve a ligação com o token em `storage/logs`.
- O registo continua a dizer "já existe uma conta com este e-mail" (validação `unique`).
- Com `SESSION_DRIVER=database`, `AuthenticateSession` invalida as sessões no
  pedido seguinte; não apaga as linhas da tabela `sessions`.

## Pendente neste CORE-03

- **Parte B** — convites com ligação e token (hash), expiração e aceitação, em
  substituição da palavra-passe temporária.
- **Parte C** — 2FA TOTP com códigos de recuperação e desafio no login.
