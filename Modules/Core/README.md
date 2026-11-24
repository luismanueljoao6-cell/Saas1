# Módulo Core

Infraestrutura comum do SaaS1: multi-tenancy, autenticação, empresas,
serviços contratados e notificações (Outbox). Os restantes módulos consomem
o Core através de **contratos**, nunca de classes internas.

## Contratos públicos (`Modules\Core\Contracts`)

| Contrato | Implementação | Para quê |
|---|---|---|
| `TenantContextInterface` | `Services\TenantContext` | Ler a empresa atual. `requireTenant()` lança `TenantNaoEncontradoException` se não houver empresa (fail-closed). |
| `FeatureAccessInterface` | `Services\FeatureAccess` | `servicoAtivo(Servico\|string, ?int)`: a empresa contratou e tem ativo o serviço? Sem empresa, ou com empresa diferente da do contexto, devolve `false`. Não é feature flag nem limite comercial. |
| `NotificationDispatcherInterface` | `Notifications\NotificationDispatcher` | Enviar notificações pela Outbox, idempotentes por (empresa, canal, chave). |
| `TenantProvisionerInterface` | `Services\TenantProvisionerRegistry` | Registar o que cada módulo precisa por empresa. Os provisionadores têm de ser idempotentes. |
| `LimitesDaEmpresa` (já existia) | `Services\SemLimites` (substituível por Subscrições) | Limites comerciais do plano. |
| `EmpresaRepositoryInterface` (já existia, em `Repositories\Contracts`) | `Repositories\EmpresaRepository` | Acesso a dados da empresa. |

## Regras para quem desenvolve um módulo

- Código novo injeta as **interfaces**, não o `TenantManager` nem classes concretas. O `TenantManager` continua a ser o mecanismo interno (scopes, middleware, jobs).
- Rotas autenticadas de negócio usam os middlewares `tenant` e, quando for um serviço contratado, `servico:<valor>`.
- Não consultar tabelas nem controladores de outros módulos; comunicar por contratos e eventos.
- O Core não calcula impostos nem emite documentos fiscais: isso é da Faturação.

```php
public function __construct(
    private TenantContextInterface $tenant,
    private NotificationDispatcherInterface $notificacoes,
) {}

public function notificar(string $destino): void
{
    $this->notificacoes->dispatch('email', $destino, ['assunto' => '...'], 'exemplo:'.$this->tenant->requireTenant());
}
```

## Autenticação (CORE-03)

| Fluxo | Rotas (nome) | Notas |
|---|---|---|
| Login / logout / registo | `core.login`, `core.logout`, `core.registo` | Limitador por e-mail+IP e por IP. |
| Recuperar palavra-passe | `password.request`, `password.email`, `password.reset`, `password.update` | Resposta idêntica exista ou não a conta; contas desativadas não recebem ligação. Revoga tokens Sanctum e remember_token. |
| Verificar e-mail | `verification.notice`, `verification.verify`, `verification.send` | Ligação assinada e temporária; reenvio limitado. |
| Convites | `core.convite.mostrar`, `core.convite.aceitar` | Token de 64 caracteres (na BD só o SHA-256), expira, uma só vez; e-mail, empresa e papel vêm sempre do convite. |
| 2FA (TOTP) | `core.2fa.desafio`, `core.seguranca.index` | Segredo e códigos cifrados; sem sessão até o código ser aceite; ações sensíveis pedem a palavra-passe. |
| Conta | `core.seguranca.palavra-passe`, `core.seguranca.sessoes.terminar` | Alterar palavra-passe e terminar outras sessões; revogam tokens de API. |

- `CORE_EXIGIR_EMAIL_VERIFICADO=true` faz o middleware `tenant` exigir e-mail verificado em **todos** os módulos (omissão: `false`).
- O grupo `web` inclui `AuthenticateSession`: mudar a palavra-passe termina as outras sessões.
- `CORE_CABECALHOS_SEGURANCA=false` desliga os cabeçalhos de segurança (omissão: ligados).
- Estado, decisões e riscos residuais: `docs/auditorias/core-03-autenticacao.md`.

## Ciclo de vida da empresa (CORE-04)

- `php artisan core:empresa-eliminar {id} --confirmar` e `core:empresa-restaurar {id}`: eliminação lógica
  (só `deleted_at`, nunca apaga dados), idempotente. Enquanto eliminada, o middleware `tenant` termina a sessão
  (web) ou responde 403 (API), o login é recusado e os convites pendentes deixam de valer.
- `php artisan core:provisionar-servicos`: tudo-ou-nada por empresa; continua nas restantes e mostra a causa.
- Estado, decisões e riscos residuais: `docs/auditorias/core-04-empresas-provisionamento.md`.

## Ainda por fazer neste módulo

Contratos previstos e **ainda não existentes**: `AuthorizationInterface`,
`ModuleRegistryInterface` e `AuditLoggerInterface`. Serão acrescentados depois
de auditar o Spatie Permission (Teams), o Nwidart e o Activitylog instalados.
