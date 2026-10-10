<?php

namespace Modules\Faturacao\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
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
    public function __construct(
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
    ) {}

    /**
     * @param  string|int|float  $valor  em unidades monetárias (ex.: '500.00')
     * @param  int|null  $faturaId  nulo para recibos de adiantamento
     *
     * @throws Throwable
     */
    public function emitir(
        int $empresaId,
        int $clienteId,
        string|int|float $valor,
        string $meioPagamento,
        ?int $faturaId = null,
    ): Recibo {
        $centimos = Dinheiro::centimos($valor);

        if ($centimos <= 0) {
            throw new \InvalidArgumentException('O valor do recibo tem de ser superior a zero.');
        }

        if (trim($meioPagamento) === '') {
            throw new \InvalidArgumentException('O meio de pagamento é obrigatório.');
        }

        try {
            return DB::transaction(function () use ($empresaId, $clienteId, $centimos, $meioPagamento, $faturaId) {
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
                    'serie_id' => $this->numeracaoService->obterOuCriarSerie($empresaId, 'RC')->id,
                    'cliente_id' => $clienteId,
                    'fatura_id' => $faturaId,
                    'estado' => 'rascunho',
                    'valor' => Dinheiro::formatar($centimos),
                    'moeda' => 'AOA',
                    'meio_pagamento' => $meioPagamento,
                ]);

                $resultado = $this->numeracaoService->proximoNumero($empresaId, 'RC');

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

        $jaRecebido = Recibo::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->where('fatura_id', $faturaId)
            ->where('estado', 'emitido')
            ->get(['valor'])
            ->sum(fn ($recibo) => Dinheiro::centimos($recibo->valor));

        if ($jaRecebido + $centimos > Dinheiro::centimos($fatura->valor_total)) {
            throw new \DomainException('O total recebido excede o valor da fatura.');
        }
    }
}
