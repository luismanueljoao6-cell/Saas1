#!/usr/bin/env bash
#
# correcoes-pr33-v2.sh — correções da PR #33 (feat/faturacao-fiscal-core)
#
# Ponto de partida: head 3d40b4a. Nesse commit o job «qualidade» do CI só falha
# num passo — o «composer audit» (o Pint, a suite e a auditoria de subscrições
# já passam).
#
# O que faz:
#   1. Corrige o «composer audit»: a Symfony publicou a 2026-11-04 a versão 8.1.11
#      com correções de segurança para symfony/http-foundation, symfony/routing e
#      symfony/http-kernel (6 advisories), que estão bloqueados em 8.1.6/8.1.7 no
#      composer.lock. O script atualiza SÓ esses 3 pacotes (os «require» não
#      mudaram entre as versões, por isso nada mais é arrastado).
#   2. Corrige 3 defeitos reais do DispararCampanhaJob:
#        a) um job sem pedido de reenvio voltava a tentar, sozinho, envios
#           falhados enquanto a campanha ainda estava em processamento;
#        b) um reenvio autorizado numa campanha que entretanto deixou de estar
#           concluída corria sobre o público todo (alargava-o);
#        c) a chave idempotente da 1.ª tentativa saía como «...-tentativa-» (sem
#           número) e podia duplicar a mensagem num retry.
#   3. Acrescenta Modules/Atelier/tests/Feature/ReenvioCampanhaRegrasTest.php, com
#      10 testes funcionais (HTTP → fila → job → Outbox) para as 4 regras: sem
#      reenvio automático, reenvio manual = nova tentativa, histórico da Outbox
#      preservado e isolamento entre empresas.
#   4. Valida (Pint como no CI, composer audit e testes), faz 2 commits e envia
#      para a PR #33.
#
# Uso, na raiz do repositório (Codespace):
#   bash correcoes-pr33-v2.sh                 # aplica, valida, faz commits e push
#   SEM_PUSH=1 bash correcoes-pr33-v2.sh      # aplica, valida e faz commits (sem push)
#   SEM_TESTES=1 bash correcoes-pr33-v2.sh    # corre só os testes novos (salta a suite completa)
#   SEM_AUDIT=1 bash correcoes-pr33-v2.sh     # salta o composer audit (ex.: sem acesso ao Packagist)
#
# Seguro por desenho: recusa-se a correr fora do ramo feat/faturacao-fiscal-core
# (nunca toca na main), não faz merge, só mexe nos ficheiros listados abaixo e
# pode ser repetido sem efeitos duplicados.

set -Eeuo pipefail

RAMO="feat/faturacao-fiscal-core"
PR_NUMERO=33
PR_URL="https://github.com/luismanueljoao6-cell/Saas1/pull/33"

# Ficheiros do commit de código (o patch cria/altera exatamente estes dois).
FICHEIROS_CODIGO=(
  "Modules/Atelier/app/Jobs/DispararCampanhaJob.php"
  "Modules/Atelier/tests/Feature/ReenvioCampanhaRegrasTest.php"
)

# Correções de segurança da Symfony de 2026-11-04.
PACOTES_SYMFONY=(symfony/http-foundation symfony/routing symfony/http-kernel)
MINIMO_SYMFONY="8.1.11"

if [[ -t 1 ]]; then
  VERDE=$'\e[32m'; AMARELO=$'\e[33m'; VERMELHO=$'\e[31m'; NEGRITO=$'\e[1m'; FIM=$'\e[0m'
else
  VERDE=""; AMARELO=""; VERMELHO=""; NEGRITO=""; FIM=""
fi

passo() { printf '\n%s▶ %s%s\n' "$NEGRITO" "$1" "$FIM"; }
ok()    { printf '  %s✔%s %s\n' "$VERDE" "$FIM" "$1"; }
aviso() { printf '  %s!%s %s\n' "$AMARELO" "$FIM" "$1"; }
falha() { printf '\n%s✘ %s%s\n' "$VERMELHO" "$1" "$FIM" >&2; exit 1; }

trap 'printf "\n%s✘ O script parou na linha %s. Se ainda não viste «Push concluído», nada foi enviado para o GitHub.%s\n" "$VERMELHO" "$LINENO" "$FIM" >&2' ERR

PATCH="$(mktemp)"
trap 'rm -f "$PATCH"' EXIT

patch_correcoes() {
cat <<'__FIM_DO_PATCH__'
diff --git a/Modules/Atelier/app/Jobs/DispararCampanhaJob.php b/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
index 24786fa..4deb1e3 100644
--- a/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
+++ b/Modules/Atelier/app/Jobs/DispararCampanhaJob.php
@@ -239,15 +239,22 @@ protected function executar(
             $campanha->refresh();
         }
 
+        $concluida = in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true);
+
         // Um job repetido pela fila/agendador nunca pode transformar uma
         // reconciliação terminal num novo envio. Só o botão de reenvio manual
         // autoriza uma nova tentativa para campanhas já concluídas.
