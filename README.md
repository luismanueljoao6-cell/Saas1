# Plataforma de Gestão Empresaria
l e Faturação (Angola)

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

## Serviços e fluxo de adesão

Fluxo do utilizador: **página de apresentação (`/`) → registo (`/registo`) ou login (`/login`) → painel**.

- No registo a empresa escolhe um ou vários **serviços**: `faturacao`, `atelier`, `estudio`
  (enum `Modules\Core\Support\Servico`). Atelier e Estúdio incluem sempre a Faturação, porque
  emitem recibos e faturas internamente.
- A adesão fica na tabela `empresa_servicos` (uma linha por empresa e serviço). O resto do código usa
  `Empresa::temServico()`, `Empresa::servicosAderidos()` e `Empresa::aderirServicos()`.
- O acesso é bloqueado pelo middleware `servico:<nome>` (`ExigirServico`), aplicado ao grupo de rotas
  autenticadas de cada módulo de negócio. Um serviço não aderido redireciona para o painel (pedidos
  JSON: 403). Super-admins não são afetados e um nome de serviço desconhecido nunca dá acesso.
- O menu (`MenuRegistry`) esconde sozinho os itens cujas rotas exigem um serviço não aderido, e o painel
  só mostra os cartões dos serviços aderidos.
- Os administradores gerem os serviços em `/servicos` (item "Serviços" do menu). Remover um serviço
  **desativa-o, nunca apaga dados**: voltar a aderir restaura o acesso. A Faturação nunca se remove,
  porque todos os outros serviços dependem dela (`Empresa::definirServicos()`).
- As landing pages públicas (`/loja/{slug}`, `/estudio/{empresa}`) passam pelo middleware
  `servico.empresa:<nome>`, que lê a empresa da própria URL e responde 404 se ela não aderiu ao serviço.
  Os portais de cliente (link assinado / token) ainda não são afetados.
- Empresas criadas antes desta funcionalidade receberam todos os serviços na migration.
- Todos os serviços partilham o mesmo período experimental e a mesma subscrição.

**Novo módulo vertical:** um caso novo no enum `Servico` e `servico:<nome>` nas rotas autenticadas do
módulo. Nada mais no Core muda.

### Preços e pacotes

A subscrição é por **pacote** (tabela `planos`): Faturação, Faturação + Atelier, Faturação + Estúdio e
Completo. Cada pacote lista os serviços que inclui (`planos.servicos`) e, ao confirmar o pagamento, os
serviços da empresa passam a ser os do pacote — os que ficam de fora são desativados, nunca apagados.

Os preços iniciais (`PlanosSeeder`, Kz/mês, IVA incluído) vêm de uma pesquisa de mercado de outubro de 2026
e são **hipóteses de lançamento**. Para alterar um preço:

```bash
php artisan planos:preco faturacao 6500
```

O novo preço aplica-se às próximas cobranças; pagamentos já gerados mantêm o valor, e repetir o seeder
nunca repõe preços alterados. Os planos de exemplo antigos são desativados, não apagados.

Testes: `php artisan test --filter=ServicosAderidosTest`.

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
- **`trustProxies`**: sem confiar no proxy, os `redirect()->route(...)` também
  saltam para `localhost`. O `bootstrap/app.php` já trata disto: no Codespaces
  (`CODESPACES=true`) confia em tudo automaticamente; em qualquer outro ambiente
  tens de definir `TRUSTED_PROXIES` (IPs do teu proxy/load balancer, separados
  por vírgula) — nunca `*` em produção.

## Variáveis de ambiente

Além das do Laravel, o `.env.example` traz as da plataforma:

| Variável | Para quê |
|---|---|
| `APP_TIMEZONE` | Fuso horário (`Africa/Luanda`) |
| `QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER` | Fila em base de dados; o `retry_after` tem de ser **maior** que o timeout do `GerarSafTJob` (600 s) |
| `FATURACAO_CHAVE_PRIVADA_PATH`, `FATURACAO_CHAVE_VERSAO` | Chave RSA privada que assina as faturas (fora do repositório) e a sua versão |
| `FATURACAO_TAXA_IVA_GERAL`, `FATURACAO_TAXAS_IVA_PERMITIDAS` | Taxas de IVA aceites na emissão |
| `FATURACAO_SAFT_NUMERO_CERTIFICADO` | Em produção, o SAF-T fica bloqueado enquanto for `0` |
| `TRUSTED_PROXIES` | IPs do proxy/load balancer; vazio = não confiar em ninguém |
| `SUBSCRICOES_GATEWAY`, `PROXYPAY_BASE_URL`, `PROXYPAY_API_KEY`, `PROXYPAY_ENTITY_ID`, `PROXYPAY_WEBHOOK_TOKEN` | Gateway de pagamentos ProxyPay |

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
php artisan test --filter=ServicosAderidosTest     # Serviços aderidos (Core)
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
