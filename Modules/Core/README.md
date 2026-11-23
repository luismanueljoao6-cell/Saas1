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

## Ainda por fazer neste módulo

Contratos previstos e **ainda não existentes**: `AuthorizationInterface`,
`ModuleRegistryInterface` e `AuditLoggerInterface`. Serão acrescentados depois
de auditar o Spatie Permission (Teams), o Nwidart e o Activitylog instalados.