-        if (! $reenvioAutorizado && in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true)) {
+        if (! $reenvioAutorizado && $concluida) {
             return null;
         }
 
-        $reenvio = $reenvioAutorizado
-            && in_array($campanha->estado, ['enviada', 'concluida_com_falhas'], true);
+        // Um reenvio autorizado só actua sobre uma campanha concluída. Se entretanto
+        // deixou de o estar, não há nada a reenviar — e nunca se alarga o público.
+        if ($reenvioAutorizado && ! $concluida) {
+            return 'A campanha deixou de estar concluída: nada a reenviar.';
+        }
+
+        $reenvio = $reenvioAutorizado;
         $idsPorReenviar = collect();
 
         if ($reenvio) {
@@ -267,15 +274,24 @@ protected function executar(
         $assunto = $campanha->assunto ?: $campanha->nome;
 
         foreach ($clientes as $cliente) {
+            // A tentativa é indicada de forma explícita: o valor por omissão da
+            // coluna não é carregado para o modelo acabado de criar, e a chave
+            // idempotente da Outbox tem de ser igual em qualquer execução do job.
             $envio = CampanhaEnvio::firstOrCreate(
                 ['campanha_id' => $campanha->id, 'cliente_id' => $cliente->id],
-                ['canal' => $campanha->canal, 'estado' => CampanhaEnvio::ESTADO_PENDENTE],
+                ['canal' => $campanha->canal, 'estado' => CampanhaEnvio::ESTADO_PENDENTE, 'tentativa' => 1],
             );
 
             if (in_array($envio->estado, [CampanhaEnvio::ESTADO_ENVIADO, CampanhaEnvio::ESTADO_ACEITE], true)) {
                 continue;
             }
 
+            // Uma tentativa falhada só volta a ser tentada num reenvio autorizado:
+            // nunca por um job repetido enquanto a campanha ainda processa.
+            if ($envio->estado === CampanhaEnvio::ESTADO_FALHOU && ! $reenvio) {
+                continue;
+            }
+
             // Um envio telefónico só pode ganhar uma nova chave idempotente
             // depois de a tentativa anterior terminar definitivamente.
             if ($envio->notification_outbox_id !== null) {
diff --git a/Modules/Atelier/tests/Feature/ReenvioCampanhaRegrasTest.php b/Modules/Atelier/tests/Feature/ReenvioCampanhaRegrasTest.php
new file mode 100644
index 0000000..53380c5
--- /dev/null
+++ b/Modules/Atelier/tests/Feature/ReenvioCampanhaRegrasTest.php
@@ -0,0 +1,522 @@
+<?php
+
+namespace Modules\Atelier\Tests\Feature;
+
+use Carbon\CarbonInterface;
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Foundation\Testing\RefreshDatabase;
+use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Str;
+use Modules\Atelier\Jobs\DispararCampanhaJob;
+use Modules\Atelier\Jobs\EnviarCampanhasAgendadasJob;
+use Modules\Atelier\Listeners\SincronizarEstadoCampanhaOutbox;
+use Modules\Atelier\Models\Campanha;
+use Modules\Atelier\Models\CampanhaEnvio;
+use Modules\Atelier\Models\CampanhaReenvio;
+use Modules\Atelier\Models\ClienteCrm;
+use Modules\Core\Events\NotificationStateChanged;
+use Modules\Core\Models\NotificationAttempt;
+use Modules\Core\Models\NotificationOutbox;
+use Modules\Core\Models\User;
+use Modules\Core\Services\RegistoEmpresaService;
+use Modules\Core\Services\TenantManager;
+use Modules\Faturacao\Models\Cliente;
+use ReflectionProperty;
+use Tests\TestCase;
+
+/**
+ * Prova, ponta a ponta (pedido HTTP → fila → job → Outbox), as regras de
+ * reenvio das campanhas do Atelier. Complementa os testes de concorrência do
+ * pedido de reenvio (ReenvioCampanhaConcorrenciaTest e ReenvioCampanhaHttpTest):
+ *
+ *  1. uma campanha nunca é reenviada automaticamente;
+ *  2. um reenvio manual cria uma nova tentativa (e só uma);
+ *  3. o histórico anterior da Outbox é preservado;
+ *  4. uma empresa nunca altera os envios de outra.
+ *
+ * Os jobs são executados como um worker os executaria (sem tenant activo e
+ * com as dependências do contentor); a fila é simulada para que a Outbox
+ * nunca fale com um fornecedor real.
+ */
+class ReenvioCampanhaRegrasTest extends TestCase
+{
+    use RefreshDatabase;
+
+    protected function setUp(): void
+    {
+        parent::setUp();
+
+        $this->withoutVite();
+
+        Queue::fake();
+    }
+
+    // ---------------------------------------------------------------------
+    // 1. Uma campanha nunca é reenviada automaticamente
+    // ---------------------------------------------------------------------
+
+    public function test_job_repetido_pela_fila_nao_reenvia_campanha_concluida(): void
+    {
+        ['admin' => $admin, 'campanha' => $campanha, 'aceite' => $aceite, 'falhado' => $falhado] = $this->campanhaConcluidaComUmaFalha('500000001');
+        $empresaId = (int) $admin->empresa_id;
+        $antes = $this->fotografia($campanha);
+
+        // Retry da fila, clique duplo, agendador a re-despachar…
+        $this->correrJob($campanha->id);
+        $this->correrJob($campanha->id);
+
+        $this->assertSame($antes, $this->fotografia($campanha));
+        $this->assertCount(2, $this->outboxDaEmpresa($empresaId), 'Não pode nascer nenhuma notificação nova.');
+        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $this->envioDe($campanha, $falhado)->estado);
+        $this->assertSame(1, (int) $this->envioDe($campanha, $falhado)->tentativa);
+        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $this->envioDe($campanha, $aceite)->estado);
+        $this->assertSame('concluida_com_falhas', $this->campanhaAtual($campanha)->estado);
+    }
+
+    public function test_job_automatico_nao_abre_nova_tentativa_enquanto_a_campanha_ainda_processa(): void
+    {
+        $admin = $this->registarAtelier('500000002');
+        $empresaId = (int) $admin->empresa_id;
+        $espera = $this->criarCliente($empresaId, 'Cliente em espera', '923456781');
+        $morto = $this->criarCliente($empresaId, 'Cliente sem sorte', '923456782');
+        $campanha = $this->criarCampanha($admin);
+
+        $this->correrJob($campanha->id);
+        $this->assertSame('em_processamento', $this->campanhaAtual($campanha)->estado);
+
+        // A Outbox esgota as tentativas do segundo cliente, mas o evento de
+        // sincronização perdeu-se: é o próprio job que reconcilia o estado.
+        NotificationOutbox::withoutGlobalScopes()
+            ->whereKey($this->envioDe($campanha, $morto)->notification_outbox_id)
+            ->update(['status' => NotificationOutbox::STATUS_DEAD_LETTER, 'last_error' => 'Fornecedor indisponível.']);
+
+        $this->correrJob($campanha->id);
+
+        $envioMorto = $this->envioDe($campanha, $morto);
+        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $envioMorto->estado, 'A reconciliação regista a falha…');
+        $this->assertSame(1, (int) $envioMorto->tentativa, '…mas não abre uma nova tentativa sem pedido expresso.');
+        $this->assertCount(2, $this->outboxDaEmpresa($empresaId));
+        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $this->envioDe($campanha, $espera)->estado);
+        $this->assertSame('em_processamento', $this->campanhaAtual($campanha)->estado);
+    }
+
+    public function test_agendador_so_despacha_campanhas_agendadas_e_nunca_como_reenvio(): void
+    {
+        $admin = $this->registarAtelier('500000003');
+        $agendada = $this->criarCampanha($admin, 'agendada', now()->subMinute());
+        $this->criarCampanha($admin, 'concluida_com_falhas', now()->subHour());
+        $this->criarCampanha($admin, 'enviada', now()->subDay());
+
+        (new EnviarCampanhasAgendadasJob)->handle(app(TenantManager::class));
+
+        Queue::assertPushed(DispararCampanhaJob::class, 1);
+        Queue::assertPushed(
+            DispararCampanhaJob::class,
+            fn (DispararCampanhaJob $job) => $this->propriedade($job, 'campanhaId') === $agendada->id
+                && $this->propriedade($job, 'reenvioToken') === null
+        );
+    }
+
+    // ---------------------------------------------------------------------
+    // 2. Um reenvio manual cria uma nova tentativa (e só uma)
+    // ---------------------------------------------------------------------
+
+    public function test_reenvio_manual_cria_nova_tentativa_apenas_para_o_envio_falhado(): void
+    {
+        ['admin' => $admin, 'campanha' => $campanha, 'aceite' => $aceite, 'falhado' => $falhado] = $this->campanhaConcluidaComUmaFalha('500000004');
+        $empresaId = (int) $admin->empresa_id;
+        $outboxFalhadaAntes = (int) $this->envioDe($campanha, $falhado)->notification_outbox_id;
+        $envioAceiteAntes = $this->envioDe($campanha, $aceite);
+
+        $this->actingAs($admin)
+            ->post(route('atelier.campanhas.enviar-agora', ['campanha' => $campanha->id]))
+            ->assertRedirect()
+            ->assertSessionHas('sucesso');
+
+        Queue::assertPushed(DispararCampanhaJob::class, 1);
+        $this->correrJobsDespachados();
+
+        $envioFalhado = $this->envioDe($campanha, $falhado);
+        $this->assertSame(2, (int) $envioFalhado->tentativa);
+        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $envioFalhado->estado);
+        $this->assertNotSame($outboxFalhadaAntes, (int) $envioFalhado->notification_outbox_id);
+
+        $nova = NotificationOutbox::withoutGlobalScopes()->findOrFail($envioFalhado->notification_outbox_id);
+        $this->assertSame(
+            'atelier-campanha-'.$campanha->id.'-cliente-'.$falhado->id.'-tentativa-2',
+            $nova->idempotency_key
+        );
+        $this->assertSame(NotificationOutbox::STATUS_PENDING, $nova->status);
+
+        // Quem já tinha a mensagem aceite não é incomodado outra vez.
+        $envioAceite = $this->envioDe($campanha, $aceite);
+        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envioAceite->estado);
+        $this->assertSame(1, (int) $envioAceite->tentativa);
+        $this->assertSame((int) $envioAceiteAntes->notification_outbox_id, (int) $envioAceite->notification_outbox_id);
+
+        // O pedido manual fica registado e fechado: uma execução, uma tentativa.
+        $pedidos = $this->pedidosDe($campanha);
+        $this->assertCount(1, $pedidos);
+        $this->assertSame(CampanhaReenvio::CONCLUIDO, $pedidos->first()->estado);
+
+        $this->assertCount(3, $this->outboxDaEmpresa($empresaId));
+        $this->assertSame('em_processamento', $this->campanhaAtual($campanha)->estado);
+    }
+
+    public function test_reenvio_autorizado_numa_campanha_que_ja_nao_esta_concluida_nao_alarga_o_publico(): void
+    {
+        ['admin' => $admin, 'campanha' => $campanha, 'aceite' => $aceite, 'falhado' => $falhado] = $this->campanhaConcluidaComUmaFalha('500000005');
+        $empresaId = (int) $admin->empresa_id;
+
+        // Só deu consentimento depois do primeiro envio: não pertence ao
+        // público desta campanha e um reenvio nunca o pode alargar.
+        $tardio = $this->criarCliente($empresaId, 'Cliente tardio', '923456783');
+
+        // Entre a aceitação do pedido e a execução do job a campanha voltou a
+        // ficar em processamento (há uma notificação ainda pendente).
+        $envioAceite = $this->envioDe($campanha, $aceite);
+        NotificationOutbox::withoutGlobalScopes()
+            ->whereKey($envioAceite->notification_outbox_id)
+            ->update(['status' => NotificationOutbox::STATUS_PENDING]);
+        CampanhaEnvio::query()->whereKey($envioAceite->id)->update(['estado' => CampanhaEnvio::ESTADO_PENDENTE]);
+        Campanha::withoutGlobalScopes()->whereKey($campanha->id)->update(['estado' => 'em_processamento']);
+
+        $pedido = CampanhaReenvio::create([
+            'empresa_id' => $empresaId,
+            'campanha_id' => $campanha->id,
+            'token' => (string) Str::uuid(),
+            'estado' => CampanhaReenvio::ACEITE,
+            'aberto' => true,
+            'solicitado_por_id' => $admin->id,
+            'solicitado_em' => now(),
+        ]);
+
+        $this->correrJob($campanha->id, $pedido->token);
+
+        $this->assertFalse(
+            CampanhaEnvio::query()->where('campanha_id', $campanha->id)->where('cliente_id', $tardio->id)->exists(),
+            'O reenvio não pode alargar o público da campanha.'
+        );
+        $this->assertSame(1, (int) $this->envioDe($campanha, $falhado)->tentativa);
+        $this->assertCount(2, $this->outboxDaEmpresa($empresaId));
+
+        $pedido = CampanhaReenvio::withoutGlobalScopes()->findOrFail($pedido->id);
+        $this->assertSame(CampanhaReenvio::IGNORADO, $pedido->estado);
+        $this->assertNull($pedido->aberto);
+    }
+
+    public function test_reenvio_manual_respeita_a_revogacao_do_consentimento(): void
+    {
+        ['admin' => $admin, 'campanha' => $campanha, 'falhado' => $falhado] = $this->campanhaConcluidaComUmaFalha('500000006');
+        $empresaId = (int) $admin->empresa_id;
+
+        ClienteCrm::withoutGlobalScopes()
+            ->where('cliente_id', $falhado->id)
+            ->update(['aceita_marketing' => false, 'consentimento_revogado_em' => now()]);
+
+        $this->actingAs($admin)
+            ->post(route('atelier.campanhas.enviar-agora', ['campanha' => $campanha->id]))
+            ->assertRedirect();
+        $this->correrJobsDespachados();
+
+        $envio = $this->envioDe($campanha, $falhado);
+        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $envio->estado);
+        $this->assertSame(1, (int) $envio->tentativa);
+        $this->assertCount(2, $this->outboxDaEmpresa($empresaId), 'Quem revogou o consentimento não recebe mais nada.');
+        $this->assertSame('concluida_com_falhas', $this->campanhaAtual($campanha)->estado);
+    }
+
+    // ---------------------------------------------------------------------
+    // 3. O histórico anterior da Outbox é preservado
+    // ---------------------------------------------------------------------
+
+    public function test_reenvio_manual_preserva_o_historico_anterior_da_outbox(): void
+    {
+        ['admin' => $admin, 'campanha' => $campanha, 'falhado' => $falhado] = $this->campanhaConcluidaComUmaFalha('500000007');
+        $empresaId = (int) $admin->empresa_id;
+        $anteriorId = (int) $this->envioDe($campanha, $falhado)->notification_outbox_id;
+
+        NotificationAttempt::create([
+            'empresa_id' => $empresaId,
+            'notification_outbox_id' => $anteriorId,
+            'attempt_number' => 1,
+            'status' => 'failed',
+            'provider' => 'teste',
+            'error_message' => 'Fornecedor indisponível.',
+            'started_at' => now()->subMinutes(5),
+            'finished_at' => now()->subMinutes(5),
+        ]);
+
+        $campos = ['uuid', 'idempotency_key', 'channel', 'recipient', 'payload', 'status', 'attempts', 'max_attempts', 'last_error'];
+        $antes = NotificationOutbox::withoutGlobalScopes()->findOrFail($anteriorId)->only($campos);
+
+        $this->actingAs($admin)
+            ->post(route('atelier.campanhas.enviar-agora', ['campanha' => $campanha->id]))
+            ->assertRedirect();
+        $this->correrJobsDespachados();
+
+        // A tentativa falhada continua lá, tal e qual, com o seu histórico…
+        $anterior = NotificationOutbox::withoutGlobalScopes()->findOrFail($anteriorId);
+        $this->assertSame($antes, $anterior->only($campos));
+        $this->assertSame(NotificationOutbox::STATUS_DEAD_LETTER, $anterior->status);
+        $this->assertSame(1, NotificationAttempt::withoutGlobalScopes()->where('notification_outbox_id', $anteriorId)->count());
+
+        // …e a nova tentativa vive noutra linha, com outra chave idempotente.
+        $atual = (int) $this->envioDe($campanha, $falhado)->notification_outbox_id;
+        $this->assertNotSame($anteriorId, $atual);
+        $this->assertCount(3, $this->outboxDaEmpresa($empresaId));
+        $this->assertSame(
+            [
+                'atelier-campanha-'.$campanha->id.'-cliente-'.$falhado->id.'-tentativa-1',
+                'atelier-campanha-'.$campanha->id.'-cliente-'.$falhado->id.'-tentativa-2',
+            ],
+            $this->outboxDaEmpresa($empresaId)
+                ->filter(fn (NotificationOutbox $outbox) => str_contains($outbox->idempotency_key, '-cliente-'.$falhado->id.'-'))
+                ->pluck('idempotency_key')
+                ->values()
+                ->all()
+        );
+    }
+
+    // ---------------------------------------------------------------------
+    // 4. Uma empresa nunca altera os envios de outra
+    // ---------------------------------------------------------------------
+
+    public function test_administrador_de_outra_empresa_nao_ve_nem_reenvia_a_campanha(): void
+    {
+        $a = $this->campanhaConcluidaComUmaFalha('500000008');
+        $b = $this->campanhaConcluidaComUmaFalha('500000009');
+        $antesDeA = $this->fotografia($a['campanha']);
+        $antesDeB = $this->fotografia($b['campanha']);
+
+        $this->actingAs($b['admin'])
+            ->get(route('atelier.campanhas.mostrar', ['campanha' => $a['campanha']->id]))
+            ->assertNotFound();
+
+        $this->actingAs($b['admin'])
+            ->post(route('atelier.campanhas.enviar-agora', ['campanha' => $a['campanha']->id]))
+            ->assertNotFound();
+
+        Queue::assertNotPushed(DispararCampanhaJob::class);
+        $this->assertSame($antesDeA, $this->fotografia($a['campanha']));
+        $this->assertSame($antesDeB, $this->fotografia($b['campanha']));
+        $this->assertSame(0, CampanhaReenvio::withoutGlobalScopes()->count(), 'Nenhum pedido de reenvio pode ter sido aceite.');
+    }
+
+    public function test_reenvio_manual_de_uma_empresa_nao_toca_nos_envios_da_outra(): void
+    {
+        $a = $this->campanhaConcluidaComUmaFalha('500000010');
+        $b = $this->campanhaConcluidaComUmaFalha('500000011');
+        $antesDeB = $this->fotografia($b['campanha']);
+
+        $this->actingAs($a['admin'])
+            ->post(route('atelier.campanhas.enviar-agora', ['campanha' => $a['campanha']->id]))
+            ->assertRedirect();
+        $this->correrJobsDespachados();
+
+        // A empresa A ganhou a sua nova tentativa e o seu pedido…
+        $this->assertSame(2, (int) $this->envioDe($a['campanha'], $a['falhado'])->tentativa);
+        $this->assertCount(3, $this->outboxDaEmpresa((int) $a['admin']->empresa_id));
+        $this->assertCount(1, $this->pedidosDe($a['campanha']));
+
+        // …e a empresa B ficou exatamente como estava.
+        $this->assertSame($antesDeB, $this->fotografia($b['campanha']));
+        $this->assertCount(2, $this->outboxDaEmpresa((int) $b['admin']->empresa_id));
+        $this->assertCount(0, $this->pedidosDe($b['campanha']));
+    }
+
+    public function test_utilizador_sem_papel_de_administrador_nao_consegue_reenviar(): void
+    {
+        ['admin' => $admin, 'campanha' => $campanha] = $this->campanhaConcluidaComUmaFalha('500000012');
+        $antes = $this->fotografia($campanha);
+
+        $semPapel = User::create([
+            'empresa_id' => $admin->empresa_id,
+            'name' => 'Utilizador sem papel',
+            'email' => 'sem-papel@example.test',
+            'password' => 'Senha-Forte-123!',
+            'ativo' => true,
+        ]);
+
+        $this->actingAs($semPapel)
+            ->post(route('atelier.campanhas.enviar-agora', ['campanha' => $campanha->id]))
+            ->assertForbidden();
+
+        Queue::assertNotPushed(DispararCampanhaJob::class);
+        $this->assertSame($antes, $this->fotografia($campanha));
+    }
+
+    // ---------------------------------------------------------------------
+    // Preparação
+    // ---------------------------------------------------------------------
+
+    protected function registarAtelier(string $nif): User
+    {
+        $admin = app(RegistoEmpresaService::class)->registar(
+            ['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif],
+            ['name' => 'Admin '.$nif, 'email' => "admin{$nif}@example.test", 'password' => 'Senha-segura-123'],
+            ['atelier'],
+        );
+
+        // Os modelos são criados com empresa_id explícito e lidos sem scope:
+        // nenhum teste depende de um tenant que sobrou de outro passo.
+        app(TenantManager::class)->clear();
+
+        return $admin;
+    }
+
+    protected function criarCliente(int $empresaId, string $nome, string $telefone): Cliente
+    {
+        $cliente = Cliente::create(['empresa_id' => $empresaId, 'nome' => $nome, 'telefone' => $telefone]);
+
+        ClienteCrm::create([
+            'empresa_id' => $empresaId,
+            'cliente_id' => $cliente->id,
+            'aceita_marketing' => true,
+            'consentimento_em' => now(),
+            'consentimento_origem' => 'teste',
+        ]);
+
+        return $cliente;
+    }
+
+    protected function criarCampanha(User $admin, string $estado = 'rascunho', ?CarbonInterface $agendadaPara = null): Campanha
+    {
+        return Campanha::create([
+            'empresa_id' => (int) $admin->empresa_id,
+            'nome' => 'Campanha de teste',
+            'canal' => 'whatsapp',
+            'segmento' => 'todos',
+            'mensagem' => 'Promoção de teste',
+            'estado' => $estado,
+            'agendada_para' => $agendadaPara,
+            'criada_por_id' => $admin->id,
+        ]);
+    }
+
+    /**
+     * Campanha por WhatsApp para dois clientes, enviada pelo próprio job. O
+     * transporte aceita a primeira mensagem e esgota as tentativas da segunda
+     * (dead letter): a campanha termina "concluída com falhas".
+     *
+     * @return array{admin: User, campanha: Campanha, aceite: Cliente, falhado: Cliente}
+     */
+    protected function campanhaConcluidaComUmaFalha(string $nif): array
+    {
+        $admin = $this->registarAtelier($nif);
+        $empresaId = (int) $admin->empresa_id;
+
+        $aceite = $this->criarCliente($empresaId, 'Cliente aceite '.$nif, '923456781');
+        $falhado = $this->criarCliente($empresaId, 'Cliente falhado '.$nif, '923456782');
+        $campanha = $this->criarCampanha($admin);
+
+        $this->correrJob($campanha->id);
+
+        $this->terminarNaOutbox($this->envioDe($campanha, $aceite), NotificationOutbox::STATUS_ACCEPTED);
+        $this->terminarNaOutbox($this->envioDe($campanha, $falhado), NotificationOutbox::STATUS_DEAD_LETTER, 'Fornecedor indisponível.');
+
+        // Pré-condição de todos os testes: o ponto de partida é este e não outro.
+        $this->assertSame('concluida_com_falhas', $this->campanhaAtual($campanha)->estado);
+        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $this->envioDe($campanha, $aceite)->estado);
+        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $this->envioDe($campanha, $falhado)->estado);
+        $this->assertCount(2, $this->outboxDaEmpresa($empresaId));
+
+        return compact('admin', 'campanha', 'aceite', 'falhado');
+    }
+
+    /**
+     * Simula o desfecho que o worker da Outbox daria à notificação do envio e
+     * avisa o Atelier pelo mesmo evento que o Core emite em produção.
+     */
+    protected function terminarNaOutbox(CampanhaEnvio $envio, string $estado, ?string $erro = null): void
+    {
+        $outbox = NotificationOutbox::withoutGlobalScopes()->findOrFail($envio->notification_outbox_id);
+
+        NotificationOutbox::withoutGlobalScopes()
+            ->whereKey($outbox->id)
+            ->update(['status' => $estado, 'last_error' => $erro]);
+
+        app(SincronizarEstadoCampanhaOutbox::class)->handle(
+            new NotificationStateChanged((int) $outbox->id, (int) $outbox->empresa_id, $estado)
+        );
+    }
+
+    /** Executa o job como um worker: sem tenant activo e com as dependências do contentor. */
+    protected function correrJob(int $campanhaId, ?string $reenvioToken = null): void
+    {
+        app()->call([new DispararCampanhaJob($campanhaId, $reenvioToken), 'handle']);
+    }
+
+    /** Executa, pela ordem em que foram despachados, os jobs que o controlador pôs na fila. */
+    protected function correrJobsDespachados(): void
+    {
+        foreach (Queue::pushed(DispararCampanhaJob::class) as $job) {
+            app()->call([$job, 'handle']);
+        }
+    }
+
+    protected function propriedade(DispararCampanhaJob $job, string $nome): mixed
+    {
+        return (new ReflectionProperty($job, $nome))->getValue($job);
+    }
+
+    // ---------------------------------------------------------------------
+    // Leitura (sem scope de tenant: os testes verificam a base de dados real)
+    // ---------------------------------------------------------------------
+
+    protected function envioDe(Campanha $campanha, Cliente $cliente): CampanhaEnvio
+    {
+        return CampanhaEnvio::query()
+            ->where('campanha_id', $campanha->id)
+            ->where('cliente_id', $cliente->id)
+            ->firstOrFail();
+    }
+
+    protected function campanhaAtual(Campanha $campanha): Campanha
+    {
+        return Campanha::withoutGlobalScopes()->findOrFail($campanha->id);
+    }
+
+    /** @return Collection<int, NotificationOutbox> */
+    protected function outboxDaEmpresa(int $empresaId)
+    {
+        return NotificationOutbox::withoutGlobalScopes()
+            ->where('empresa_id', $empresaId)
+            ->orderBy('id')
+            ->get();
+    }
+
+    /** @return Collection<int, CampanhaReenvio> */
+    protected function pedidosDe(Campanha $campanha)
+    {
+        return CampanhaReenvio::withoutGlobalScopes()
+            ->where('campanha_id', $campanha->id)
+            ->orderBy('id')
+            ->get();
+    }
+
+    /** Estado completo de uma campanha: o que outro tenant nunca pode alterar. */
+    protected function fotografia(Campanha $campanha): array
+    {
+        $campanha = $this->campanhaAtual($campanha);
+
+        return [
+            'estado' => $campanha->estado,
+            'envios' => CampanhaEnvio::query()
+                ->where('campanha_id', $campanha->id)
+                ->orderBy('id')
+                ->get(['cliente_id', 'estado', 'tentativa', 'notification_outbox_id', 'erro'])
+                ->toArray(),
+            'outbox' => NotificationOutbox::withoutGlobalScopes()
+                ->where('empresa_id', $campanha->empresa_id)
+                ->orderBy('id')
+                ->get(['id', 'idempotency_key', 'status', 'attempts', 'last_error'])
+                ->toArray(),
+            'pedidos' => CampanhaReenvio::withoutGlobalScopes()
+                ->where('campanha_id', $campanha->id)
+                ->orderBy('id')
+                ->get(['id', 'estado', 'aberto'])
+                ->toArray(),
+        ];
+    }
+}
__FIM_DO_PATCH__
}

