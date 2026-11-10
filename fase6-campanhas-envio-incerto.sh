#!/usr/bin/env bash
# PR #33 — Fase 6 (parte verificada no código): envio «incerto» coerente em toda a campanha.
# A listener já trata unknown_result como «incerto» e mantém a campanha em processamento;
# o reconciliador e o fim do DispararCampanhaJob ainda não — este script alinha os três.
# Correr na raiz do repositório Saas1, na branch feat/faturacao-fiscal-core. Não faz commit.
set -euo pipefail

RAMO="$(git rev-parse --abbrev-ref HEAD)"
if [ "$RAMO" != "feat/faturacao-fiscal-core" ]; then
  echo "Branch errada: $RAMO (esperado feat/faturacao-fiscal-core)." >&2
  exit 1
fi

for f in \
  Modules/Atelier/app/Services/CampanhaReconciliador.php \
  Modules/Atelier/app/Jobs/DispararCampanhaJob.php; do
  [ -f "$f" ] || { echo "Ficheiro em falta: $f" >&2; exit 1; }
done

python3 - <<'PY'
import sys

def patch(path, trocas):
    texto = open(path, encoding='utf-8').read()
    for antigo, novo in trocas:
        if texto.count(antigo) != 1:
            sys.exit(f"Âncora não encontrada exatamente uma vez em {path}:\n{antigo[:160]}")
        texto = texto.replace(antigo, novo)
    open(path, 'w', encoding='utf-8').write(texto)

patch('Modules/Atelier/app/Services/CampanhaReconciliador.php', [
    # 1. constante: Outbox em resultado desconhecido
    (
        r'''        NotificationOutbox::STATUS_CANCELLED,
    ];
''',
        r'''        NotificationOutbox::STATUS_CANCELLED,
    ];

    /** A Outbox não sabe se o fornecedor recebeu a mensagem: o envio fica incerto até haver decisão humana. */
    private const OUTBOX_INCERTA = [
        NotificationOutbox::STATUS_UNKNOWN_RESULT,
    ];
''',
    ),
    # 2. os envios incertos também são relidos da Outbox (o evento da decisão pode perder-se)
    (
        r'''            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE])
            ->chunkById(200, function ($envios) use ($campanha): void {''',
        r'''            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE, CampanhaEnvio::ESTADO_INCERTO])
            ->chunkById(200, function ($envios) use ($campanha): void {''',
    ),
    # 3. falhada na Outbox: também resolve envios incertos
    (
        r'''                            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE])
                            ->update([
                                'estado' => CampanhaEnvio::ESTADO_FALHOU,''',
        r'''                            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_ACEITE, CampanhaEnvio::ESTADO_INCERTO])
                            ->update([
                                'estado' => CampanhaEnvio::ESTADO_FALHOU,''',
    ),
    # 4. aceite na Outbox resolve pendentes e incertos; resultado desconhecido torna o pendente incerto
    (
        r'''                    } elseif (
                        $envio->estado === CampanhaEnvio::ESTADO_PENDENTE
                        && in_array($outbox->status, self::OUTBOX_ACEITE, true)
                    ) {
                        CampanhaEnvio::query()
                            ->whereKey($envio->id)
                            ->where('notification_outbox_id', $outbox->id)
                            ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
                            ->update(['estado' => CampanhaEnvio::ESTADO_ACEITE, 'erro' => null]);
                    }''',
        r'''                    } elseif (
                        in_array($envio->estado, [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_INCERTO], true)
                        && in_array($outbox->status, self::OUTBOX_ACEITE, true)
                    ) {
                        CampanhaEnvio::query()
                            ->whereKey($envio->id)
                            ->where('notification_outbox_id', $outbox->id)
                            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_INCERTO])
                            ->update(['estado' => CampanhaEnvio::ESTADO_ACEITE, 'erro' => null]);
                    } elseif (
                        $envio->estado === CampanhaEnvio::ESTADO_PENDENTE
                        && in_array($outbox->status, self::OUTBOX_INCERTA, true)
                    ) {
                        CampanhaEnvio::query()
                            ->whereKey($envio->id)
                            ->where('notification_outbox_id', $outbox->id)
                            ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
                            ->update(['estado' => CampanhaEnvio::ESTADO_INCERTO, 'erro' => CampanhaEnvio::ERRO_INCERTO]);
                    }''',
    ),
    # 5. uma campanha com envios incertos não fecha (mesma regra da listener)
    (
        r'''            if ($bloqueada->envios()->where('estado', CampanhaEnvio::ESTADO_PENDENTE)->exists()) {
                return;
            }''',
        r'''            if ($bloqueada->envios()->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_INCERTO])->exists()) {
                return;
            }''',
    ),
])

