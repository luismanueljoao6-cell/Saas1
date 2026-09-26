# Subscrições e Pagamentos (SaaS Billing)

Segunda entrega do sistema: controla o ciclo de vida da subscrição de cada
empresa (plano, referência de pagamento, confirmação, renovação) e liga-se
ao **Core** — que já tinha os campos (`estado_subscricao`,
`periodo_tolerancia_ate`) e os métodos (`TenantService::ativar/suspender`)
preparados precisamente para este módulo.

> **Depende do módulo Core.** Instala o Core primeiro — este módulo usa
> `Modules\Core\Models\Empresa`, `Modules\Core\Services\TenantManager` e
> `Modules\Core\Services\TenantService` diretamente.

## Sobre o gateway de pagamento — o que encontrei

Pesquisei as opções para "Multicaixa Express" antes de escrever código,
porque não faz sentido inventar uma API. Eis o que está realmente
disponível hoje:

- **EMIS GPO** é o gateway "oficial" por trás do Multicaixa Express. A
  adesão faz-se através de um banco (vi um formulário de adesão do Access
  Bank), com três formas de integração: API completa (com certificação),
  iFrame simples, ou através de um PST-SP (integrador certificado). O
  cliente final paga pela app MCX Express, por número de telemóvel ou QR
  Code. É um processo robusto mas com fricção de arranque — não dá para
  gerar uma referência de teste sem passar pela adesão bancária.
- **ProxyPay** (proxypay.co.ao) é um agregador de pagamento por referência
  no Multicaixa, com documentação pública e SDKs de terceiros em vários
  ecossistemas (Ruby, Node.js, PHP, plugins Magento/WooCommerce). É a opção
  com o caminho de integração mais direto para arrancar — por isso é a que
  implementei (`ProxyPayGateway`).
- Também encontrei **AppyPay** e **Ekwanza**, dois wrappers não-oficiais
  que também dão acesso ao GPO/Multicaixa Express (incluindo cobrança
  "push" por número de telemóvel, não só referência). Não os implementei
  para não multiplicar integrações não testadas nesta entrega, mas como a
  arquitetura está desenhada por trás de `GatewayPagamentoInterface`,
  qualquer um destes é, no limite, uma nova classe.

**Sê honesto contigo próprio sobre isto:** escrevi o `ProxyPayGateway` com
base na documentação pública e em SDKs de terceiros, não numa chamada real
testada com credenciais válidas — este ambiente de execução não tem acesso
à rede. A forma geral está correta (API key no cabeçalho, referência com
`amount`/`expiry_date`/`custom_fields`, webhook de confirmação), mas os
nomes exatos de campos/endpoint estão marcados com `// TODO` no código
para confirmares contra a documentação atual antes de produção.

## Estrutura entregue

```
Modules/Subscricoes/
├── module.json, composer.json
├── config/config.php                      # gateway ativo, credenciais, moeda
├── database/
│   ├── migrations/                        # planos, subscricoes, pagamentos
│   └── seeders/PlanosSeeder.php           # 3 planos de exemplo (ajustar preços)
├── app/
│   ├── Models/{Plano,Subscricao,Pagamento}.php
│   ├── Services/
│   │   ├── Gateways/
│   │   │   ├── Contracts/GatewayPagamentoInterface.php
│   │   │   └── ProxyPayGateway.php
│   │   ├── SubscricaoService.php          # inicia subscrição + referência
│   │   └── PagamentoService.php           # confirma pagamento (idempotente)
│   ├── Http/
│   │   ├── Controllers/{PlanoController,SubscricaoController,Webhooks/ProxyPayWebhookController}.php
│   │   ├── Requests/IniciarSubscricaoRequest.php
│   │   └── Middleware/VerificarAssinaturaWebhook.php
│   ├── Jobs/{ProcessarPagamentoConfirmadoJob,VerificarSubscricoesExpiradasJob}.php
│   ├── Notifications/PagamentoConfirmadoNotification.php
│   ├── Exceptions/GatewayPagamentoException.php
│   └── Providers/{SubscricoesServiceProvider,RouteServiceProvider}.php
├── routes/{web,api}.php
├── resources/views/{planos/index,subscricao/pendente}.blade.php
└── tests/Feature/FluxoPagamentoTest.php
```

## Decisões de arquitetura mais importantes

**1. `Empresa.estado_subscricao` continua a ser a fonte usada para
controlo de acesso; `Subscricao` é a fonte de verdade detalhada.** Sempre
que uma subscrição muda de estado, o `SubscricaoService`/`PagamentoService`
chamam o `TenantService` do Core para manter os dois sincronizados. O
middleware `VerificarSubscricaoAtiva` do Core continua a só olhar para
`Empresa`, sem precisar de saber que este módulo existe — é isto que
permite que os módulos futuros (Atelier, Estúdio) não tenham de repetir
esta lógica.

