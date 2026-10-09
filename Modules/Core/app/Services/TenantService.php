<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Models\Empresa;
use Throwable;

/**
 * Encapsula as transições de estado da subscrição de uma empresa. O Módulo
 * de Subscrições e Pagamentos deve chamar estes métodos a partir dos seus
 * webhooks/Jobs (ex.: quando a Multicaixa Express confirma um pagamento),
 * em vez de tocar diretamente nas colunas de Empresa.
 */
class TenantService
{
    public function ativar(Empresa $empresa, ?\DateTimeInterface $expiraEm = null): Empresa
    {
        return $this->transacionar($empresa, function (Empresa $empresa) use ($expiraEm) {
            $empresa->estado_subscricao = 'ativa';
            $empresa->subscricao_expira_em = $expiraEm;
            $empresa->periodo_tolerancia_ate = null;
            $empresa->save();

            return $empresa;
        }, 'ativar');
    }

    public function marcarPendente(Empresa $empresa): Empresa
    {
        return $this->transacionar($empresa, function (Empresa $empresa) {
            $empresa->estado_subscricao = 'pendente';
            $empresa->periodo_tolerancia_ate = null;
            $empresa->periodo_tolerancia_ate = null;
            $empresa->periodo_tolerancia_ate = null;
            $empresa->periodo_tolerancia_ate = null;
            $empresa->periodo_tolerancia_ate = null;
            $empresa->save();

            return $empresa;
        }, 'marcar como pendente');
    }

    public function suspender(Empresa $empresa): Empresa
    {
        return $this->transacionar($empresa, function (Empresa $empresa) {
            $dias = (int) Config::get('core.periodo_tolerancia_dias', 5);

            $empresa->estado_subscricao = 'suspensa';
            $empresa->periodo_tolerancia_ate = now()->addDays($dias);
            $empresa->save();

            return $empresa;
        }, 'suspender');
    }

    /**
     * Chamado normalmente por um Job agendado quando o período de tolerância
     * termina sem regularização do pagamento.
     */
    public function expirar(Empresa $empresa): Empresa
    {
        return $this->transacionar($empresa, function (Empresa $empresa) {
            $empresa->estado_subscricao = 'expirada';
            $empresa->periodo_tolerancia_ate = null;
            $empresa->periodo_tolerancia_ate = null;
            $empresa->periodo_tolerancia_ate = null;
            $empresa->periodo_tolerancia_ate = null;
            $empresa->periodo_tolerancia_ate = null;
            $empresa->save();

            return $empresa;
        }, 'expirar');
    }

    /**
     * Envolve cada transição em DB::transaction + log estruturado + captura
     * defensiva de exceções, para nunca deixar a empresa num estado a meio.
     */
    protected function transacionar(Empresa $empresa, callable $callback, string $acao): Empresa
    {
        try {
            return DB::transaction(function () use ($empresa, $callback, $acao) {
                $estadoAnterior = $empresa->estado_subscricao;

                /** @var Empresa $empresa */
                $empresa = $callback($empresa);

                Log::info("Subscrição da empresa alterada: {$acao}", [
                    'empresa_id' => $empresa->id,
                    'estado_anterior' => $estadoAnterior,
                    'estado_novo' => $empresa->estado_subscricao,
                ]);

                return $empresa;
            });
        } catch (Throwable $e) {
            Log::error("Falha ao {$acao} a subscrição da empresa", [
                'empresa_id' => $empresa->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
