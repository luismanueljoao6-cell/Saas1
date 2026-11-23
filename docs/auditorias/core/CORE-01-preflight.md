# CORE-01 — Estado inicial (pré-voo)

Gerado em 2026-11-23T02:31Z a partir de origin/main (45caf81).

## Versões
```
PHP 8.5.1 (cli) (built: Feb 17 2026 12:46:50) (NTS)
Laravel Framework 13.42.0
```

## Dependências diretas
```
fakerphp/faker             1.24.1  Faker is a PHP library that generates fa...
laravel/framework          13.42.0 The Laravel Framework.
laravel/pail               1.2.7   Easily delve into your Laravel applicati...
laravel/pao                1.1.5   Agent-optimized output for PHP testing t...
laravel/pint               1.32.1  An opinionated code formatter for PHP.
laravel/sanctum            4.3.3   Laravel Sanctum provides a featherweight...
laravel/tinker             3.0.2   Powerful REPL for the Laravel framework.
mockery/mockery            1.6.15  Mockery is a simple yet flexible PHP moc...
nunomaduro/collision       8.9.5   Cli error handling for console/command-l...
nwidart/laravel-modules    13.0.0  Laravel Module management
phpunit/phpunit            12.5.36 The PHP Unit Testing framework.
spatie/laravel-activitylog 5.1.1   A very simple activity logger to monitor...
spatie/laravel-permission  8.3.0   Permission handling for Laravel 12 and up
```

## Pacotes de referência do prompt mestre
- laravel/fortify: NÃO instalado
- laravel/sanctum: instalado
- spatie/laravel-permission: instalado
- spatie/laravel-activitylog: instalado
- laravel/horizon: NÃO instalado
- laravel/pennant: NÃO instalado
- pestphp/pest: NÃO instalado
- spatie/laravel-data: NÃO instalado
- spatie/laravel-multitenancy: NÃO instalado
- stancl/tenancy: NÃO instalado
- spatie/laravel-event-sourcing: NÃO instalado
- nwidart/laravel-modules: instalado

## Contratos/interfaces existentes no Core
```
Modules/Core/app/Notifications/Contracts/NotificationChannel.php
Modules/Core/app/Contracts/LimitesDaEmpresa.php
Modules/Core/app/Repositories/Contracts/EmpresaRepositoryInterface.php
```

## Inventário de Modules/Core/app (ficheiros por pasta)
```
      1 Modules/Core/app/Console
      3 Modules/Core/app/Console/Commands
      1 Modules/Core/app/Contracts
      1 Modules/Core/app/Events
      2 Modules/Core/app/Exceptions
      4 Modules/Core/app/Http/Controllers
      2 Modules/Core/app/Http/Controllers/Auth
      6 Modules/Core/app/Http/Middleware
      4 Modules/Core/app/Http/Requests
      1 Modules/Core/app/Jobs
      5 Modules/Core/app/Models
      5 Modules/Core/app/Notifications
      1 Modules/Core/app/Notifications/Contracts
      1 Modules/Core/app/Notifications/DTO
      1 Modules/Core/app/Notifications/Exceptions
      1 Modules/Core/app/Notifications/Support
      2 Modules/Core/app/Providers
      1 Modules/Core/app/Repositories
      1 Modules/Core/app/Repositories/Contracts
      1 Modules/Core/app/Scopes
      6 Modules/Core/app/Services
      3 Modules/Core/app/Support
      1 Modules/Core/app/Traits
```

