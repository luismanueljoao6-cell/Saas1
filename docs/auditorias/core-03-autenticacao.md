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
| Convites | Admin define palavra-passe temporária e comunica por fora | Convite por e-mail com token (hash na BD), expiração, revogação e aceitação pelo próprio utilizador |
| 2FA | Só colunas na BD (sem UI nem fluxo); segredo sem cifra no model | TOTP, segredo e códigos cifrados, desafio no login e definições de segurança (Parte C) |
| Alterar a palavra-passe estando autenticado | Não existia (só o fluxo "esqueci") | Página de segurança, exige a atual (Parte D) |
| Terminar outras sessões | Não existia | Disponível, com a palavra-passe (Parte D) |
| Cabeçalhos de segurança | Nenhum | Middleware no grupo `web` (Parte D) |

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

## Parte C — 2FA

- TOTP (RFC 6238): SHA-1, 6 dígitos, passo de 30 s, tolerância de ±1 passo, comparação em tempo
  constante. Cada passo só pode ser usado uma vez (`two_factor_ultimo_passo`).
- O segredo e os códigos de recuperação ficam cifrados na BD (casts `encrypted`, chave `APP_KEY`).
  São 8 códigos de uso único, guardados como HMAC-SHA256.
- Login: palavra-passe certa + 2FA ativo ⇒ a sessão **não** é aberta. Fica só um estado pendente
  (10 minutos); o código tem limite de 5 tentativas por 5 minutos por utilizador+IP.
- Ativação em dois passos (segredo pendente + confirmação por código): só então passa a valer.
  Ativar, desativar e gerar novos códigos pedem a palavra-passe atual.

### Riscos residuais da Parte C

- Perder a `APP_KEY` inutiliza os segredos e os códigos de 2FA: guardar a chave em cofre e planear a rotação.
- Não há fluxo para um administrador da plataforma repor o 2FA de quem perdeu o telemóvel e os códigos.
- O 2FA não é obrigatório por papel nem por empresa (decisão para o CORE-05).
- Redefinir a palavra-passe por e-mail não pede o 2FA; o login seguinte pede.

## Parte D — fecho

- **Alterar palavra-passe** (`core.seguranca.palavra-passe`): exige a atual, recusa nova igual à atual,
  roda o `remember_token` e revoga os tokens Sanctum. A sessão atual mantém-se: o `AuthenticateSession`
  regrava o hash novo no fim do pedido; as outras sessões ficam com o hash antigo e caem no pedido seguinte.
- **Terminar outras sessões** (`core.seguranca.sessoes.terminar`): `Auth::logoutOtherDevices` + revoga tokens.
- **Cabeçalhos de segurança** (`CabecalhosDeSeguranca`, grupo `web`): `nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy`, `Permissions-Policy` e HSTS apenas sobre HTTPS. Nunca substitui um cabeçalho já definido.
  Desligável com `CORE_CABECALHOS_SEGURANCA=false`.

## Fora do CORE-03 (passa para as fases seguintes)

- **CORE-05** — autorização: 2FA obrigatório por papel/empresa; matriz de permissões das ações de segurança.
- **CORE-06** — API: emissão de tokens Sanctum, abilities e expiração.
- **CORE-08** — auditoria: eventos de segurança no Activitylog (hoje só `Log::info`).
- **CORE-11** — Content-Security-Policy (exige nonce no script do tema e remover o Alpine por CDN).
- Registo e convites continuam a revelar se um e-mail já tem conta (validação `unique`).
- Não existe lista de sessões ativas por dispositivo; só "terminar todas as outras".

## Parte B — convites

- Token aleatório de 64 caracteres; na BD fica só o SHA-256. Um só convite pendente
  por e-mail (convidar outra vez revoga o anterior). Validade: `core.convites.validade_dias`
  (por omissão 7 dias).
- O e-mail, a empresa e o papel vêm sempre do convite, nunca do formulário de aceitação.
- A vaga do plano conta convites pendentes ao convidar e é verificada outra vez ao
  aceitar, sob bloqueio da empresa.
- A ligação responde 404 igual para token desconhecido, expirado, revogado ou já usado,
  com `Referrer-Policy: no-referrer` e sem cache.
- Aceitar cria o utilizador com o e-mail verificado (a ligação provou o controlo da caixa).

### Riscos residuais da Parte B

- Ao convidar, o administrador fica a saber se um e-mail já tem conta na plataforma.
- O e-mail de convite é enviado de forma síncrona: uma falha de SMTP deixa o convite
  criado (convidar o mesmo e-mail outra vez substitui-o).
- Convites só podem ser aceites por visitantes: quem já tem sessão aberta é reencaminhado.
