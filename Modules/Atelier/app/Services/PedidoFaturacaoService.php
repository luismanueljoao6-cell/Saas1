<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NumeracaoService;
use Throwable;

/**
 * A ponte entre o Atelier e o motor fiscal do Faturacao (requisito B).
 *
 * Duas decisões de arquitetura que vale a pena explicar:
 *
 * 1) O SINAL vira um Recibo com fatura_id nulo, não uma "fatura proforma".
 *    A migration de `recibos` já previa exatamente este caso ("Nulo para
 *    recibos de adiantamento, sem fatura associada ainda") — uma proforma
 *    não é um documento fiscal AGT (não entra na cadeia de numeração/hash),
 *    por isso não faz sentido modelá-la como Fatura. Ao usar Recibo aqui,
 *    este módulo acaba por ser o primeiro a preencher um gap que o próprio
 *    README do Faturacao já assinalava (Recibo não tinha nenhum consumidor
 *    além do model).
 *
 * 2) Não existe ReciboService no módulo Faturacao — por isso este serviço
 *    fala diretamente com NumeracaoService e AssinaturaFiscalService,
 *    replicando o mesmo procedimento de duas fases que FaturaService::emitir()
 *    já usa para Fatura (ambos operam só sobre DocumentoFiscalInterface,
 *    exatamente para permitir isto). Preferi isto a alterar ficheiros do
 *    módulo Faturacao para lá acrescentar um ReciboService — mantém este
 *    módulo self-contained, ao custo de ~30 linhas duplicadas.
 *
 * A fatura final é sempre criada como RASCUNHO e a emissão fica a cargo do
 * ecrã normal de faturas (Modules\Faturacao) — não duplicamos aqui a UI de
 * revisão/emissão que já existe lá.
 */
class PedidoFaturacaoService
{
    public function __construct(
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
        protected FaturaService $faturaService,
        protected TenantManager $tenantManager,
    ) {}

    /**
     * @throws PedidoException|Throwable
     */
    public function registarSinal(Pedido $pedido, float $valor, string $meioPagamento): Recibo
    {
        if ($pedido->jaTemSinalRegistado()) {
            throw PedidoException::sinalJaRegistado();
        }

        if ($valor <= 0) {
            throw PedidoException::valorInvalido();
        }

        return DB::transaction(function () use ($pedido, $valor, $meioPagamento) {
            $recibo = $this->emitirRecibo($pedido, $valor, $meioPagamento, faturaId: null);

            $pedido->update([
                'valor_sinal' => $valor,
                'recibo_sinal_id' => $recibo->id,
            ]);

            return $recibo;
        });
    }

    /**
     * Cria o rascunho da fatura final com uma linha para o serviço/peça
     * (valor_orcamento, à taxa guardada no próprio pedido — nunca a taxa
     * "atual" da config, ver comentário na migration de atelier_pedidos) e
     * uma linha por cada material extra cobrado.
     *
     * @throws PedidoException
     */
    public function gerarFaturaFinalRascunho(Pedido $pedido, ?string $observacoes = null): Fatura
    {
        if ($pedido->jaTemFaturaFinal()) {
            throw PedidoException::faturaFinalJaGerada();
        }

        $cliente = $pedido->cliente;

        if (! $cliente) {
            throw PedidoException::clienteSemDadosFaturacao();
        }

        $linhas = [
            [
                'descricao' => "{$pedido->tipoServicoRotulo()}: {$pedido->descricao}",
                'quantidade' => 1,
                'preco_unitario' => (float) $pedido->valor_orcamento,
                'taxa_iva' => (float) $pedido->taxa_iva_aplicada,
            ],
        ];

        foreach ($pedido->materiais as $material) {
            $linhas[] = [
                'descricao' => $material->descricao,
                'quantidade' => (float) $material->quantidade,
                'preco_unitario' => (float) $material->valor_unitario,
                'taxa_iva' => (float) $pedido->taxa_iva_aplicada,
            ];
        }

        $fatura = $this->faturaService->criarRascunho($pedido->empresa, $cliente, $linhas, $observacoes);

        $pedido->update(['fatura_id' => $fatura->id]);

        return $fatura;
    }

    /**
     * Regista o recebimento do saldo (valor_total da fatura já emitida
     * menos o sinal já recebido) como um Recibo ligado a essa fatura.
     *
     * @throws PedidoException|Throwable
     */
    public function registarPagamentoFinal(Pedido $pedido, string $meioPagamento): Recibo
    {
        if (! $pedido->jaTemFaturaFinal()) {
            throw new PedidoException('É preciso gerar e emitir a fatura final antes de registar o pagamento do saldo.');
        }

        $fatura = $pedido->fatura()->firstOrFail();

        if (! $fatura->estaEmitido()) {
            throw new PedidoException('A fatura final ainda não foi emitida — emite-a no módulo Faturação antes de registar o pagamento.');
        }

        $valor = $pedido->saldoEmFalta();

        if ($valor <= 0) {
            throw PedidoException::valorInvalido();
        }

        return DB::transaction(function () use ($pedido, $valor, $meioPagamento, $fatura) {
            $recibo = $this->emitirRecibo($pedido, $valor, $meioPagamento, faturaId: $fatura->id);

            $pedido->update(['recibo_saldo_final_id' => $recibo->id]);

            return $recibo;
        });
    }

    /**
     * Réplica deliberada do procedimento de FaturaService::emitir(), só que
     * para Recibo — ver o ponto 2) na doc desta classe.
     */
    protected function emitirRecibo(Pedido $pedido, float $valor, string $meioPagamento, ?int $faturaId): Recibo
    {
        $empresaId = $pedido->empresa_id;

        $recibo = Recibo::create([
            'empresa_id' => $empresaId,
            'serie_id' => $this->numeracaoService->obterOuCriarSerie($empresaId, 'RC')->id,
            'cliente_id' => $pedido->cliente_id,
            'fatura_id' => $faturaId,
            'estado' => 'rascunho',
            'valor' => $valor,
            'moeda' => 'AOA',
            'meio_pagamento' => $meioPagamento,
        ]);

        try {
            $resultado = $this->numeracaoService->proximoNumero($empresaId, 'RC');

            $hashAnterior = $this->tenantManager->semTenant(
                fn () => Recibo::query()
                    ->where('serie_id', $resultado['serie']->id)
                    ->where('numero_sequencial', $resultado['numero_sequencial'] - 1)
                    ->value('hash')
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

            Log::info('Recibo emitido a partir de um pedido do Atelier', [
                'recibo_id' => $recibo->id,
                'pedido_id' => $pedido->id,
                'numero_documento' => $recibo->numero_documento,
            ]);

            return $recibo->fresh();
        } catch (Throwable $e) {
            Log::error('Falha ao emitir recibo do Atelier', [
                'pedido_id' => $pedido->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