## Testes existentes no Core
```
Modules/Core/tests/Feature/BloqueioNifAposEmissaoTest.php
Modules/Core/tests/Feature/ContextoPermissoesQueueTest.php
Modules/Core/tests/Feature/EscritasSemContextoTest.php
Modules/Core/tests/Feature/EstadoSubscricaoTest.php
Modules/Core/tests/Feature/GarantirEmpresaTest.php
Modules/Core/tests/Feature/GerirServicosTest.php
Modules/Core/tests/Feature/IsolamentoTenantTest.php
Modules/Core/tests/Feature/ListarNotificacoesDesconhecidasTest.php
Modules/Core/tests/Feature/LoginContaDesativadaTest.php
Modules/Core/tests/Feature/ModelosDeNegocioUsamTenantTest.php
Modules/Core/tests/Feature/NifEdicaoEmpresaTest.php
Modules/Core/tests/Feature/NotificationProcessorTest.php
Modules/Core/tests/Feature/OrdemMiddlewareTenantTest.php
Modules/Core/tests/Feature/OutboxRecuperacaoAgendadaTest.php
Modules/Core/tests/Feature/OutboxResultadoDesconhecidoTest.php
Modules/Core/tests/Feature/ProtecaoAtribuicaoEmMassaTest.php
Modules/Core/tests/Feature/QueueRetryAfterTest.php
Modules/Core/tests/Feature/RegistoSessaoSeguraTest.php
Modules/Core/tests/Feature/ServicosAderidosTest.php
Modules/Core/tests/Feature/ServicosPublicosTest.php
Modules/Core/tests/Feature/SuperAdminPainelTest.php
Modules/Core/tests/Feature/UtilizadoresEPapeisTest.php
Modules/Core/tests/Unit/NotificationChannelRegistryLazyTest.php
Modules/Core/tests/Unit/NotificationChannelRegistryTest.php
Modules/Core/tests/Unit/NotificationOutboxModelTest.php
Modules/Core/tests/Unit/NotificationRetryPolicyTest.php
```

## PRs abertos (gh)
```
#71 [fix/armazem-stock-minimo-atomico-20261122] (rascunho) fix(armazem): tornar configuração de stock mínimo atómica
#70 [fix/armazem-auditoria-utilizadores-tenant-20261122] (rascunho) fix(armazem): isolar identidades de auditoria por empresa
#68 [fix/armazem-impedir-inativacao-inventario-pendente-20261121] (rascunho) fix(armazem): impedir inativação com inventário pendente
#66 [fix/core-utilizadores-estado-idempotente-20261120] (rascunho) fix(core): tornar ativação de utilizadores idempotente
#65 [fix/armazem-rececao-mesma-transferencia-20261119] (rascunho) fix(armazem): impedir linhas de rececao entre transferencias
#64 [fix/armazem-stock-inventarios-tenant-fks-20261118] (rascunho) fix(armazem): enforce tenant integrity on stock and inventories
#63 [fix/armazem-transferencias-tenant-fks-20261117] (rascunho) fix(armazem): enforce tenant integrity in transferências e receções
#62 [plan/fase-5-desempenho-ux-20261116] (rascunho) [Fase 5] Otimizar desempenho, acessibilidade e experiência transversal
#61 [plan/fase-4-seguranca-operacoes-20261116] (rascunho) [Fase 4] Endurecer segurança, filas, integrações e operação em produção
#60 [plan/fase-3-arquitetura-dados-20261116] (rascunho) [Fase 3] Desacoplar módulos e reforçar integridade estrutural da BD
#59 [plan/fase-2-conformidade-fiscal-agt-20261116] (rascunho) [Fase 2] Consolidar faturação fiscal, integração AGT e SAF-T AO
#58 [plan/fase-1-integridade-critica-20261116] (rascunho) [Fase 1] Corrigir isolamento multiempresa e integridade financeira
#57 [fix/estudio-provisionamento-aditivo-main] (rascunho) fix(estudio): preservar permissões personalizadas no provisionamento
#56 [fix/faturacao-stock-ledger-unificado] (rascunho) fix(stock): unificar histórico e precisão no painel de faturação

--- PR #66 ---
{"changedFiles":3,"headRefName":"fix/core-utilizadores-estado-idempotente-20261120","isDraft":true,"mergeable":"MERGEABLE","state":"OPEN"}
```

## Branches remotas não integradas que tocam nos ficheiros que este PR altera
```
origin/audit/codespace-commits-2026-11: Modules/Core/app/Providers/CoreServiceProvider.php 
origin/fix/audit-hardening-2026-11: Modules/Core/app/Providers/CoreServiceProvider.php 
```
