<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\GuardaEmissao;
use Throwable;

/**
 * Caminho ÚNICO de emissão de Recibos (Atelier, EstudioMusica e qualquer
 * módulo futuro). Numeração sem lacunas + cadeia de hash + assinatura, tudo
 * numa transação, com os mesmos cuidados do FaturaService.
 */
class ReciboService
{
    /** Valores aceites pelo contrato persistido em recibos.meio_pagamento. */
    private const MEIOS_PAGAMENTO = ['dinheiro', 'transferencia', 'multicaixa', 'outro'];

    public function __construct(
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
    ) {}

    /**
     * @param  string|int  $valor  em unidades monetárias (ex.: '500.00')
     * @param  int|null  $faturaId  nulo para recibos de adiantamento
     *
     * @throws Throwable
     */
    public function emitir(
        int $empresaId,
        int $clienteId,
        string|int $valor,
        string $meioPagamento,
        ?int $faturaId = null,
    ): Recibo {
        return $this->emitirTipo('RC', $empresaId, $clienteId, $valor, $meioPagamento, $faturaId);
    }

    /**
     * Recibo de regularização (RG): refere SEMPRE a fatura que regulariza e, com a AGT ativa,
     * segue como `paymentReceipt` (a AGT só aceita paymentReceipt em AR/RG). Usa série própria
     * do tipo RG (com a AGT ativa, a série RG tem de estar autorizada: AgtSerieService::solicitar).
     *
     * @param  string|int  $valor  em unidades monetárias (ex.: '500.00')
     *
     * @throws Throwable
     */
    public function emitirRegularizacao(
        int $empresaId,
        int $clienteId,
        string|int $valor,
        string $meioPagamento,
        int $faturaId,
    ): Recibo {
        return $this->emitirTipo('RG', $empresaId, $clienteId, $valor, $meioPagamento, $faturaId);
    }

