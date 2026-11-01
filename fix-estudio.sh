#!/usr/bin/env bash
# =============================================================================
# fix-estudio.sh — correções da auditoria completa do módulo EstudioMusica (Saas1)
#
# Uso (na raiz do repositório, no terminal do Codespace):
#     bash fix-estudio.sh                # aplica, migra, testa e faz commit local
#     bash fix-estudio.sh --sem-migrar   # não corre php artisan migrate
#     bash fix-estudio.sh --sem-testes   # não corre os testes
#     bash fix-estudio.sh --sem-commit   # deixa as alterações por commitar
#
# É idempotente: se já estiver aplicado, diz-o e não mexe em nada. É atómico: ou
# aplica tudo ou não toca em nada (se o código local divergir do esperado, aborta
# antes de escrever). Não faz push. Para desfazer antes de commitar:
#     git checkout -- Modules/EstudioMusica && git clean -fd Modules/EstudioMusica
#
# O que corrige (detalhe em Modules/EstudioMusica/docs/AUDITORIA.md):
#   - Portal do cliente: ouvir/descarregar/comentar/aprovar/pedir ajustes davam erro 500
#     (parâmetro {token} em falta nos controllers); respostas largavam o cliente no
#     ficheiro de áudio; o original saía sem aceite dos termos.
#   - Faturação: notas de crédito, recibos emitidos fora do Estúdio, horas ao cêntimo,
#     limite mensal de faturas sem erro 500, check-out que nunca perde a hora de fim.
#   - Ecrãs: sessões com projeto/engenheiro/cancelar/reagendar/faturar, salas editáveis,
#     portfólio da landing, erros de validação visíveis.
#   - Jobs: só estúdios ativos, canal que falha não bloqueia o e-mail, preview em fila.
# =============================================================================
set -euo pipefail

MIGRAR=1; TESTAR=1; COMMITAR=1
for arg in "$@"; do
  case "$arg" in
    --sem-migrar) MIGRAR=0 ;;
    --sem-testes) TESTAR=0 ;;
    --sem-commit) COMMITAR=0 ;;
    *) echo "Opção desconhecida: $arg"; exit 2 ;;
  esac
done

cd "$(git rev-parse --show-toplevel)"
M="Modules/EstudioMusica"
[ -d "$M/app" ] || { echo "ERRO: $M não encontrado. Corre este script na raiz do Saas1."; exit 1; }
[ -f "$0" ] || { echo "ERRO: corre o script como ficheiro (bash fix-estudio.sh), não por pipe."; exit 1; }

PATCH="$(mktemp)"
LOG="$(mktemp)"
trap 'rm -f "$PATCH" "$LOG"' EXIT
# O patch vai no fim deste ficheiro, depois da linha-marcador.
sed -n '/^__PATCH_ABAIXO__$/,$p' "$0" | tail -n +2 > "$PATCH"
[ -s "$PATCH" ] || { echo "ERRO: o patch embutido está vazio — o ficheiro foi truncado?"; exit 1; }

echo "==> A aplicar as correções"
if git apply --check --reverse "$PATCH" >/dev/null 2>&1; then
  echo "    Já estavam aplicadas — nada a fazer neste passo."
elif git apply --check "$PATCH" >"$LOG" 2>&1; then
  git apply --whitespace=nowarn "$PATCH"
  echo "    OK: $(git ls-files -m -o --exclude-standard -- "$M" | wc -l | tr -d ' ') ficheiros alterados/criados em $M"
else
  echo "ERRO: as correções não encaixam no código atual (nada foi alterado)."
  echo "      Costuma ser por haver alterações locais por commitar no módulo, ou por o"
  echo "      script já ter sido aplicado e depois editado à mão. Detalhe:"
  sed 's/^/        /' "$LOG"
  echo "      Faz commit (ou stash) das alterações em $M e corre outra vez."
  exit 1
fi

# -----------------------------------------------------------------------------
# VALIDAÇÃO
# -----------------------------------------------------------------------------
echo "==> A validar sintaxe PHP"
if command -v php >/dev/null; then
  FICHEIROS=$(git ls-files -m -o --exclude-standard -- "$M" | grep '\.php$' || true)
  N=0
  for f in $FICHEIROS; do php -l "$f" >/dev/null || { echo "ERRO de sintaxe em $f"; exit 1; }; N=$((N+1)); done
  echo "    OK ($N ficheiros)"
else
  echo "    php não encontrado — a saltar"
fi

if [ "$MIGRAR" = 1 ]; then
  echo "==> A correr migrações (índice de sessões para o job de lembretes)"
  php artisan migrate
fi

if [ "$TESTAR" = 1 ]; then
  echo "==> A correr testes do módulo"
  export XDEBUG_MODE=off
  php artisan test --filter=EstudioMusica
fi

if [ "$COMMITAR" = 1 ]; then
  echo "==> A fazer commit (local)"
  git add "$M"
  git commit -m "fix(estudio): auditoria completa — portal (erro 500 por {token}, redirects, termos), faturação (notas de crédito, recibos, cêntimos, limite do plano), sessões/salas/portfólio, jobs e erros de validação visíveis" \
    || echo "    nada para commitar (ou falta configurar git user.name/user.email)"
  echo "    Revê com 'git show --stat' e faz 'git push origin main' quando estiveres satisfeito."
fi

cat <<'FIM'

==> Concluído. Para confirmares à mão, no Codespace:
      - Portal: abre o link do portal de um cliente, toca uma versão, comenta e aprova — deve
        voltar sempre ao portal (antes: erro 500 / ficheiro de áudio).
      - Sessões: «Agendar sessão» já pede projeto e engenheiro; cada linha tem Reagendar,
        Cancelar e (se faltar) Faturar. Salas: «Editar» por linha.
      - Projeto → «Portfólio na página pública» (só Administrador).
      - Recomendado: php artisan queue:work  (a pré-visualização do áudio gera-se em fila; precisa de ffmpeg)

    Fica por decidir (ver Modules/EstudioMusica/docs/AUDITORIA.md): landing por id numérico,
    ação «confirmar sessão», editar dados do projeto depois de criado.
FIM
exit 0

__PATCH_ABAIXO__
diff --git a/Modules/EstudioMusica/README.md b/Modules/EstudioMusica/README.md
index bb24c13..3f0234c 100644
--- a/Modules/EstudioMusica/README.md
+++ b/Modules/EstudioMusica/README.md
@@ -8,18 +8,19 @@ # Módulo EstudioMusica
 `NumeracaoService`, `AssinaturaFiscalService`, `Cliente`, `Fatura`, `Recibo`). **Não altera nenhum
 ficheiro desses módulos** — tudo o que precisa deles é feito a partir daqui.
 
-> ⚠️ **Nada disto foi executado.** O ambiente onde o módulo foi escrito não tem PHP nem acesso ao
-> Packagist. O código foi verificado estaticamente (delimitadores, namespaces, `use`, rotas ↔
-> controllers ↔ views) e relido contra o código real dos outros módulos, mas **corre primeiro
-> `php artisan test`** antes de confiares nele.
+> ✅ **Verificação.** O módulo tem testes de feature (`php artisan test --testsuite=Modules --filter=EstudioMusica`)
+> que correm contra o motor real do Faturação (emissão, notas de crédito, recibos), o portal por HTTP e os jobs.
+> O que **continua sem teste contra o serviço real**: o WhatsApp Cloud API e o Twilio (só se testa o
+> comportamento do job quando o canal falha) e o `ffmpeg` (os testes usam um executável falso). A auditoria
+> mais recente e o que ficou de fora estão em [docs/AUDITORIA.md](docs/AUDITORIA.md).
 
 ## Ativar
 
 ```bash
 composer dump-autoload                       # novos namespaces PSR-4 (já em composer.json)
-php artisan migrate                          # 12 migrations, prefixo 2026_04_01_
+php artisan migrate                          # migrations do módulo (prefixos 2026_04_01_ e 2026_10_)
 php artisan db:seed --class="Modules\EstudioMusica\Database\Seeders\EstudioMusicaPermissoesSeeder"
-php artisan test                             # inclui os 2 testes do módulo
+php artisan test                             # inclui os testes do módulo
 ```
 
 - **Papéis/permissões**: o seeder cria, **por empresa** (o spatie está com `teams`), os papéis
@@ -96,7 +97,9 @@ ## Como interpretei o requisito (confirma)
   campanha de aniversário não funciona sem ela.
 - **Landing**: identificada por `/estudio/{id da empresa}` (sem slug/subdomínio — não existe no
   projeto). Equipamentos/história do estúdio ficam como texto simples; o portfólio vem de projetos
-  marcados `destaque_portfolio` com `link_publico`. **Falta um ecrã para marcar esses dois campos.**
+  marcados `destaque_portfolio` com `link_publico`. Marca-se na página do projeto (cartão «Portfólio na
+  página pública», só Administrador — permissão `estudiomusica.gerir-marketing`), com a confirmação de
+  que o artista autorizou a divulgação.
 - **Formulário público** → `SolicitacaoOrcamento` (lead); a Receção converte à mão em Cliente/Sessão.
 - **Link do portal**: ao gerá-lo, é enviado de imediato ao cliente por `NotificacaoEstudioService`
   (WhatsApp/SMS/e-mail) — e também mostrado numa mensagem flash à equipa, para reenvio manual se precisar.
@@ -106,11 +109,14 @@ ## Limitações conhecidas
 - **WhatsApp Cloud API e Twilio** foram reconstruídos da documentação pública, sem teste real. Por
   omissão os dois canais são `log` (só registam). WhatsApp fora da janela de 24h exige *message
   templates* aprovados pela Meta; este canal envia texto livre.
-- Faltam ecrãs dedicados para: editar a ficha do artista (só existe a rota
-  `PUT clientes/{cliente}/perfil-artista`, sem formulário — depende de um ecrã que a chame), marcar
-  portfólio, registar recibos, e ver o calendário em grelha (a lista de sessões é uma tabela). Os
-  endpoints/serviços existem.
-- Sem paginação/filtros nas listas do portal; sem *rate limit* dedicado ao portal em si — só o
-  formulário de orçamento da landing tem `throttle` (o token do portal tem 48 caracteres aleatórios).
-- Testes: só os dois de maior risco (conflito de agenda e quitação), no espírito dos testes dos
-  outros módulos. O fluxo HTTP do portal e os jobs não têm testes.
+- Faltam ecrãs dedicados para: editar os dados de um projeto depois de criado (prazo, valor do pacote,
+  percentagem do sinal, produtor/engenheiro) e ver o calendário em grelha (a lista de sessões é uma
+  tabela). A ficha do artista, as salas (editar/desativar), o portfólio, os recibos e o agendamento
+  (projeto, engenheiro, reagendar, cancelar, faturar) têm ecrã.
+- O estado de sessão `confirmada` existe (e o check-in aceita-o) mas nada o atribui: não há ação
+  «confirmar sessão».
+- Sem paginação/filtros nas listas do portal. O portal tem *rate limit* por token; o formulário de
+  orçamento e as reclamações da landing têm o seu próprio `throttle`.
+- A landing continua a usar o id numérico da empresa (`/estudio/{id}`), o que permite enumerar
+  estúdios. Há `slug` na tabela `empresas`, mas é do módulo Atelier — usá-lo aqui criava uma
+  dependência entre verticais, por isso ficou por decidir.
diff --git a/Modules/EstudioMusica/app/Http/Controllers/FaixaMusicalController.php b/Modules/EstudioMusica/app/Http/Controllers/FaixaMusicalController.php
index 404f0d2..bb21006 100644
--- a/Modules/EstudioMusica/app/Http/Controllers/FaixaMusicalController.php
+++ b/Modules/EstudioMusica/app/Http/Controllers/FaixaMusicalController.php
@@ -20,6 +20,11 @@ public function store(Request $request, int $projeto): RedirectResponse
 
         $projeto = ProjetoMusical::findOrFail($projeto);
 
