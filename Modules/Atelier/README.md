# Atelier de Costura

Módulo de negócio para ateliers de costura/alfaiataria: fichas de medidas com
histórico, pedidos de peça com fluxo de produção e provas, ligação direta ao
motor fiscal do Faturação (sinal + fatura final), notificações automáticas
ao cliente, CRM/marketing básico, portal do cliente por link assinado e
página pública com portfólio. Depende dos módulos **Core** e **Faturacao**.

## Sobre os canais externos (WhatsApp/SMS) — o que encontrei

Sem acesso à rede neste ambiente de desenvolvimento (e sem uma conta/número
reais para testar), `WhatsAppCanal` e `SmsCanal` foram escritos a partir da
documentação pública da WhatsApp Business Cloud API e de um contrato REST
genérico para SMS — **não testados contra um fornecedor real**, ao contrário
do email (que funciona de imediato, via `Notification`). Antes de produção,
confirma sobretudo:

- **WhatsApp**: se a mensagem precisa de um *template* pré-aprovado pela Meta
  (fora da janela de 24h de conversa, texto livre é normalmente recusado) e
  a versão exata da Graph API.
- **SMS**: qual fornecedor angolano vais usar — o requisito não especificava
  um, ao contrário do pagamento (onde o Subscrições já tinha escolhido
  ProxyPay/Multicaixa) — e ajustar `SmsCanal` ao formato exato dele.

Por omissão, `atelier.canais_notificacao` só tem `mail` — os outros dois só
disparam quando preencheres as credenciais em `.env` (ver `config/config.php`).

## Estrutura entregue

- **Medidas** (`Medida`) — cada registo é uma fotografia completa; nunca se
  edita uma linha, cria-se sempre uma nova (é assim que "histórico" existe
  sem tabela de auditoria à parte).
- **Pedidos** (`Pedido`) — tipo de serviço, descrição, fotos, materiais
  extra, fluxo de 8 estados com transições validadas (`PedidoStatusService`),
  responsável + prazos.
- **Provas** (`Prova`) — agendamento de 1ª/2ª prova e entrega, lembrete
  automático (job horário) e reagendamento pedido pelo cliente.
- **Faturação** (`PedidoFaturacaoService`) — sinal → Recibo sem fatura
  associada; entrega → rascunho de Fatura (linha da peça + uma por
  material); saldo final → Recibo ligado a essa fatura.
- **Notificações** (`NotificadorClienteService` + `PedidoMudouEstado`) —
  mudança de estado, lembrete de prova, "pronto para retirada" com saldo.
- **CRM/Marketing** (`Campanha`, `CrmService`) — aniversários automáticos
  (diário) e campanhas manuais (todos / aniversariantes do mês / inativos).
- **Equipa** (`EquipaService`) — atribuição dos papéis Secretária/Costureira
  a utilizadores já existentes, com Costureira sem acesso a dados
  financeiros (`podeVerFinancas` nas views, `AvancarEstadoPedidoRequest` nos
  estados finais).
- **Portal do cliente** (`/portal/atelier/{cliente}`) — link assinado
  (`URL::temporarySignedRoute`), sem conta/password.
- **Landing + portfólio** (`/loja/{empresa:slug}`) — perfil institucional,
  galeria filtrável e formulário de orçamento (`PedidoOrcamento`, um lead,
  não um `Pedido`).

## Decisões de arquitetura mais importantes

- **O sinal é um `Recibo`, não uma "fatura proforma".** A migration de
  `recibos` já previa `fatura_id` nulo "para recibos de adiantamento, sem
  fatura associada ainda" — uma proforma não é documento fiscal AGT (não
  entra na numeração/hash), por isso não fazia sentido modelá-la como
  `Fatura`. Isto também significa que este módulo é o primeiro a dar uso
  real a `Recibo`, que até aqui só tinha o model.
- **Não existe `ReciboService` no Faturacao.** `PedidoFaturacaoService`
  fala diretamente com `NumeracaoService`/`AssinaturaFiscalService`,
  replicando o procedimento de duas fases que `FaturaService::emitir()` já
  usa — preferi isto a alterar ficheiros do Faturacao, ao custo de ~30
  linhas replicadas.
