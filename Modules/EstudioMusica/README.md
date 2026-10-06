# Módulo EstudioMusica

Gestão de estúdio de música para o SaaS: artistas, projetos/faixas, agendamento de salas, portal do
cliente com player de demos, notificações automáticas, faturação por hora ou pacote (com sinal),
marketing automático e landing page pública.

Depende de **Core** (tenancy, menu, `RegistoEmpresaService`) e **Faturacao** (`FaturaService`,
`NumeracaoService`, `AssinaturaFiscalService`, `Cliente`, `Fatura`, `Recibo`). **Não altera nenhum
ficheiro desses módulos** — tudo o que precisa deles é feito a partir daqui.

> ⚠️ **Nada disto foi executado.** O ambiente onde o módulo foi escrito não tem PHP nem acesso ao
> Packagist. O código foi verificado estaticamente (delimitadores, namespaces, `use`, rotas ↔
> controllers ↔ views) e relido contra o código real dos outros módulos, mas **corre primeiro
> `php artisan test`** antes de confiares nele.

## Ativar

```bash
composer dump-autoload                       # novos namespaces PSR-4 (já em composer.json)
php artisan migrate                          # 12 migrations, prefixo 2026_04_01_
php artisan db:seed --class="Modules\EstudioMusica\Database\Seeders\EstudioMusicaPermissoesSeeder"
php artisan test                             # inclui os 2 testes do módulo
```

- **Papéis/permissões**: o seeder cria, **por empresa** (o spatie está com `teams`), os papéis
  `Recepcao` e `Engenheiro` e junta as permissões `estudiomusica.*` ao `Administrador` que o
  `RegistoEmpresaService` já cria. Empresas novas só ganham isto quando o seeder correr para elas.
  Depois atribui o papel a cada utilizador da equipa (`$user->assignRole('Recepcao')`, com o team da
  empresa definido).
- **Agendador + queue** (lembretes de sessão, aniversários, follow-up, envio de mensagens): precisa do
  cron `* * * * * php artisan schedule:run` e de um worker (`php artisan queue:work`) — o
  `.env.example` usa `QUEUE_CONNECTION=database`.
- **Upload de áudio**: o limite do módulo é 50 MB, mas o PHP por omissão só aceita 2 MB — sobe
  `upload_max_filesize` e `post_max_size` no `php.ini`.
- Ficheiros de áudio ficam no disco `local` (privado, **sem** `storage:link`); só são servidos por
  rotas do módulo.
- Variáveis novas em `.env.example` (todas opcionais).

## Mapa requisito → código

| Secção | Onde |
|---|---|
| A. Ficha do artista | migration `…000001` (colunas em `clientes`) + `PerfilArtistaService` + `PerfilArtistaController` |
| A. Projetos/faixas, 8 estados | `ProjetoMusical`, `FaixaMusical`, `config('estudiomusica.estados_projeto')` |
| B. Salas, sessões, sem double booking | `SalaEstudio`, `SessaoEstudio`, `AgendamentoService` (transação + `lockForUpdate` na sala) |
| C. Portal (token temporário, player, marcadores, aprovação, download só após quitação) | `VerificarTokenPortalCliente`, `PortalClienteController`, `VersaoAudio`, `MarcadorAudio`, view `portal/mostrar` |
| D. Notificações (WhatsApp/SMS/e-mail) | `NotificacaoEstudioService` → `EnviarMensagemClienteJob`; canais em `Notifications/Channels`; `EnviarLembretesSessaoJob` (24h e 2h) |
| E. Cobrança por hora/pacote, sinal, faturas/recibos | `FaturacaoEstudioService` (usa `FaturaService`), pivots `projeto_musical_fatura/recibo` |
| F. Marketing (aniversário + cupão, follow-up 30 dias) | `CampanhaMarketingService`, `EnviarCampanhaAniversarioJob`, `EnviarFollowUpPosLancamentoJob`, `CupomDesconto`, `CampanhaEnviada` |
| G. Landing pública + formulário | `LandingController`, `SolicitacaoOrcamento`, view `landing/index`; rota `/estudio/{empresa}` |
| H. Papéis | `EstudioMusicaPermissoesSeeder` + `permission:` nas rotas. **Artista/Cliente não é um `User`** — acede só pelo portal |

