# Plataforma de Gestão Empresarial e Faturação (Angola)

Aplicação SaaS multi-tenant de gestão empresarial e faturação, construída
em Laravel 13 com arquitetura modular (`nwidart/laravel-modules`). Este
README cobre o projeto como um todo; cada módulo tem o seu próprio README
com os detalhes de arquitetura e as decisões tomadas.

## Estado atual

| Módulo | Estado | README |
|---|---|---|
| **Core** (empresas, autenticação, permissões, navegação) | Completo e testado | `Modules/Core/README.md` |
| **Subscrições e Pagamentos** (planos, ProxyPay, grace period) | Completo e testado | `Modules/Subscricoes/README.md` |
| **Faturação & Gestão Financeira** (faturas, hash fiscal, SAF-T) | Completo e testado; **não certificado pela AGT** | `Modules/Faturacao/README.md` |
| **Atelier de Costura** | Em desenvolvimento (código e testes presentes; por rever) | — |
| **Estúdio de Música** | Em desenvolvimento (código e testes presentes; por rever) | — |

Os três primeiros módulos foram validados de duas formas: testes
automatizados (`php artisan test`) e uso real, ponta a ponta, num
GitHub Codespace — registo de empresa, login, criação e emissão de
faturas, subscrição a um plano, e exportação SAF-T processada em fila.

## ⚠️ Conformidade AGT — ler antes de qualquer uso real

O módulo de Faturação implementa a numeração sequencial, a cadeia de
assinatura RSA e a imutabilidade de documentos exigidas pela AGT, mas isto
**não substitui a certificação do software junto da AGT**, obrigatória
para faturação eletrónica real em Angola desde janeiro de 2026 (grandes
contribuintes) e a alargar a todas as empresas em 2027. Detalhes completos
em `Modules/Faturacao/README.md`.

## Stack

- **Backend**: Laravel 13, PHP 8.3+ (ambiente de desenvolvimento em 8.4)
- **Base de dados**: MySQL (SQLite em memória nos testes automatizados)
- **Frontend**: Tailwind CSS 4, Alpine.js (via CDN), Vite
- **Módulos**: `nwidart/laravel-modules`
- **Permissões e auditoria**: `spatie/laravel-permission` (com "teams" por
  empresa), `spatie/laravel-activitylog`
- **Filas**: driver `database` (Redis/Horizon ainda não configurados — ver
  "Próximos passos")

## Arquitetura em duas frases

Cada empresa (tenant) é isolada por `empresa_id` em cada tabela de
negócio, aplicado automaticamente por um Global Scope com política
*fail-closed* (bloqueia por omissão, nunca expõe dados de outra empresa
por engano) — ver `Modules/Core/app/Scopes/TenantScope.php` e
`Modules/Core/app/Traits/BelongsToTenant.php`. A navegação entre módulos
é resolvida por um registo central (`Modules\Core\Services\MenuRegistry`)
ao qual cada módulo acrescenta os seus próprios itens no `boot()` do seu
`ServiceProvider` — o Core nunca precisa de saber que os outros módulos
existem.

## Instalação num GitHub Codespace (validada — foi assim que este projeto arrancou)

Isto reflete os passos reais, incluindo os problemas que apanhámos a
integrar tudo pela primeira vez — não é só a teoria de cada README de
módulo.

1. **Criar o Codespace** a partir do repositório.

2. **Instalar o Laravel 13 de raiz**, numa pasta temporária e depois mover
   para a raiz do repositório (o Composer recusa instalar em pastas não
   vazias, mesmo só com `.git` lá dentro):
```bash
   composer create-project laravel/laravel tmp-laravel "13.*"
   shopt -s dotglob && mv tmp-laravel/* . && rmdir tmp-laravel
```

3. **Instalar as dependências dos módulos**:
```bash
   composer require nwidart/laravel-modules spatie/laravel-permission spatie/laravel-activitylog laravel/sanctum
```

4. **Copiar `Modules/Core`, `Modules/Subscricoes` e `Modules/Faturacao`** para a pasta `Modules/` do projeto.

5. **Registar os módulos no autoload da raiz** — o `composer.json` de cada
   módulo não chega sozinho; o `composer.json` da RAIZ do projeto precisa
   de apontar para lá também (`app/` e `database/seeders/` de cada um):
```json
   "autoload": {
       "psr-4": {
           "App\\": "app/",
           "Database\\Factories\\": "database/factories/",
           "Database\\Seeders\\": "database/seeders/",
           "Modules\\Core\\": "Modules/Core/app/",
           "Modules\\Subscricoes\\": "Modules/Subscricoes/app/",
           "Modules\\Faturacao\\": "Modules/Faturacao/app/",
           "Modules\\Core\\Database\\Seeders\\": "Modules/Core/database/seeders/",
           "Modules\\Subscricoes\\Database\\Seeders\\": "Modules/Subscricoes/database/seeders/",
           "Modules\\Faturacao\\Database\\Seeders\\": "Modules/Faturacao/database/seeders/"
       }
   },
```
```bash
   composer dump-autoload
   php artisan module:enable Core Faturacao Subscricoes
```

6. **Apontar a autenticação para o `User` do Core** — em `config/auth.php`,
   `providers.users.model` para `\Modules\Core\Models\User::class`, e
   apagar `app/Models/User.php` (o Core substitui-o por completo).

7. **Publicar e configurar o spatie/laravel-permission e o
   spatie/laravel-activitylog**:
```bash
   php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
   php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider"
```
   Em `config/permission.php`: `'teams' => true` e
   `'team_foreign_key' => 'empresa_id'`.

8. **Variáveis de ambiente** — ver a secção seguinte.