patch('Modules/Atelier/app/Jobs/DispararCampanhaJob.php', [
    (
        r'''        $temPendente = $campanha->envios()
            ->where('estado', CampanhaEnvio::ESTADO_PENDENTE)
            ->exists();''',
        r'''        // Um envio incerto também mantém a campanha aberta (como na listener):
        // nunca se apresenta como concluída uma campanha com resultado desconhecido.
        $temPendente = $campanha->envios()
            ->whereIn('estado', [CampanhaEnvio::ESTADO_PENDENTE, CampanhaEnvio::ESTADO_INCERTO])
            ->exists();''',
    ),
])
print('Patches aplicados.')
PY

cat > Modules/Atelier/tests/Feature/ReconciliacaoEnvioIncertoTest.php <<'PHP'
<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Services\CampanhaReconciliador;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Models\User;
use Modules\Core\Services\RegistoEmpresaService;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Fase 6 da PR #33: um resultado desconhecido da Outbox nunca é apresentado como
 * entregue nem como falhado, nunca fecha a campanha e nunca provoca um reenvio.
 * A listener já fazia isto em tempo real; aqui prova-se que a rede de segurança
 * (reconciliador) e o fim do job seguem a mesma regra.
 */
class ReconciliacaoEnvioIncertoTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_em_resultado_desconhecido_torna_o_envio_incerto_e_mantem_a_campanha_aberta(): void
    {
        [$campanha, $envio] = $this->campanhaComUmEnvio('500001001', CampanhaEnvio::ESTADO_PENDENTE, NotificationOutbox::STATUS_UNKNOWN_RESULT);

        app(CampanhaReconciliador::class)->reconciliar($campanha);

        $this->assertSame(CampanhaEnvio::ESTADO_INCERTO, $envio->fresh()->estado);
        $this->assertSame(CampanhaEnvio::ERRO_INCERTO, $envio->fresh()->erro);
        $this->assertSame('em_processamento', $campanha->fresh()->estado);
        $this->assertNull($campanha->fresh()->enviada_em);
    }

    public function test_envio_incerto_resolvido_como_aceite_fecha_a_campanha(): void
    {
        [$campanha, $envio] = $this->campanhaComUmEnvio('500001002', CampanhaEnvio::ESTADO_INCERTO, NotificationOutbox::STATUS_ACCEPTED);

        app(CampanhaReconciliador::class)->reconciliar($campanha);

        $this->assertSame(CampanhaEnvio::ESTADO_ACEITE, $envio->fresh()->estado);
        $this->assertNull($envio->fresh()->erro);
        $this->assertSame('enviada', $campanha->fresh()->estado);
    }

    public function test_envio_incerto_resolvido_como_falhado_conclui_com_falhas(): void
    {
        [$campanha, $envio] = $this->campanhaComUmEnvio('500001003', CampanhaEnvio::ESTADO_INCERTO, NotificationOutbox::STATUS_FAILED, 'Fornecedor confirma que não recebeu.');

        app(CampanhaReconciliador::class)->reconciliar($campanha);

        $this->assertSame(CampanhaEnvio::ESTADO_FALHOU, $envio->fresh()->estado);
        $this->assertSame('concluida_com_falhas', $campanha->fresh()->estado);
    }

    public function test_envio_incerto_sem_decisao_nao_muda_nem_fecha_a_campanha(): void
    {
        [$campanha, $envio] = $this->campanhaComUmEnvio('500001004', CampanhaEnvio::ESTADO_INCERTO, NotificationOutbox::STATUS_UNKNOWN_RESULT);

        app(CampanhaReconciliador::class)->reconciliar($campanha, abandonarOrfaos: true);

        $this->assertSame(CampanhaEnvio::ESTADO_INCERTO, $envio->fresh()->estado);
        $this->assertSame('em_processamento', $campanha->fresh()->estado);
    }

    public function test_so_conta_a_outbox_incerta_da_propria_empresa(): void
    {
        [$campanha, $envio] = $this->campanhaComUmEnvio('500001005', CampanhaEnvio::ESTADO_PENDENTE, NotificationOutbox::STATUS_PENDING);
        $outraEmpresa = $this->registarAtelier('500001006');
        $alheia = $this->outbox((int) $outraEmpresa->empresa_id, NotificationOutbox::STATUS_UNKNOWN_RESULT);

        $envio->update(['notification_outbox_id' => $alheia->id]);

        app(CampanhaReconciliador::class)->reconciliar($campanha);

        $this->assertSame(CampanhaEnvio::ESTADO_PENDENTE, $envio->fresh()->estado);
    }

    public function test_o_job_nao_reenvia_um_envio_incerto_nem_fecha_a_campanha(): void
    {
        [$campanha, $envio] = $this->campanhaComUmEnvio('500001007', CampanhaEnvio::ESTADO_INCERTO, NotificationOutbox::STATUS_UNKNOWN_RESULT);

        app()->call([new DispararCampanhaJob($campanha->id), 'handle']);

        $this->assertSame(CampanhaEnvio::ESTADO_INCERTO, $envio->fresh()->estado);
        $this->assertSame('em_processamento', $campanha->fresh()->estado);
        $this->assertNull($campanha->fresh()->enviada_em);
        $this->assertSame(1, NotificationOutbox::withoutGlobalScopes()->count(), 'Nenhuma notificação nova pode ser criada.');
    }

    // ---------------------------------------------------------------------
    // Preparação
    // ---------------------------------------------------------------------

    /** @return array{0: Campanha, 1: CampanhaEnvio} */
    protected function campanhaComUmEnvio(string $nif, string $estadoEnvio, string $statusOutbox, ?string $erro = null): array
    {
        $admin = $this->registarAtelier($nif);
        $empresaId = (int) $admin->empresa_id;

        $campanha = Campanha::create([
            'empresa_id' => $empresaId,
            'nome' => 'Campanha '.$nif,
            'canal' => 'whatsapp',
            'segmento' => 'todos',
            'mensagem' => 'Mensagem de teste',
            'estado' => 'em_processamento',
            'criada_por_id' => $admin->id,
        ]);

        $cliente = Cliente::create(['empresa_id' => $empresaId, 'nome' => 'Cliente '.$nif, 'telefone' => '923456781']);
        ClienteCrm::create([
            'empresa_id' => $empresaId,
            'cliente_id' => $cliente->id,
            'aceita_marketing' => true,
            'consentimento_em' => now(),
            'consentimento_origem' => 'teste',
        ]);

        $envio = CampanhaEnvio::create([
            'campanha_id' => $campanha->id,
            'cliente_id' => $cliente->id,
            'canal' => $campanha->canal,
            'estado' => $estadoEnvio,
            'tentativa' => 1,
            'notification_outbox_id' => $this->outbox($empresaId, $statusOutbox, $erro)->id,
        ]);

        return [$campanha, $envio];
    }

    protected function registarAtelier(string $nif): User
    {
        $admin = app(RegistoEmpresaService::class)->registar(
            ['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif],
            ['name' => 'Admin '.$nif, 'email' => "admin{$nif}@example.test", 'password' => 'Senha-segura-123'],
            ['atelier'],
        );

        // Como num worker: sem tenant ativo; os modelos levam empresa_id explícito.
        app(TenantManager::class)->clear();

        return $admin;
    }

    protected function outbox(int $empresaId, string $status, ?string $erro = null): NotificationOutbox
    {
        return NotificationOutbox::withoutGlobalScopes()->create([
            'empresa_id' => $empresaId,
            'idempotency_key' => 'teste-'.Str::uuid(),
            'channel' => 'atelier.whatsapp',
            'recipient' => '244923456781',
            'payload' => ['mensagem' => 'Mensagem de teste'],
            'status' => $status,
            'max_attempts' => 5,
            'available_at' => now(),
            'last_error' => $erro,
        ]);
    }
}
PHP

FICHEIROS=(
  Modules/Atelier/app/Services/CampanhaReconciliador.php
  Modules/Atelier/app/Jobs/DispararCampanhaJob.php
  Modules/Atelier/tests/Feature/ReconciliacaoEnvioIncertoTest.php
)

for f in "${FICHEIROS[@]}"; do php -l "$f"; done

vendor/bin/pint "${FICHEIROS[@]}"

php artisan test --filter='ReconciliacaoEnvioIncertoTest|ReconciliacaoCampanhasTest|Reenvio|NotificacoesELandingTest'

echo
git status --short
echo
echo "Se os testes estiverem verdes:"
echo "  git add Modules/Atelier && git commit -m \"fix(atelier): envio incerto coerente no reconciliador e no job (fase 6 da PR #33)\" && git push"