## Decisões que convém conheceres

1. **Nada fora do módulo foi alterado** (além de registar o módulo: `composer.json`,
   `modules_statuses.json`, `.env.example`). `Cliente` do Faturação não sabe destes campos —
   `PerfilArtistaService` usa `forceFill()` e trata o JSON à mão.
2. **Sem binding implícito de models com tenant nas rotas.** Os controllers recebem `int $id` e fazem
   `findOrFail()` lá dentro. A posição de `SubstituteBindings` face a `tenant`/`portal.cliente` na
   pipeline não está garantida neste projeto (não há `priority()` em `bootstrap/app.php`); se o
   binding corresse antes do tenant estar definido, a `TenantScope` (fail-closed) daria 404 em tudo.
   *(Pode valer a pena verificar se as rotas `/faturas/{fatura}` do Faturação sofrem disto.)*
3. **Quitação** (`ProjetoMusical::financeiramenteQuitado()`): mede contra o **valor do pacote**, não só
   contra o faturado — pagar apenas o sinal não liberta o download. Projetos por hora: quitado quando
   tudo o que já foi faturado está recebido. A audição é livre; só o **download** é bloqueado.
4. **Recibos**: o Faturação ainda não tem `ReciboService`, por isso `registrarRecibo()` reproduz o
   `FaturaService::emitir()` (numeração + assinatura) para `Recibo`. Não há ecrã que o chame ainda
   — regista-se hoje por código/tinker.
5. **Jobs multi-empresa** usam `TenantManager::semTenant()` e passam sempre `empresa_id` explícito nos
   `create()` (o bypass desliga a atribuição automática).

## Como interpretei o requisito (confirma)

- **Sinal/saldo final só para projetos por pacote** — por hora não há total conhecido de antemão;
  cobra-se sessão a sessão no check-out.
- **"Aviso de Aprovação Pendente"**: botão manual da Receção (`lembrete-aprovacao`), sem prazo
  inventado. **"Campanhas para datas comemorativas"**: comando manual
  `php artisan estudiomusica:campanha-generica {empresa} {id} "{assunto}" "{mensagem}"` — o
  requisito só dá exemplos de ocasiões, não datas.
- **"BPE/Tom base"** lido como **BPM**. **`data_nascimento`** foi acrescentada ao cliente porque a
  campanha de aniversário não funciona sem ela.
- **Landing**: identificada por `/estudio/{id da empresa}` (sem slug/subdomínio — não existe no
  projeto). Equipamentos/história do estúdio ficam como texto simples; o portfólio vem de projetos
  marcados `destaque_portfolio` com `link_publico`. **Falta um ecrã para marcar esses dois campos.**
- **Formulário público** → `SolicitacaoOrcamento` (lead); a Receção converte à mão em Cliente/Sessão.
- **Link do portal**: ao gerá-lo, é enviado de imediato ao cliente por `NotificacaoEstudioService`
  (WhatsApp/SMS/e-mail) — e também mostrado numa mensagem flash à equipa, para reenvio manual se precisar.

## Limitações conhecidas

- **WhatsApp Cloud API e Twilio** foram reconstruídos da documentação pública, sem teste real. Por
  omissão os dois canais são `log` (só registam). WhatsApp fora da janela de 24h exige *message
  templates* aprovados pela Meta; este canal envia texto livre.
- Faltam ecrãs dedicados para: editar a ficha do artista (só existe a rota
  `PUT clientes/{cliente}/perfil-artista`, sem formulário — depende de um ecrã que a chame), marcar
  portfólio, registar recibos, e ver o calendário em grelha (a lista de sessões é uma tabela). Os
  endpoints/serviços existem.
- Sem paginação/filtros nas listas do portal; sem *rate limit* dedicado ao portal em si — só o
  formulário de orçamento da landing tem `throttle` (o token do portal tem 48 caracteres aleatórios).
- Testes: só os dois de maior risco (conflito de agenda e quitação), no espírito dos testes dos
  outros módulos. O fluxo HTTP do portal e os jobs não têm testes.
