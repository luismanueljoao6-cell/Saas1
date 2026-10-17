<?php

namespace Modules\Subscricoes\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Subscricoes\Models\Pagamento;
use Modules\Subscricoes\Models\WebhookEvento;
use Modules\Subscricoes\Services\PagamentoService;
use Throwable;

/**
 * Confirmação manual de um pagamento (ex.: transferência bancária, ou
 * pagamento recebido depois de a referência expirar / a renovação ser
 * cancelada). Exige, sempre:
 *   - operador que seja super admin ativo;
 *   - valor efetivamente recebido (comparado ao devido pelo PagamentoService);
 *   - referência do comprovativo/extrato bancário;
 *   - justificação (mínimo 15 caracteres).
 * Cada tentativa (sucesso ou recusa) fica na auditoria (activity log).
 * Um pagamento já confirmado não pode ser confirmado de novo.
 *
 *   php artisan subscricoes:confirmar-manual 42 \
 *       --operador=admin@exemplo.ao --valor=15000.00 \
 *       --comprovativo="BAI-2026-10-17-00123" \
 *       --justificacao="Transferência recebida após expirar a referência"
 */
class ConfirmarPagamentoManualCommand extends Command
{
    protected $signature = 'subscricoes:confirmar-manual
        {pagamento : ID do pagamento}
        {--operador= : E-mail do super admin que autoriza}
        {--valor= : Valor efetivamente recebido (ex.: 15000.00)}
        {--comprovativo= : Referência do comprovativo ou do extrato bancário}
        {--justificacao= : Motivo da confirmação manual (mín. 15 caracteres)}';

    protected $description = 'Confirma manualmente um pagamento, com autorização, comprovativo e auditoria';

    public function handle(TenantManager $tenantManager, PagamentoService $pagamentos): int
    {
        $email = trim((string) $this->option('operador'));
        $valor = trim((string) $this->option('valor'));
        $comprovativo = trim((string) $this->option('comprovativo'));
        $justificacao = trim((string) $this->option('justificacao'));

        if ($email === '' || $valor === '' || $comprovativo === '' || mb_strlen($justificacao) < 15) {
            $this->error('Obrigatórios: --operador, --valor, --comprovativo e --justificacao (mín. 15 caracteres).');

            return self::INVALID;
        }

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $valor)) {
            $this->error('--valor inválido. Usa ponto decimal e no máximo 2 casas (ex.: 15000.00).');

            return self::INVALID;
        }

        $operador = User::query()->where('email', $email)->first();

        if (! $operador || ! $operador->is_super_admin || ! $operador->ativo) {
            $this->error('Operador não autorizado: tem de ser um super admin ativo.');

            return self::FAILURE;
        }

        $pagamento = $tenantManager->semTenant(
            fn () => Pagamento::query()->find((int) $this->argument('pagamento'))
        );

        if (! $pagamento) {
            $this->error('Pagamento não encontrado.');

            return self::FAILURE;
        }

        if ($pagamento->estado === 'confirmado') {
            $this->error('Este pagamento já está confirmado; a operação não pode ser repetida.');

            return self::FAILURE;
        }

        $dados = [
            'operador_id' => $operador->id,
            'operador_email' => $operador->email,
            'estado_anterior' => $pagamento->estado,
            'valor_devido' => (string) $pagamento->valor,
            'valor_recebido' => $valor,
            'comprovativo' => $comprovativo,
            'justificacao' => $justificacao,
        ];

        try {
            $resultado = $pagamentos->confirmar($pagamento, [], $operador, $valor);
        } catch (Throwable $e) {
            activity('pagamentos')
                ->performedOn($pagamento)
                ->causedBy($operador)
                ->withProperties($dados + ['erro' => $e->getMessage()])
                ->log('confirmacao_manual_recusada');

            $this->error('Confirmação recusada: '.$e->getMessage());

            return self::FAILURE;
        }

        // Outro processo confirmou o pagamento entre a nossa leitura e o lock:
        // nada foi aplicado por este comando, por isso não o registamos como tal.
        if ((int) $resultado->confirmado_por !== (int) $operador->id) {
            $this->error('O pagamento foi confirmado entretanto por outro processo; nada foi alterado por este comando.');

            return self::FAILURE;
        }

        activity('pagamentos')
            ->performedOn($pagamento)
            ->causedBy($operador)
            ->withProperties($dados)
            ->log('confirmacao_manual');

        $this->resolverEventosDaReferencia($tenantManager, $pagamento);

        $this->info("Pagamento #{$pagamento->id} confirmado manualmente por {$operador->email}.");

        return self::SUCCESS;
    }

    /**
     * Webhooks rejeitados/falhados para a mesma referência deixam de ser
     * anomalias abertas na reconciliação: o pagamento já foi aplicado.
     */
    protected function resolverEventosDaReferencia(TenantManager $tenantManager, Pagamento $pagamento): void
    {
        if (! $pagamento->referencia_externa) {
            return;
        }

        try {
            $tenantManager->semTenant(fn () => WebhookEvento::query()
                ->where('gateway', $pagamento->gateway)
                ->where('referencia_externa', $pagamento->referencia_externa)
                ->whereIn('estado', [WebhookEvento::REJEITADO, WebhookEvento::FALHADO])
                ->update(['estado' => WebhookEvento::RESOLVIDO, 'processado_em' => now()]));
        } catch (Throwable $e) {
            Log::warning('Confirmação manual aplicada, mas não foi possível resolver os eventos de webhook', [
                'pagamento_id' => $pagamento->id,
                'erro' => $e->getMessage(),
            ]);

            $this->warn('Pagamento confirmado, mas os eventos de webhook não foram marcados como resolvidos (usa --resolver na reconciliação).');
        }
    }
}