- **CRM não altera o model `Cliente` do Faturacao.** Aniversário e opt-in de
  marketing vivem em `atelier_clientes_crm`, uma tabela 1-para-1 só deste
  módulo — mantém o Faturacao intocado.
- **A Fatura final é sempre um rascunho.** A emissão fica a cargo do ecrã
  normal de faturas do Faturacao — não duplicamos essa UI de revisão aqui.
- **`empresas.slug` nasce neste módulo, não no Core**, porque a Landing Page
  é a primeira página pública identificada por tenant do projeto todo. Se
  um segundo módulo precisar do mesmo conceito, faz sentido promovê-lo para
  o Core numa refactorização futura.
- **Cada módulo compila o seu próprio CSS** — o comentário já existente em
  `Modules/Core/resources/css/app.css` dizia isto, mas `core::layouts.app`
  não tinha nenhum ponto de extensão para um módulo injetar o seu próprio
  `@vite`. Acrescentei um `@stack('estilos')` a essa layout (a única
  alteração a um ficheiro fora deste módulo) — sem nenhuma view a usar
  `@push('estilos')`, isto não muda nada do que já existia.
- **Portal e Landing resolvem o tenant "às cegas".** `Cliente`/`Pedido`/
  `Prova` usam `BelongsToTenant`, mas estas rotas não passam por
  `IdentificarTenant` (o "cliente" não é um `User`). O controlador resolve o
  `Cliente` dentro de `TenantManager::semTenant()` e só depois chama `set()`
  — nunca deixa o bypass "aberto" mais tempo do que essa única resolução.
- **Validações `exists:` reforçadas por empresa.** A regra `exists:` do
  Laravel corre em SQL puro e ignora qualquer *global scope* — por isso
  `cliente_id`/`medida_id`/`responsavel_id`/`utilizador_id` usam
  `Rule::exists(...)->where('empresa_id', ...)` explicitamente, e
  `EquipaController` resolve o utilizador pela relação `empresa->utilizadores()`
  em vez de `User::find()` (o próprio `User` não pode usar `BelongsToTenant`
  — seria um ciclo, já que é o `Auth::user()` que identifica o tenant).

## O que fica para depois (por desenho)

- **Convite de novos utilizadores.** `EquipaService` só atribui papéis a
  utilizadores já existentes — criar Secretária/Costureira de raiz depende
  de uma funcionalidade de gestão de equipa que ainda não existe no Core.
- **Segmentação de campanhas além dos 3 filtros atuais** (todos /
  aniversariantes do mês / inativos). Um construtor de segmentos mais fino
  é uma entrega à parte.
- **WhatsApp/SMS reais** — ver a secção acima.
- **Edição de materiais depois de criado o pedido** — hoje só se adicionam
  na criação; editar/remover depois exigiria também recalcular
  `valorTotalComIva()` sobre um pedido possivelmente já com sinal cobrado.

## Instalação

```bash
composer dump-autoload
php artisan migrate
php artisan storage:link   # fotos de pedidos e portfólio
npm run build              # compila Modules/Atelier/resources/css/app.css
```

Garante que `config/atelier.php` (ou as variáveis `ATELIER_*` no `.env`) tem
o que precisares para WhatsApp/SMS — sem isso, esses canais ficam
silenciosamente desativados e só `mail` dispara.

O scheduler do projeto precisa de `php artisan schedule:run` no cron (tal
como já é preciso para o Subscrições) para os lembretes de prova, o envio de
campanhas agendadas e a verificação diária de aniversários.

## Rotas principais

- `/atelier/pedidos` — lista e criação de pedidos (autenticado)
- `/atelier/clientes/{cliente}/medidas` — histórico e nova ficha de medidas
- `/atelier/equipa`, `/atelier/perfil`, `/atelier/portfolio`, `/atelier/campanhas`
- `/portal/atelier/{cliente}` — portal do cliente (link assinado, sem login)
- `/loja/{empresa:slug}` — página pública + formulário de orçamento

## Sugestão para a próxima entrega

A funcionalidade de gestão de equipa no Core (convidar/criar utilizadores
com um papel já atribuído) desbloquearia por completo o requisito G deste
módulo — hoje a Secretária/Costureira têm de ser criadas manualmente antes
de `EquipaService` lhes poder atribuir o papel.