+        // Uma faixa nova faria um projeto concluído deixar de estar "todo aprovado" sem ninguém dar por isso.
+        if (in_array($projeto->estado, ['concluido', 'cancelado'], true)) {
+            return back()->with('erro', 'Não se acrescentam faixas a um projeto concluído ou cancelado — muda primeiro o estado do projeto.');
+        }
+
         $projeto->faixas()->create([
             'empresa_id' => $projeto->empresa_id,
             'nome' => $dados['nome'],
diff --git a/Modules/EstudioMusica/app/Http/Controllers/PortalClienteController.php b/Modules/EstudioMusica/app/Http/Controllers/PortalClienteController.php
index 92eb448..356ac7f 100644
--- a/Modules/EstudioMusica/app/Http/Controllers/PortalClienteController.php
+++ b/Modules/EstudioMusica/app/Http/Controllers/PortalClienteController.php
@@ -5,7 +5,9 @@
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Routing\Controller;
+use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Storage;
+use Illuminate\Support\Facades\Validator;
 use Illuminate\View\View;
 use Modules\EstudioMusica\Models\ProjetoMusical;
 use Modules\EstudioMusica\Models\VersaoAudio;
@@ -26,6 +28,11 @@
  * pipeline) não está garantida — ver o mesmo cuidado, pela mesma razão,
  * em SalaEstudioController::update(). Resolver o model aqui dentro,
  * depois do middleware já ter corrido, elimina a ambiguidade.
+ *
+ * ATENÇÃO — os métodos das rotas com {token} E {versao} declaram `string $token`
+ * mesmo sem o usarem: o Laravel entrega os parâmetros de rota por POSIÇÃO, não por
+ * nome, e o token vem antes da versão. Sem ele, $versao recebia o token e o pedido
+ * rebentava com TypeError (erro 500). Não o removas (PortalRotasHttpTest cobre isto).
  */
 class PortalClienteController extends Controller
 {
@@ -63,7 +70,7 @@ public function aceitarTermos(Request $request): RedirectResponse
 
         $this->termos->registarAceite($cliente, $this->termos->atual($cliente->empresa_id), $request);
 
-        return back()->with('sucesso', 'Termos de Serviço aceites. Obrigado!');
+        return $this->paraOPortal($request)->with('sucesso', 'Termos de Serviço aceites. Obrigado!');
     }
 
     /** Mensagem de bloqueio se o cliente ainda não aceitou a versão em vigor dos termos. */
@@ -86,18 +93,20 @@ protected function termosPendentes(Request $request): ?string
      * usa para saltar para um ponto da faixa (ex.: o marcador 01:23) sem
      * ter de descarregar o ficheiro inteiro antes.
      */
-    public function reproduzir(Request $request, int $versao): BinaryFileResponse
+    public function reproduzir(Request $request, string $token, int $versao): BinaryFileResponse
     {
         $versao = $this->autorizarVersao($request, $versao);
         $projeto = $versao->faixaMusical->projetoMusical;
 
-        // Projeto quitado: o cliente já tem direito ao original.
-        if ($projeto->financeiramenteQuitado()) {
-            return response()->file(Storage::disk('local')->path($versao->caminho_ficheiro));
+        // Projeto quitado E termos aceites: o cliente já tem direito ao original. A regra é a
+        // mesma do download — servir o original no player sem o aceite dos termos deixava esse
+        // aceite contornável (bastava "guardar áudio como...").
+        if ($projeto->financeiramenteQuitado() && $this->termosPendentes($request) === null) {
+            return $this->ficheiroOuNaoEncontrado($versao->caminho_ficheiro);
         }
 
-        // Ainda por pagar: só a pré-visualização (baixa qualidade). Servir o
-        // original aqui deixava o "download só após quitação" contornável —
+        // Ainda por pagar (ou termos por aceitar): só a pré-visualização (baixa qualidade).
+        // Servir o original aqui deixava o "download só após quitação" contornável —
         // bastava guardar o áudio a partir do player.
         $preview = $this->previews->garantir($versao);
 
@@ -113,35 +122,49 @@ public function reproduzir(Request $request, int $versao): BinaryFileResponse
      * saldo do projeto" (secção C) — o único ponto de todo o módulo onde
      * isto é verificado, de propósito (ver ProjetoMusical::financeiramenteQuitado()).
      */
-    public function descarregar(Request $request, int $versao): StreamedResponse|RedirectResponse
+    public function descarregar(Request $request, string $token, int $versao): StreamedResponse|RedirectResponse
     {
         $versao = $this->autorizarVersao($request, $versao);
         $projeto = $versao->faixaMusical->projetoMusical;
 
         if (! $projeto->financeiramenteQuitado()) {
-            return back()->with('erro', 'O download fica disponível assim que o saldo do projeto estiver quitado.');
+            return $this->paraOPortal($request)->with('erro', 'O download fica disponível assim que o saldo do projeto estiver quitado.');
         }
 
         // O original só sai depois de o cliente aceitar os Termos de Serviço
         // (propriedade dos masters, direitos de autor).
         if ($erro = $this->termosPendentes($request)) {
-            return back()->with('erro', $erro);
+            return $this->paraOPortal($request)->with('erro', $erro);
         }
 
+        abort_unless(
+            Storage::disk('local')->exists($versao->caminho_ficheiro),
+            404,
+            'O ficheiro desta versão não está disponível. Contacta o estúdio.'
+        );
+
         $extensao = pathinfo($versao->caminho_ficheiro, PATHINFO_EXTENSION);
 
         return Storage::disk('local')->download($versao->caminho_ficheiro, "{$versao->rotulo}.{$extensao}");
     }
 
-    public function comentar(Request $request, int $versao): RedirectResponse
+    public function comentar(Request $request, string $token, int $versao): RedirectResponse
     {
         $versao = $this->autorizarVersao($request, $versao);
 
-        $dados = $request->validate([
+        // Validação manual (e não $request->validate): a recusa tem de voltar ao PORTAL — o redirect
+        // automático usa o "último GET" da sessão, que depois de tocar o áudio é o stream.
+        $validador = Validator::make($request->all(), [
             'tempo_segundos' => ['required', 'numeric', 'min:0', 'max:86400'],
             'comentario' => ['required', 'string', 'max:2000'],
         ]);
 
+        if ($validador->fails()) {
+            return $this->paraOPortal($request)->withErrors($validador)->withInput();
+        }
+
+        $dados = $validador->validated();
+
         $versao->marcadores()->create([
             'tempo_segundos' => $dados['tempo_segundos'],
             'comentario' => $dados['comentario'],
@@ -149,35 +172,77 @@ public function comentar(Request $request, int $versao): RedirectResponse
             'nome_autor' => $request->attributes->get('cliente_portal')->nome,
         ]);
 
-        return back()->with('sucesso', 'Comentário adicionado.');
+        return $this->paraOPortal($request)->with('sucesso', 'Comentário adicionado.');
     }
 
-    public function aprovar(Request $request, int $versao, NotificacaoEstudioService $notificacao): RedirectResponse
+    public function aprovar(Request $request, string $token, int $versao, NotificacaoEstudioService $notificacao): RedirectResponse
     {
         $versao = $this->autorizarVersao($request, $versao);
 
-        if ($erro = $this->termosPendentes($request) ?? $this->motivoParaNaoDecidir($versao)) {
-            return back()->with('erro', $erro);
+        if ($erro = $this->termosPendentes($request) ?? $this->registarDecisao($versao, 'aprovada')) {
+            return $this->paraOPortal($request)->with('erro', $erro);
         }
 
-        $versao->update(['estado' => 'aprovada']);
         $notificacao->equipaDecisaoCliente($versao, 'aprovada');
 
-        return back()->with('sucesso', 'Versão aprovada!');
+        return $this->paraOPortal($request)->with('sucesso', 'Versão aprovada!');
     }
 
-    public function solicitarAjustes(Request $request, int $versao, NotificacaoEstudioService $notificacao): RedirectResponse
+    public function solicitarAjustes(Request $request, string $token, int $versao, NotificacaoEstudioService $notificacao): RedirectResponse
     {
         $versao = $this->autorizarVersao($request, $versao);
 
-        if ($erro = $this->motivoParaNaoDecidir($versao)) {
-            return back()->with('erro', $erro);
+        if ($erro = $this->registarDecisao($versao, 'ajustes_solicitados')) {
+            return $this->paraOPortal($request)->with('erro', $erro);
         }
 
-        $versao->update(['estado' => 'ajustes_solicitados']);
         $notificacao->equipaDecisaoCliente($versao, 'ajustes_solicitados');
 
-        return back()->with('sucesso', 'Pedido de ajustes enviado à equipa.');
+        return $this->paraOPortal($request)->with('sucesso', 'Pedido de ajustes enviado à equipa.');
+    }
+
+    /**
+     * Volta SEMPRE à página do portal, nunca com back(): o portal envia
+     * Referrer-Policy: no-referrer, por isso o POST não leva Referer e o back() cai no
+     * "último GET" guardado na sessão — que, depois de o cliente tocar o áudio, é o próprio
+     * ficheiro de áudio (o cliente era largado no stream em vez de no portal).
+     */
+    protected function paraOPortal(Request $request): RedirectResponse
+    {
+        return redirect()->route('estudiomusica.portal.mostrar', ['token' => $request->route('token')]);
+    }
+
+    /** O original no disco, ou 404 limpo (response()->file() daria 500 se o ficheiro faltasse). */
+    protected function ficheiroOuNaoEncontrado(string $caminho): BinaryFileResponse
+    {
+        $disco = Storage::disk('local');
+
+        abort_unless($disco->exists($caminho), 404, 'O ficheiro desta versão não está disponível. Contacta o estúdio.');
+
+        return response()->file($disco->path($caminho));
+    }
+
+    /**
+     * Grava a decisão do cliente com lock na versão: dois cliques (ou dois pedidos em
+     * simultâneo) já não decidem — nem notificam a equipa — duas vezes.
+     *
+     * @return string|null mensagem de recusa, ou null se a decisão foi gravada
+     */
+    protected function registarDecisao(VersaoAudio $versao, string $estado): ?string
+    {
+        return DB::transaction(function () use ($versao, $estado): ?string {
+            VersaoAudio::query()->whereKey($versao->id)->lockForUpdate()->first();
+
+            $atual = VersaoAudio::with('faixaMusical.projetoMusical')->findOrFail($versao->id);
+
+            if ($erro = $this->motivoParaNaoDecidir($atual)) {
+                return $erro;
+            }
+
+            $atual->update(['estado' => $estado]);
+
+            return null;
+        });
     }
 
     /**
diff --git a/Modules/EstudioMusica/app/Http/Controllers/ProjetoMusicalController.php b/Modules/EstudioMusica/app/Http/Controllers/ProjetoMusicalController.php
index eada036..e3c051c 100644
--- a/Modules/EstudioMusica/app/Http/Controllers/ProjetoMusicalController.php
+++ b/Modules/EstudioMusica/app/Http/Controllers/ProjetoMusicalController.php
@@ -10,6 +10,7 @@
 use DomainException;
 use InvalidArgumentException;
 use Modules\Core\Models\User;
+use Modules\EstudioMusica\Http\Requests\AtualizarPortfolioRequest;
 use Modules\EstudioMusica\Http\Requests\GuardarProjetoMusicalRequest;
 use Modules\EstudioMusica\Models\ProjetoMusical;
 use Modules\EstudioMusica\Services\FaturacaoEstudioService;
@@ -66,6 +67,21 @@ public function show(int $projeto): View
         ]);
     }
 
+    /** Destaque do projeto na landing pública (a landing só mostra projetos marcados aqui, com link). */
+    public function atualizarPortfolio(AtualizarPortfolioRequest $request, int $projeto): RedirectResponse
+    {
+        $projeto = ProjetoMusical::findOrFail($projeto);
+
+        $projeto->forceFill([
+            'destaque_portfolio' => $request->boolean('destaque_portfolio'),
+            'link_publico' => $request->validated('link_publico'),
+        ])->save();
+
+        return back()->with('sucesso', $projeto->destaque_portfolio
+            ? 'Projeto marcado como destaque na página pública.'
+            : 'Projeto retirado do portfólio público.');
+    }
+
     public function atualizarEstado(Request $request, int $projeto, NotificacaoEstudioService $notificacao): RedirectResponse
     {
         $request->validate([
@@ -132,7 +148,7 @@ public function gerarSinal(int $projeto): RedirectResponse
 
         try {
             $this->faturacaoEstudio->gerarFaturaSinal($projeto);
-        } catch (InvalidArgumentException $e) {
+        } catch (InvalidArgumentException|DomainException $e) {
             return back()->with('erro', $e->getMessage());
         }
 
@@ -145,7 +161,7 @@ public function gerarSaldoFinal(int $projeto): RedirectResponse
 
         try {
             $this->faturacaoEstudio->gerarFaturaSaldoFinal($projeto);
-        } catch (InvalidArgumentException $e) {
+        } catch (InvalidArgumentException|DomainException $e) {
             return back()->with('erro', $e->getMessage());
         }
 
@@ -160,6 +176,6 @@ public function gerarLinkPortal(int $projeto, PerfilArtistaService $perfilArtist
 
         $notificacao->linkPortal($projeto->cliente, $url);
 
-        return back()->with('sucesso', "Link do portal enviado ao cliente. Link: {$url}");
+        return back()->with('sucesso', "Link do portal gerado (a mensagem ao cliente segue pelos canais autorizados). Link: {$url}");
     }
 }
diff --git a/Modules/EstudioMusica/app/Http/Controllers/SessaoEstudioController.php b/Modules/EstudioMusica/app/Http/Controllers/SessaoEstudioController.php
index cc791e7..aeebf72 100644
--- a/Modules/EstudioMusica/app/Http/Controllers/SessaoEstudioController.php
+++ b/Modules/EstudioMusica/app/Http/Controllers/SessaoEstudioController.php
@@ -2,13 +2,19 @@
 
 namespace Modules\EstudioMusica\Http\Controllers;
 
+use Carbon\Carbon;
+use DomainException;
+use Illuminate\Http\Request;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Routing\Controller;
 use Illuminate\Support\Facades\DB;
 use Illuminate\View\View;
 use InvalidArgumentException;
+use Modules\Core\Models\User;
 use Modules\EstudioMusica\Exceptions\ConflitoAgendamentoException;
 use Modules\EstudioMusica\Http\Requests\GuardarSessaoEstudioRequest;
+use Modules\EstudioMusica\Http\Requests\ReagendarSessaoEstudioRequest;
+use Modules\EstudioMusica\Models\ProjetoMusical;
 use Modules\EstudioMusica\Models\SalaEstudio;
 use Modules\EstudioMusica\Models\SessaoEstudio;
 use Modules\EstudioMusica\Services\AgendamentoService;
@@ -22,14 +28,27 @@ public function __construct(
         protected FaturacaoEstudioService $faturacaoEstudio,
     ) {}
 
-    public function index(): View
+    public function index(Request $request): View
     {
+        $projetoPreSelecionado = $request->filled('projeto')
+            ? ProjetoMusical::query()->whereKey($request->integer('projeto'))->first()
+            : null;
+
         return view('estudiomusica::sessoes.index', [
-            'sessoes' => SessaoEstudio::with(['salaEstudio', 'cliente', 'engenheiro'])
+            'sessoes' => SessaoEstudio::with(['salaEstudio', 'cliente', 'engenheiro', 'projetoMusical'])
                 ->orderByDesc('inicio_previsto')
                 ->paginate(20),
             'salas' => SalaEstudio::ativas()->orderBy('nome')->get(),
             'clientes' => Cliente::orderBy('nome')->get(),
+            // Sem a ligação sessão→projeto a cobrança por hora nunca chegava ao projeto (e este nunca
+            // chegava a "quitado"): o formulário tem de a oferecer.
+            'projetos' => ProjetoMusical::with('cliente')->where('estado', '!=', 'cancelado')->orderBy('nome')->get(),
+            'membrosEquipa' => User::query()
+                ->where('empresa_id', $request->user()->empresa_id)
+                ->where('ativo', true)
+                ->orderBy('name')
+                ->get(),
+            'projetoPreSelecionado' => $projetoPreSelecionado,
         ]);
     }
 
@@ -65,34 +84,63 @@ public function checkIn(int $sessao): RedirectResponse
     }
 
     /**
-     * Check-out fecha a sessão E, no mesmo momento, gera a fatura por
-     * hora quando aplicável (secção E) — "ao finalizar sessões" é
-     * literalmente o gatilho que o requisito pede para a faturação
-     * automática. As duas operações correm na mesma transação: se a
-     * faturação falhar a meio, o check-out também não fica gravado — não
-     * queremos uma sessão "concluída" sem a fatura correspondente alguma
-     * vez ter saído.
+     * Check-out fecha a sessão E, logo a seguir, gera a fatura por hora quando
+     * aplicável (secção E) — "ao finalizar sessões" é literalmente o gatilho que o
+     * requisito pede para a faturação automática.
+     *
+     * As duas operações são independentes de propósito: o fim real fica SEMPRE gravado.
+     * Antes corriam na mesma transação, e uma falha da faturação (limite mensal de faturas
+     * do plano, subscrição inativa, série por configurar) desfazia o check-out e devolvia
+     * um erro 500 — o estúdio ficava com a sessão "em curso" e, ao repetir mais tarde, o
+     * cliente pagava as horas entretanto passadas. Agora a sessão fica concluída e, se a
+     * fatura não puder sair, o utilizador é avisado e usa «Faturar» quando o problema
+     * estiver resolvido (cobrarSessao é idempotente).
      */
     public function checkOut(int $sessao): RedirectResponse
     {
         try {
-            $fatura = DB::transaction(function () use ($sessao) {
-                // lockForUpdate: dois check-outs em simultâneo não passam ambos
-                // pela guarda de estado (e não geram duas faturas por hora).
-                $sessao = SessaoEstudio::query()->lockForUpdate()->findOrFail($sessao);
-                $this->agendamento->registrarCheckOut($sessao);
-
-                return $this->faturacaoEstudio->cobrarSessao($sessao);
+            DB::transaction(function () use ($sessao) {
+                // lockForUpdate: dois check-outs em simultâneo não passam ambos pela guarda de estado.
+                $this->agendamento->registrarCheckOut(
+                    SessaoEstudio::query()->lockForUpdate()->findOrFail($sessao)
+                );
             });
         } catch (InvalidArgumentException $e) {
             return back()->with('erro', $e->getMessage());
         }
 
-        $mensagem = $fatura
-            ? "Check-out registado. Fatura {$fatura->numero_documento} emitida."
-            : 'Check-out registado.';
+        return $this->faturarSessao($sessao, 'Check-out registado.');
+    }
+
+    /** Emite (ou repete) a fatura por hora de uma sessão concluída que ainda não a tem. */
+    public function faturar(int $sessao): RedirectResponse
+    {
+        $registo = SessaoEstudio::query()->findOrFail($sessao);
+
+        if ($registo->estado !== 'concluida') {
+            return back()->with('erro', 'Só se fatura uma sessão concluída (depois do check-out).');
+        }
+
+        if ($registo->fatura_id) {
+            return back()->with('erro', 'Esta sessão já tem fatura.');
+        }
+
+        return $this->faturarSessao($sessao, null);
+    }
+
+    public function reagendar(ReagendarSessaoEstudioRequest $request, int $sessao): RedirectResponse
+    {
+        try {
+            $this->agendamento->reagendar(
+                SessaoEstudio::query()->findOrFail($sessao),
+                Carbon::parse($request->validated('inicio_previsto')),
+                Carbon::parse($request->validated('fim_previsto')),
+            );
+        } catch (ConflitoAgendamentoException|InvalidArgumentException $e) {
+            return back()->with('erro', $e->getMessage());
+        }
 
-        return back()->with('sucesso', $mensagem);
+        return back()->with('sucesso', 'Sessão reagendada.');
     }
 
     public function cancelar(int $sessao): RedirectResponse
@@ -107,4 +155,29 @@ public function cancelar(int $sessao): RedirectResponse
 
         return back()->with('sucesso', 'Sessão cancelada.');
     }
+
+    /**
+     * Cobra a sessão em transação própria (com lock: dois pedidos não geram duas faturas por
+     * hora). Qualquer recusa do motor de faturação — pedido inválido ou regra comercial como o
+     * limite mensal do plano — vira um aviso, nunca um erro 500.
+     */
+    protected function faturarSessao(int $sessao, ?string $prefixo): RedirectResponse
+    {
+        try {
+            $fatura = DB::transaction(fn () => $this->faturacaoEstudio->cobrarSessao(
+                SessaoEstudio::query()->lockForUpdate()->findOrFail($sessao)
+            ));
+        } catch (InvalidArgumentException|DomainException $e) {
+            return back()->with('erro', trim(
+                ($prefixo ? $prefixo.' ' : '')
+                ."A fatura NÃO foi emitida: {$e->getMessage()} Quando o problema estiver resolvido, usa «Faturar» nesta sessão."
+            ));
+        }
+
+        if ($fatura) {
+            return back()->with('sucesso', trim(($prefixo ? $prefixo.' ' : '')."Fatura {$fatura->numero_documento} emitida."));
+        }
+
+        return back()->with('sucesso', $prefixo ?? 'Esta sessão não tem nada a faturar (projeto por pacote, sala sem preço ou já faturada).');
+    }
 }
diff --git a/Modules/EstudioMusica/app/Http/Controllers/SolicitacaoOrcamentoController.php b/Modules/EstudioMusica/app/Http/Controllers/SolicitacaoOrcamentoController.php
index 922473f..20910ea 100644
--- a/Modules/EstudioMusica/app/Http/Controllers/SolicitacaoOrcamentoController.php
+++ b/Modules/EstudioMusica/app/Http/Controllers/SolicitacaoOrcamentoController.php
@@ -47,25 +47,26 @@ public function atualizarEstado(Request $request, int $solicitacao): RedirectRes
      */
     public function converter(int $solicitacao): RedirectResponse
     {
-        $solicitacao = SolicitacaoOrcamento::findOrFail($solicitacao);
+        $cliente = DB::transaction(function () use ($solicitacao) {
+            // Com lock: dois cliques (ou dois utilizadores) já não criam o cliente duas vezes.
+            $solicitacao = SolicitacaoOrcamento::query()->lockForUpdate()->findOrFail($solicitacao);
 
-        if ($solicitacao->estado === 'convertido') {
-            return back()->with('erro', 'Este pedido já foi convertido em cliente.');
-        }
+            if ($solicitacao->estado === 'convertido') {
+                return null;
+            }
 
-        $cliente = DB::transaction(function () use ($solicitacao) {
+            // Primeiro pelo e-mail (é da própria pessoa); só depois pelo telefone, que pode ser
+            // partilhado (número da família, da editora...).
             $existente = null;
+            $porEmail = false;
 
-            if ($solicitacao->email || $solicitacao->telefone) {
-                $existente = Cliente::query()->where(function ($q) use ($solicitacao) {
-                    if ($solicitacao->email) {
-                        $q->orWhere('email', $solicitacao->email);
-                    }
+            if ($solicitacao->email) {
+                $existente = Cliente::query()->where('email', $solicitacao->email)->first();
+                $porEmail = $existente !== null;
+            }
 
-                    if ($solicitacao->telefone) {
-                        $q->orWhere('telefone', $solicitacao->telefone);
-                    }
-                })->first();
+            if ($existente === null && $solicitacao->telefone) {
+                $existente = Cliente::query()->where('telefone', $solicitacao->telefone)->first();
             }
 
             $cliente = $existente ?? (new Cliente)->forceFill([
@@ -75,7 +76,11 @@ public function converter(int $solicitacao): RedirectResponse
                 'telefone' => $solicitacao->telefone,
             ]);
 
-            if ($solicitacao->aceita_marketing && ! $cliente->aceita_marketing) {
+            // O consentimento de marketing é pessoal: só passa para um cliente que já existia se o
+            // e-mail coincidir. Um telefone igual pode ser de outra pessoa, que nunca consentiu.
+            $podeHerdarConsentimento = $existente === null || $porEmail;
+
+            if ($podeHerdarConsentimento && $solicitacao->aceita_marketing && ! $cliente->aceita_marketing) {
                 $cliente->forceFill([
                     'aceita_marketing' => true,
                     'aceita_marketing_em' => $solicitacao->consentimento_dados_em ?? now(),
@@ -88,6 +93,10 @@ public function converter(int $solicitacao): RedirectResponse
             return $cliente;
         });
 
+        if ($cliente === null) {
+            return back()->with('erro', 'Este pedido já foi convertido em cliente.');
+        }
+
         return back()->with('sucesso', "Cliente \"{$cliente->nome}\" pronto — já podes criar o projeto ou agendar a sessão.");
     }
 }
diff --git a/Modules/EstudioMusica/app/Http/Controllers/VersaoAudioController.php b/Modules/EstudioMusica/app/Http/Controllers/VersaoAudioController.php
index c174a3a..3b548db 100644
--- a/Modules/EstudioMusica/app/Http/Controllers/VersaoAudioController.php
+++ b/Modules/EstudioMusica/app/Http/Controllers/VersaoAudioController.php
@@ -7,6 +7,7 @@
 use Illuminate\Routing\Controller;
 use Illuminate\Support\Facades\Storage;
 use Throwable;
+use Modules\EstudioMusica\Jobs\GerarPreviewAudioJob;
 use Modules\EstudioMusica\Models\FaixaMusical;
 use Modules\EstudioMusica\Models\VersaoAudio;
 use Modules\EstudioMusica\Services\NotificacaoEstudioService;
@@ -57,6 +58,10 @@ public function store(Request $request, int $faixa, NotificacaoEstudioService $n
             throw $e;
         }
 
+        // A pré-visualização (ffmpeg) gera-se já, em segundo plano — antes só nascia no primeiro
+        // "play" do cliente, dentro do pedido HTTP dele (lento, e com vários pedidos Range em paralelo).
+        GerarPreviewAudioJob::dispatch($versao->id, (int) $versao->empresa_id);
+
         $notificacao->novaDemoDisponivel($versao);
 
         return back()->with('sucesso', 'Nova versão enviada.');
@@ -70,6 +75,9 @@ public function reproduzir(int $versao): BinaryFileResponse
     {
         $versao = VersaoAudio::findOrFail($versao);
 
+        // Ficheiro em falta no disco: 404 limpo (response()->file() daria 500).
+        abort_unless(Storage::disk('local')->exists($versao->caminho_ficheiro), 404, 'O ficheiro desta versão não está no servidor.');
+
         // BinaryFileResponse (não Storage::response) por causa dos pedidos
         // HTTP Range do <audio> — ver PortalClienteController::reproduzir().
         return response()->file(Storage::disk('local')->path($versao->caminho_ficheiro));
@@ -84,7 +92,15 @@ public function reproduzir(int $versao): BinaryFileResponse
      */
     public function lembrarAprovacao(int $versao, NotificacaoEstudioService $notificacao): RedirectResponse
     {
-        $notificacao->aprovacaoPendente(VersaoAudio::findOrFail($versao));
+        $versao = VersaoAudio::findOrFail($versao);
+
+        // Só faz sentido lembrar a versão mais recente que ainda aguarda decisão — não uma
+        // versão antiga, nem uma já aprovada (ou com ajustes pedidos).
+        if ($versao->estado !== 'pendente_aprovacao' || $versao->faixaMusical->ultimaVersao()?->id !== $versao->id) {
+            return back()->with('erro', 'Esta versão já não está à espera de aprovação do artista.');
+        }
+
+        $notificacao->aprovacaoPendente($versao);
 
         return back()->with('sucesso', 'Lembrete enviado ao artista.');
     }
diff --git a/Modules/EstudioMusica/app/Http/Requests/AtualizarPerfilArtistaRequest.php b/Modules/EstudioMusica/app/Http/Requests/AtualizarPerfilArtistaRequest.php
index 6738de2..1b7e821 100644
--- a/Modules/EstudioMusica/app/Http/Requests/AtualizarPerfilArtistaRequest.php
+++ b/Modules/EstudioMusica/app/Http/Requests/AtualizarPerfilArtistaRequest.php
@@ -17,7 +17,7 @@ public function rules(): array
             'nome_artistico' => ['nullable', 'string', 'max:255'],
             'genero_musical' => ['nullable', 'string', 'max:255'],
             'integrantes_banda' => ['nullable', 'array', 'max:30'],
-            'integrantes_banda.*' => ['string', 'max:255'],
+            'integrantes_banda.*' => ['nullable', 'string', 'max:255'],
             'links_redes_sociais' => ['nullable', 'array', 'max:15'],
             'links_redes_sociais.*' => ['nullable', 'string', 'max:255'],
             'data_nascimento' => ['nullable', 'date', 'before:today'],
diff --git a/Modules/EstudioMusica/app/Http/Requests/AtualizarPortfolioRequest.php b/Modules/EstudioMusica/app/Http/Requests/AtualizarPortfolioRequest.php
new file mode 100644
index 0000000..8502921
--- /dev/null
+++ b/Modules/EstudioMusica/app/Http/Requests/AtualizarPortfolioRequest.php
@@ -0,0 +1,36 @@
+<?php
+
+namespace Modules\EstudioMusica\Http\Requests;
+
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * Marca (ou desmarca) um projeto como destaque da landing pública. Só https: o link vai para
+ * um <iframe>/<a> numa página pública (ver ProjetoMusical::incorporacaoPublica()).
+ */
+class AtualizarPortfolioRequest extends FormRequest
+{
+    public function authorize(): bool
+    {
+        return $this->user() !== null;
+    }
+
+    public function rules(): array
+    {
+        return [
+            'destaque_portfolio' => ['sometimes', 'boolean'],
+            'link_publico' => ['nullable', 'url:https', 'max:255', 'required_if:destaque_portfolio,1'],
+            // Divulgar o trabalho de um artista exige a autorização dele: o Administrador confirma-a aqui.
+            'autorizacao_artista' => ['nullable', 'accepted_if:destaque_portfolio,1'],
+        ];
+    }
+
+    public function messages(): array
+    {
+        return [
+            'link_publico.required_if' => 'Para aparecer na landing, o projeto precisa de um link público (Spotify, YouTube ou outro, em https).',
+            'link_publico.url' => 'O link público tem de ser um endereço https válido.',
+            'autorizacao_artista.accepted_if' => 'Confirma que o artista autorizou a divulgação deste projeto.',
+        ];
+    }
+}
diff --git a/Modules/EstudioMusica/app/Http/Requests/GuardarSalaEstudioRequest.php b/Modules/EstudioMusica/app/Http/Requests/GuardarSalaEstudioRequest.php
index 4903d3a..acb32cc 100644
--- a/Modules/EstudioMusica/app/Http/Requests/GuardarSalaEstudioRequest.php
+++ b/Modules/EstudioMusica/app/Http/Requests/GuardarSalaEstudioRequest.php
@@ -16,8 +16,10 @@ public function rules(): array
         return [
             'nome' => ['required', 'string', 'max:255'],
             'descricao' => ['nullable', 'string', 'max:2000'],
-            'preco_hora' => ['required', 'numeric', 'min:0', 'max:999999999999'],
-            'ativa' => ['boolean'],
+            // A coluna é decimal(10,2): acima de 99.999.999,99 a base de dados recusava com erro 500.
+            'preco_hora' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
+            // sometimes: um formulário que não envia o campo não pode ser lido como "desativar".
+            'ativa' => ['sometimes', 'boolean'],
         ];
     }
 }
diff --git a/Modules/EstudioMusica/app/Http/Requests/GuardarSessaoEstudioRequest.php b/Modules/EstudioMusica/app/Http/Requests/GuardarSessaoEstudioRequest.php
index 32d2017..023127e 100644
--- a/Modules/EstudioMusica/app/Http/Requests/GuardarSessaoEstudioRequest.php
+++ b/Modules/EstudioMusica/app/Http/Requests/GuardarSessaoEstudioRequest.php
@@ -22,8 +22,10 @@ public function rules(): array
                 ->where('empresa_id', $this->user()->empresa_id)->whereNull('deleted_at')],
             // O projeto tem de ser do MESMO cliente da sessão: caso contrário a
             // fatura saía para um cliente e ficava ligada ao projeto de outro.
+            // (nem apagado, nem cancelado.)
             'projeto_musical_id' => ['nullable', Rule::exists('projetos_musicais', 'id')
-                ->where('empresa_id', $this->user()->empresa_id)->where('cliente_id', $this->input('cliente_id'))],
+                ->where('empresa_id', $this->user()->empresa_id)->where('cliente_id', $this->input('cliente_id'))
+                ->whereNull('deleted_at')->whereNot('estado', 'cancelado')],
             'engenheiro_user_id' => ['nullable', Rule::exists('users', 'id')
                 ->where('empresa_id', $this->user()->empresa_id)->where('ativo', true)],
             'tipo_servico' => ['required', Rule::in(array_keys(config('estudiomusica.tipos_servico')))],
@@ -32,4 +34,12 @@ public function rules(): array
             'notas' => ['nullable', 'string', 'max:2000'],
         ];
     }
+
+    public function messages(): array
+    {
+        return [
+            'projeto_musical_id.exists' => 'O projeto escolhido não pertence ao cliente selecionado, está cancelado ou já não existe.',
+            'engenheiro_user_id.exists' => 'O engenheiro escolhido não é um membro ativo da equipa.',
+        ];
+    }
 }
diff --git a/Modules/EstudioMusica/app/Http/Requests/ReagendarSessaoEstudioRequest.php b/Modules/EstudioMusica/app/Http/Requests/ReagendarSessaoEstudioRequest.php
new file mode 100644
index 0000000..2ad14e2
--- /dev/null
+++ b/Modules/EstudioMusica/app/Http/Requests/ReagendarSessaoEstudioRequest.php
@@ -0,0 +1,21 @@
+<?php
+
+namespace Modules\EstudioMusica\Http\Requests;
+
+use Illuminate\Foundation\Http\FormRequest;
+
+class ReagendarSessaoEstudioRequest extends FormRequest
+{
+    public function authorize(): bool
+    {
+        return $this->user() !== null;
+    }
+
+    public function rules(): array
+    {
+        return [
+            'inicio_previsto' => ['required', 'date'],
+            'fim_previsto' => ['required', 'date', 'after:inicio_previsto'],
+        ];
+    }
+}
diff --git a/Modules/EstudioMusica/app/Jobs/EnviarLembretesSessaoJob.php b/Modules/EstudioMusica/app/Jobs/EnviarLembretesSessaoJob.php
index e5dbeca..c5d1194 100644
--- a/Modules/EstudioMusica/app/Jobs/EnviarLembretesSessaoJob.php
+++ b/Modules/EstudioMusica/app/Jobs/EnviarLembretesSessaoJob.php
@@ -11,6 +11,7 @@
 use Modules\Core\Services\TenantManager;
 use Modules\EstudioMusica\Models\SessaoEstudio;
 use Modules\EstudioMusica\Services\NotificacaoEstudioService;
+use Modules\EstudioMusica\Support\EmpresasDoEstudio;
 use Throwable;
 
 /**
@@ -35,16 +36,21 @@ class EnviarLembretesSessaoJob implements ShouldQueue
     public function handle(TenantManager $tenantManager, NotificacaoEstudioService $notificacao): void
     {
         $tenantManager->semTenant(function () use ($notificacao): void {
-            $this->enviarJanela(24, 'lembrete_24h_enviado_em', $notificacao);
-            $this->enviarJanela(2, 'lembrete_2h_enviado_em', $notificacao);
+            // Só estúdios com o serviço ativo e acesso: os outros já não são clientes.
+            $empresas = EmpresasDoEstudio::ativas();
+
+            $this->enviarJanela(24, 'lembrete_24h_enviado_em', $notificacao, $empresas);
+            $this->enviarJanela(2, 'lembrete_2h_enviado_em', $notificacao, $empresas);
         });
     }
 
-    protected function enviarJanela(int $horasAntes, string $coluna, NotificacaoEstudioService $notificacao): void
+    /** @param  array<int, int>  $empresas */
+    protected function enviarJanela(int $horasAntes, string $coluna, NotificacaoEstudioService $notificacao, array $empresas): void
     {
         $alvo = now()->addHours($horasAntes);
 
         SessaoEstudio::query()
+            ->whereIn('empresa_id', $empresas)
             ->whereNotIn('estado', ['cancelada', 'concluida'])
             ->whereNull($coluna)
             ->whereBetween('inicio_previsto', [
diff --git a/Modules/EstudioMusica/app/Jobs/EnviarMensagemClienteJob.php b/Modules/EstudioMusica/app/Jobs/EnviarMensagemClienteJob.php
index 1b7959c..da333fb 100644
--- a/Modules/EstudioMusica/app/Jobs/EnviarMensagemClienteJob.php
+++ b/Modules/EstudioMusica/app/Jobs/EnviarMensagemClienteJob.php
@@ -11,6 +11,7 @@
 use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Facades\Mail;
 use RuntimeException;
+use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalMensagemInterface;
 use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
 use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
 use Modules\EstudioMusica\Support\TransferenciaInternacional;
@@ -65,8 +66,8 @@ public function handle(CanalWhatsAppInterface $canalWhatsapp, CanalSmsInterface
 
             if ($whatsappPermitido || $smsPermitido) {
                 $tentou = true;
-                $entregue = ($whatsappPermitido && $canalWhatsapp->enviar($this->telefone, $this->mensagem))
-                    || ($smsPermitido && $canalSms->enviar($this->telefone, $this->mensagem));
+                $entregue = ($whatsappPermitido && $this->tentar($canalWhatsapp))
+                    || ($smsPermitido && $this->tentar($canalSms));
             } else {
                 Log::notice('EstudioMusica: envio por telefone bloqueado — falta a autorização da APD para a transferência internacional (ESTUDIOMUSICA_APD_AUTORIZACAO).');
             }
@@ -91,4 +92,23 @@ public function handle(CanalWhatsAppInterface $canalWhatsapp, CanalSmsInterface
             throw new RuntimeException('Nenhum canal conseguiu entregar a mensagem ao cliente.');
         }
     }
+
+    /**
+     * Uma exceção do canal (fornecedor em baixo, timeout) conta como "não entregou" — antes
+     * rebentava o job ANTES de tentar o e-mail, e as repetições falhavam sempre no mesmo ponto,
+     * por isso o cliente que também tinha e-mail nunca chegava a receber a mensagem.
+     */
+    protected function tentar(CanalMensagemInterface $canal): bool
+    {
+        try {
+            return $canal->enviar((string) $this->telefone, $this->mensagem);
+        } catch (Throwable $e) {
+            Log::error('EstudioMusica: falha no canal de mensagens', [
+                'canal' => $canal->identificador(),
+                'erro' => $e->getMessage(),
+            ]);
+
+            return false;
+        }
+    }
 }
diff --git a/Modules/EstudioMusica/app/Jobs/GerarPreviewAudioJob.php b/Modules/EstudioMusica/app/Jobs/GerarPreviewAudioJob.php
new file mode 100644
index 0000000..6016ff7
--- /dev/null
+++ b/Modules/EstudioMusica/app/Jobs/GerarPreviewAudioJob.php
@@ -0,0 +1,46 @@
+<?php
+
+namespace Modules\EstudioMusica\Jobs;
+
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Queue\InteractsWithQueue;
+use Modules\Core\Services\TenantManager;
+use Modules\EstudioMusica\Models\VersaoAudio;
+use Modules\EstudioMusica\Services\PreviewAudioService;
+
+/**
+ * Gera a pré-visualização (mp3 mono, baixa taxa de bits) de uma versão logo
+ * a seguir ao upload, fora do pedido HTTP. Antes só nascia no primeiro "play"
+ * do cliente: o pedido ficava a transcodificar com o ffmpeg (até 3 minutos) e
+ * os vários pedidos Range do <audio> arrancavam vários ffmpeg em paralelo.
+ *
+ * Recebe só ids (um worker não tem tenant identificado) e trabalha sem o scope
+ * de tenant, restringindo à empresa a que a versão pertence.
+ */
+class GerarPreviewAudioJob implements ShouldQueue
+{
+    use Dispatchable;
+    use InteractsWithQueue;
+    use Queueable;
+
+    public int $tries = 2;
+
+    public int $timeout = 300;
+
+    public function __construct(public int $versaoId, public int $empresaId) {}
+
+    public function handle(TenantManager $tenantManager, PreviewAudioService $previews): void
+    {
+        $tenantManager->semTenant(function () use ($previews): void {
+            $versao = VersaoAudio::query()
+                ->where('empresa_id', $this->empresaId)
+                ->find($this->versaoId);
+
+            if ($versao !== null) {
+                $previews->garantir($versao);
+            }
+        });
+    }
+}
diff --git a/Modules/EstudioMusica/app/Models/FaixaMusical.php b/Modules/EstudioMusica/app/Models/FaixaMusical.php
index b1f65f5..19b7401 100644
--- a/Modules/EstudioMusica/app/Models/FaixaMusical.php
+++ b/Modules/EstudioMusica/app/Models/FaixaMusical.php
@@ -21,9 +21,10 @@ class FaixaMusical extends Model
         'ordem',
     ];
 
+    /** withTrashed: a faixa continua a conhecer o seu projeto mesmo que ele tenha sido apagado (notificações, portal). */
     public function projetoMusical(): BelongsTo
     {
-        return $this->belongsTo(ProjetoMusical::class);
+        return $this->belongsTo(ProjetoMusical::class)->withTrashed();
     }
 
     public function versoes(): HasMany
diff --git a/Modules/EstudioMusica/app/Models/MarcadorAudio.php b/Modules/EstudioMusica/app/Models/MarcadorAudio.php
index a2c6df7..dcd4bce 100644
--- a/Modules/EstudioMusica/app/Models/MarcadorAudio.php
+++ b/Modules/EstudioMusica/app/Models/MarcadorAudio.php
@@ -39,8 +39,10 @@ public function versaoAudio(): BelongsTo
      */
     public function tempoFormatado(): string
     {
-        $segundos = (float) $this->tempo_segundos;
+        // Segundos inteiros primeiro: `float % int` gera um aviso de depreciação no PHP 8.1+
+        // (conversão implícita com perda de precisão) sempre que há décimas, como 83.45.
+        $total = max(0, (int) floor((float) $this->tempo_segundos));
 
-        return sprintf('%02d:%02d', floor($segundos / 60), floor($segundos % 60));
+        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
     }
 }
diff --git a/Modules/EstudioMusica/app/Models/ProjetoMusical.php b/Modules/EstudioMusica/app/Models/ProjetoMusical.php
index 36fcddb..5d34221 100644
--- a/Modules/EstudioMusica/app/Models/ProjetoMusical.php
+++ b/Modules/EstudioMusica/app/Models/ProjetoMusical.php
@@ -7,11 +7,14 @@
 use Illuminate\Database\Eloquent\Relations\BelongsToMany;
 use Illuminate\Database\Eloquent\Relations\HasMany;
 use Illuminate\Database\Eloquent\SoftDeletes;
+use Illuminate\Support\Collection;
 use Modules\Core\Models\User;
 use Modules\Core\Traits\BelongsToTenant;
 use Modules\Faturacao\Models\Cliente;
 use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\NotaCreditoDebito;
 use Modules\Faturacao\Models\Recibo;
+use Modules\Faturacao\Support\Dinheiro;
 
 class ProjetoMusical extends Model
 {
@@ -212,58 +215,61 @@ public function valorTotalEstimado(): float
         return $this->valorTotalFaturado();
     }
 
+    /**
+     * Faturado LÍQUIDO, com IVA: faturas emitidas menos as notas de crédito emitidas sobre
+     * elas. Uma fatura emitida nunca muda de estado — corrige-se/anula-se com nota de crédito
+     * (ver NotaCreditoDebitoService) — por isso ignorar os créditos deixava o projeto com uma
+     * "dívida fantasma" (e sem poder refaturar o que foi anulado).
+     */
     public function valorTotalFaturado(): float
     {
-        return (float) $this->faturas()
-            ->where('faturas.estado', 'emitida')
-            ->sum('faturas.valor_total');
+        return $this->emKwanzas($this->faturadoCentimos());
     }
 
     /**
-     * Soma do que já foi faturado SEM IVA. `valor_pacote` é sempre um valor
-     * sem IVA (é o preço unitário das faturas de sinal/saldo, onde o IVA é
-     * acrescentado), por isso o saldo a faturar tem de ser calculado nesta
-     * mesma base — nunca contra `valor_total` (que já inclui IVA).
+     * Soma do que já foi faturado SEM IVA (líquido de notas de crédito). `valor_pacote` é
+     * sempre um valor sem IVA (é o preço unitário das faturas de sinal/saldo, onde o IVA é
+     * acrescentado), por isso o saldo a faturar tem de ser calculado nesta mesma base —
+     * nunca contra `valor_total` (que já inclui IVA).
      */
     public function valorTotalFaturadoSemIva(): float
     {
-        return (float) $this->faturas()
-            ->where('faturas.estado', 'emitida')
-            ->sum('faturas.valor_sem_iva');
+        return $this->emKwanzas($this->faturadoSemIvaCentimos());
     }
 
+    /** Total (com IVA) já creditado por notas de crédito emitidas sobre as faturas do projeto. */
+    public function valorCreditado(): float
+    {
+        return $this->emKwanzas($this->resumoDasFaturas()->sum('creditado'));
+    }
+
+    /**
+     * Recebido: recibos emitidos ligados ao projeto pelo Estúdio (tabela própria) E os
+     * recibos emitidos diretamente no Faturação contra faturas do projeto — um pagamento
+     * registado no ecrã de Recibos do Faturação também quita o projeto.
+     */
     public function valorTotalRecebido(): float
     {
-        return (float) $this->recibos()
-            ->where('recibos.estado', 'emitido')
-            ->sum('recibos.valor');
+        return $this->emKwanzas($this->recebidoCentimos());
     }
 
     /**
      * O que o cliente tem de pagar no total, para efeitos de saldo e de
-     * quitação. Num projeto por PACOTE é o valor fechado do pacote, mesmo
+     * quitação. Num projeto por PACOTE é o valor fechado do pacote (com IVA), mesmo
      * que ainda só o sinal esteja faturado — senão um sinal de 40% pago
      * daria "saldo zero" e libertaria os ficheiros com 60% por pagar.
-     * (max() cobre o caso de se ter faturado mais do que o pacote, ex.:
-     * horas extra.) Num projeto por HORA não há total fixo: é o que já foi
-     * faturado, sessão a sessão.
+     * Menos o que foi creditado (um desconto dado por nota de crédito baixa o preço), mas
+     * nunca menos do que já está faturado (ex.: horas extra). Num projeto por HORA não há
+     * total fixo: é o que já foi faturado, sessão a sessão, líquido de créditos.
      */
     public function valorDevido(): float
     {
-        if ($this->tipo_cobranca === 'pacote') {
-            // O cliente paga o pacote COM IVA (valor_pacote é sem IVA).
-            $taxa = (float) config('faturacao.taxa_iva_geral');
-            $pacoteComIva = round((float) $this->valor_pacote * (1 + $taxa / 100), 2);
-
-            return max($pacoteComIva, $this->valorTotalFaturado());
-        }
-
-        return $this->valorTotalFaturado();
+        return $this->emKwanzas($this->devidoCentimos());
     }
 
     public function saldoDevedor(): float
     {
-        return max(0.0, round($this->valorDevido() - $this->valorTotalRecebido(), 2));
+        return $this->emKwanzas(max(0, $this->devidoCentimos() - $this->recebidoCentimos()));
     }
 
     /**
@@ -274,6 +280,184 @@ public function saldoDevedor(): float
      */
     public function financeiramenteQuitado(): bool
     {
-        return $this->valorDevido() > 0.0 && $this->saldoDevedor() <= 0.0;
+        $devido = $this->devidoCentimos();
+
+        return $devido > 0 && $devido - $this->recebidoCentimos() <= 0;
+    }
+
+    /**
+     * Faturas "vivas": emitidas e NÃO totalmente creditadas. É o que conta para saber se o
+     * sinal / o saldo já foram faturados — um sinal anulado por nota de crédito pode (e tem de
+     * poder) ser emitido outra vez.
+     *
+     * @return Collection<int, object{id: int, tipo: ?string, total: int, sem_iva: int, creditado: int, creditado_sem_iva: int}>
+     */
+    public function faturasAtivas(?string $tipo = null): Collection
+    {
+        return $this->resumoDasFaturas()
+            ->filter(fn (object $f) => $f->creditado < $f->total && ($tipo === null || $f->tipo === $tipo))
+            ->values();
+    }
+
+    /** Quanto falta receber de UMA fatura (cêntimos): total − notas de crédito − recibos emitidos. */
+    public function saldoDaFaturaCentimos(Fatura $fatura): int
+    {
+        $creditado = NotaCreditoDebito::withoutGlobalScopes()
+            ->where('empresa_id', $this->empresa_id)
+            ->where('fatura_id', $fatura->id)
+            ->where('tipo', 'credito')
+            ->where('estado', 'emitida')
+            ->get(['valor_total'])
+            ->sum(fn ($nota) => Dinheiro::centimos($nota->valor_total));
+
+        $recebido = Recibo::withoutGlobalScopes()
+            ->where('empresa_id', $this->empresa_id)
+            ->where('fatura_id', $fatura->id)
+            ->where('estado', 'emitido')
+            ->get(['valor'])
+            ->sum(fn ($recibo) => Dinheiro::centimos($recibo->valor));
+
+        return max(0, Dinheiro::centimos($fatura->valor_total) - $creditado - $recebido);
+    }
+
+    /**
+     * Situação de cada fatura emitida do projeto, para o ecrã: total, creditado, recebido e
+     * o que falta (em Kz). Uma fatura totalmente creditada fica com `em_falta` 0.
+     *
+     * @return Collection<int, object{fatura: Fatura, tipo: ?string, total: float, creditado: float, recebido: float, em_falta: float}>
+     */
+    public function situacaoDasFaturas(): Collection
+    {
+        $faturas = $this->faturas()
+            ->where('faturas.estado', 'emitida')
+            ->orderBy('faturas.id')
+            ->get();
+
+        if ($faturas->isEmpty()) {
+            return collect();
+        }
+
+        $creditos = NotaCreditoDebito::withoutGlobalScopes()
+            ->where('empresa_id', $this->empresa_id)
+            ->whereIn('fatura_id', $faturas->pluck('id'))
+            ->where('tipo', 'credito')
+            ->where('estado', 'emitida')
+            ->get(['fatura_id', 'valor_total'])
+            ->groupBy('fatura_id');
+
+        $recibos = Recibo::withoutGlobalScopes()
+            ->where('empresa_id', $this->empresa_id)
+            ->whereIn('fatura_id', $faturas->pluck('id'))
+            ->where('estado', 'emitido')
+            ->get(['fatura_id', 'valor'])
+            ->groupBy('fatura_id');
+
+        return $faturas->map(function (Fatura $fatura) use ($creditos, $recibos) {
+            $total = Dinheiro::centimos($fatura->valor_total);
+            $creditado = $creditos->get($fatura->id, collect())->sum(fn ($n) => Dinheiro::centimos($n->valor_total));
+            $recebido = $recibos->get($fatura->id, collect())->sum(fn ($r) => Dinheiro::centimos($r->valor));
+
+            return (object) [
+                'fatura' => $fatura,
+                'tipo' => $fatura->pivot->tipo,
+                'total' => $this->emKwanzas($total),
+                'creditado' => $this->emKwanzas($creditado),
+                'recebido' => $this->emKwanzas($recebido),
+                'em_falta' => $this->emKwanzas(max(0, $total - $creditado - $recebido)),
+            ];
+        })->values();
+    }
+
+    /**
+     * Faturas EMITIDAS do projeto com o que já foi creditado sobre cada uma (cêntimos).
+     *
+     * @return Collection<int, object{id: int, tipo: ?string, total: int, sem_iva: int, creditado: int, creditado_sem_iva: int}>
+     */
+    protected function resumoDasFaturas(): Collection
+    {
+        $faturas = $this->faturas()
+            ->where('faturas.estado', 'emitida')
+            ->get(['faturas.id', 'faturas.valor_total', 'faturas.valor_sem_iva']);
+
+        if ($faturas->isEmpty()) {
+            return collect();
+        }
+
+        $creditos = NotaCreditoDebito::withoutGlobalScopes()
+            ->where('empresa_id', $this->empresa_id)
+            ->whereIn('fatura_id', $faturas->pluck('id'))
+            ->where('tipo', 'credito')
+            ->where('estado', 'emitida')
+            ->get(['fatura_id', 'valor_total', 'valor_sem_iva'])
+            ->groupBy('fatura_id');
+
+        return $faturas->map(function (Fatura $fatura) use ($creditos) {
+            $notas = $creditos->get($fatura->id, collect());
+
+            return (object) [
+                'id' => (int) $fatura->id,
+                'tipo' => $fatura->pivot->tipo,
+                'total' => Dinheiro::centimos($fatura->valor_total),
+                'sem_iva' => Dinheiro::centimos($fatura->valor_sem_iva),
+                'creditado' => $notas->sum(fn ($n) => Dinheiro::centimos($n->valor_total)),
+                'creditado_sem_iva' => $notas->sum(fn ($n) => Dinheiro::centimos($n->valor_sem_iva)),
+            ];
+        })->values();
+    }
+
+    public function faturadoCentimos(): int
+    {
+        return $this->resumoDasFaturas()->sum(fn (object $f) => max(0, $f->total - $f->creditado));
+    }
+
+    public function faturadoSemIvaCentimos(): int
+    {
+        return $this->resumoDasFaturas()->sum(fn (object $f) => max(0, $f->sem_iva - $f->creditado_sem_iva));
+    }
+
+    public function recebidoCentimos(): int
+    {
+        $viaProjeto = $this->recibos()
+            ->where('recibos.estado', 'emitido')
+            ->get(['recibos.id', 'recibos.valor']);
+
+        $idsFaturas = $this->faturas()->pluck('faturas.id');
+
+        $viaFatura = $idsFaturas->isEmpty()
+            ? collect()
+            : Recibo::withoutGlobalScopes()
+                ->where('empresa_id', $this->empresa_id)
+                ->whereIn('fatura_id', $idsFaturas)
+                ->where('estado', 'emitido')
+                ->get(['id', 'valor']);
+
+        // Um recibo ligado pelas duas vias conta uma só vez.
+        return $viaProjeto->concat($viaFatura)
+            ->unique('id')
+            ->sum(fn ($recibo) => Dinheiro::centimos($recibo->valor));
+    }
+
+    public function devidoCentimos(): int
+    {
+        if ($this->tipo_cobranca === 'pacote') {
+            $creditado = $this->resumoDasFaturas()->sum('creditado');
+
+            return max(0, $this->pacoteComIvaCentimos() - $creditado, $this->faturadoCentimos());
+        }
+
+        return $this->faturadoCentimos();
+    }
+
+    /** O pacote COM IVA, calculado como uma única fatura o calcularia (mesma aritmética do Faturação). */
+    public function pacoteComIvaCentimos(): int
+    {
+        $linha = Dinheiro::calcularLinha(1, $this->valor_pacote ?? 0, config('faturacao.taxa_iva_geral'));
+
+        return Dinheiro::centimos($linha['total']);
+    }
+
+    protected function emKwanzas(int $centimos): float
+    {
+        return round($centimos / 100, 2);
     }
 }
diff --git a/Modules/EstudioMusica/app/Models/SessaoEstudio.php b/Modules/EstudioMusica/app/Models/SessaoEstudio.php
index 0a27a45..b4b110f 100644
--- a/Modules/EstudioMusica/app/Models/SessaoEstudio.php
+++ b/Modules/EstudioMusica/app/Models/SessaoEstudio.php
@@ -53,9 +53,13 @@ public function cliente(): BelongsTo
         return $this->belongsTo(Cliente::class)->withTrashed();
     }
 
+    /**
+     * withTrashed: um projeto apagado não pode "desaparecer" daqui — a cobrança decide por ele
+     * (um projeto por PACOTE apagado não pode passar a faturar as sessões à hora).
+     */
     public function projetoMusical(): BelongsTo
     {
-        return $this->belongsTo(ProjetoMusical::class);
+        return $this->belongsTo(ProjetoMusical::class)->withTrashed();
     }
 
     public function engenheiro(): BelongsTo
@@ -85,11 +89,27 @@ public function estaFeitoCheckOut(): bool
      * sessão concluída sem horas para cobrar só porque ninguém carregou
      * no botão de check-out.
      */
-    public function duracaoHoras(): float
+    public function rotuloEstado(): string
+    {
+        return [
+            'agendada' => 'Agendada',
+            'confirmada' => 'Confirmada',
+            'em_curso' => 'Em curso',
+            'concluida' => 'Concluída',
+            'cancelada' => 'Cancelada',
+        ][$this->estado] ?? (string) $this->estado;
+    }
+
+    public function duracaoMinutos(): int
     {
         $inicio = $this->inicio_real ?? $this->inicio_previsto;
         $fim = $this->fim_real ?? $this->fim_previsto;
 
-        return round(abs($inicio->diffInMinutes($fim)) / 60, 3);
+        return (int) round(abs($inicio->diffInMinutes($fim)));
+    }
+
+    public function duracaoHoras(): float
+    {
+        return round($this->duracaoMinutos() / 60, 3);
     }
 }
diff --git a/Modules/EstudioMusica/app/Services/AgendamentoService.php b/Modules/EstudioMusica/app/Services/AgendamentoService.php
index 942f2a1..d78facb 100644
--- a/Modules/EstudioMusica/app/Services/AgendamentoService.php
+++ b/Modules/EstudioMusica/app/Services/AgendamentoService.php
@@ -40,9 +40,7 @@ public function criarSessao(array $dados): SessaoEstudio
             $inicio = Carbon::parse($dados['inicio_previsto']);
             $fim = Carbon::parse($dados['fim_previsto']);
 
-            if ($fim->lte($inicio)) {
-                throw new InvalidArgumentException('O fim da sessão tem de ser posterior ao início.');
-            }
+            $this->validarIntervalo($inicio, $fim);
 
             if ($this->verificarConflito($sala, $inicio, $fim)) {
                 throw ConflitoAgendamentoException::paraSala($sala, $inicio->format('d/m H:i'), $fim->format('d/m H:i'));
@@ -60,9 +58,7 @@ public function criarSessao(array $dados): SessaoEstudio
      */
     public function reagendar(SessaoEstudio $sessao, Carbon $novoInicio, Carbon $novoFim): SessaoEstudio
     {
-        if ($novoFim->lte($novoInicio)) {
-            throw new InvalidArgumentException('O fim da sessão tem de ser posterior ao início.');
-        }
+        $this->validarIntervalo($novoInicio, $novoFim);
 
         return DB::transaction(function () use ($sessao, $novoInicio, $novoFim) {
             // Estado lido com lock: não se reagenda uma sessão cancelada, em curso ou concluída.
@@ -92,6 +88,24 @@ public function reagendar(SessaoEstudio $sessao, Carbon $novoInicio, Carbon $nov
         });
     }
 
+    /**
+     * O fim tem de ser depois do início e a duração não pode passar do máximo configurado.
+     *
+     * @throws InvalidArgumentException
+     */
+    protected function validarIntervalo(Carbon $inicio, Carbon $fim): void
+    {
+        if ($fim->lte($inicio)) {
+            throw new InvalidArgumentException('O fim da sessão tem de ser posterior ao início.');
+        }
+
+        $maxHoras = (int) config('estudiomusica.sessao_duracao_max_horas', 24);
+
+        if ($maxHoras > 0 && abs($inicio->diffInMinutes($fim)) > $maxHoras * 60) {
+            throw new InvalidArgumentException("Uma sessão não pode durar mais de {$maxHoras} horas — confirma as datas.");
+        }
+    }
+
     /**
      * Sobreposição clássica de intervalos: [inicio, fim] colide com
      * [inicio_previsto, fim_previsto] sempre que inicio < fim_previsto E
diff --git a/Modules/EstudioMusica/app/Services/CampanhaMarketingService.php b/Modules/EstudioMusica/app/Services/CampanhaMarketingService.php
index 432f71f..3af328a 100644
--- a/Modules/EstudioMusica/app/Services/CampanhaMarketingService.php
+++ b/Modules/EstudioMusica/app/Services/CampanhaMarketingService.php
@@ -10,6 +10,7 @@
 use Modules\EstudioMusica\Models\CupomDesconto;
 use Modules\EstudioMusica\Models\ProjetoMusical;
 use Modules\EstudioMusica\Support\Aniversario;
+use Modules\EstudioMusica\Support\EmpresasDoEstudio;
 use Modules\Faturacao\Models\Cliente;
 
 /**
@@ -46,6 +47,7 @@ public function enviarAniversarios(TenantManager $tenantManager): int
 
         $tenantManager->semTenant(function () use ($hoje, &$enviados): void {
             Cliente::query()
+                ->whereIn('empresa_id', EmpresasDoEstudio::ativas())
                 ->whereNotNull('data_nascimento')
                 ->where('aceita_marketing', true)
                 ->chunkById(100, function ($clientes) use ($hoje, &$enviados): void {
@@ -64,7 +66,7 @@ public function enviarAniversarios(TenantManager $tenantManager): int
                         $this->notificacao->enviar(
                             $cliente,
                             'Parabéns!',
-                            "Parabéns pelo teu dia, {$nome}! Ganhaste {$cupom->percentual_desconto}% de desconto em horas de estúdio, válido até {$cupom->valido_ate->format('d/m/Y')} — aplicado automaticamente na tua próxima sessão (cupão {$cupom->codigo}).".$this->rodapeSaida($cliente)
+                            "Parabéns pelo teu dia, {$nome}! Ganhaste {$this->percentagemLegivel($cupom->percentual_desconto)}% de desconto em horas de estúdio, válido até {$cupom->valido_ate->format('d/m/Y')} — aplicado automaticamente na tua próxima sessão (cupão {$cupom->codigo}).".$this->rodapeSaida($cliente)
                         );
 
                         $this->registarEnvio($cliente, 'aniversario', (string) $hoje->year);
@@ -90,6 +92,7 @@ public function enviarFollowUpsPosLancamento(TenantManager $tenantManager): int
 
         $tenantManager->semTenant(function () use ($dataAlvo, $dataLimite, &$enviados): void {
             ProjetoMusical::query()
+                ->whereIn('empresa_id', EmpresasDoEstudio::ativas())
                 ->where('estado', 'concluido')
                 ->whereDate('concluido_em', '<=', $dataAlvo)
                 ->whereDate('concluido_em', '>=', $dataLimite)
@@ -159,6 +162,12 @@ public function enviarCampanhaGenerica(string $identificadorCampanha, string $as
      * mensagens promocionais — direito de oposição ao marketing direto.
      * Ver LandingController::cancelarMarketing().
      */
+    /** "15.00" → "15"; "12.50" → "12.5". */
+    protected function percentagemLegivel(string|float $percentual): string
+    {
+        return rtrim(rtrim(number_format((float) $percentual, 2, '.', ''), '0'), '.');
+    }
+
     protected function rodapeSaida(Cliente $cliente): string
     {
         $url = URL::signedRoute('estudiomusica.landing.marketing-sair', [
diff --git a/Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php b/Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php
index ecb65ab..1daf54d 100644
--- a/Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php
+++ b/Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php
@@ -2,6 +2,7 @@
 
 namespace Modules\EstudioMusica\Services;
 
+use DomainException;
 use Illuminate\Support\Facades\DB;
 use InvalidArgumentException;
 use Modules\Core\Models\Empresa;
@@ -23,6 +24,11 @@
  * E). Nunca duplica a lógica fiscal: Faturas passam pelo FaturaService e
  * Recibos pelo ReciboService (caminho único de emissão, com lock na fatura,
  * teto do saldo e numeração/hash sem lacunas).
+ *
+ * Todo o dinheiro é calculado em cêntimos inteiros (Dinheiro), nunca em float.
+ * Os métodos que emitem lançam InvalidArgumentException (pedido inválido para este
+ * projeto/sessão) ou DomainException (o motor de faturação recusou: limite mensal do
+ * plano, subscrição inativa...) — quem chama tem de apanhar as duas.
  */
 class FaturacaoEstudioService
 {
@@ -41,7 +47,7 @@ public function __construct(
      * ProjetoMusical::valorTotalEstimado()), por isso é cobrado sessão a
      * sessão em cobrarSessao(), sem sinal em separado.
      *
-     * @throws InvalidArgumentException
+     * @throws InvalidArgumentException|DomainException
      */
     public function gerarFaturaSinal(ProjetoMusical $projeto): Fatura
     {
@@ -53,26 +59,25 @@ public function gerarFaturaSinal(ProjetoMusical $projeto): Fatura
             // emitido não se apaga, só se anula com nota de crédito.
             $projeto = ProjetoMusical::query()->lockForUpdate()->findOrFail($projeto->id);
 
-            $jaTemSinal = $projeto->faturas()
-                ->wherePivot('tipo', 'sinal')
-                ->where('faturas.estado', 'emitida')
-                ->exists();
+            $this->exigirProjetoAtivo($projeto);
 
-            if ($jaTemSinal) {
+            // "Viva" = emitida e não totalmente creditada: um sinal anulado por nota de crédito
+            // pode ser emitido outra vez.
+            if ($projeto->faturasAtivas('sinal')->isNotEmpty()) {
                 throw new InvalidArgumentException('O sinal deste projeto já foi emitido.');
             }
 
             $percentual = $projeto->percentualSinalEfetivo();
-            $valor = round($projeto->valorTotalEstimado() * $percentual / 100, 2);
+            $sinal = $this->percentagemEmCentimos(Dinheiro::centimos($projeto->valor_pacote), $percentual);
 
-            if ($valor <= 0.0) {
+            if ($sinal <= 0) {
                 throw new InvalidArgumentException('O valor do sinal tem de ser superior a zero — confirma o valor do pacote e a percentagem do sinal.');
             }
 
             $fatura = $this->criarEEmitirFatura($projeto->empresa, $projeto->cliente, [[
                 'descricao' => "Sinal ({$percentual}%) - Projeto \"{$projeto->nome}\"",
                 'quantidade' => 1,
-                'preco_unitario' => $valor,
+                'preco_unitario' => Dinheiro::formatar($sinal),
                 'taxa_iva' => (float) config('faturacao.taxa_iva_geral'),
             ]]);
 
@@ -84,9 +89,10 @@ public function gerarFaturaSinal(ProjetoMusical $projeto): Fatura
 
     /**
      * Fatura do saldo restante (valor do pacote menos o que já foi
-     * faturado — sinal incluído). Idem: só para projetos por pacote.
+     * faturado — sinal incluído, líquido de notas de crédito). Idem: só para
+     * projetos por pacote.
      *
-     * @throws InvalidArgumentException
+     * @throws InvalidArgumentException|DomainException
      */
     public function gerarFaturaSaldoFinal(ProjetoMusical $projeto): Fatura
     {
@@ -98,17 +104,19 @@ public function gerarFaturaSaldoFinal(ProjetoMusical $projeto): Fatura
             // fatura do primeiro e acabar em "sem saldo por faturar".
             $projeto = ProjetoMusical::query()->lockForUpdate()->findOrFail($projeto->id);
 
+            $this->exigirProjetoAtivo($projeto);
+
             // Tudo sem IVA: o IVA é acrescentado pela fatura (sinal + saldo = pacote + IVA).
-            $restante = max(0.0, round($projeto->valorTotalEstimado() - $projeto->valorTotalFaturadoSemIva(), 2));
+            $restante = Dinheiro::centimos($projeto->valor_pacote) - $projeto->faturadoSemIvaCentimos();
 
-            if ($restante <= 0.0) {
+            if ($restante <= 0) {
                 throw new InvalidArgumentException('Este projeto já não tem saldo por faturar.');
             }
 
             $fatura = $this->criarEEmitirFatura($projeto->empresa, $projeto->cliente, [[
                 'descricao' => "Saldo final - Projeto \"{$projeto->nome}\"",
                 'quantidade' => 1,
-                'preco_unitario' => $restante,
+                'preco_unitario' => Dinheiro::formatar($restante),
                 'taxa_iva' => (float) config('faturacao.taxa_iva_geral'),
             ]]);
 
@@ -124,6 +132,12 @@ public function gerarFaturaSaldoFinal(ProjetoMusical $projeto): Fatura
      * horas dessas sessões já estão cobertas pelo sinal/saldo final do
      * projeto; devolve null nesse caso para o controller saber que não há
      * nada novo para mostrar ao utilizador.
+     *
+     * O valor é calculado ao cêntimo a partir dos MINUTOS (minutos × preço/hora ÷ 60) e vai
+     * numa só linha — antes a duração era arredondada a milésimos de hora (20 min = 0,333 h)
+     * e o desconto aplicado ao preço unitário, o que fazia perder cêntimos em cada sessão.
+     *
+     * @throws InvalidArgumentException|DomainException
      */
     public function cobrarSessao(SessaoEstudio $sessao): ?Fatura
     {
@@ -140,16 +154,23 @@ public function cobrarSessao(SessaoEstudio $sessao): ?Fatura
         }
 
         $sala = $sessao->salaEstudio;
-        $horas = $sessao->duracaoHoras();
+        $minutos = $sessao->duracaoMinutos();
+        $precoHora = Dinheiro::centimos($sala->preco_hora);
+        $total = intdiv($minutos * $precoHora + 30, 60);
 
         // Sala gratuita ou sessão sem duração: não há nada a cobrar, e um
         // documento fiscal de valor zero não faz sentido.
-        if (round($horas * (float) $sala->preco_hora, 2) <= 0.0) {
+        if ($total <= 0) {
             return null;
         }
 
-        $descricao = "Sessão de estúdio - {$sala->nome} - {$sessao->rotuloTipoServico()} ({$horas}h)";
-        $precoUnitario = (float) $sala->preco_hora;
+        $descricao = sprintf(
+            'Sessão de estúdio - %s - %s (%s a %s/h)',
+            $sala->nome,
+            $sessao->rotuloTipoServico(),
+            $this->duracaoLegivel($minutos),
+            Dinheiro::formatar($precoHora),
+        );
 
         // Cupão de aniversário (secção F): "X% de desconto em horas de
         // estúdio" — aplicado aqui, na cobrança por hora, ao cupão válido
@@ -165,14 +186,20 @@ public function cobrarSessao(SessaoEstudio $sessao): ?Fatura
 
         if ($cupom) {
             $percentual = (float) $cupom->percentual_desconto;
-            $precoUnitario = round($precoUnitario * (1 - $percentual / 100), 2);
-            $descricao .= " — desconto {$percentual}% (cupão {$cupom->codigo})";
+            $liquido = $total - $this->percentagemEmCentimos($total, $percentual);
+
+            if ($liquido > 0) {
+                $total = $liquido;
+                $descricao .= ' — desconto '.rtrim(rtrim(number_format($percentual, 2, '.', ''), '0'), '.')."% (cupão {$cupom->codigo})";
+            } else {
+                $cupom = null; // desconto total: não gasta o cupão numa fatura que não se emite
+            }
         }
 
         $fatura = $this->criarEEmitirFatura($sessao->empresa, $sessao->cliente, [[
             'descricao' => $descricao,
-            'quantidade' => $horas,
-            'preco_unitario' => $precoUnitario,
+            'quantidade' => 1,
+            'preco_unitario' => Dinheiro::formatar($total),
             'taxa_iva' => (float) config('faturacao.taxa_iva_geral'),
         ]]);
 
@@ -188,10 +215,11 @@ public function cobrarSessao(SessaoEstudio $sessao): ?Fatura
 
     /**
      * Regista o recebimento de um pagamento contra uma fatura já emitida
-     * DESTE projeto. Sem valor, recebe o que ainda falta na fatura. A emissão
-     * (lock, teto do saldo, numeração e hash) é do ReciboService.
+     * DESTE projeto. Sem valor, recebe o que ainda falta na fatura (já deduzidas as notas
+     * de crédito e os recibos anteriores). A emissão (lock, teto do saldo, numeração e
+     * hash) é do ReciboService.
      *
-     * @throws InvalidArgumentException|\DomainException
+     * @throws InvalidArgumentException|DomainException
      */
     public function registrarRecibo(ProjetoMusical $projeto, Fatura $fatura, string $meioPagamento, ?float $valor = null): Recibo
     {
@@ -205,15 +233,14 @@ public function registrarRecibo(ProjetoMusical $projeto, Fatura $fatura, string
 
             if ($valor === null) {
                 // Por omissão recebe o que falta (e não o total da fatura, que
-                // seria recusado se já houver recebimentos parciais).
-                $jaRecebido = Recibo::withoutGlobalScopes()
-                    ->where('empresa_id', $fatura->empresa_id)
-                    ->where('fatura_id', $fatura->id)
-                    ->where('estado', 'emitido')
-                    ->get(['valor'])
-                    ->sum(fn ($recibo) => Dinheiro::centimos($recibo->valor));
-
-                $valor = Dinheiro::formatar(Dinheiro::centimos($fatura->valor_total) - $jaRecebido);
+                // seria recusado se já houver recebimentos parciais ou notas de crédito).
+                $emFalta = $projeto->saldoDaFaturaCentimos($fatura);
+
+                if ($emFalta <= 0) {
+                    throw new InvalidArgumentException('Esta fatura já não tem valor por receber.');
+                }
+
+                $valor = Dinheiro::formatar($emFalta);
             }
 
             $recibo = $this->recibos()->emitir(
@@ -236,7 +263,7 @@ protected function recibos(): ReciboService
     }
 
     /**
-     * @param  array<int, array{descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
+     * @param  array<int, array{descricao: string, quantidade: int|float, preco_unitario: string|float, taxa_iva: float}>  $linhas
      */
     protected function criarEEmitirFatura(Empresa $empresa, Cliente $cliente, array $linhas): Fatura
     {
@@ -245,6 +272,34 @@ protected function criarEEmitirFatura(Empresa $empresa, Cliente $cliente, array
         return $this->faturaService->emitir($fatura);
     }
 
+    /** $percentual% de um valor em cêntimos, arredondado a meio para cima (40,00% de 100000,00 → 40000,00). */
+    protected function percentagemEmCentimos(int $centimos, float $percentual): int
+    {
+        $pontosBase = (int) round($percentual * 100);
+
+        return intdiv($centimos * $pontosBase + 5000, 10000);
+    }
+
+    /** 80 → "1h20min"; 60 → "1h"; 45 → "45min". */
+    protected function duracaoLegivel(int $minutos): string
+    {
+        $horas = intdiv($minutos, 60);
+        $resto = $minutos % 60;
+
+        return match (true) {
+            $horas === 0 => "{$resto}min",
+            $resto === 0 => "{$horas}h",
+            default => "{$horas}h{$resto}min",
+        };
+    }
+
+    protected function exigirProjetoAtivo(ProjetoMusical $projeto): void
+    {
+        if ($projeto->estado === 'cancelado') {
+            throw new InvalidArgumentException('Um projeto cancelado não pode ser faturado.');
+        }
+    }
+
     protected function exigirCobrancaPorPacote(ProjetoMusical $projeto): void
     {
         if ($projeto->tipo_cobranca !== 'pacote') {
diff --git a/Modules/EstudioMusica/app/Services/PreviewAudioService.php b/Modules/EstudioMusica/app/Services/PreviewAudioService.php
index 23e6af0..5ef3e05 100644
--- a/Modules/EstudioMusica/app/Services/PreviewAudioService.php
+++ b/Modules/EstudioMusica/app/Services/PreviewAudioService.php
@@ -2,6 +2,9 @@
 
 namespace Modules\EstudioMusica\Services;
 
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Filesystem\FilesystemAdapter;
+use Illuminate\Support\Facades\Cache;
 use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Facades\Storage;
 use Modules\EstudioMusica\Models\VersaoAudio;
@@ -9,12 +12,15 @@
 use Throwable;
 
 /**
- * Gera (uma vez, de forma preguiçosa) a pré-visualização de uma versão de
- * áudio: mono, baixa taxa de bits. É o que o portal serve ENQUANTO o
- * projeto não está quitado — antes, o player recebia o ficheiro original
- * completo, e o "download só após quitação" era contornável (bastava
- * guardar o áudio a partir do player). Requer `ffmpeg` no servidor
- * (binário configurável em ESTUDIOMUSICA_FFMPEG_BIN).
+ * Gera (uma vez) a pré-visualização de uma versão de áudio: mono, baixa taxa
+ * de bits. É o que o portal serve ENQUANTO o projeto não está quitado — antes,
+ * o player recebia o ficheiro original completo, e o "download só após
+ * quitação" era contornável (bastava guardar o áudio a partir do player).
+ * Requer `ffmpeg` no servidor (binário configurável em ESTUDIOMUSICA_FFMPEG_BIN).
+ *
+ * Normalmente gerada logo após o upload (Jobs\GerarPreviewAudioJob); o portal
+ * continua a chamar garantir() como rede de segurança. Um lock por versão
+ * garante UM só ffmpeg de cada vez (o <audio> faz vários pedidos Range).
  */
 class PreviewAudioService
 {
@@ -25,7 +31,7 @@ public function garantir(VersaoAudio $versao): ?string
     {
         $disco = Storage::disk('local');
 
-        if ($versao->caminho_preview && $disco->exists($versao->caminho_preview)) {
+        if ($this->previewPronto($versao, $disco)) {
             return $versao->caminho_preview;
         }
 
@@ -33,14 +39,36 @@ public function garantir(VersaoAudio $versao): ?string
             return null;
         }
 
+        try {
+            return Cache::lock("estudiomusica:preview:{$versao->id}", 200)->block(25, function () use ($versao, $disco): ?string {
+                // Outro processo pode ter acabado a pré-visualização enquanto esperávamos pelo lock.
+                $versao->refresh();
+
+                if ($this->previewPronto($versao, $disco)) {
+                    return $versao->caminho_preview;
+                }
+
+                return $this->gerar($versao, $disco);
+            });
+        } catch (LockTimeoutException) {
+            return null;
+        }
+    }
+
+    protected function previewPronto(VersaoAudio $versao, FilesystemAdapter $disco): bool
+    {
+        return $versao->caminho_preview && $disco->exists($versao->caminho_preview);
+    }
+
+    protected function gerar(VersaoAudio $versao, FilesystemAdapter $disco): ?string
+    {
         $destino = "estudiomusica/previews/{$versao->empresa_id}/{$versao->id}.mp3";
         $temporario = "estudiomusica/previews/{$versao->empresa_id}/{$versao->id}-".bin2hex(random_bytes(4)).'.tmp.mp3';
 
         $disco->makeDirectory(dirname($destino));
 
         try {
-            // Ficheiro temporário + move: dois pedidos simultâneos (o <audio>
-            // faz vários pedidos Range) nunca escrevem no mesmo ficheiro.
+            // Ficheiro temporário + move: nunca se escreve a meio no destino final.
             $processo = new Process([
                 config('estudiomusica.ffmpeg_bin', 'ffmpeg'),
                 '-y', '-v', 'error',
diff --git a/Modules/EstudioMusica/app/Services/TermosEstudioService.php b/Modules/EstudioMusica/app/Services/TermosEstudioService.php
index 506f0ae..ad7cba9 100644
--- a/Modules/EstudioMusica/app/Services/TermosEstudioService.php
+++ b/Modules/EstudioMusica/app/Services/TermosEstudioService.php
@@ -5,6 +5,7 @@
 use Illuminate\Database\QueryException;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\DB;
+use Modules\Core\Models\Empresa;
 use Modules\EstudioMusica\Models\AceiteTermos;
 use Modules\EstudioMusica\Models\TermosEstudio;
 use Modules\Faturacao\Models\Cliente;
@@ -40,6 +41,10 @@ public function atual(int $empresaId, string $nomeEstudio = 'o Estúdio'): Termo
     public function publicar(int $empresaId, string $titulo, string $conteudo): TermosEstudio
     {
         return DB::transaction(function () use ($empresaId, $titulo, $conteudo) {
+            // Lock na empresa: duas publicações em simultâneo já não calculam o mesmo número de
+            // versão (a segunda rebentava na chave única empresa+versão).
+            Empresa::query()->whereKey($empresaId)->lockForUpdate()->first();
+
             $proxima = (int) TermosEstudio::query()->where('empresa_id', $empresaId)->max('versao') + 1;
 
             return TermosEstudio::create([
diff --git a/Modules/EstudioMusica/app/Support/EmpresasDoEstudio.php b/Modules/EstudioMusica/app/Support/EmpresasDoEstudio.php
new file mode 100644
index 0000000..1d6c19d
--- /dev/null
+++ b/Modules/EstudioMusica/app/Support/EmpresasDoEstudio.php
@@ -0,0 +1,27 @@
+<?php
+
+namespace Modules\EstudioMusica\Support;
+
+use Modules\Core\Models\Empresa;
+use Modules\Core\Support\Servico;
+
+/**
+ * Empresas a quem os jobs agendados (lembretes de sessão, campanhas) podem enviar mensagens
+ * em nome do estúdio: têm o serviço Estúdio ativo e acesso (subscrição ativa ou em tolerância).
+ * Os jobs varrem TODAS as empresas sem tenant — sem este filtro continuavam a contactar os
+ * clientes de estúdios que já cancelaram o serviço ou deixaram a subscrição expirar.
+ */
+final class EmpresasDoEstudio
+{
+    /** @return array<int, int> ids das empresas elegíveis */
+    public static function ativas(): array
+    {
+        return Empresa::query()
+            ->with('servicos')
+            ->get()
+            ->filter(fn (Empresa $empresa) => $empresa->temServico(Servico::Estudio) && $empresa->temAcesso())
+            ->map(fn (Empresa $empresa) => (int) $empresa->id)
+            ->values()
+            ->all();
+    }
+}
diff --git a/Modules/EstudioMusica/config/config.php b/Modules/EstudioMusica/config/config.php
index dd7f4ef..a22db5b 100644
--- a/Modules/EstudioMusica/config/config.php
+++ b/Modules/EstudioMusica/config/config.php
@@ -99,6 +99,10 @@
     // Quantas horas antes do início marcado já se pode fazer check-in.
     'checkin_antecedencia_max_horas' => 12,
 
+    // Duração máxima de uma sessão agendada. Um erro de digitação no fim (ex.: o ano) bloqueava a
+    // sala durante meses; sessões mais longas dividem-se em várias.
+    'sessao_duracao_max_horas' => 24,
+
     // Binário do ffmpeg, usado para gerar as pré-visualizações do portal.
     'ffmpeg_bin' => env('ESTUDIOMUSICA_FFMPEG_BIN', 'ffmpeg'),
 
diff --git a/Modules/EstudioMusica/database/migrations/2026_10_28_000001_add_indice_estado_inicio_to_sessoes_estudio_table.php b/Modules/EstudioMusica/database/migrations/2026_10_28_000001_add_indice_estado_inicio_to_sessoes_estudio_table.php
new file mode 100644
index 0000000..861b7e5
--- /dev/null
+++ b/Modules/EstudioMusica/database/migrations/2026_10_28_000001_add_indice_estado_inicio_to_sessoes_estudio_table.php
@@ -0,0 +1,26 @@
+<?php
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * O job de lembretes (de hora a hora, sem tenant) procura sessões por estado + janela de
+     * início: sem este índice varria a tabela inteira de todas as empresas em cada execução.
+     */
+    public function up(): void
+    {
+        Schema::table('sessoes_estudio', function (Blueprint $table) {
+            $table->index(['estado', 'inicio_previsto'], 'sessoes_estudio_estado_inicio_idx');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('sessoes_estudio', function (Blueprint $table) {
+            $table->dropIndex('sessoes_estudio_estado_inicio_idx');
+        });
+    }
+};
diff --git a/Modules/EstudioMusica/docs/AUDITORIA.md b/Modules/EstudioMusica/docs/AUDITORIA.md
new file mode 100644
index 0000000..12546a5
--- /dev/null
+++ b/Modules/EstudioMusica/docs/AUDITORIA.md
@@ -0,0 +1,40 @@
+# Auditoria do módulo EstudioMusica
+
+Auditoria completa (código, base de dados, portal, faturação, jobs, vistas), feita lendo o módulo
+contra o código real do Core e do Faturação e **executando** a suite — não só por leitura. Cada
+correção tem pelo menos um teste que falha no código anterior.
+
+## Corrigido
+
+| # | Gravidade | Problema | Correção |
+|---|-----------|----------|----------|
+| 1 | Crítica | Portal: `reproduzir`, `descarregar`, `comentar`, `aprovar` e `solicitarAjustes` davam **erro 500**. O Laravel passa os parâmetros de rota por posição; a rota tem `{token}` e `{versao}` mas os métodos só declaravam `$versao` (3 testes já falhavam). | Os métodos declaram `string $token` antes de `$versao`. |
+| 2 | Alta | Depois de o cliente tocar o áudio, comentar/aprovar/aceitar termos redirecionava-o **para o ficheiro de áudio** em vez do portal (`back()` + `Referrer-Policy: no-referrer` → usa o último GET da sessão, que é o stream). | Todas as respostas do portal vão explicitamente para a página do portal. |
+| 3 | Alta | O player servia o **original** a projetos quitados mesmo sem o cliente ter aceitado os Termos (bastava «guardar áudio como…»). | O original só sai com projeto quitado **e** termos aceites; senão, a pré-visualização. |
+| 4 | Alta | Notas de crédito ignoradas: anular um sinal/saldo deixava uma «dívida fantasma», impedia de refaturar («já foi emitido» / «sem saldo») e podia deixar o projeto impossível de quitar (o ReciboService recusa receber acima de total − créditos). | `ProjetoMusical` calcula faturado/devido líquido de notas de crédito; o sinal/saldo anulados podem ser emitidos outra vez. |
+| 5 | Alta | Só contavam como pagos os recibos ligados pela tabela do Estúdio: um pagamento registado no ecrã de Recibos do Faturação **não quitava** o projeto. | Conta também qualquer recibo emitido contra uma fatura do projeto (sem contar duas vezes). |
+| 6 | Alta | Sessões criadas pelo ecrã nunca tinham **projeto** (o formulário não o oferecia): a cobrança por hora nunca chegava ao projeto e este nunca ficava quitado. Também faltava engenheiro. | O formulário tem projeto e engenheiro; abre pré-selecionado a partir da página do projeto. |
+| 7 | Alta | Erros da faturação (limite mensal de faturas do plano, subscrição inativa) davam **500** em sinal, saldo e check-out; no check-out o rollback fazia a sessão ficar «em curso» e, ao repetir mais tarde, o cliente pagava as horas entretanto passadas. | Sinal/saldo mostram o motivo. O check-out grava sempre o fim real; se a fatura não sair, aviso claro e botão **Faturar** (idempotente) na sessão. |
+| 8 | Média | Horas faturadas em milésimos (20 min = 0,333 h → perda de cêntimos) e cupão aplicado ao preço unitário. | Cobrança calculada ao cêntimo a partir dos minutos; cupão sobre o total; todo o dinheiro em `Dinheiro` (cêntimos), sem `float`. |
+| 9 | Média | Sessão de um projeto **por pacote apagado** era faturada à hora (a relação ignorava projetos apagados). | `withTrashed()` em `SessaoEstudio` e `FaixaMusical`. |
+| 10 | Média | Erros de validação **não apareciam** em quase nenhum ecrã (o layout do Core só mostra `sucesso`/`erro`): formulários inválidos falhavam em silêncio. | Partial `partials/erros` em todos os ecrãs do staff e no portal. |
+| 11 | Média | `preco_hora` aceitava valores que a coluna `decimal(10,2)` não aguenta (erro 500); `ativa` sem `sometimes`; e **não havia como editar/desativar uma sala**. | Limites corrigidos; edição inline (nome, preço, descrição, ativa). |
+| 12 | Média | Sessões sem limite de duração (um ano errado bloqueava a sala meses); projeto apagado/cancelado aceite; sem cancelar/reagendar no ecrã. | Máximo configurável (`sessao_duracao_max_horas`, 24); só projetos ativos do cliente; botões Cancelar e Reagendar (`reagendar()` já existia no serviço, sem rota). |
+| 13 | Média | A landing só mostra projetos marcados como destaque, mas **não havia ecrã** para os marcar. | Cartão «Portfólio na página pública» (só Administrador), link https obrigatório e confirmação da autorização do artista. |
+| 14 | Média | Lembretes e campanhas varriam **todas** as empresas, incluindo estúdios que já cancelaram o serviço ou com subscrição expirada. | Só empresas com o serviço Estúdio ativo e acesso (`EmpresasDoEstudio`). |
+| 15 | Média | Se o canal WhatsApp/SMS **lançava exceção**, o job rebentava antes de tentar o e-mail e as repetições falhavam sempre no mesmo ponto. | Exceção do canal conta como «não entregou»; o e-mail e o SMS continuam a ser tentados. |
+| 16 | Média | A pré-visualização (ffmpeg, até 3 min) gerava-se **dentro do pedido HTTP** do primeiro «play», com vários pedidos Range a lançar vários ffmpeg. | Gerada logo após o upload por job; lock por versão como rede de segurança. |
+| 17 | Baixa | `MarcadorAudio::tempoFormatado()`: `float % int` → aviso de depreciação no PHP 8.1+. | Segundos inteiros primeiro. |
+| 18 | Baixa | Converter pedido de orçamento em cliente: sem lock (duplicados) e o consentimento de marketing passava para um cliente achado só por **telefone** (pode ser outra pessoa). | Lock; o consentimento só passa se o e-mail coincidir. |
+| 19 | Baixa | Ficheiro em falta no disco → 500 (`response()->file`/`download`). | 404 limpo. |
+| 20 | Baixa | Lembrete de aprovação para qualquer versão (antiga ou já aprovada); sem botão no ecrã. | Só a versão mais recente pendente; botão na página do projeto. |
+| 21 | Baixa | Publicar termos em simultâneo → erro de chave única; faixa acrescentada a projeto concluído/cancelado; `integrantes_banda` em branco recusava o formulário; percentagem do cupão «15.00%». | Lock por empresa; guarda de estado; `nullable`; «15%». |
+| 22 | Baixa | Job de lembretes sem índice (varre a tabela de todas as empresas de hora a hora). | Migration `(estado, inicio_previsto)`. |
+
+## Não alterado (decisões para ti)
+
+- **Landing por id numérico** (`/estudio/{id}`) permite enumerar estúdios; o `slug` em `empresas` pertence ao Atelier.
+- **Estado `confirmada`** nunca é atribuído; não há ação «confirmar sessão».
+- **Editar projeto** depois de criado (prazo, valor do pacote, % do sinal, produtor/engenheiro) continua sem ecrã.
+- **`criado_por_cliente = false`** (nota interna) é mostrado no portal: hoje nada cria essas notas; se passar a existir, filtrar no portal.
+- A conversão de pedido→cliente continua a juntar por e-mail ou telefone idênticos (texto exato; sem normalizar números).
diff --git a/Modules/EstudioMusica/resources/views/equipa/index.blade.php b/Modules/EstudioMusica/resources/views/equipa/index.blade.php
index 23e339d..11b497c 100644
--- a/Modules/EstudioMusica/resources/views/equipa/index.blade.php
+++ b/Modules/EstudioMusica/resources/views/equipa/index.blade.php
@@ -3,6 +3,7 @@
 @section('titulo', 'Equipa do Estúdio')
 
 @section('conteudo')
+@include('estudiomusica::partials.erros')
     <h1 class="mb-1 text-lg font-semibold text-slate-900">Equipa do Estúdio</h1>
     <p class="mb-6 text-sm text-slate-500">
         Atribui os papéis de Receção e Engenheiro a utilizadores ativos da tua empresa.
diff --git a/Modules/EstudioMusica/resources/views/partials/erros.blade.php b/Modules/EstudioMusica/resources/views/partials/erros.blade.php
new file mode 100644
index 0000000..a814c0a
--- /dev/null
+++ b/Modules/EstudioMusica/resources/views/partials/erros.blade.php
@@ -0,0 +1,10 @@
+{{-- Erros de validação: o layout do Core só mostra os avisos 'sucesso'/'erro', por isso sem isto um formulário inválido falhava em silêncio. --}}
+@if ($errors->any())
+    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
+        <ul class="list-disc space-y-0.5 pl-5">
+            @foreach ($errors->all() as $mensagem)
+                <li>{{ $mensagem }}</li>
+            @endforeach
+        </ul>
+    </div>
+@endif
diff --git a/Modules/EstudioMusica/resources/views/portal/mostrar.blade.php b/Modules/EstudioMusica/resources/views/portal/mostrar.blade.php
index 1da388e..d965e9d 100644
--- a/Modules/EstudioMusica/resources/views/portal/mostrar.blade.php
+++ b/Modules/EstudioMusica/resources/views/portal/mostrar.blade.php
@@ -9,6 +9,8 @@
         <p class="text-sm text-slate-500">Os teus projetos, versões para audição e aprovação.</p>
     </header>
 
+    @include('estudiomusica::partials.erros')
+
     @unless ($termosAceites)
         <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm space-y-2">
             <p class="font-medium text-amber-900">{{ $termos->titulo }} (versão {{ $termos->versao }})</p>
diff --git a/Modules/EstudioMusica/resources/views/projetos/criar.blade.php b/Modules/EstudioMusica/resources/views/projetos/criar.blade.php
index 806e6e1..1fccbeb 100644
--- a/Modules/EstudioMusica/resources/views/projetos/criar.blade.php
+++ b/Modules/EstudioMusica/resources/views/projetos/criar.blade.php
@@ -3,6 +3,7 @@
 @section('titulo', 'Novo Projeto Musical')
 
 @section('conteudo')
+@include('estudiomusica::partials.erros')
 <div class="max-w-2xl space-y-6">
     <h1 class="text-lg font-semibold text-slate-900">Novo Projeto Musical</h1>
 
diff --git a/Modules/EstudioMusica/resources/views/projetos/index.blade.php b/Modules/EstudioMusica/resources/views/projetos/index.blade.php
index 88cfadb..3a0549d 100644
--- a/Modules/EstudioMusica/resources/views/projetos/index.blade.php
+++ b/Modules/EstudioMusica/resources/views/projetos/index.blade.php
@@ -3,6 +3,7 @@
 @section('titulo', 'Projetos Musicais')
 
 @section('conteudo')
+@include('estudiomusica::partials.erros')
 <div class="space-y-6">
     <div class="flex items-center justify-between">
         <h1 class="text-lg font-semibold text-slate-900">Projetos Musicais</h1>
diff --git a/Modules/EstudioMusica/resources/views/projetos/show.blade.php b/Modules/EstudioMusica/resources/views/projetos/show.blade.php
index 035a445..5b74314 100644
--- a/Modules/EstudioMusica/resources/views/projetos/show.blade.php
+++ b/Modules/EstudioMusica/resources/views/projetos/show.blade.php
@@ -3,6 +3,7 @@
 @section('titulo', $projeto->nome)
 
 @section('conteudo')
+@include('estudiomusica::partials.erros')
 <div class="space-y-6">
     <div class="flex flex-wrap items-center justify-between gap-3">
         <div>
@@ -39,9 +40,12 @@
             <dl class="mt-2 space-y-1 text-sm text-slate-600">
                 @if ($projeto->tipo_cobranca === 'pacote')
                     <div class="flex justify-between"><dt>Valor do pacote (sem IVA)</dt><dd>{{ number_format((float) $projeto->valor_pacote, 2) }} Kz</dd></div>
-                    <div class="flex justify-between"><dt>Pacote com IVA</dt><dd>{{ number_format((float) $projeto->valor_pacote * (1 + (float) config('faturacao.taxa_iva_geral') / 100), 2) }} Kz</dd></div>
+                    <div class="flex justify-between"><dt>Pacote com IVA</dt><dd>{{ number_format($projeto->pacoteComIvaCentimos() / 100, 2) }} Kz</dd></div>
+                @endif
+                <div class="flex justify-between"><dt>Faturado (líquido de notas de crédito)</dt><dd>{{ number_format($projeto->valorTotalFaturado(), 2) }} Kz</dd></div>
+                @if ($projeto->valorCreditado() > 0)
+                    <div class="flex justify-between text-slate-500"><dt>Creditado por notas de crédito</dt><dd>{{ number_format($projeto->valorCreditado(), 2) }} Kz</dd></div>
                 @endif
-                <div class="flex justify-between"><dt>Faturado</dt><dd>{{ number_format($projeto->valorTotalFaturado(), 2) }} Kz</dd></div>
                 <div class="flex justify-between"><dt>Recebido</dt><dd>{{ number_format($projeto->valorTotalRecebido(), 2) }} Kz</dd></div>
                 <div class="flex justify-between font-medium text-slate-900"><dt>Saldo devedor</dt><dd>{{ number_format($projeto->saldoDevedor(), 2) }} Kz</dd></div>
             </dl>
@@ -67,15 +71,19 @@
     <div class="rounded-lg border border-slate-200 bg-white p-4">
         <h2 class="text-sm font-semibold text-slate-900">Faturas e pagamentos</h2>
         <div class="mt-2 divide-y divide-slate-100 text-sm">
-            @forelse ($projeto->faturas->where('estado', 'emitida') as $fatura)
+            @forelse ($projeto->situacaoDasFaturas() as $situacao)
                 @php
-                    $recebido = (float) $projeto->recibos->where('estado', 'emitido')->where('fatura_id', $fatura->id)->sum('valor');
-                    $emFalta = round((float) $fatura->valor_total - $recebido, 2);
+                    $fatura = $situacao->fatura;
+                    $emFalta = $situacao->em_falta;
                 @endphp
                 <div class="flex flex-wrap items-center justify-between gap-2 py-2">
                     <div>
                         <p class="font-medium text-slate-900">{{ $fatura->numero_documento }}</p>
-                        <p class="text-xs text-slate-500">{{ number_format((float) $fatura->valor_total, 2) }} Kz &middot; recebido {{ number_format($recebido, 2) }} Kz</p>
+                        <p class="text-xs text-slate-500">
+                            {{ number_format($situacao->total, 2) }} Kz
+                            @if ($situacao->creditado > 0) &middot; creditado {{ number_format($situacao->creditado, 2) }} Kz @endif
+                            &middot; recebido {{ number_format($situacao->recebido, 2) }} Kz
+                        </p>
                     </div>
                     @if ($emFalta > 0)
                         <form method="POST" action="{{ route('estudiomusica.projetos.recibos', $projeto) }}" class="flex flex-wrap items-center gap-2">
@@ -90,6 +98,8 @@
                             <input type="number" step="0.01" min="0.01" max="{{ $emFalta }}" name="valor" placeholder="{{ number_format($emFalta, 2, '.', '') }}" class="w-28 rounded-md border-slate-300 text-xs" title="Deixa vazio para receber o que falta">
                             <button type="submit" class="rounded-md bg-slate-900 px-2.5 py-1 text-xs text-white hover:bg-slate-700">Registar pagamento</button>
                         </form>
+                    @elseif ($situacao->creditado >= $situacao->total)
+                        <span class="text-xs text-slate-500">Anulada por nota de crédito</span>
                     @else
                         <span class="text-xs text-emerald-700">Paga</span>
                     @endif
@@ -100,6 +110,33 @@
         </div>
     </div>
 
+    {{-- Portfólio na página pública: só quem tem a permissão de marketing (Administrador) --}}
+    @can('estudiomusica.gerir-marketing')
+        <div class="rounded-lg border border-slate-200 bg-white p-4">
+            <h2 class="text-sm font-semibold text-slate-900">Portfólio na página pública</h2>
+            <p class="mt-1 text-xs text-slate-500">
+                A landing só mostra projetos marcados aqui e com link público (Spotify, YouTube ou outro, em https).
+                Divulgar o trabalho de um artista exige a autorização dele.
+            </p>
+            <form method="POST" action="{{ route('estudiomusica.projetos.portfolio', $projeto) }}" class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
+                @csrf
+                @method('PUT')
+                <label class="flex items-center gap-2 text-slate-700 sm:col-span-2">
+                    <input type="hidden" name="destaque_portfolio" value="0">
+                    <input type="checkbox" name="destaque_portfolio" value="1" @checked(old('destaque_portfolio', $projeto->destaque_portfolio)) class="rounded border-slate-300">
+                    Mostrar este projeto na página pública
+                </label>
+                <input type="url" name="link_publico" value="{{ old('link_publico', $projeto->link_publico) }}" placeholder="https://open.spotify.com/..."
+                       class="rounded-md border-slate-300 text-sm sm:col-span-2">
+                <label class="flex items-center gap-2 text-xs text-slate-600 sm:col-span-2">
+                    <input type="checkbox" name="autorizacao_artista" value="1" class="rounded border-slate-300">
+                    O artista autorizou a divulgação deste projeto
+                </label>
+                <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 sm:col-span-2 sm:w-40">Guardar</button>
+            </form>
+        </div>
+    @endcan
+
     {{-- Ficha do artista (e consentimento de marketing) --}}
     <div class="rounded-lg border border-slate-200 bg-white p-4">
         <h2 class="text-sm font-semibold text-slate-900">Ficha do artista</h2>
@@ -154,7 +191,15 @@
                         @forelse ($faixa->versoes as $versao)
                             <li class="flex items-center justify-between">
                                 <span>{{ $versao->rotulo }} — {{ $versao->estado }} ({{ $versao->marcadores->count() }} comentário(s))</span>
-                                <a href="{{ route('estudiomusica.versoes.reproduzir', $versao) }}" class="underline">ouvir</a>
+                                <span class="flex items-center gap-3">
+                                    @if ($loop->first && $versao->estado === 'pendente_aprovacao')
+                                        <form method="POST" action="{{ route('estudiomusica.versoes.lembrete-aprovacao', $versao) }}" class="inline">
+                                            @csrf
+                                            <button type="submit" class="underline">lembrar aprovação</button>
+                                        </form>
+                                    @endif
+                                    <a href="{{ route('estudiomusica.versoes.reproduzir', $versao) }}" class="underline">ouvir</a>
+                                </span>
                             </li>
                         @empty
                             <li class="text-slate-400">Sem versões enviadas.</li>
@@ -186,7 +231,7 @@
                 <li class="py-2 text-slate-500">Ainda sem sessões agendadas para este projeto.</li>
             @endforelse
         </ul>
-        <a href="{{ route('estudiomusica.sessoes.index') }}" class="mt-2 inline-block text-xs text-indigo-600 underline">Agendar nova sessão &rarr;</a>
+        <a href="{{ route('estudiomusica.sessoes.index', ['projeto' => $projeto->id]) }}" class="mt-2 inline-block text-xs text-indigo-600 underline">Agendar nova sessão &rarr;</a>
     </div>
 </div>
 @endsection
diff --git a/Modules/EstudioMusica/resources/views/reclamacoes/index.blade.php b/Modules/EstudioMusica/resources/views/reclamacoes/index.blade.php
index a7e3820..7d46adb 100644
--- a/Modules/EstudioMusica/resources/views/reclamacoes/index.blade.php
+++ b/Modules/EstudioMusica/resources/views/reclamacoes/index.blade.php
@@ -3,6 +3,7 @@
 @section('titulo', 'Reclamações')
 
 @section('conteudo')
+@include('estudiomusica::partials.erros')
     <h1 class="mb-1 text-lg font-semibold text-slate-900">Reclamações</h1>
     <p class="mb-6 text-sm text-slate-500">
         Reclamações recebidas pela página pública. Não substituem o Livro de Reclamações físico, que deve estar
diff --git a/Modules/EstudioMusica/resources/views/salas/index.blade.php b/Modules/EstudioMusica/resources/views/salas/index.blade.php
index 251adf3..ea49c7c 100644
--- a/Modules/EstudioMusica/resources/views/salas/index.blade.php
+++ b/Modules/EstudioMusica/resources/views/salas/index.blade.php
@@ -3,7 +3,8 @@
 @section('titulo', 'Salas do Estúdio')
 
 @section('conteudo')
-<div x-data="{ aFormar: false }" class="space-y-6">
+@include('estudiomusica::partials.erros')
+<div x-data="{ aFormar: {{ $errors->any() ? 'true' : 'false' }} }" class="space-y-6">
     <div class="flex items-center justify-between">
         <h1 class="text-lg font-semibold text-slate-900">Salas do Estúdio</h1>
         <button type="button" @click="aFormar = !aFormar" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
@@ -14,15 +15,15 @@
     <form x-show="aFormar" x-cloak method="POST" action="{{ route('estudiomusica.salas.store') }}"
           class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-4">
         @csrf
-        <input type="text" name="nome" placeholder="Nome (ex.: Sala A - Captação)" required
+        <input type="text" name="nome" value="{{ old('nome') }}" placeholder="Nome (ex.: Sala A - Captação)" required
                class="rounded-md border-slate-300 text-sm sm:col-span-2">
-        <input type="number" step="0.01" min="0" name="preco_hora" placeholder="Preço/hora (Kz)" required
+        <input type="number" step="0.01" min="0" max="99999999.99" name="preco_hora" value="{{ old('preco_hora') }}" placeholder="Preço/hora (Kz)" required
                class="rounded-md border-slate-300 text-sm">
         <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
             Guardar
         </button>
         <textarea name="descricao" placeholder="Descrição (opcional)" rows="2"
-                  class="rounded-md border-slate-300 text-sm sm:col-span-4"></textarea>
+                  class="rounded-md border-slate-300 text-sm sm:col-span-4">{{ old('descricao') }}</textarea>
     </form>
 
     <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
@@ -32,21 +33,47 @@ class="rounded-md border-slate-300 text-sm sm:col-span-4"></textarea>
                     <th class="px-4 py-2">Nome</th>
                     <th class="px-4 py-2">Preço/hora</th>
                     <th class="px-4 py-2">Estado</th>
+                    <th class="px-4 py-2"></th>
                 </tr>
             </thead>
             <tbody class="divide-y divide-slate-100">
                 @forelse ($salas as $sala)
-                    <tr>
-                        <td class="px-4 py-2 font-medium text-slate-900">{{ $sala->nome }}</td>
+                    <tr class="align-top">
+                        <td class="px-4 py-2 font-medium text-slate-900">
+                            {{ $sala->nome }}
+                            @if ($sala->descricao)
+                                <span class="block text-xs font-normal text-slate-400">{{ $sala->descricao }}</span>
+                            @endif
+                        </td>
                         <td class="px-4 py-2 text-slate-600">{{ number_format($sala->preco_hora, 2) }} Kz</td>
                         <td class="px-4 py-2">
                             <span class="rounded-full px-2 py-0.5 text-xs {{ $sala->ativa ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                                 {{ $sala->ativa ? 'Ativa' : 'Inativa' }}
                             </span>
                         </td>
+                        <td class="px-4 py-2 text-right">
+                            @can('estudiomusica.gerir-salas')
+                                <details class="text-left">
+                                    <summary class="cursor-pointer text-right text-xs text-indigo-600 underline">Editar</summary>
+                                    <form method="POST" action="{{ route('estudiomusica.salas.update', $sala) }}" class="mt-2 grid gap-2 sm:grid-cols-2">
+                                        @csrf
+                                        @method('PUT')
+                                        <input type="text" name="nome" value="{{ $sala->nome }}" required class="rounded-md border-slate-300 text-xs">
+                                        <input type="number" step="0.01" min="0" max="99999999.99" name="preco_hora" value="{{ $sala->preco_hora }}" required class="rounded-md border-slate-300 text-xs">
+                                        <textarea name="descricao" rows="2" placeholder="Descrição" class="rounded-md border-slate-300 text-xs sm:col-span-2">{{ $sala->descricao }}</textarea>
+                                        <label class="flex items-center gap-2 text-xs text-slate-600">
+                                            <input type="hidden" name="ativa" value="0">
+                                            <input type="checkbox" name="ativa" value="1" @checked($sala->ativa) class="rounded border-slate-300">
+                                            Sala ativa (aceita novas sessões)
+                                        </label>
+                                        <button type="submit" class="rounded-md bg-slate-900 px-2.5 py-1 text-xs text-white hover:bg-slate-700">Guardar</button>
+                                    </form>
+                                </details>
+                            @endcan
+                        </td>
                     </tr>
                 @empty
-                    <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">Ainda sem salas registadas.</td></tr>
+                    <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Ainda sem salas registadas.</td></tr>
                 @endforelse
             </tbody>
         </table>
diff --git a/Modules/EstudioMusica/resources/views/sessoes/index.blade.php b/Modules/EstudioMusica/resources/views/sessoes/index.blade.php
index 9dc3f56..4d9f4cc 100644
--- a/Modules/EstudioMusica/resources/views/sessoes/index.blade.php
+++ b/Modules/EstudioMusica/resources/views/sessoes/index.blade.php
@@ -3,7 +3,8 @@
 @section('titulo', 'Sessões de Estúdio')
 
 @section('conteudo')
-<div x-data="{ aFormar: false }" class="space-y-6">
+@include('estudiomusica::partials.erros')
+<div x-data="{ aFormar: {{ $projetoPreSelecionado || $errors->any() || old('sala_estudio_id') ? 'true' : 'false' }} }" class="space-y-6">
     <div class="flex items-center justify-between">
         <h1 class="text-lg font-semibold text-slate-900">Sessões de Estúdio</h1>
         <button type="button" @click="aFormar = !aFormar" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">
@@ -17,23 +18,39 @@ class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-3
         <select name="sala_estudio_id" required class="rounded-md border-slate-300 text-sm">
             <option value="">Sala...</option>
             @foreach ($salas as $sala)
-                <option value="{{ $sala->id }}">{{ $sala->nome }}</option>
+                <option value="{{ $sala->id }}" @selected((int) old('sala_estudio_id') === $sala->id)>{{ $sala->nome }}</option>
             @endforeach
         </select>
         <select name="cliente_id" required class="rounded-md border-slate-300 text-sm">
             <option value="">Artista/Cliente...</option>
             @foreach ($clientes as $cliente)
-                <option value="{{ $cliente->id }}">{{ $cliente->nome_artistico ?: $cliente->nome }}</option>
+                <option value="{{ $cliente->id }}" @selected((int) old('cliente_id', $projetoPreSelecionado?->cliente_id) === $cliente->id)>{{ $cliente->nome_artistico ?: $cliente->nome }}</option>
             @endforeach
         </select>
         <select name="tipo_servico" required class="rounded-md border-slate-300 text-sm">
             <option value="">Tipo de serviço...</option>
             @foreach (config('estudiomusica.tipos_servico') as $chave => $rotulo)
-                <option value="{{ $chave }}">{{ $rotulo }}</option>
+                <option value="{{ $chave }}" @selected(old('tipo_servico') === $chave)>{{ $rotulo }}</option>
             @endforeach
         </select>
-        <input type="datetime-local" name="inicio_previsto" required class="rounded-md border-slate-300 text-sm">
-        <input type="datetime-local" name="fim_previsto" required class="rounded-md border-slate-300 text-sm">
+        {{-- Sem esta ligação sessão→projeto, as horas nunca chegavam ao projeto (nem à sua quitação). --}}
+        <select name="projeto_musical_id" class="rounded-md border-slate-300 text-sm" title="Tem de ser um projeto do cliente escolhido">
+            <option value="">Sem projeto (sessão avulsa)</option>
+            @foreach ($projetos as $projeto)
+                <option value="{{ $projeto->id }}" @selected((int) old('projeto_musical_id', $projetoPreSelecionado?->id) === $projeto->id)>
+                    {{ $projeto->cliente->nome_artistico ?: $projeto->cliente->nome }} — {{ $projeto->nome }}
+                </option>
+            @endforeach
+        </select>
+        <select name="engenheiro_user_id" class="rounded-md border-slate-300 text-sm">
+            <option value="">Engenheiro (opcional)...</option>
+            @foreach ($membrosEquipa as $membro)
+                <option value="{{ $membro->id }}" @selected((int) old('engenheiro_user_id') === $membro->id)>{{ $membro->name }}</option>
+            @endforeach
+        </select>
+        <div></div>
+        <input type="datetime-local" name="inicio_previsto" value="{{ old('inicio_previsto') }}" required class="rounded-md border-slate-300 text-sm">
+        <input type="datetime-local" name="fim_previsto" value="{{ old('fim_previsto') }}" required class="rounded-md border-slate-300 text-sm">
         <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
             Confirmar
         </button>
@@ -45,7 +62,7 @@ class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-3
                 <tr>
                     <th class="px-4 py-2">Quando</th>
                     <th class="px-4 py-2">Sala</th>
-                    <th class="px-4 py-2">Cliente</th>
+                    <th class="px-4 py-2">Cliente / Projeto</th>
                     <th class="px-4 py-2">Serviço</th>
                     <th class="px-4 py-2">Estado</th>
                     <th class="px-4 py-2"></th>
@@ -53,23 +70,63 @@ class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-3
             </thead>
             <tbody class="divide-y divide-slate-100">
                 @forelse ($sessoes as $sessao)
-                    <tr>
+                    @php
+                        $porPacote = $sessao->projetoMusical && $sessao->projetoMusical->tipo_cobranca === 'pacote';
+                        $faturavel = $sessao->estado === 'concluida' && ! $sessao->fatura_id && ! $porPacote && (float) $sessao->salaEstudio->preco_hora > 0;
+                    @endphp
+                    <tr class="align-top">
                         <td class="px-4 py-2 text-slate-600">{{ $sessao->inicio_previsto->format('d/m/Y H:i') }}</td>
                         <td class="px-4 py-2 text-slate-600">{{ $sessao->salaEstudio->nome }}</td>
-                        <td class="px-4 py-2 text-slate-600">{{ $sessao->cliente->nome_artistico ?: $sessao->cliente->nome }}</td>
-                        <td class="px-4 py-2 text-slate-600">{{ $sessao->rotuloTipoServico() }}</td>
-                        <td class="px-4 py-2 text-slate-600">{{ $sessao->estado }}</td>
-                        <td class="px-4 py-2 text-right">
+                        <td class="px-4 py-2 text-slate-600">
+                            {{ $sessao->cliente->nome_artistico ?: $sessao->cliente->nome }}
+                            @if ($sessao->projetoMusical)
+                                <span class="block text-xs text-slate-400">{{ $sessao->projetoMusical->nome }}</span>
+                            @endif
+                        </td>
+                        <td class="px-4 py-2 text-slate-600">
+                            {{ $sessao->rotuloTipoServico() }}
+                            @if ($sessao->engenheiro)
+                                <span class="block text-xs text-slate-400">{{ $sessao->engenheiro->name }}</span>
+                            @endif
+                        </td>
+                        <td class="px-4 py-2 text-slate-600">
+                            {{ $sessao->rotuloEstado() }}
+                            @if ($faturavel)
+                                <span class="block text-xs text-amber-700">Sem fatura</span>
+                            @endif
+                        </td>
+                        <td class="space-y-1 px-4 py-2 text-right">
                             @if ($sessao->estado === 'agendada' || $sessao->estado === 'confirmada')
                                 <form method="POST" action="{{ route('estudiomusica.sessoes.check-in', $sessao) }}" class="inline">
                                     @csrf
                                     <button type="submit" class="text-xs text-indigo-600 underline">check-in</button>
                                 </form>
+                                <form method="POST" action="{{ route('estudiomusica.sessoes.cancelar', $sessao) }}" class="inline"
+                                      onsubmit="return confirm('Cancelar esta sessão?')">
+                                    @csrf
+                                    @method('DELETE')
+                                    <button type="submit" class="ml-2 text-xs text-red-600 underline">Cancelar</button>
+                                </form>
+                                <details class="text-left">
+                                    <summary class="cursor-pointer text-right text-xs text-indigo-600 underline">Reagendar</summary>
+                                    <form method="POST" action="{{ route('estudiomusica.sessoes.reagendar', $sessao) }}" class="mt-1 flex flex-wrap justify-end gap-1">
+                                        @csrf
+                                        @method('PUT')
+                                        <input type="datetime-local" name="inicio_previsto" value="{{ $sessao->inicio_previsto->format('Y-m-d\TH:i') }}" required class="rounded-md border-slate-300 text-xs">
+                                        <input type="datetime-local" name="fim_previsto" value="{{ $sessao->fim_previsto->format('Y-m-d\TH:i') }}" required class="rounded-md border-slate-300 text-xs">
+                                        <button type="submit" class="rounded-md bg-slate-900 px-2 py-1 text-xs text-white hover:bg-slate-700">Guardar</button>
+                                    </form>
+                                </details>
                             @elseif ($sessao->estado === 'em_curso')
                                 <form method="POST" action="{{ route('estudiomusica.sessoes.check-out', $sessao) }}" class="inline">
                                     @csrf
                                     <button type="submit" class="text-xs text-indigo-600 underline">check-out</button>
                                 </form>
+                            @elseif ($faturavel)
+                                <form method="POST" action="{{ route('estudiomusica.sessoes.faturar', $sessao) }}" class="inline">
+                                    @csrf
+                                    <button type="submit" class="text-xs text-indigo-600 underline">Faturar</button>
+                                </form>
                             @endif
                         </td>
                     </tr>
diff --git a/Modules/EstudioMusica/resources/views/solicitacoes/index.blade.php b/Modules/EstudioMusica/resources/views/solicitacoes/index.blade.php
index 4025d29..4627299 100644
--- a/Modules/EstudioMusica/resources/views/solicitacoes/index.blade.php
+++ b/Modules/EstudioMusica/resources/views/solicitacoes/index.blade.php
@@ -3,6 +3,7 @@
 @section('titulo', 'Pedidos de Orçamento')
 
 @section('conteudo')
+@include('estudiomusica::partials.erros')
 <div class="space-y-6">
     <h1 class="text-lg font-semibold text-slate-900">Pedidos de Orçamento (landing page)</h1>
 
diff --git a/Modules/EstudioMusica/routes/web.php b/Modules/EstudioMusica/routes/web.php
index b1c6f9b..7cf8de2 100644
--- a/Modules/EstudioMusica/routes/web.php
+++ b/Modules/EstudioMusica/routes/web.php
@@ -120,6 +120,11 @@
             Route::post('/projetos/{projeto}/faixas', [FaixaMusicalController::class, 'store'])->name('projetos.faixas.store');
         });
 
+        // Portfólio da landing pública: só quem tem a permissão de marketing (Administrador).
+        Route::middleware('permission:estudiomusica.gerir-marketing')->group(function () {
+            Route::put('/projetos/{projeto}/portfolio', [ProjetoMusicalController::class, 'atualizarPortfolio'])->whereNumber('projeto')->name('projetos.portfolio');
+        });
+
         // Estado do projeto/faixa: Administrador + Engenheiro (quem
         // acompanha a produção tecnicamente).
         Route::middleware('permission:estudiomusica.atualizar-estado-projeto')->group(function () {
@@ -138,6 +143,7 @@
         // Sessões e pedidos de orçamento recebidos: Administrador + Receção.
         Route::middleware('permission:estudiomusica.gerir-sessoes')->group(function () {
             Route::post('/sessoes', [SessaoEstudioController::class, 'store'])->name('sessoes.store');
+            Route::put('/sessoes/{sessao}/reagendar', [SessaoEstudioController::class, 'reagendar'])->name('sessoes.reagendar');
             Route::delete('/sessoes/{sessao}', [SessaoEstudioController::class, 'cancelar'])->name('sessoes.cancelar');
             Route::get('/solicitacoes', [SolicitacaoOrcamentoController::class, 'index'])->name('solicitacoes.index');
             Route::put('/solicitacoes/{solicitacao}/estado', [SolicitacaoOrcamentoController::class, 'atualizarEstado'])->name('solicitacoes.estado');
@@ -149,6 +155,8 @@
         Route::middleware('permission:estudiomusica.checkin-checkout')->group(function () {
             Route::post('/sessoes/{sessao}/check-in', [SessaoEstudioController::class, 'checkIn'])->name('sessoes.check-in');
             Route::post('/sessoes/{sessao}/check-out', [SessaoEstudioController::class, 'checkOut'])->name('sessoes.check-out');
+            // Repete a fatura por hora de uma sessão já concluída (quando o check-out não a pôde emitir).
+            Route::post('/sessoes/{sessao}/faturar', [SessaoEstudioController::class, 'faturar'])->name('sessoes.faturar');
         });
 
         // Upload de áudio: Administrador + Engenheiro.
diff --git a/Modules/EstudioMusica/tests/Feature/FaturacaoEstudioTest.php b/Modules/EstudioMusica/tests/Feature/FaturacaoEstudioTest.php
new file mode 100644
index 0000000..8cae649
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Feature/FaturacaoEstudioTest.php
@@ -0,0 +1,269 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Feature;
+
+use Carbon\Carbon;
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Services\TenantManager;
+use Modules\EstudioMusica\Models\CupomDesconto;
+use Modules\EstudioMusica\Models\ProjetoMusical;
+use Modules\EstudioMusica\Models\SalaEstudio;
+use Modules\EstudioMusica\Models\SessaoEstudio;
+use Modules\EstudioMusica\Services\FaturacaoEstudioService;
+use Modules\EstudioMusica\Tests\Support\CriaDocumentosFiscais;
+use Modules\Faturacao\Models\Cliente;
+use Modules\Faturacao\Services\NotaCreditoDebitoService;
+use Modules\Faturacao\Services\ReciboService;
+use Tests\TestCase;
+
+require_once __DIR__.'/../Support/CriaDocumentosFiscais.php';
+
+/**
+ * Faturação do Estúdio contra o motor REAL do Faturação (emissão, notas de
+ * crédito e recibos): o que é faturado, creditado e recebido tem de bater
+ * certo com o que o projeto considera "devido" e "quitado".
+ */
+class FaturacaoEstudioTest extends TestCase
+{
+    use CriaDocumentosFiscais;
+    use RefreshDatabase;
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
+
+    protected FaturacaoEstudioService $faturacao;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->prepararChaveFiscal();
+        config(['faturacao.taxa_iva_geral' => 14.0]);
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Faturação', 'nif' => '400000031']);
+        app(TenantManager::class)->set($this->empresa->id);
+
+        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Artista Faturação']);
+        $this->faturacao = app(FaturacaoEstudioService::class);
+    }
+
+    protected function projeto(string $tipo = 'pacote', array $extra = []): ProjetoMusical
+    {
+        return ProjetoMusical::create(array_merge([
+            'empresa_id' => $this->empresa->id,
+            'cliente_id' => $this->cliente->id,
+            'nome' => 'Projeto Faturação',
+            'tipo_cobranca' => $tipo,
+            'valor_pacote' => $tipo === 'pacote' ? 100000 : null,
+            'percentual_sinal' => 40,
+        ], $extra));
+    }
+
+    protected function anularComNotaDeCredito($fatura): void
+    {
+        $notas = app(NotaCreditoDebitoService::class);
+        $notas->emitir($notas->criarParaAnularFatura($fatura, 'Emitida por engano'));
+    }
+
+    protected function sessaoConcluida(int $minutos, ?ProjetoMusical $projeto = null, float $precoHora = 5000): SessaoEstudio
+    {
+        $sala = SalaEstudio::create(['empresa_id' => $this->empresa->id, 'nome' => 'Sala A', 'preco_hora' => $precoHora]);
+        $inicio = Carbon::parse('2026-10-20 10:00:00');
+
+        $sessao = new SessaoEstudio;
+        $sessao->forceFill([
+            'empresa_id' => $this->empresa->id,
+            'sala_estudio_id' => $sala->id,
+            'cliente_id' => $this->cliente->id,
+            'projeto_musical_id' => $projeto?->id,
+            'tipo_servico' => 'mixagem',
+            'inicio_previsto' => $inicio,
+            'fim_previsto' => $inicio->copy()->addMinutes($minutos),
+            'inicio_real' => $inicio,
+            'fim_real' => $inicio->copy()->addMinutes($minutos),
+            'estado' => 'concluida',
+        ])->save();
+
+        return $sessao;
+    }
+
+    public function test_sinal_e_saldo_somam_exatamente_o_pacote_mais_iva(): void
+    {
+        $projeto = $this->projeto();
+
+        $sinal = $this->faturacao->gerarFaturaSinal($projeto);
+        $saldo = $this->faturacao->gerarFaturaSaldoFinal($projeto);
+
+        $this->assertSame('45600.00', $sinal->valor_total);
+        $this->assertSame('68400.00', $saldo->valor_total);
+        $this->assertSame(114000.0, $projeto->valorDevido());
+    }
+
+    public function test_anular_o_sinal_com_nota_de_credito_permite_emiti_lo_outra_vez(): void
+    {
+        $projeto = $this->projeto();
+        $sinal = $this->faturacao->gerarFaturaSinal($projeto);
+
+        $this->anularComNotaDeCredito($sinal);
+
+        // O sinal anulado já não conta como emitido: nem para o bloqueio "já foi emitido",
+        // nem para o que está faturado — senão o projeto ficava preso para sempre.
+        $this->assertSame(0.0, $projeto->valorTotalFaturado());
+        $this->assertSame(0.0, $projeto->valorTotalFaturadoSemIva());
+
+        $novo = $this->faturacao->gerarFaturaSinal($projeto);
+
+        $this->assertNotSame($sinal->id, $novo->id);
+        $this->assertSame('45600.00', $novo->valor_total);
+        $this->assertSame(45600.0, $projeto->valorTotalFaturado());
+    }
+
+    public function test_anular_o_saldo_permite_refaturar_o_que_falta(): void
+    {
+        $projeto = $this->projeto();
+        $this->faturacao->gerarFaturaSinal($projeto);
+        $saldo = $this->faturacao->gerarFaturaSaldoFinal($projeto);
+
+        $this->anularComNotaDeCredito($saldo);
+
+        $novoSaldo = $this->faturacao->gerarFaturaSaldoFinal($projeto);
+
+        $this->assertSame('68400.00', $novoSaldo->valor_total);
+    }
+
+    public function test_fatura_anulada_nao_deixa_o_projeto_por_hora_com_divida_fantasma(): void
+    {
+        $projeto = $this->projeto('hora');
+        $fatura = $this->faturaEmitida($this->empresa->id, $this->cliente->id, 30000);
+        $projeto->faturas()->attach($fatura->id, ['tipo' => 'avulso']);
+
+        $this->notaCreditoEmitida($fatura, 30000);
+
+        $this->assertSame(0.0, $projeto->valorDevido());
+        $this->assertSame(0.0, $projeto->saldoDevedor());
+        $this->assertFalse($projeto->financeiramenteQuitado(), 'Sem nada devido não há o que quitar.');
+    }
+
+    public function test_desconto_por_nota_de_credito_reduz_o_que_e_preciso_pagar_para_quitar(): void
+    {
+        $projeto = $this->projeto();
+        $sinal = $this->faturaEmitida($this->empresa->id, $this->cliente->id, 45600, 40000);
+        $saldo = $this->faturaEmitida($this->empresa->id, $this->cliente->id, 68400, 60000);
+        $projeto->faturas()->attach($sinal->id, ['tipo' => 'sinal']);
+        $projeto->faturas()->attach($saldo->id, ['tipo' => 'saldo_final']);
+
+        // Desconto de 10.000 + IVA no saldo: o recibo do saldo só pode ir até 68.400 − 11.400 = 57.000
+        // (é o teto do ReciboService), por isso o projeto tem de ficar quitado nesse valor.
+        $this->notaCreditoEmitida($saldo, 11400, 10000);
+
+        $this->assertSame(102600.0, $projeto->valorDevido());
+
+        $projeto->recibos()->attach($this->reciboEmitido($sinal, 45600)->id);
+        $projeto->recibos()->attach($this->reciboEmitido($saldo, 57000)->id);
+
+        $this->assertSame(0.0, $projeto->saldoDevedor());
+        $this->assertTrue($projeto->financeiramenteQuitado());
+    }
+
+    public function test_recibo_emitido_no_faturacao_sem_passar_pelo_estudio_tambem_quita(): void
+    {
+        $projeto = $this->projeto();
+        $sinal = $this->faturacao->gerarFaturaSinal($projeto);
+        $saldo = $this->faturacao->gerarFaturaSaldoFinal($projeto);
+
+        // Pagamentos registados no ecrã de Recibos do Faturação: não ficam ligados ao projeto
+        // pela tabela do Estúdio, mas são recibos das faturas do projeto.
+        $recibos = app(ReciboService::class);
+        $recibos->emitir($this->empresa->id, $this->cliente->id, $sinal->valor_total, 'multicaixa', $sinal->id);
+        $recibos->emitir($this->empresa->id, $this->cliente->id, $saldo->valor_total, 'transferencia', $saldo->id);
+
+        $this->assertSame(114000.0, $projeto->valorTotalRecebido());
+        $this->assertTrue($projeto->financeiramenteQuitado());
+    }
+
+    public function test_recibo_ligado_pelas_duas_vias_nao_conta_a_dobrar(): void
+    {
+        $projeto = $this->projeto();
+        $sinal = $this->faturacao->gerarFaturaSinal($projeto);
+
+        $recibo = $this->faturacao->registrarRecibo($projeto, $sinal, 'multicaixa');
+
+        $this->assertSame(45600.0, $projeto->valorTotalRecebido());
+        $this->assertSame($recibo->valor, '45600.00');
+    }
+
+    public function test_pagamento_por_omissao_recebe_o_que_falta_depois_de_uma_nota_de_credito(): void
+    {
+        $projeto = $this->projeto('hora');
+        $fatura = $this->faturaEmitida($this->empresa->id, $this->cliente->id, 30000);
+        $projeto->faturas()->attach($fatura->id, ['tipo' => 'avulso']);
+        $this->notaCreditoEmitida($fatura, 5000);
+
+        $recibo = $this->faturacao->registrarRecibo($projeto, $fatura, 'multicaixa');
+
+        $this->assertSame('25000.00', $recibo->valor);
+        $this->assertTrue($projeto->financeiramenteQuitado());
+    }
+
+    public function test_projeto_cancelado_nao_pode_ser_faturado(): void
+    {
+        $projeto = $this->projeto('pacote', ['estado' => 'cancelado']);
+
+        $this->expectException(\InvalidArgumentException::class);
+
+        $this->faturacao->gerarFaturaSinal($projeto);
+    }
+
+    public function test_sessao_cobrada_ao_cêntimo_e_nao_a_milesimos_de_hora(): void
+    {
+        // 20 minutos a 5.000 Kz/h = 1.666,67 (antes: 0,333 h × 5.000 = 1.665,00).
+        $sessao = $this->sessaoConcluida(20);
+
+        $fatura = $this->faturacao->cobrarSessao($sessao);
+
+        $this->assertSame('1666.67', $fatura->valor_sem_iva);
+        $this->assertSame($fatura->id, $sessao->fresh()->fatura_id);
+    }
+
+    public function test_cupao_de_aniversario_aplica_se_ao_total_exato_da_sessao(): void
+    {
+        $cupom = CupomDesconto::create([
+            'empresa_id' => $this->empresa->id,
+            'cliente_id' => $this->cliente->id,
+            'codigo' => 'ANIV-TESTE1',
+            'percentual_desconto' => 15,
+            'motivo' => 'aniversario',
+            'valido_ate' => now()->addDays(10),
+        ]);
+
+        // 1h20 a 5.000 Kz/h = 6.666,67; menos 15% (1.000,00) = 5.666,67.
+        $fatura = $this->faturacao->cobrarSessao($this->sessaoConcluida(80));
+
+        $this->assertSame('5666.67', $fatura->valor_sem_iva);
+        $this->assertNotNull($cupom->fresh()->usado_em);
+        $this->assertStringContainsString('ANIV-TESTE1', $fatura->linhas->first()->descricao);
+    }
+
+    public function test_sessao_de_projeto_por_pacote_apagado_nao_e_faturada_a_hora(): void
+    {
+        $projeto = $this->projeto();
+        $sessao = $this->sessaoConcluida(60, $projeto);
+        $projeto->delete();
+
+        $this->assertNull($this->faturacao->cobrarSessao($sessao->fresh()));
+    }
+
+    public function test_sessao_por_hora_liga_a_fatura_ao_projeto(): void
+    {
+        $projeto = $this->projeto('hora');
+        $sessao = $this->sessaoConcluida(60, $projeto);
+
+        $fatura = $this->faturacao->cobrarSessao($sessao);
+
+        $this->assertSame('5000.00', $fatura->valor_sem_iva);
+        $this->assertSame(5700.0, $projeto->valorTotalFaturado());
+        $this->assertNull($this->faturacao->cobrarSessao($sessao->fresh()), 'Segunda cobrança da mesma sessão não emite outra fatura.');
+    }
+}
diff --git a/Modules/EstudioMusica/tests/Feature/OperacaoEstudioTest.php b/Modules/EstudioMusica/tests/Feature/OperacaoEstudioTest.php
new file mode 100644
index 0000000..18fce29
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Feature/OperacaoEstudioTest.php
@@ -0,0 +1,323 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Facades\Schema;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Models\User;
+use Modules\Core\Services\TenantManager;
+use Modules\EstudioMusica\Jobs\EnviarLembretesSessaoJob;
+use Modules\EstudioMusica\Jobs\EnviarMensagemClienteJob;
+use Modules\EstudioMusica\Models\CupomDesconto;
+use Modules\EstudioMusica\Models\ProjetoMusical;
+use Modules\EstudioMusica\Models\SalaEstudio;
+use Modules\EstudioMusica\Models\SessaoEstudio;
+use Modules\EstudioMusica\Models\SolicitacaoOrcamento;
+use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalSmsInterface;
+use Modules\EstudioMusica\Notifications\Channels\Contracts\CanalWhatsAppInterface;
+use Modules\EstudioMusica\Services\CampanhaMarketingService;
+use Modules\EstudioMusica\Services\NotificacaoEstudioService;
+use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
+use Modules\Faturacao\Models\Cliente;
+use RuntimeException;
+use Tests\TestCase;
+
+require_once __DIR__.'/../Support/ComEstudioAutenticado.php';
+
+/** Salas, formulários, conversão de orçamentos e os jobs agendados — o dia a dia do estúdio. */
+class OperacaoEstudioTest extends TestCase
+{
+    use ComEstudioAutenticado;
+    use RefreshDatabase;
+
+    protected User $admin;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->admin = $this->registarEstudio('510000301');
+        $this->noTenantDe($this->admin);
+    }
+
+    protected function cliente(array $extra = []): Cliente
+    {
+        $cliente = (new Cliente)->forceFill(array_merge([
+            'empresa_id' => $this->admin->empresa_id,
+            'nome' => 'Artista Operação',
+        ], $extra));
+        $cliente->save();
+
+        return $cliente;
+    }
+
+    protected function canal(string $interface, \Closure $enviar): object
+    {
+        return new class($enviar) implements CanalSmsInterface, CanalWhatsAppInterface
+        {
+            public function __construct(protected \Closure $enviar) {}
+
+            public function identificador(): string
+            {
+                return 'falso';
+            }
+
+            public function enviar(string $destino, string $mensagem): bool
+            {
+                return ($this->enviar)($destino, $mensagem);
+            }
+        };
+    }
+
+    // ---------------------------------------------------------------- salas
+
+    public function test_preco_por_hora_acima_do_que_a_coluna_aguenta_e_recusado_com_mensagem(): void
+    {
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.salas.store'), ['nome' => 'Sala Cara', 'preco_hora' => 100000000])
+            ->assertSessionHasErrors('preco_hora');
+
+        $this->noTenantDe($this->admin);
+        $this->assertSame(0, SalaEstudio::query()->count());
+    }
+
+    public function test_sala_edita_se_e_desativa_se_pela_interface(): void
+    {
+        $sala = SalaEstudio::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Sala A', 'preco_hora' => 5000]);
+
+        $this->actingAs($this->admin)->get(route('estudiomusica.salas.index'))
+            ->assertOk()->assertSee('Editar')->assertSee('name="ativa"', false);
+
+        // Um formulário que não envia 'ativa' não pode desativar a sala por engano.
+        $this->actingAs($this->admin)
+            ->put(route('estudiomusica.salas.update', $sala->id), ['nome' => 'Sala A2', 'preco_hora' => 6000])
+            ->assertSessionHas('sucesso');
+
+        $this->noTenantDe($this->admin);
+        $this->assertTrue($sala->fresh()->ativa);
+        $this->assertSame('6000.00', $sala->fresh()->preco_hora);
+
+        $this->actingAs($this->admin)
+            ->put(route('estudiomusica.salas.update', $sala->id), ['nome' => 'Sala A2', 'preco_hora' => 6000, 'ativa' => 0])
+            ->assertSessionHas('sucesso');
+
+        $this->noTenantDe($this->admin);
+        $this->assertFalse($sala->fresh()->ativa);
+    }
+
+    // ------------------------------------------------ erros de validação visíveis
+
+    public function test_erros_de_validacao_aparecem_no_ecra_do_staff(): void
+    {
+        $this->actingAs($this->admin)
+            ->from(route('estudiomusica.salas.index'))
+            ->followingRedirects()
+            ->post(route('estudiomusica.salas.store'), [])
+            ->assertOk()
+            ->assertSee(trans('validation.required', ['attribute' => 'nome']));
+    }
+
+    public function test_comentario_invalido_volta_ao_portal_com_o_erro_visivel(): void
+    {
+        $token = 'token-erros-portal-0123456789abcdef0123456789abcdef';
+        $cliente = $this->cliente(['token_portal' => hash('sha256', $token), 'token_portal_expira_em' => now()->addDay()]);
+        $projeto = ProjetoMusical::create(['empresa_id' => $cliente->empresa_id, 'cliente_id' => $cliente->id, 'nome' => 'P', 'tipo_cobranca' => 'hora']);
+        $faixa = $projeto->faixas()->create(['empresa_id' => $cliente->empresa_id, 'nome' => 'F', 'ordem' => 1]);
+        $versao = $faixa->versoes()->create(['empresa_id' => $cliente->empresa_id, 'rotulo' => 'v1', 'caminho_ficheiro' => 'x.mp3', 'estado' => 'pendente_aprovacao', 'enviada_em' => now()]);
+
+        $this->get(route('estudiomusica.portal.mostrar', ['token' => $token]))->assertOk();
+        $this->get(route('estudiomusica.portal.versoes.reproduzir', ['token' => $token, 'versao' => $versao->id]));
+
+        $this->post(route('estudiomusica.portal.versoes.comentar', ['token' => $token, 'versao' => $versao->id]), ['comentario' => ''])
+            ->assertRedirect(route('estudiomusica.portal.mostrar', ['token' => $token]))
+            ->assertSessionHasErrors(['tempo_segundos', 'comentario']);
+    }
+
+    // ------------------------------------------------------------- faixas
+
+    public function test_nao_se_acrescentam_faixas_a_projetos_concluidos_ou_cancelados(): void
+    {
+        $cliente = $this->cliente();
+        $normal = ProjetoMusical::create(['empresa_id' => $cliente->empresa_id, 'cliente_id' => $cliente->id, 'nome' => 'Normal', 'tipo_cobranca' => 'hora']);
+        $cancelado = ProjetoMusical::create(['empresa_id' => $cliente->empresa_id, 'cliente_id' => $cliente->id, 'nome' => 'Cancelado', 'tipo_cobranca' => 'hora', 'estado' => 'cancelado']);
+
+        $this->actingAs($this->admin)->post(route('estudiomusica.projetos.faixas.store', $normal->id), ['nome' => 'Faixa 1'])
+            ->assertSessionHas('sucesso');
+        $this->actingAs($this->admin)->post(route('estudiomusica.projetos.faixas.store', $cancelado->id), ['nome' => 'Faixa 1'])
+            ->assertSessionHas('erro');
+
+        $this->noTenantDe($this->admin);
+        $this->assertSame(1, $normal->faixas()->count());
+        $this->assertSame(0, $cancelado->faixas()->count());
+    }
+
+    // ------------------------------------------------- orçamento → cliente
+
+    protected function pedido(array $extra): SolicitacaoOrcamento
+    {
+        return SolicitacaoOrcamento::create(array_merge([
+            'empresa_id' => $this->admin->empresa_id,
+            'nome' => 'Lead',
+            'tipo_servico' => 'mixagem',
+            'aceita_marketing' => true,
+            'consentimento_dados_em' => now(),
+        ], $extra));
+    }
+
+    public function test_conversao_por_email_herda_o_consentimento_mas_por_telefone_nao(): void
+    {
+        $existente = $this->cliente(['nome' => 'Existente', 'email' => 'ele@example.test', 'telefone' => '923111111']);
+        $outro = $this->cliente(['nome' => 'Outro', 'email' => 'outro@example.test', 'telefone' => '923222222']);
+
+        $porEmail = $this->pedido(['email' => 'ele@example.test', 'telefone' => '900000000']);
+        $porTelefone = $this->pedido(['email' => 'novo@example.test', 'telefone' => '923222222']);
+
+        $this->actingAs($this->admin)->post(route('estudiomusica.solicitacoes.converter', $porEmail->id))->assertSessionHas('sucesso');
+        $this->actingAs($this->admin)->post(route('estudiomusica.solicitacoes.converter', $porTelefone->id))->assertSessionHas('sucesso');
+
+        $this->noTenantDe($this->admin);
+        $this->assertTrue((bool) $existente->fresh()->aceita_marketing, 'Mesmo e-mail = mesma pessoa: o consentimento passa.');
+        $this->assertFalse((bool) $outro->fresh()->aceita_marketing, 'Telefone igual pode ser de outra pessoa: não herda consentimento.');
+        $this->assertSame(2, Cliente::query()->count(), 'Nenhum cliente novo: os dois pedidos acharam um cliente existente.');
+    }
+
+    public function test_converter_duas_vezes_o_mesmo_pedido_so_cria_um_cliente(): void
+    {
+        $pedido = $this->pedido(['email' => 'lead@example.test']);
+
+        $this->actingAs($this->admin)->post(route('estudiomusica.solicitacoes.converter', $pedido->id))->assertSessionHas('sucesso');
+        $this->actingAs($this->admin)->post(route('estudiomusica.solicitacoes.converter', $pedido->id))->assertSessionHas('erro');
+
+        $this->noTenantDe($this->admin);
+        $this->assertSame(1, Cliente::query()->count());
+    }
+
+    // ------------------------------------------------------------- jobs
+
+    protected function empresaSemServico(string $nif): Empresa
+    {
+        return Empresa::create(['nome_comercial' => 'Ex-cliente '.$nif, 'nif' => $nif]);
+    }
+
+    protected function sessaoDaqui24Horas(int $empresaId): SessaoEstudio
+    {
+        // Fora do tenant: com o tenant do admin ativo, o BelongsToTenant sobrepõe o empresa_id dado.
+        return app(TenantManager::class)->semTenant(fn () => $this->criarSessaoDaqui24Horas($empresaId));
+    }
+
+    protected function criarSessaoDaqui24Horas(int $empresaId): SessaoEstudio
+    {
+        $sala = SalaEstudio::withoutGlobalScopes()->create(['empresa_id' => $empresaId, 'nome' => 'Sala', 'preco_hora' => 1000]);
+        $cliente = (new Cliente)->forceFill(['empresa_id' => $empresaId, 'nome' => 'Cliente '.$empresaId, 'email' => "c{$empresaId}@example.test"]);
+        $cliente->save();
+
+        $sessao = new SessaoEstudio;
+        $sessao->forceFill([
+            'empresa_id' => $empresaId,
+            'sala_estudio_id' => $sala->id,
+            'cliente_id' => $cliente->id,
+            'tipo_servico' => 'mixagem',
+            'inicio_previsto' => now()->addHours(24),
+            'fim_previsto' => now()->addHours(25),
+            'estado' => 'agendada',
+        ])->save();
+
+        return $sessao;
+    }
+
+    public function test_lembretes_so_vao_para_estudios_com_o_servico_ativo(): void
+    {
+        Queue::fake();
+
+        $deles = $this->sessaoDaqui24Horas((int) $this->admin->empresa_id);
+        $exCliente = $this->empresaSemServico('510000302');
+        $dele = $this->sessaoDaqui24Horas($exCliente->id);
+
+        (new EnviarLembretesSessaoJob)->handle(app(TenantManager::class), app(NotificacaoEstudioService::class));
+
+        Queue::assertPushed(EnviarMensagemClienteJob::class, 1);
+
+        app(TenantManager::class)->semTenant(function () use ($deles, $dele): void {
+            $this->assertNotNull($deles->fresh()->lembrete_24h_enviado_em);
+            $this->assertNull($dele->fresh()->lembrete_24h_enviado_em);
+        });
+    }
+
+    public function test_aniversario_so_para_estudios_ativos_e_com_a_percentagem_legivel(): void
+    {
+        Queue::fake();
+        config(['estudiomusica.percentual_desconto_aniversario' => 15]);
+
+        $nascimento = now()->subYears(30)->toDateString();
+        $meu = $this->cliente(['data_nascimento' => $nascimento, 'aceita_marketing' => true]);
+
+        $exCliente = $this->empresaSemServico('510000303');
+        app(TenantManager::class)->semTenant(fn () => (new Cliente)
+            ->forceFill(['empresa_id' => $exCliente->id, 'nome' => 'Fantasma', 'data_nascimento' => $nascimento, 'aceita_marketing' => true])
+            ->save());
+
+        $enviados = app(CampanhaMarketingService::class)->enviarAniversarios(app(TenantManager::class));
+
+        $this->assertSame(1, $enviados);
+        Queue::assertPushed(EnviarMensagemClienteJob::class, function (EnviarMensagemClienteJob $job) {
+            return str_contains($job->mensagem, 'Ganhaste 15% de desconto') && ! str_contains($job->mensagem, '15.00');
+        });
+
+        $this->noTenantDe($this->admin);
+        $this->assertSame(1, CupomDesconto::query()->count());
+        $this->assertSame($meu->id, CupomDesconto::query()->sole()->cliente_id);
+    }
+
+    public function test_excecao_do_whatsapp_nao_impede_o_envio_do_email(): void
+    {
+        config(['mail.default' => 'array', 'estudiomusica.exigir_autorizacao_apd' => false]);
+
+        $whatsapp = $this->canal(CanalWhatsAppInterface::class, fn () => throw new RuntimeException('fornecedor em baixo'));
+        $sms = $this->canal(CanalSmsInterface::class, fn () => false);
+
+        (new EnviarMensagemClienteJob('923000000', 'artista@example.test', 'Assunto', 'Mensagem de teste'))
+            ->handle($whatsapp, $sms);
+
+        // (o registo da empresa, no setUp, também pode ter enviado e-mails: filtra-se pelo texto.)
+        $enviadas = app('mailer')->getSymfonyTransport()->messages()
+            ->filter(fn ($mensagem) => str_contains($mensagem->toString(), 'Mensagem de teste'));
+
+        $this->assertCount(1, $enviadas);
+    }
+
+    public function test_sem_nenhum_canal_a_entregar_o_job_falha_para_a_queue_repetir(): void
+    {
+        config(['estudiomusica.exigir_autorizacao_apd' => false]);
+
+        $whatsapp = $this->canal(CanalWhatsAppInterface::class, fn () => throw new RuntimeException('fornecedor em baixo'));
+        $sms = $this->canal(CanalSmsInterface::class, fn () => false);
+
+        $this->expectException(RuntimeException::class);
+
+        (new EnviarMensagemClienteJob('923000000', null, 'Assunto', 'Mensagem'))->handle($whatsapp, $sms);
+    }
+
+    public function test_o_sms_entrega_quando_o_whatsapp_falha(): void
+    {
+        config(['estudiomusica.exigir_autorizacao_apd' => false]);
+
+        $recebidos = [];
+        $whatsapp = $this->canal(CanalWhatsAppInterface::class, fn () => throw new RuntimeException('fornecedor em baixo'));
+        $sms = $this->canal(CanalSmsInterface::class, function (string $destino, string $mensagem) use (&$recebidos): bool {
+            $recebidos[] = [$destino, $mensagem];
+
+            return true;
+        });
+
+        (new EnviarMensagemClienteJob('923000000', null, 'Assunto', 'Olá'))->handle($whatsapp, $sms);
+
+        $this->assertSame([['923000000', 'Olá']], $recebidos);
+    }
+
+    public function test_a_tabela_de_sessoes_tem_o_indice_dos_lembretes(): void
+    {
+        $this->assertTrue(Schema::hasIndex('sessoes_estudio', 'sessoes_estudio_estado_inicio_idx'));
+    }
+}
diff --git a/Modules/EstudioMusica/tests/Feature/PortalRotasHttpTest.php b/Modules/EstudioMusica/tests/Feature/PortalRotasHttpTest.php
new file mode 100644
index 0000000..7ca2cfc
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Feature/PortalRotasHttpTest.php
@@ -0,0 +1,179 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Storage;
+use Modules\Core\Models\Empresa;
+use Modules\Core\Services\TenantManager;
+use Modules\EstudioMusica\Models\FaixaMusical;
+use Modules\EstudioMusica\Models\MarcadorAudio;
+use Modules\EstudioMusica\Models\ProjetoMusical;
+use Modules\EstudioMusica\Models\VersaoAudio;
+use Modules\EstudioMusica\Tests\Support\CriaDocumentosFiscais;
+use Modules\Faturacao\Models\Cliente;
+use Tests\TestCase;
+
+require_once __DIR__.'/../Support/CriaDocumentosFiscais.php';
+
+/**
+ * Rotas do portal que levam {token} E {versao}: o Laravel passa os
+ * parâmetros de rota por POSIÇÃO, por isso cada método do controller tem de
+ * declarar $token antes de $versao. Antes, reproduzir/descarregar/comentar/
+ * aprovar/ajustes davam TypeError (HTTP 500) — só o aprovar tinha teste.
+ */
+class PortalRotasHttpTest extends TestCase
+{
+    use CriaDocumentosFiscais;
+    use RefreshDatabase;
+
+    protected const TOKEN = 'token-http-portal-0123456789abcdef0123456789abcd';
+
+    protected Empresa $empresa;
+
+    protected Cliente $cliente;
+
+    protected ProjetoMusical $projeto;
+
+    protected VersaoAudio $versao;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->withoutVite();
+        Storage::fake('local');
+
+        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio HTTP', 'nif' => '400000021']);
+        app(TenantManager::class)->set($this->empresa->id);
+
+        $this->cliente = (new Cliente)->forceFill([
+            'empresa_id' => $this->empresa->id,
+            'nome' => 'Artista HTTP',
+            'token_portal' => hash('sha256', self::TOKEN),
+            'token_portal_expira_em' => now()->addDay(),
+        ]);
+        $this->cliente->save();
+
+        $this->projeto = ProjetoMusical::create([
+            'empresa_id' => $this->empresa->id,
+            'cliente_id' => $this->cliente->id,
+            'nome' => 'Projeto HTTP',
+            'tipo_cobranca' => 'hora',
+        ]);
+
+        $faixa = FaixaMusical::create([
+            'empresa_id' => $this->empresa->id,
+            'projeto_musical_id' => $this->projeto->id,
+            'nome' => 'Faixa HTTP',
+            'ordem' => 1,
+        ]);
+
+        Storage::disk('local')->put('estudiomusica/versoes-audio/original.mp3', 'ORIGINAL-AUDIO');
+
+        $this->versao = VersaoAudio::create([
+            'empresa_id' => $this->empresa->id,
+            'faixa_musical_id' => $faixa->id,
+            'rotulo' => 'Mix v1',
+            'caminho_ficheiro' => 'estudiomusica/versoes-audio/original.mp3',
+            'estado' => 'pendente_aprovacao',
+            'enviada_em' => now(),
+        ]);
+    }
+
+    protected function rota(string $nome): string
+    {
+        return route("estudiomusica.portal.{$nome}", ['token' => self::TOKEN, 'versao' => $this->versao->id]);
+    }
+
+    protected function paginaDoPortal(): string
+    {
+        return route('estudiomusica.portal.mostrar', ['token' => self::TOKEN]);
+    }
+
+    protected function quitarProjeto(): void
+    {
+        $fatura = $this->faturaEmitida($this->empresa->id, $this->cliente->id, 30000);
+        $this->projeto->faturas()->attach($fatura->id, ['tipo' => 'avulso']);
+        $this->projeto->recibos()->attach($this->reciboEmitido($fatura, 30000)->id);
+    }
+
+    protected function aceitarTermos(): void
+    {
+        $this->post(route('estudiomusica.portal.termos.aceitar', ['token' => self::TOKEN]))->assertRedirect();
+    }
+
+    public function test_reproduzir_nao_rebenta_com_o_token_na_rota(): void
+    {
+        // Sem ffmpeg/pré-visualização o portal responde 503 limpo — nunca 500 (TypeError).
+        $this->get($this->rota('versoes.reproduzir'))->assertStatus(503);
+    }
+
+    public function test_comentar_cria_o_marcador_e_volta_ao_portal(): void
+    {
+        $this->post($this->rota('versoes.comentar'), ['tempo_segundos' => 83.45, 'comentario' => 'Mais graves aqui'])
+            ->assertRedirect($this->paginaDoPortal())
+            ->assertSessionHas('sucesso');
+
+        $marcador = MarcadorAudio::query()->sole();
+
+        $this->assertSame('Mais graves aqui', $marcador->comentario);
+        $this->assertSame('01:23', $marcador->tempoFormatado());
+    }
+
+    public function test_solicitar_ajustes_funciona(): void
+    {
+        $this->post($this->rota('versoes.ajustes'))
+            ->assertRedirect($this->paginaDoPortal())
+            ->assertSessionHas('sucesso');
+
+        $this->assertSame('ajustes_solicitados', $this->versao->fresh()->estado);
+    }
+
+    public function test_descarregar_projeto_por_quitar_volta_ao_portal_com_erro(): void
+    {
+        $this->get($this->rota('versoes.descarregar'))
+            ->assertRedirect($this->paginaDoPortal())
+            ->assertSessionHas('erro');
+    }
+
+    public function test_depois_de_ouvir_o_audio_as_respostas_continuam_a_voltar_ao_portal(): void
+    {
+        // O portal envia Referrer-Policy: no-referrer, por isso o POST não leva Referer e o
+        // back() cai no "último GET" da sessão — que, depois de tocar o áudio, é o próprio
+        // ficheiro de áudio. O cliente era redirecionado para o stream em vez de para o portal.
+        $this->get($this->paginaDoPortal())->assertOk();
+        $this->get($this->rota('versoes.reproduzir'));
+
+        $this->post($this->rota('versoes.comentar'), ['tempo_segundos' => 10, 'comentario' => 'Ok'])
+            ->assertRedirect($this->paginaDoPortal());
+
+        $this->post(route('estudiomusica.portal.termos.aceitar', ['token' => self::TOKEN]))
+            ->assertRedirect($this->paginaDoPortal());
+    }
+
+    public function test_original_so_e_servido_a_projeto_quitado_com_termos_aceites(): void
+    {
+        $this->quitarProjeto();
+
+        // Quitado mas sem aceitar os termos: o player não pode receber o original
+        // (bastava "guardar áudio como..." para contornar o aceite dos termos).
+        $this->get($this->rota('versoes.reproduzir'))->assertStatus(503);
+
+        $this->aceitarTermos();
+
+        $resposta = $this->get($this->rota('versoes.reproduzir'))->assertOk();
+
+        $this->assertSame('ORIGINAL-AUDIO', file_get_contents($resposta->baseResponse->getFile()->getPathname()));
+    }
+
+    public function test_ficheiro_original_em_falta_responde_404_e_nao_500(): void
+    {
+        $this->quitarProjeto();
+        $this->aceitarTermos();
+        Storage::disk('local')->delete('estudiomusica/versoes-audio/original.mp3');
+
+        $this->get($this->rota('versoes.descarregar'))->assertNotFound();
+        $this->get($this->rota('versoes.reproduzir'))->assertNotFound();
+    }
+}
diff --git a/Modules/EstudioMusica/tests/Feature/PortfolioEPerfilTest.php b/Modules/EstudioMusica/tests/Feature/PortfolioEPerfilTest.php
new file mode 100644
index 0000000..f2ebe2c
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Feature/PortfolioEPerfilTest.php
@@ -0,0 +1,126 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Hash;
+use Modules\Core\Models\User;
+use Modules\EstudioMusica\Models\ProjetoMusical;
+use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
+use Modules\Faturacao\Models\Cliente;
+use Tests\TestCase;
+
+require_once __DIR__.'/../Support/ComEstudioAutenticado.php';
+
+/** Destaque do portfólio na landing pública (só Administrador) e pequenos pormenores da ficha do artista. */
+class PortfolioEPerfilTest extends TestCase
+{
+    use ComEstudioAutenticado;
+    use RefreshDatabase;
+
+    protected User $admin;
+
+    protected ProjetoMusical $projeto;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->admin = $this->registarEstudio('510000401');
+        $this->noTenantDe($this->admin);
+
+        $cliente = Cliente::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Artista Portfólio']);
+        $this->projeto = ProjetoMusical::create([
+            'empresa_id' => $this->admin->empresa_id,
+            'cliente_id' => $cliente->id,
+            'nome' => 'Álbum Vitrine',
+            'tipo_cobranca' => 'hora',
+        ]);
+    }
+
+    protected function rotaPortfolio(): string
+    {
+        return route('estudiomusica.projetos.portfolio', $this->projeto->id);
+    }
+
+    public function test_administrador_marca_o_destaque_e_a_landing_passa_a_mostra_lo(): void
+    {
+        $landing = route('estudiomusica.landing.mostrar', $this->admin->empresa_id);
+
+        $this->get($landing)->assertOk()->assertDontSee('Álbum Vitrine');
+
+        $this->actingAs($this->admin)
+            ->put($this->rotaPortfolio(), [
+                'destaque_portfolio' => 1,
+                'link_publico' => 'https://open.spotify.com/album/abc123',
+                'autorizacao_artista' => 1,
+            ])
+            ->assertSessionHas('sucesso');
+
+        $this->get($landing)->assertOk()->assertSee('Álbum Vitrine');
+
+        $this->actingAs($this->admin)
+            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 0, 'link_publico' => 'https://open.spotify.com/album/abc123'])
+            ->assertSessionHas('sucesso');
+
+        $this->get($landing)->assertOk()->assertDontSee('Álbum Vitrine');
+    }
+
+    public function test_destaque_exige_link_https_e_a_autorizacao_do_artista(): void
+    {
+        $this->actingAs($this->admin)
+            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 1, 'autorizacao_artista' => 1])
+            ->assertSessionHasErrors('link_publico');
+
+        $this->actingAs($this->admin)
+            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 1, 'link_publico' => 'http://exemplo.test/faixa', 'autorizacao_artista' => 1])
+            ->assertSessionHasErrors('link_publico');
+
+        $this->actingAs($this->admin)
+            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 1, 'link_publico' => 'javascript:alert(1)', 'autorizacao_artista' => 1])
+            ->assertSessionHasErrors('link_publico');
+
+        $this->actingAs($this->admin)
+            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 1, 'link_publico' => 'https://open.spotify.com/album/abc123'])
+            ->assertSessionHasErrors('autorizacao_artista');
+
+        $this->noTenantDe($this->admin);
+        $this->assertFalse((bool) $this->projeto->fresh()->destaque_portfolio);
+    }
+
+    public function test_so_o_administrador_gere_o_portfolio_e_so_ele_ve_o_cartao(): void
+    {
+        $colega = User::create([
+            'empresa_id' => $this->admin->empresa_id,
+            'name' => 'Receção',
+            'email' => 'rececao@example.test',
+            'password' => Hash::make('Senha-segura-123'),
+        ]);
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.equipa.atribuir'), ['utilizador_id' => $colega->id, 'papel' => 'Recepcao'])
+            ->assertRedirect();
+
+        $this->actingAs($colega)
+            ->put($this->rotaPortfolio(), ['destaque_portfolio' => 1, 'link_publico' => 'https://open.spotify.com/album/abc', 'autorizacao_artista' => 1])
+            ->assertForbidden();
+
+        $this->actingAs($colega)->get(route('estudiomusica.projetos.show', $this->projeto->id))
+            ->assertOk()->assertDontSee('Portfólio na página pública');
+
+        $this->actingAs($this->admin)->get(route('estudiomusica.projetos.show', $this->projeto->id))
+            ->assertOk()->assertSee('Portfólio na página pública');
+    }
+
+    public function test_ficha_do_artista_aceita_campos_de_integrantes_em_branco(): void
+    {
+        // Os campos vazios chegam como null (ConvertEmptyStringsToNull): antes recusava o formulário inteiro.
+        $this->actingAs($this->admin)
+            ->put(route('estudiomusica.clientes.perfil-artista', $this->projeto->cliente_id), [
+                'nome_artistico' => 'Banda Teste',
+                'integrantes_banda' => ['Ana', '', null, 'Bruno'],
+            ])
+            ->assertSessionHasNoErrors()
+            ->assertSessionHas('sucesso');
+    }
+}
diff --git a/Modules/EstudioMusica/tests/Feature/SessoesHttpTest.php b/Modules/EstudioMusica/tests/Feature/SessoesHttpTest.php
new file mode 100644
index 0000000..725c3e5
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Feature/SessoesHttpTest.php
@@ -0,0 +1,267 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Feature;
+
+use Carbon\Carbon;
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Modules\Core\Models\User;
+use Modules\EstudioMusica\Models\ProjetoMusical;
+use Modules\EstudioMusica\Models\SalaEstudio;
+use Modules\EstudioMusica\Models\SessaoEstudio;
+use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
+use Modules\EstudioMusica\Tests\Support\CriaDocumentosFiscais;
+use Modules\Faturacao\Models\Cliente;
+use Tests\TestCase;
+
+require_once __DIR__.'/../Support/ComEstudioAutenticado.php';
+require_once __DIR__.'/../Support/CriaDocumentosFiscais.php';
+
+/** Sessões e faturação vistas pelo staff: o que o utilizador carrega nos botões. */
+class SessoesHttpTest extends TestCase
+{
+    use ComEstudioAutenticado;
+    use CriaDocumentosFiscais;
+    use RefreshDatabase;
+
+    protected User $admin;
+
+    protected Cliente $cliente;
+
+    protected SalaEstudio $sala;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->prepararChaveFiscal();
+        config(['faturacao.taxa_iva_geral' => 14.0]);
+
+        $this->admin = $this->registarEstudio('510000201');
+        $this->noTenantDe($this->admin);
+
+        $this->cliente = Cliente::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Artista Sessões']);
+        $this->sala = SalaEstudio::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Sala A', 'preco_hora' => 5000]);
+    }
+
+    protected function projeto(string $tipo = 'hora', array $extra = []): ProjetoMusical
+    {
+        return ProjetoMusical::create(array_merge([
+            'empresa_id' => $this->admin->empresa_id,
+            'cliente_id' => $this->cliente->id,
+            'nome' => 'Projeto '.$tipo,
+            'tipo_cobranca' => $tipo,
+            'valor_pacote' => $tipo === 'pacote' ? 100000 : null,
+            'percentual_sinal' => 40,
+        ], $extra));
+    }
+
+    protected function sessao(string $estado, Carbon $inicio, int $minutos = 60, array $extra = []): SessaoEstudio
+    {
+        $sessao = new SessaoEstudio;
+        $sessao->forceFill([
+            'empresa_id' => $this->admin->empresa_id,
+            'sala_estudio_id' => $this->sala->id,
+            'cliente_id' => $this->cliente->id,
+            'tipo_servico' => 'mixagem',
+            'inicio_previsto' => $inicio,
+            'fim_previsto' => $inicio->copy()->addMinutes($minutos),
+            'estado' => $estado,
+        ] + $extra)->save();
+
+        return $sessao;
+    }
+
+    protected function dadosDaSessao(array $extra = []): array
+    {
+        return array_merge([
+            'sala_estudio_id' => $this->sala->id,
+            'cliente_id' => $this->cliente->id,
+            'tipo_servico' => 'mixagem',
+            'inicio_previsto' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'),
+            'fim_previsto' => now()->addDays(2)->setTime(12, 0)->format('Y-m-d\TH:i'),
+        ], $extra);
+    }
+
+    public function test_check_out_grava_o_fim_real_mesmo_quando_o_limite_de_faturas_foi_atingido(): void
+    {
+        $this->limitarFaturasPorMes(0);
+        $sessao = $this->sessao('em_curso', now()->subMinutes(80), 80, ['inicio_real' => now()->subMinutes(80)]);
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.sessoes.check-out', $sessao->id))
+            ->assertRedirect()
+            ->assertSessionHas('erro', fn (string $mensagem) => str_contains($mensagem, 'NÃO foi emitida'));
+
+        $this->noTenantDe($this->admin);
+        $sessao->refresh();
+
+        // O estúdio não fica com a sessão "em curso" para sempre (nem com o relógio a contar horas
+        // a mais): o fim real fica gravado e a fatura emite-se depois.
+        $this->assertSame('concluida', $sessao->estado);
+        $this->assertNotNull($sessao->fim_real);
+        $this->assertNull($sessao->fatura_id);
+
+        $fimOriginal = $sessao->fim_real->toDateTimeString();
+        $this->limitarFaturasPorMes(null);
+        $this->travel(3)->hours();
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.sessoes.faturar', $sessao->id))
+            ->assertSessionHas('sucesso');
+
+        $this->noTenantDe($this->admin);
+        $sessao->refresh();
+
+        $this->assertNotNull($sessao->fatura_id);
+        $this->assertSame($fimOriginal, $sessao->fim_real->toDateTimeString(), 'Faturar mais tarde não pode mexer na hora de fim.');
+    }
+
+    public function test_check_out_normal_continua_a_faturar_na_hora(): void
+    {
+        $sessao = $this->sessao('em_curso', now()->subMinutes(60), 60, ['inicio_real' => now()->subMinutes(60)]);
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.sessoes.check-out', $sessao->id))
+            ->assertSessionHas('sucesso', fn (string $mensagem) => str_contains($mensagem, 'emitida'));
+
+        $this->noTenantDe($this->admin);
+        $this->assertNotNull($sessao->fresh()->fatura_id);
+    }
+
+    public function test_faturar_so_aceita_sessoes_concluidas_e_nao_duplica_a_fatura(): void
+    {
+        $agendada = $this->sessao('agendada', now()->addDay());
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.sessoes.faturar', $agendada->id))
+            ->assertSessionHas('erro');
+
+        $concluida = $this->sessao('concluida', now()->subHours(3), 60, ['inicio_real' => now()->subHours(3), 'fim_real' => now()->subHours(2)]);
+
+        $this->actingAs($this->admin)->post(route('estudiomusica.sessoes.faturar', $concluida->id))->assertSessionHas('sucesso');
+        $this->actingAs($this->admin)->post(route('estudiomusica.sessoes.faturar', $concluida->id))->assertSessionHas('erro');
+
+        $this->noTenantDe($this->admin);
+        $this->assertSame(1, \Modules\Faturacao\Models\Fatura::query()->count());
+    }
+
+    public function test_sinal_e_saldo_com_limite_atingido_mostram_um_erro_em_vez_de_rebentar(): void
+    {
+        $projeto = $this->projeto('pacote');
+        $this->limitarFaturasPorMes(0);
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.projetos.sinal', $projeto->id))
+            ->assertRedirect()
+            ->assertSessionHas('erro');
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.projetos.saldo-final', $projeto->id))
+            ->assertRedirect()
+            ->assertSessionHas('erro');
+    }
+
+    public function test_sessao_pode_ser_agendada_ligada_a_um_projeto_e_a_um_engenheiro(): void
+    {
+        $projeto = $this->projeto();
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.sessoes.store'), $this->dadosDaSessao([
+                'projeto_musical_id' => $projeto->id,
+                'engenheiro_user_id' => $this->admin->id,
+            ]))
+            ->assertSessionHas('sucesso');
+
+        $this->noTenantDe($this->admin);
+        $sessao = SessaoEstudio::query()->sole();
+
+        $this->assertSame($projeto->id, $sessao->projeto_musical_id);
+        $this->assertSame($this->admin->id, $sessao->engenheiro_user_id);
+    }
+
+    public function test_projeto_de_outro_cliente_cancelado_ou_apagado_nao_aceita_sessoes(): void
+    {
+        $outro = Cliente::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Outro Artista']);
+        $deOutro = ProjetoMusical::create(['empresa_id' => $this->admin->empresa_id, 'cliente_id' => $outro->id, 'nome' => 'Alheio', 'tipo_cobranca' => 'hora']);
+        $cancelado = $this->projeto('hora', ['estado' => 'cancelado']);
+        $apagado = $this->projeto('hora');
+        $apagado->delete();
+
+        foreach ([$deOutro, $cancelado, $apagado] as $projeto) {
+            $this->actingAs($this->admin)
+                ->post(route('estudiomusica.sessoes.store'), $this->dadosDaSessao(['projeto_musical_id' => $projeto->id]))
+                ->assertSessionHasErrors('projeto_musical_id');
+        }
+
+        $this->noTenantDe($this->admin);
+        $this->assertSame(0, SessaoEstudio::query()->count());
+    }
+
+    public function test_sessao_com_duracao_absurda_e_recusada(): void
+    {
+        // Sem limite, um erro de digitação (ano errado) bloqueava a sala durante meses.
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.sessoes.store'), $this->dadosDaSessao([
+                'fim_previsto' => now()->addDays(40)->format('Y-m-d\TH:i'),
+            ]))
+            ->assertSessionHas('erro');
+
+        $this->noTenantDe($this->admin);
+        $this->assertSame(0, SessaoEstudio::query()->count());
+    }
+
+    public function test_reagendar_muda_o_horario_e_respeita_conflitos_e_estados(): void
+    {
+        $amanha = now()->addDay()->setTime(10, 0);
+        $sessao = $this->sessao('agendada', $amanha, 60);
+        $this->sessao('agendada', $amanha->copy()->addHours(5), 60); // ocupa 15:00–16:00
+
+        $novo = fn (int $hora, int $duracao = 1) => [
+            'inicio_previsto' => $amanha->copy()->setTime($hora, 0)->format('Y-m-d\TH:i'),
+            'fim_previsto' => $amanha->copy()->setTime($hora + $duracao, 0)->format('Y-m-d\TH:i'),
+        ];
+
+        $this->actingAs($this->admin)
+            ->put(route('estudiomusica.sessoes.reagendar', $sessao->id), $novo(15))
+            ->assertSessionHas('erro');
+
+        $this->actingAs($this->admin)
+            ->put(route('estudiomusica.sessoes.reagendar', $sessao->id), $novo(13, 2))
+            ->assertSessionHas('sucesso');
+
+        $this->noTenantDe($this->admin);
+        $this->assertSame('13:00', $sessao->fresh()->inicio_previsto->format('H:i'));
+
+        $sessao->update(['estado' => 'cancelada']);
+
+        $this->actingAs($this->admin)
+            ->put(route('estudiomusica.sessoes.reagendar', $sessao->id), $novo(9))
+            ->assertSessionHas('erro');
+    }
+
+    public function test_ecra_de_sessoes_oferece_projeto_engenheiro_e_as_acoes_de_cada_sessao(): void
+    {
+        $this->projeto('hora');
+        $this->sessao('agendada', now()->addDay(), 60);
+        $this->sessao('concluida', now()->subHours(3), 60, ['inicio_real' => now()->subHours(3), 'fim_real' => now()->subHours(2)]);
+
+        $this->actingAs($this->admin)
+            ->get(route('estudiomusica.sessoes.index'))
+            ->assertOk()
+            ->assertSee('name="projeto_musical_id"', false)
+            ->assertSee('name="engenheiro_user_id"', false)
+            ->assertSee('Cancelar')
+            ->assertSee('Reagendar')
+            ->assertSee('Faturar');
+    }
+
+    public function test_ecra_de_sessoes_pre_seleciona_o_projeto_do_link_da_pagina_do_projeto(): void
+    {
+        $projeto = $this->projeto('hora');
+
+        $this->actingAs($this->admin)
+            ->get(route('estudiomusica.sessoes.index', ['projeto' => $projeto->id]))
+            ->assertOk()
+            ->assertSee('value="'.$projeto->id.'" selected', false);
+    }
+}
diff --git a/Modules/EstudioMusica/tests/Feature/VersaoAudioStaffTest.php b/Modules/EstudioMusica/tests/Feature/VersaoAudioStaffTest.php
new file mode 100644
index 0000000..f8fa623
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Feature/VersaoAudioStaffTest.php
@@ -0,0 +1,181 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Feature;
+
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Http\UploadedFile;
+use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Facades\Storage;
+use Modules\Core\Models\User;
+use Modules\Core\Services\TenantManager;
+use Modules\EstudioMusica\Jobs\EnviarMensagemClienteJob;
+use Modules\EstudioMusica\Jobs\GerarPreviewAudioJob;
+use Modules\EstudioMusica\Models\FaixaMusical;
+use Modules\EstudioMusica\Models\MarcadorAudio;
+use Modules\EstudioMusica\Models\ProjetoMusical;
+use Modules\EstudioMusica\Models\VersaoAudio;
+use Modules\EstudioMusica\Services\PreviewAudioService;
+use Modules\EstudioMusica\Tests\Support\ComEstudioAutenticado;
+use Modules\Faturacao\Models\Cliente;
+use Tests\TestCase;
+
+require_once __DIR__.'/../Support/ComEstudioAutenticado.php';
+
+/** Versões de áudio vistas pela equipa: upload, audição interna, lembrete e pré-visualização. */
+class VersaoAudioStaffTest extends TestCase
+{
+    use ComEstudioAutenticado;
+    use RefreshDatabase;
+
+    protected User $admin;
+
+    protected FaixaMusical $faixa;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        Storage::fake('local');
+
+        $this->admin = $this->registarEstudio('510000101');
+        $this->noTenantDe($this->admin);
+
+        $cliente = Cliente::create(['empresa_id' => $this->admin->empresa_id, 'nome' => 'Artista Staff', 'email' => 'artista@example.test']);
+        $projeto = ProjetoMusical::create([
+            'empresa_id' => $this->admin->empresa_id,
+            'cliente_id' => $cliente->id,
+            'nome' => 'Projeto Staff',
+            'tipo_cobranca' => 'hora',
+        ]);
+        $this->faixa = FaixaMusical::create([
+            'empresa_id' => $this->admin->empresa_id,
+            'projeto_musical_id' => $projeto->id,
+            'nome' => 'Faixa Staff',
+            'ordem' => 1,
+        ]);
+    }
+
+    protected function versao(string $rotulo, string $estado, int $minutos, string $caminho = 'estudiomusica/versoes-audio/x.mp3'): VersaoAudio
+    {
+        return VersaoAudio::create([
+            'empresa_id' => $this->admin->empresa_id,
+            'faixa_musical_id' => $this->faixa->id,
+            'rotulo' => $rotulo,
+            'caminho_ficheiro' => $caminho,
+            'estado' => $estado,
+            'enviada_em' => now()->addMinutes($minutos),
+        ]);
+    }
+
+    public function test_upload_guarda_a_versao_e_pede_a_pre_visualizacao_em_segundo_plano(): void
+    {
+        Queue::fake();
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.faixas.versoes.store', $this->faixa), [
+                'rotulo' => 'Mix v1',
+                'ficheiro' => UploadedFile::fake()->create('mix.mp3', 100, 'audio/mpeg'),
+            ])
+            ->assertRedirect()
+            ->assertSessionHas('sucesso');
+
+        $this->noTenantDe($this->admin);
+        $versao = VersaoAudio::query()->sole();
+
+        Queue::assertPushed(GerarPreviewAudioJob::class, fn (GerarPreviewAudioJob $job) => $job->versaoId === $versao->id
+            && $job->empresaId === (int) $this->admin->empresa_id);
+        Storage::disk('local')->assertExists($versao->caminho_ficheiro);
+    }
+
+    public function test_audicao_interna_com_ficheiro_em_falta_responde_404(): void
+    {
+        $versao = $this->versao('Mix v1', 'pendente_aprovacao', 0, 'estudiomusica/versoes-audio/nao-existe.mp3');
+
+        $this->actingAs($this->admin)
+            ->get(route('estudiomusica.versoes.reproduzir', $versao))
+            ->assertNotFound();
+    }
+
+    public function test_lembrete_so_vai_para_a_versao_mais_recente_que_ainda_aguarda_decisao(): void
+    {
+        Queue::fake();
+
+        $antiga = $this->versao('Mix v1', 'pendente_aprovacao', 0);
+        $recente = $this->versao('Mix v2', 'pendente_aprovacao', 5);
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.versoes.lembrete-aprovacao', $antiga))
+            ->assertSessionHas('erro');
+        Queue::assertNotPushed(EnviarMensagemClienteJob::class);
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.versoes.lembrete-aprovacao', $recente))
+            ->assertSessionHas('sucesso');
+        Queue::assertPushed(EnviarMensagemClienteJob::class, 1);
+
+        $this->noTenantDe($this->admin);
+        $recente->update(['estado' => 'aprovada']);
+
+        $this->actingAs($this->admin)
+            ->post(route('estudiomusica.versoes.lembrete-aprovacao', $recente))
+            ->assertSessionHas('erro');
+        Queue::assertPushed(EnviarMensagemClienteJob::class, 1);
+    }
+
+    public function test_marcador_formata_o_tempo_sem_avisos_de_depreciacao(): void
+    {
+        $avisos = [];
+        set_error_handler(function (int $nivel, string $mensagem) use (&$avisos): bool {
+            $avisos[] = $mensagem;
+
+            return true;
+        });
+
+        try {
+            $textos = array_map(
+                fn (string $segundos) => (new MarcadorAudio(['tempo_segundos' => $segundos]))->tempoFormatado(),
+                ['0', '5.9', '83.45', '3599.99', '3600'],
+            );
+        } finally {
+            restore_error_handler();
+        }
+
+        $this->assertSame([], $avisos);
+        $this->assertSame(['00:00', '00:05', '01:23', '59:59', '60:00'], $textos);
+    }
+
+    public function test_pre_visualizacao_e_gerada_uma_vez_e_guardada_na_versao(): void
+    {
+        $ffmpeg = sys_get_temp_dir().'/ffmpeg-falso-'.bin2hex(random_bytes(4)).'.sh';
+        file_put_contents($ffmpeg, "#!/bin/sh\nfor ultimo; do :; done\nprintf 'PREVIEW' > \"\$ultimo\"\n");
+        chmod($ffmpeg, 0755);
+        config(['estudiomusica.ffmpeg_bin' => $ffmpeg]);
+
+        $versao = $this->versao('Mix v1', 'pendente_aprovacao', 0);
+        Storage::disk('local')->put($versao->caminho_ficheiro, 'ORIGINAL');
+
+        (new GerarPreviewAudioJob($versao->id, (int) $versao->empresa_id))
+            ->handle(app(TenantManager::class), app(PreviewAudioService::class));
+
+        @unlink($ffmpeg);
+        $this->noTenantDe($this->admin);
+        $versao->refresh();
+
+        $this->assertSame("estudiomusica/previews/{$versao->empresa_id}/{$versao->id}.mp3", $versao->caminho_preview);
+        $this->assertSame('PREVIEW', Storage::disk('local')->get($versao->caminho_preview));
+        // Só o ficheiro final: nenhum temporário esquecido.
+        $this->assertCount(1, Storage::disk('local')->allFiles('estudiomusica/previews'));
+    }
+
+    public function test_sem_ffmpeg_a_pre_visualizacao_falha_em_silencio_e_sem_lixo(): void
+    {
+        config(['estudiomusica.ffmpeg_bin' => '/nao/existe/ffmpeg']);
+
+        $versao = $this->versao('Mix v1', 'pendente_aprovacao', 0);
+        Storage::disk('local')->put($versao->caminho_ficheiro, 'ORIGINAL');
+
+        $this->assertNull(app(PreviewAudioService::class)->garantir($versao));
+        $this->assertNull($versao->fresh()->caminho_preview);
+        $this->assertSame([], Storage::disk('local')->allFiles('estudiomusica/previews'));
+    }
+}
diff --git a/Modules/EstudioMusica/tests/Support/ComEstudioAutenticado.php b/Modules/EstudioMusica/tests/Support/ComEstudioAutenticado.php
new file mode 100644
index 0000000..d250a6f
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Support/ComEstudioAutenticado.php
@@ -0,0 +1,33 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Support;
+
+use Modules\Core\Models\User;
+use Modules\Core\Services\RegistoEmpresaService;
+use Modules\Core\Services\TenantManager;
+
+/**
+ * Empresa nova com o serviço Estúdio ativo + o Administrador que a registou
+ * (papéis, permissões e subscrição de ensaio já provisionados), pronto para
+ * actingAs(). Os módulos não têm autoload-dev: os testes fazem require_once
+ * deste ficheiro.
+ */
+trait ComEstudioAutenticado
+{
+    protected function registarEstudio(string $nif): User
+    {
+        $this->withoutVite();
+
+        return app(RegistoEmpresaService::class)->registar(
+            ['nome_comercial' => 'Estúdio '.$nif, 'nif' => $nif],
+            ['name' => 'Admin '.$nif, 'email' => "admin{$nif}@example.test", 'password' => 'Senha-segura-123'],
+            ['estudio'],
+        );
+    }
+
+    /** Pedidos HTTP limpam o tenant no fim: volta a defini-lo antes de ler/escrever modelos no teste. */
+    protected function noTenantDe(User $utilizador): void
+    {
+        app(TenantManager::class)->set((int) $utilizador->empresa_id);
+    }
+}
diff --git a/Modules/EstudioMusica/tests/Support/CriaDocumentosFiscais.php b/Modules/EstudioMusica/tests/Support/CriaDocumentosFiscais.php
new file mode 100644
index 0000000..5d1045e
--- /dev/null
+++ b/Modules/EstudioMusica/tests/Support/CriaDocumentosFiscais.php
@@ -0,0 +1,134 @@
+<?php
+
+namespace Modules\EstudioMusica\Tests\Support;
+
+use Modules\Core\Contracts\LimitesDaEmpresa;
+use Modules\Core\Models\Empresa;
+use Modules\Faturacao\Models\Fatura;
+use Modules\Faturacao\Models\NotaCreditoDebito;
+use Modules\Faturacao\Models\Recibo;
+use Modules\Faturacao\Models\Serie;
+
+/**
+ * Cria documentos fiscais "já emitidos" diretamente na base de dados
+ * (forceFill), sem passar pelo fluxo completo de emissão — esse está coberto
+ * pelos testes do módulo Faturação; aqui só interessa o estado final das
+ * colunas que o Estúdio lê.
+ *
+ * Os módulos não têm autoload-dev: os testes que usam este trait fazem
+ * require_once deste ficheiro.
+ */
+trait CriaDocumentosFiscais
+{
+    /** @var array<string, int> */
+    protected array $contadoresDocumentos = [];
+
+    /** Chave RSA só para este processo de teste, gerada uma vez e apagada no fim — como nos testes do Faturação. */
+    protected function prepararChaveFiscal(): void
+    {
+        static $caminho = null;
+
+        if ($caminho === null) {
+            $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
+            openssl_pkey_export($recurso, $pem);
+
+            $caminho = sys_get_temp_dir().'/estudiomusica-teste-'.uniqid().'.pem';
+            file_put_contents($caminho, $pem);
+            register_shutdown_function(static fn () => @unlink($caminho));
+        }
+
+        config(['faturacao.chave_privada_path' => $caminho]);
+    }
+
+    /** Limite 'max_faturas_mes' do plano (null = sem limite) — a emissão recusa-o com EmissaoNaoPermitidaException. */
+    protected function limitarFaturasPorMes(?int $limite): void
+    {
+        $this->app->bind(LimitesDaEmpresa::class, fn () => new class($limite) implements LimitesDaEmpresa
+        {
+            public function __construct(protected ?int $limite) {}
+
+            public function limite(Empresa $empresa, string $chave): ?int
+            {
+                return $chave === 'max_faturas_mes' ? $this->limite : null;
+            }
+        });
+    }
+
+    protected function proximoNumeroDocumento(string $tipo): int
+    {
+        return $this->contadoresDocumentos[$tipo] = ($this->contadoresDocumentos[$tipo] ?? 0) + 1;
+    }
+
+    protected function serieDeTeste(string $tipo, int $empresaId): Serie
+    {
+        return Serie::query()->firstOrCreate(
+            ['empresa_id' => $empresaId, 'tipo_documento' => $tipo, 'ano' => 2026, 'prefixo' => 'T'],
+            ['ultimo_numero' => 0],
+        );
+    }
+
+    protected function faturaEmitida(int $empresaId, int $clienteId, float $total, ?float $semIva = null): Fatura
+    {
+        $semIva ??= round($total / 1.14, 2);
+        $numero = $this->proximoNumeroDocumento('FT');
+
+        $fatura = new Fatura;
+        $fatura->forceFill([
+            'empresa_id' => $empresaId,
+            'serie_id' => $this->serieDeTeste('FT', $empresaId)->id,
+            'cliente_id' => $clienteId,
+            'numero_sequencial' => $numero,
+            'numero_documento' => "FT T2026/{$numero}",
+            'estado' => 'emitida',
+            'valor_total' => $total,
+            'valor_sem_iva' => $semIva,
+            'valor_iva' => round($total - $semIva, 2),
+        ])->save();
+
+        return $fatura;
+    }
+
+    protected function reciboEmitido(Fatura $fatura, float $valor): Recibo
+    {
+        $numero = $this->proximoNumeroDocumento('RC');
+
+        $recibo = new Recibo;
+        $recibo->forceFill([
+            'empresa_id' => $fatura->empresa_id,
+            'serie_id' => $this->serieDeTeste('RC', (int) $fatura->empresa_id)->id,
+            'cliente_id' => $fatura->cliente_id,
+            'fatura_id' => $fatura->id,
+            'numero_sequencial' => $numero,
+            'numero_documento' => "RC T2026/{$numero}",
+            'estado' => 'emitido',
+            'valor' => $valor,
+            'meio_pagamento' => 'multicaixa',
+        ])->save();
+
+        return $recibo;
+    }
+
+    protected function notaCreditoEmitida(Fatura $fatura, float $total, ?float $semIva = null): NotaCreditoDebito
+    {
+        $semIva ??= round($total / 1.14, 2);
+        $numero = $this->proximoNumeroDocumento('NC');
+
+        $nota = new NotaCreditoDebito;
+        $nota->forceFill([
+            'empresa_id' => $fatura->empresa_id,
+            'serie_id' => $this->serieDeTeste('NC', (int) $fatura->empresa_id)->id,
+            'cliente_id' => $fatura->cliente_id,
+            'fatura_id' => $fatura->id,
+            'tipo' => 'credito',
+            'motivo' => 'Correção de teste',
+            'numero_sequencial' => $numero,
+            'numero_documento' => "NC T2026/{$numero}",
+            'estado' => 'emitida',
+            'valor_total' => $total,
+            'valor_sem_iva' => $semIva,
+            'valor_iva' => round($total - $semIva, 2),
+        ])->save();
+
+        return $nota;
+    }
+}