    /** @throws Throwable */
    protected function emitirTipo(
        string $tipo,
        int $empresaId,
        int $clienteId,
        string|int $valor,
        string $meioPagamento,
        ?int $faturaId,
    ): Recibo {
        $centimos = Dinheiro::centimos($valor);

        if ($centimos <= 0) {
            throw new \InvalidArgumentException('O valor do recibo tem de ser superior a zero.');
        }

        if (! in_array($meioPagamento, self::MEIOS_PAGAMENTO, true)) {
            throw new \InvalidArgumentException('Meio de pagamento inválido. Usa dinheiro, transferencia, multicaixa ou outro.');
        }

        if ($tipo === 'RG' && $faturaId === null) {
            throw new \InvalidArgumentException('Um recibo de regularização (RG) tem de referir a fatura que regulariza.');
        }

        // O modelo Recibo emite RC. A especificação AGT exige "lines" para RC,
        // enquanto "paymentReceipt" só é permitido para AR/RG. Como este modelo
        // ainda não guarda linhas fiscais próprias nem selecciona RG, bloquear a
        // emissão com AGT activa é mais seguro do que criar um documento impossível
        // de submeter ou enviar um payload estruturalmente inválido.
        if ($tipo === 'RC' && config('faturacao.agt.ativo')) {
            throw new \DomainException('A emissão de recibos RC ainda não está habilitada com a AGT ativa: é necessário implementar a estrutura RC (linhas) ou o tipo RG (paymentReceipt) conforme a especificação AGT.');
        }

        try {
            return DB::transaction(function () use ($tipo, $empresaId, $clienteId, $centimos, $meioPagamento, $faturaId) {
                GuardaEmissao::garantir($empresaId);

                $cliente = Cliente::withoutGlobalScopes()->where('empresa_id', $empresaId)->find($clienteId);

                if (! $cliente) {
                    throw new \DomainException('O cliente não pertence a esta empresa.');
                }

                if ($faturaId !== null) {
                    $this->validarFatura($empresaId, $clienteId, $faturaId, $centimos);
                }

                $recibo = Recibo::create([
                    'empresa_id' => $empresaId,
                    'tipo_documento' => $tipo,
                    'serie_id' => $this->numeracaoService->obterOuCriarSerie($empresaId, $tipo)->id,
                    'cliente_id' => $clienteId,
                    'fatura_id' => $faturaId,
                    'estado' => 'rascunho',
                    'valor' => Dinheiro::formatar($centimos),
                    'moeda' => 'AOA',
                    'meio_pagamento' => $meioPagamento,
                ]);

                $resultado = $this->numeracaoService->proximoNumero($empresaId, $tipo);

                $hashAnterior = $this->numeracaoService->hashDoDocumentoAnterior(
                    Recibo::class,
                    $resultado['serie']->id,
                    $resultado['numero_sequencial'],
                );

                $recibo->serie_id = $resultado['serie']->id;

                $this->assinaturaFiscalService->assinarEEmitir(
                    $recibo,
                    $resultado['numero_sequencial'],
                    $resultado['numero_documento'],
                    now(),
                    $hashAnterior,
                );

                $recibo->save();

                Log::info('Recibo emitido', [
                    'recibo_id' => $recibo->id,
                    'numero_documento' => $recibo->numero_documento,
                    'empresa_id' => $empresaId,
                    'fatura_id' => $faturaId,
                ]);

                return Recibo::withoutGlobalScopes()->findOrFail($recibo->id);
            }, attempts: 5);
        } catch (Throwable $e) {
            Log::error('Falha ao emitir recibo', [
                'empresa_id' => $empresaId,
                'fatura_id' => $faturaId,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /** Lock na fatura serializa recibos concorrentes; o total recebido nunca excede o da fatura. */
    protected function validarFatura(int $empresaId, int $clienteId, int $faturaId, int $centimos): void
    {
        $fatura = Fatura::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->lockForUpdate()
            ->find($faturaId);

        if (! $fatura || ! $fatura->estaEmitido()) {
            throw new \DomainException('A fatura tem de existir nesta empresa e estar emitida.');
        }

        if ((int) $fatura->cliente_id !== $clienteId) {
            throw new \DomainException('O cliente do recibo tem de ser o da fatura.');
        }

        [$jaRecebido, $creditado, $debitado, $abatido] = $this->recebidoECreditado($empresaId, $faturaId);

        if ($jaRecebido + $centimos > Dinheiro::centimos($fatura->valor_total) + $debitado - $creditado - $abatido) {
            throw new \DomainException('O total recebido excede o valor em dívida da fatura (já deduzidas as notas de crédito).');
        }
    }

    /**
     * Valor em dívida: total + notas de débito − recibos − notas de crédito − abatimentos.
     */
    public function saldoEmCentimos(Fatura $fatura): int
    {
        return $this->resumoDaFatura($fatura)['saldo'];
    }

    /** @return array{recebido: int, creditado: int, abatido: int, saldo: int} em cêntimos */
    public function resumoDaFatura(Fatura $fatura): array
    {
        [$recebido, $creditado, $debitado, $abatido] = $this->recebidoECreditado((int) $fatura->empresa_id, (int) $fatura->id);

        return [
            'recebido' => $recebido,
            'creditado' => $creditado,
                        'abatido' => $abatido,
            'saldo' => Dinheiro::centimos($fatura->valor_total) + $debitado - $recebido - $creditado - $abatido,
        ];
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} [recebido, creditado, debitado, abatido por adiantamentos] em cêntimos */
    protected function recebidoECreditado(int $empresaId, int $faturaId): array
    {
        $recebido = Recibo::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('fatura_id', $faturaId)
            ->where('estado', 'emitido')
            ->get(['valor'])
            ->sum(fn ($recibo) => Dinheiro::centimos($recibo->valor));

        $creditado = NotaCreditoDebito::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('fatura_id', $faturaId)
            ->where('tipo', 'credito')
            ->where('estado', 'emitida')
            ->get(['valor_total'])
            ->sum(fn ($nota) => Dinheiro::centimos($nota->valor_total));

        $debitado = NotaCreditoDebito::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('fatura_id', $faturaId)
            ->where('tipo', 'debito')
            ->where('estado', 'emitida')
            ->get(['valor_total'])
            ->sum(fn ($nota) => Dinheiro::centimos($nota->valor_total));

        $abatido = (int) DB::table('adiantamento_abatimentos')
            ->where('empresa_id', $empresaId)
            ->where('fatura_final_id', $faturaId)
            ->sum('valor_centimos');

        return [(int) $recebido, (int) $creditado, (int) $debitado, $abatido];
    }
}