**2. Webhooks e jobs agendados correm com bypass explícito de tenant.**
Descobri isto a meio da implementação: `Pagamento` e `Subscricao` usam a
trait `BelongsToTenant` do Core (correto — são dados de negócio de uma
empresa), mas um webhook do gateway não tem nenhum utilizador autenticado,
logo não há tenant definido, e a `TenantScope` fail-closed bloquearia
sempre a busca pela referência. A solução foi usar
`TenantManager::semTenant()` explicitamente nos dois sítios onde isso
acontece (`PagamentoService::localizarPorReferencia/confirmar` e
`VerificarSubscricoesExpiradasJob`) — nunca de forma automática ou ampla.
Vale a pena leres os comentários nesses ficheiros.

**3. Confirmação de pagamento é idempotente.** Gateways reenviam webhooks
(falhas de rede, timeouts). `PagamentoService::confirmar()` verifica o
estado atual antes de qualquer escrita — confirmar duas vezes o mesmo
pagamento nunca duplica o período de subscrição.

**4. O webhook nunca faz trabalho pesado.** `ProxyPayWebhookController`
só interpreta o payload e despacha `ProcessarPagamentoConfirmadoJob` — a
escrita na base de dados e o envio de notificações correm em fila, para o
gateway receber um 200 rápido (evita reenvios desnecessários por timeout).

**5. Confirmação manual reutiliza o mesmo caminho.** `PagamentoService::confirmar()`
aceita um `$confirmadoPor` opcional — é o gancho para um super admin
confirmar uma transferência bancária reconciliada manualmente (o "outros
métodos alternativos" do pedido original), sem duplicar lógica.

## O que fica para depois (por desenho)

- **Confirmação manual não tem UI própria ainda** — o método
  `PagamentoService::confirmar($pagamento, [], $utilizadorAdmin)` já
  suporta o caso de uso; falta o controller/rota de administração.
- **Lembrete de renovação** — `aviso_renovacao_dias` já está na
  configuração, mas a notificação em si não foi escrita nesta entrega.
- **EMIS GPO direto / AppyPay / Ekwanza** — ficam como implementações
  alternativas de `GatewayPagamentoInterface`, não construídas aqui.
- **Faturas geradas pela subscrição em si** (a "fatura da fatura", por
  assim dizer) pertencem ao Módulo de Faturação, não a este.

## Instalação

1. **Copiar o módulo** (a seguir ao Core) e correr `composer dump-autoload`.

2. **Variáveis de ambiente** — acrescenta ao `.env`:
   ```
   SUBSCRICOES_GATEWAY=proxypay
   PROXYPAY_BASE_URL=https://api.proxypay.co.ao
   PROXYPAY_API_KEY=
   PROXYPAY_WEBHOOK_TOKEN=
   SUBSCRICOES_MOEDA=AOA
   SUBSCRICOES_VALIDADE_REFERENCIA_DIAS=3
   SUBSCRICOES_AVISO_RENOVACAO_DIAS=3
   ```

3. **Migrar e semear**
   ```bash
   php artisan migrate
   php artisan db:seed --class="Modules\Subscricoes\Database\Seeders\PlanosSeeder"
   ```
   Edita os preços em `PlanosSeeder` antes disto — os valores incluídos são
   apenas exemplos para desenvolvimento.

4. **Configurar o webhook na ProxyPay** — no painel da tua conta, aponta as
   notificações de pagamento para:
   ```
   https://<o-teu-dominio>/api/webhooks/proxypay
   ```
   e confirma no código (`ProxyPayGateway::validarPedidoWebhook`) qual é o
   mecanismo de assinatura que a tua conta usa.

5. **Garantir que o scheduler e as filas estão a correr** — no Codespace
   (ou no servidor):
   ```bash
   php artisan schedule:work   # ou schedule:run via cron a cada minuto
   php artisan queue:work      # idealmente gerido pelo Laravel Horizon (ver Core)
   ```
   Sem isto, os pagamentos ficam confirmados na fila mas nunca processados,
   e as subscrições vencidas nunca transitam para suspensa/expirada.

6. **Correr os testes**
   ```bash
   php artisan test --filter=FluxoPagamentoTest
   ```

## Rotas principais

| Rota | Método | Descrição |
|---|---|---|
| `/planos` | GET | Página de preços |
| `/subscricao` | POST | Inicia subscrição a um plano (gera referência) |
| `/subscricao/pagamentos/{pagamento}` | GET | Mostra a referência gerada |
| `/api/webhooks/proxypay` | POST | Recebe confirmação de pagamento (ProxyPay) |

## Sugestão para a próxima entrega

Com o Core e as Subscrições prontos, já dá para uma empresa se registar,
subscrever, pagar e manter o acesso — o essencial do "SaaS" em si está
fechado. A partir daqui, tanto o **Atelier de Costura** como o **Estúdio
de Música** são módulos de negócio "normais" (cada um só precisa de
`BelongsToTenant` nos seus models), sem mais nenhuma peça de infraestrutura
em falta. Diz qual preferes.