# Versão de um pacote no composer.lock (sem o «v»); vazio se não existir.
versao_bloqueada() {
  php -r '$l = json_decode(file_get_contents("composer.lock"), true);
          foreach (array_merge($l["packages"], $l["packages-dev"]) as $p) {
              if ($p["name"] === $argv[1]) { echo ltrim($p["version"], "vV"); exit; }
          }' "$1"
}

# 0 se $1 >= $2 (comparação de versões).
versao_minima() {
  [[ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" == "$2" ]]
}

# ---------------------------------------------------------------------------
passo "1/7 · Verificar o ambiente"

RAIZ="$(git rev-parse --show-toplevel 2>/dev/null)" || falha "Corre este script dentro do repositório Saas1."
cd "$RAIZ"
[[ -f artisan && -d Modules/Atelier ]] || falha "Isto não parece ser a raiz do Saas1 (falta o artisan ou o Modules/Atelier)."

RAMO_ATUAL="$(git rev-parse --abbrev-ref HEAD)"
[[ "$RAMO_ATUAL" == "$RAMO" ]] || falha "Estás no ramo «$RAMO_ATUAL». Muda primeiro para o da PR: git switch $RAMO"

command -v php >/dev/null || falha "PHP não encontrado."
command -v composer >/dev/null || falha "Composer não encontrado."
if [[ ! -f vendor/autoload.php ]]; then
  aviso "A instalar as dependências (composer install)…"
  composer install --no-interaction --prefer-dist --no-progress
fi
if [[ ! -f .env ]]; then
  cp .env.example .env
  php artisan key:generate --ansi
  aviso ".env criado a partir do .env.example"
fi
ok "Ramo $RAMO · PHP $(php -r 'echo PHP_VERSION;')"

# ---------------------------------------------------------------------------
passo "2/7 · Sincronizar com o GitHub"

git fetch origin "$RAMO" main --quiet
LOCAL="$(git rev-parse HEAD)"
REMOTO="$(git rev-parse "origin/$RAMO")"

if [[ "$LOCAL" == "$REMOTO" ]]; then
  ok "Ramo local igual ao remoto (${LOCAL:0:7})"
elif git merge-base --is-ancestor "$LOCAL" "$REMOTO"; then
  git merge --ff-only --quiet "origin/$RAMO" || falha "Não consegui actualizar o ramo local. Faz: git pull --ff-only"
  ok "Ramo local actualizado para ${REMOTO:0:7}"
elif git merge-base --is-ancestor "$REMOTO" "$LOCAL"; then
  aviso "Tens commits locais ainda não enviados; vão seguir no push."
else
  falha "O ramo local e o remoto divergiram. Resolve primeiro (git pull --rebase) e repete."
fi

# ---------------------------------------------------------------------------
passo "3/7 · Aplicar as correções de código (job de campanhas + testes novos)"

patch_correcoes > "$PATCH"

if git apply --reverse --check "$PATCH" 2>/dev/null; then
  ok "As correções de código já estão aplicadas nesta cópia."
elif git apply --check "$PATCH" 2>/dev/null; then
  if ! git diff --quiet HEAD -- "${FICHEIROS_CODIGO[@]}"; then
    git status --short -- "${FICHEIROS_CODIGO[@]}"
    falha "Tens alterações locais nestes ficheiros. Faz commit ou git stash e repete."
  fi
  git apply --index "$PATCH"
  ok "Correções aplicadas:"
  git apply --stat "$PATCH" | sed 's/^/      /'
else
  git apply --check "$PATCH" || true
  falha "O patch não encaixa: o ramo mudou desde que foi preparado. Envia-me o erro acima e eu adapto."
fi

# ---------------------------------------------------------------------------
passo "4/7 · Dependências: correções de segurança da Symfony (composer audit)"

PRECISAM_UPDATE=()
for pacote in "${PACOTES_SYMFONY[@]}"; do
  versao="$(versao_bloqueada "$pacote")"
  [[ -n "$versao" ]] || continue
  if versao_minima "$versao" "$MINIMO_SYMFONY"; then
    ok "$pacote $versao (já corrigido)"
  else
    aviso "$pacote $versao → vai subir para ≥ $MINIMO_SYMFONY"
    PRECISAM_UPDATE+=("$pacote")
  fi
done

if ((${#PRECISAM_UPDATE[@]})); then
  composer update "${PRECISAM_UPDATE[@]}" --no-interaction --no-scripts --no-progress
  for pacote in "${PRECISAM_UPDATE[@]}"; do
    versao="$(versao_bloqueada "$pacote")"
    versao_minima "$versao" "$MINIMO_SYMFONY" || falha "$pacote ficou em $versao (< $MINIMO_SYMFONY). Envia-me o output do composer update."
    ok "$pacote → $versao"
  done
fi

composer validate --no-check-publish >/dev/null || falha "composer validate falhou depois da atualização."
ok "composer validate: OK"

if [[ "${SEM_AUDIT:-0}" == "1" ]]; then
  aviso "SEM_AUDIT=1: composer audit saltado (o CI vai corrê-lo)."
else
  composer audit --no-interaction || falha "O composer audit ainda encontra problemas (ver acima). Nada foi commitado. Envia-me o output."
  ok "composer audit: sem vulnerabilidades"
fi

# ---------------------------------------------------------------------------
passo "5/7 · Validar como o CI (Pint em todos os ficheiros PHP da PR)"

vendor/bin/pint "${FICHEIROS_CODIGO[@]}" >/dev/null || true
BASE="$(git merge-base origin/main HEAD)"
mapfile -t PHP_ALTERADOS < <(git diff --name-only --diff-filter=d "$BASE" -- '*.php')
vendor/bin/pint --test "${PHP_ALTERADOS[@]}" || falha "O Pint ainda encontra problemas (ver acima). Nada foi commitado."
ok "Pint: ${#PHP_ALTERADOS[@]} ficheiros PHP sem problemas"

# ---------------------------------------------------------------------------
passo "6/7 · Testes"

php artisan config:clear --ansi >/dev/null 2>&1 || true
php artisan test Modules/Atelier/tests/Feature/ReenvioCampanhaRegrasTest.php
ok "Testes funcionais de reenvio de campanhas: OK"

if [[ "${SEM_TESTES:-0}" == "1" ]]; then
  aviso "SEM_TESTES=1: suite completa saltada (o CI vai corrê-la)."
else
  php artisan test --exclude-group=auditoria
  ok "Suite completa: OK"
fi

# ---------------------------------------------------------------------------
passo "7/7 · Commits e envio para a PR #$PR_NUMERO"

git add -- "${FICHEIROS_CODIGO[@]}"
if git diff --cached --quiet -- "${FICHEIROS_CODIGO[@]}"; then
  ok "Código: nada para commitar (já estava tudo commitado)."
else
  git commit --quiet -F - -- "${FICHEIROS_CODIGO[@]}" <<'__FIM_DA_MENSAGEM__'
Corrige reenvios automáticos de campanhas e acrescenta testes funcionais

DispararCampanhaJob:
- um job sem pedido de reenvio nunca volta a tentar um envio falhado enquanto a
  campanha ainda está em processamento (só um reenvio autorizado abre nova
  tentativa)
- um reenvio autorizado só actua sobre uma campanha concluída; se entretanto
  deixou de o estar, é ignorado e nunca alarga o público
- a chave idempotente da 1.ª tentativa passa a ser «...-tentativa-1» (o valor
  por omissão da coluna não era carregado para o modelo acabado de criar)

Testes funcionais (ReenvioCampanhaRegrasTest, HTTP → fila → job → Outbox):
- uma campanha não é reenviada automaticamente
- um reenvio manual cria uma nova tentativa (e só uma)
- o histórico anterior da Outbox é preservado
- uma empresa nunca altera os envios de outra
__FIM_DA_MENSAGEM__
  ok "Commit de código: $(git log -1 --format='%h %s')"
fi

git add -- composer.lock
if git diff --cached --quiet -- composer.lock; then
  ok "composer.lock: nada para commitar."
else
  git commit --quiet -F - -- composer.lock <<'__FIM_DA_MENSAGEM__'
Atualiza o Symfony para 8.1.11 (correções de segurança de 2026-11-04)

symfony/http-foundation, symfony/routing e symfony/http-kernel sobem para a
8.1.11, que corrige seis advisories publicadas a 2026-11-04 (regras de acesso
contornáveis por path, host ou pedidos HEAD, hosts de URLs gerados e o atalho
private_ranges dos proxies confiáveis). Faz passar o «composer audit» do CI.
__FIM_DA_MENSAGEM__
  ok "Commit do lockfile: $(git log -1 --format='%h %s')"
fi

if [[ "${SEM_PUSH:-0}" == "1" ]]; then
  aviso "SEM_PUSH=1: os commits ficaram só locais. Para enviar: git push origin $RAMO"
else
  git push origin "$RAMO" || falha "O push foi recusado. Os commits estão feitos localmente; resolve e faz: git push origin $RAMO"
  ok "Push concluído"
fi

printf '\n%sConcluído.%s A PR continua em Draft e a main não foi tocada.\n' "$NEGRITO" "$FIM"
printf '  • Acompanha o CI: %s  (ou: gh pr checks %s --watch)\n' "$PR_URL" "$PR_NUMERO"
printf '  • Esperado: «qualidade» e «testes-mysql» a verde.\n'