9. **Gerar a chave RSA da Faturação**:
```bash
   mkdir -p storage/app/faturacao
   openssl genrsa -out storage/app/faturacao/chave-privada.pem 2048
   openssl rsa -in storage/app/faturacao/chave-privada.pem -pubout -out storage/app/faturacao/chave-publica.pem
   echo "storage/app/faturacao/" >> .gitignore
```

10. **Migrar e semear**:
```bash
    php artisan migrate
    php artisan db:seed --class="Modules\Core\Database\Seeders\CoreDatabaseSeeder"
    php artisan db:seed --class="Modules\Subscricoes\Database\Seeders\PlanosSeeder"
```

11. **Ligar o CSS do Core ao Vite** — em `vite.config.js`, acrescentar
    `'Modules/Core/resources/css/app.css'` à lista `input` do plugin
    `laravel(...)`. Depois:
```bash
    npm install
    npm run build
```

12. **Registar as tabelas e o worker de filas** (o Laravel 13 já costuma
    trazer as migrations de `jobs`/`failed_jobs`; confirma antes de
    recriar):
```bash
    ls database/migrations/ | grep -i -E "jobs|failed"
    php artisan migrate   # só se o passo acima não mostrar nada
```
    `QUEUE_CONNECTION=database` no `.env`.

13. **Acrescentar a testsuite dos módulos ao `phpunit.xml`** (por omissão
    só conhece `tests/Unit` e `tests/Feature`):
```xml
    <testsuite name="Modules">
        <directory>Modules/*/tests/Feature</directory>
        <directory>Modules/*/tests/Unit</directory>
    </testsuite>
```

### Particularidades do GitHub Codespaces (não são do Laravel em si)

Estas três só aparecem porque o Codespaces corre atrás de um proxy — não
acontecem num servidor normal:

- **`ASSET_URL`**: sem isto no `.env`, os `<link>`/`<script>` gerados pelo
  Vite apontam para `localhost`, que não existe fora do próprio
  Codespace. Define `ASSET_URL` para o domínio público
  (`https://<nome-do-codespace>-8000.app.github.dev`, visível em
  `echo "https://${CODESPACE_NAME}-8000.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"`).
- **`trustProxies`**: sem isto, os `redirect()->route(...)` também saltam
  para `localhost`. Em `bootstrap/app.php`, dentro de `->withMiddleware()`:
  `$middleware->trustProxies(at: '*');`
- **`public/hot`**: se correste `npm run dev` alguma vez, este ficheiro
  fica para trás e força o Blade a apontar sempre para o servidor de
  desenvolvimento do Vite, mesmo depois de correres `npm run build`. Se o
  CSS parar de carregar sem explicação, `rm -f public/hot` é o primeiro
  sítio a olhar.

## Variáveis de ambiente


## Correr a aplicação

```bash
php artisan serve --host=0.0.0.0    # terminal 1
php artisan queue:work --verbose    # terminal 2 — processa Jobs em fila
```

(`npm run dev` não é necessário para correr a aplicação — só para
desenvolvimento ativo de CSS/JS, e nem sempre funciona bem através do
proxy do Codespaces; `npm run build` gera os ficheiros finais que o
`php artisan serve` já serve sozinho.)

## Testes

```bash
php artisan test --filter=IsolamentoTenantTest    # Core
php artisan test --filter=FluxoPagamentoTest       # Subscrições
php artisan test --filter=CadeiaFiscalTest         # Faturação
```

## Próximos passos

- **Atelier de Costura** e **Estúdio de Música** — módulos de negócio
  verticais; cada um só precisa de `BelongsToTenant` nos seus models e,
  onde vender bens/serviços gerar necessidade de fatura, podem chamar
  `Modules\Faturacao\Services\FaturaService` diretamente.
- **Redis + Laravel Horizon** — a fila `database` funciona, mas o pedido
  original previa Redis/Horizon para monitorização e desempenho a sério.
- **2FA** — as colunas já existem em `users` (formato Fortify); falta o
  fluxo de ecrãs (QR code, códigos de recuperação).
- **Verificação de e-mail** — o model `User` implementa `MustVerifyEmail`,
  mas o fluxo de confirmação (página, reenvio, middleware `verified`)
  ainda não foi construído.
- **CSS por módulo** — Subscrições e Faturação reaproveitam o CSS do Core
  por agora; separá-los cumpriria a intenção original à letra.
- **Validação do SAF-T (AO) contra o XSD oficial da AGT** — ver aviso de
  conformidade acima.

## Processos em segundo plano (obrigatórios em produção)

Sem estes dois processos a faturação da plataforma **não funciona**:

| Processo | Para quê | Comando |
|---|---|---|
| **Worker de filas** | Confirmar pagamentos (webhook), exportar SAF-T, enviar e-mails em fila | `php artisan queue:work --tries=3 --max-time=3600` |
| **Agendador** | Job diário de subscrições (expirar, suspender, gerar renovações) | cron: `* * * * * cd /caminho && php artisan schedule:run >> /dev/null 2>&1` |

Em desenvolvimento: `php artisan queue:work` e `php artisan schedule:work` em terminais separados.
Em produção, mantém o worker vivo com Supervisor ou systemd e reinicia-o em cada deploy (`php artisan queue:restart`).

A expiração do acesso é avaliada em tempo real (`Empresa::subscricaoAtiva()`), por isso um
atraso do agendador não dá acesso gratuito; o job apenas atualiza estados e gera renovações.

### Webhook ProxyPay

URL a configurar no painel: `https://SEU-DOMINIO/api/webhooks/proxypay?token=<PROXYPAY_WEBHOOK_TOKEN>`.
Os pagamentos com valor inferior ao devido são rejeitados e ficam em `failed_jobs` para revisão manual
(`php artisan queue:failed`).
