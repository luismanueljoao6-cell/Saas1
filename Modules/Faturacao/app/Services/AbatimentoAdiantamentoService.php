<?php

namespace Modules\Faturacao\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\GuardaEmissao;
use Throwable;

/**
 * Abate (parte de) um adiantamento já RECEBIDO (FA) numa fatura final (FT) do mesmo cliente.
 *
 * É contabilidade interna de saldos: o valor abatido deixa de contar como "em dívida" na FT
 * (ver ReciboService::resumoDaFatura). Não altera nenhum documento fiscal emitido.
 * Concorrência: as duas faturas são bloqueadas por ordem de id (sem deadlocks), na mesma
 * transação em que se valida e se grava — o mesmo lock que serializa os recibos.
 */
class AbatimentoAdiantamentoService
{
    public function __construct(protected ReciboService $recibos) {}

    /**
     * @param  string|int  $valor  em unidades monetárias (ex.: '500.00')
     * @param  string  $chave  chave de idempotência (8-64 caracteres: letras, números, _ e -)
     * @return array<string, mixed> linha do abatimento
     *
     * @throws Throwable
     */
    public function abater(
        int $empresaId,
        int $faturaAdiantamentoId,
        int $faturaFinalId,
        string|int $valor,
        string $chave,
    ): array {
        $centimos = Dinheiro::centimos($valor);

        if ($centimos <= 0) {
            throw new \InvalidArgumentException('O valor a abater tem de ser superior a zero.');
        }

        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $chave)) {
            throw new \InvalidArgumentException('Chave de idempotência inválida (8-64 caracteres: letras, números, _ e -).');
        }

        if ($faturaAdiantamentoId === $faturaFinalId) {
            throw new \InvalidArgumentException('O adiantamento e a fatura final têm de ser documentos diferentes.');
        }

        return DB::transaction(function () use ($empresaId, $faturaAdiantamentoId, $faturaFinalId, $centimos, $chave) {
            GuardaEmissao::garantir($empresaId);

            if ($resultado = $this->resolverExistente($empresaId, $chave, $faturaAdiantamentoId, $faturaFinalId, $centimos)) {
                return $resultado;
            }

            $faturas = Fatura::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->whereIn('id', [$faturaAdiantamentoId, $faturaFinalId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $fa = $faturas->get($faturaAdiantamentoId);
            $ft = $faturas->get($faturaFinalId);

            if (! $fa || ! $ft) {
                throw new \DomainException('As duas faturas têm de existir nesta empresa.');
            }

            if ($fa->tipoDocumentoFiscal() !== 'FA' || ! $fa->estaEmitido()) {
                throw new \DomainException('O adiantamento tem de ser uma fatura de adiantamento (FA) emitida.');
            }

            if ($ft->tipoDocumentoFiscal() !== 'FT' || ! $ft->estaEmitido()) {
                throw new \DomainException('A fatura final tem de ser uma fatura (FT) emitida.');
            }

            if ((int) $fa->cliente_id !== (int) $ft->cliente_id) {
                throw new \DomainException('O adiantamento e a fatura final têm de ser do mesmo cliente.');
            }

            $jaAbatidoDoFa = (int) DB::table('adiantamento_abatimentos')
                ->where('empresa_id', $empresaId)
                ->where('fatura_adiantamento_id', $faturaAdiantamentoId)
                ->sum('valor_centimos');

            // Notas de crédito já emitidas sobre o FA também reduzem o que ainda se pode abater.
            $resumoFa = $this->recibos->resumoDaFatura($fa);
            $disponivel = $resumoFa['recebido'] - $jaAbatidoDoFa - $resumoFa['creditado'];

            if ($centimos > $disponivel) {
                throw new \DomainException('O valor excede o adiantamento já recebido e ainda por abater.');
            }

            if ($centimos > $this->recibos->resumoDaFatura($ft)['saldo']) {
                throw new \DomainException('O valor excede o saldo em dívida da fatura final.');
            }

            try {
                $id = DB::table('adiantamento_abatimentos')->insertGetId([
                    'empresa_id' => $empresaId,
                    'fatura_adiantamento_id' => $faturaAdiantamentoId,
                    'fatura_final_id' => $faturaFinalId,
                    'valor_centimos' => $centimos,
                    'chave' => $chave,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Corrida perdida: outra requisição gravou a mesma chave entretanto.
                $resultado = $this->resolverExistente($empresaId, $chave, $faturaAdiantamentoId, $faturaFinalId, $centimos);

                if ($resultado) {
                    return $resultado;
                }

                throw $e;
            }

            Log::info('Adiantamento abatido', [
                'abatimento_id' => $id,
                'fatura_adiantamento_id' => $faturaAdiantamentoId,
                'fatura_final_id' => $faturaFinalId,
                'valor_centimos' => $centimos,
                'empresa_id' => $empresaId,
            ]);

            return (array) DB::table('adiantamento_abatimentos')->where('id', $id)->first();
        }, attempts: 5);
    }

    /** @return array<string, mixed>|null */
    protected function resolverExistente(
        int $empresaId, string $chave, int $faId, int $ftId, int $centimos,
    ): ?array {
        $existente = DB::table('adiantamento_abatimentos')
            ->where('empresa_id', $empresaId)
            ->where('chave', $chave)
            ->lockForUpdate()
            ->first();

        if (! $existente) {
            return null;
        }

        if ((int) $existente->fatura_adiantamento_id !== $faId
            || (int) $existente->fatura_final_id !== $ftId
            || (int) $existente->valor_centimos !== $centimos) {
            throw new \DomainException('Chave de idempotência reutilizada com dados diferentes.');
        }

        return (array) $existente;
    }
}
