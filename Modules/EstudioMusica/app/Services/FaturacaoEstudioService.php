<?php

namespace Modules\EstudioMusica\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\CupomDesconto;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NumeracaoService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Dinheiro;

/**
 * Ponte entre o EstudioMusica e o motor de faturação do Faturacao (secção
 * E). Nunca duplica a lógica fiscal: Faturas passam pelo FaturaService e
 * Recibos pelo ReciboService (caminho único de emissão, com lock na fatura,
 * teto do saldo e numeração/hash sem lacunas).
 */
class FaturacaoEstudioService
{
    public function __construct(
        protected FaturaService $faturaService,
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
        protected TenantManager $tenantManager,
        protected ?ReciboService $reciboService = null,
    ) {}

    /**
     * Fatura de sinal/adiantamento (secção E: "30% a 50% para confirmar a
     * agenda"). Só faz sentido para projetos por PACOTE — um projeto por
     * hora não tem um total conhecido antes das sessões acontecerem (ver
     * ProjetoMusical::valorTotalEstimado()), por isso é cobrado sessão a
     * sessão em cobrarSessao(), sem sinal em separado.
     *
     * @throws InvalidArgumentException
     */
    public function gerarFaturaSinal(ProjetoMusical $projeto): Fatura
    {
        $this->exigirCobrancaPorPacote($projeto);

        return DB::transaction(function () use ($projeto) {
            // Lock na linha do projeto: um duplo clique (ou dois pedidos em
            // simultâneo) não pode emitir dois sinais — um documento fiscal
            // emitido não se apaga, só se anula com nota de crédito.
            $projeto = ProjetoMusical::query()->lockForUpdate()->findOrFail($projeto->id);

            $jaTemSinal = $projeto->faturas()
                ->wherePivot('tipo', 'sinal')
                ->where('faturas.estado', 'emitida')
                ->exists();

            if ($jaTemSinal) {
                throw new InvalidArgumentException('O sinal deste projeto já foi emitido.');
            }

            $percentual = $projeto->percentualSinalEfetivo();
            $valor = round($projeto->valorTotalEstimado() * $percentual / 100, 2);

            if ($valor <= 0.0) {
                throw new InvalidArgumentException('O valor do sinal tem de ser superior a zero — confirma o valor do pacote e a percentagem do sinal.');
            }

            $fatura = $this->criarEEmitirFatura($projeto->empresa, $projeto->cliente, [[
                'descricao' => "Sinal ({$percentual}%) - Projeto \"{$projeto->nome}\"",
                'quantidade' => 1,
                'preco_unitario' => $valor,
                'taxa_iva' => (float) config('faturacao.taxa_iva_geral'),
            ]]);

            $projeto->faturas()->attach($fatura->id, ['tipo' => 'sinal']);

            return $fatura;
        });
    }

    /**
     * Fatura do saldo restante (valor do pacote menos o que já foi
     * faturado — sinal incluído). Idem: só para projetos por pacote.
     *
     * @throws InvalidArgumentException
     */
    public function gerarFaturaSaldoFinal(ProjetoMusical $projeto): Fatura
    {
        $this->exigirCobrancaPorPacote($projeto);

        return DB::transaction(function () use ($projeto) {
            // Mesmo lock do sinal — e o "restante" só é calculado DEPOIS de
            // o obter, para o segundo de dois pedidos simultâneos já ver a
            // fatura do primeiro e acabar em "sem saldo por faturar".
            $projeto = ProjetoMusical::query()->lockForUpdate()->findOrFail($projeto->id);

            // Tudo sem IVA: o IVA é acrescentado pela fatura (sinal + saldo = pacote + IVA).
            $restante = max(0.0, round($projeto->valorTotalEstimado() - $projeto->valorTotalFaturadoSemIva(), 2));

            if ($restante <= 0.0) {
                throw new InvalidArgumentException('Este projeto já não tem saldo por faturar.');
            }

            $fatura = $this->criarEEmitirFatura($projeto->empresa, $projeto->cliente, [[
                'descricao' => "Saldo final - Projeto \"{$projeto->nome}\"",
                'quantidade' => 1,
                'preco_unitario' => $restante,
                'taxa_iva' => (float) config('faturacao.taxa_iva_geral'),
            ]]);

            $projeto->faturas()->attach($fatura->id, ['tipo' => 'saldo_final']);

            return $fatura;
        });
    }

    /**
     * Cobrança por hora de uma sessão já com check-out feito (secção E,
     * ponto 1). Sessões de projetos por PACOTE não passam por aqui — as
     * horas dessas sessões já estão cobertas pelo sinal/saldo final do
     * projeto; devolve null nesse caso para o controller saber que não há
     * nada novo para mostrar ao utilizador.
     */
    public function cobrarSessao(SessaoEstudio $sessao): ?Fatura
    {
        $projeto = $sessao->projetoMusical;

        if ($projeto && $projeto->tipo_cobranca === 'pacote') {
            return null;
        }

        // Idempotência: uma sessão só gera UMA fatura por hora, mesmo que o
        // check-out seja chamado duas vezes (ver também o lock no controller).
        if ($sessao->fatura_id) {
            return null;
        }

        $sala = $sessao->salaEstudio;
        $horas = $sessao->duracaoHoras();

        // Sala gratuita ou sessão sem duração: não há nada a cobrar, e um
        // documento fiscal de valor zero não faz sentido.
        if (round($horas * (float) $sala->preco_hora, 2) <= 0.0) {
            return null;
        }

        $descricao = "Sessão de estúdio - {$sala->nome} - {$sessao->rotuloTipoServico()} ({$horas}h)";
        $precoUnitario = (float) $sala->preco_hora;

        // Cupão de aniversário (secção F): "X% de desconto em horas de
        // estúdio" — aplicado aqui, na cobrança por hora, ao cupão válido
        // que expira primeiro. Corre dentro da transação do check-out, por
        // isso o lock impede o mesmo cupão de ser usado em duas faturas.
        $cupom = CupomDesconto::query()
            ->where('cliente_id', $sessao->cliente_id)
            ->whereNull('usado_em')
            ->whereDate('valido_ate', '>=', now()->toDateString())
            ->orderBy('valido_ate')
            ->lockForUpdate()
            ->first();

        if ($cupom) {
            $percentual = (float) $cupom->percentual_desconto;
            $precoUnitario = round($precoUnitario * (1 - $percentual / 100), 2);
            $descricao .= " — desconto {$percentual}% (cupão {$cupom->codigo})";
        }

        $fatura = $this->criarEEmitirFatura($sessao->empresa, $sessao->cliente, [[
            'descricao' => $descricao,
            'quantidade' => $horas,
            'preco_unitario' => $precoUnitario,
            'taxa_iva' => (float) config('faturacao.taxa_iva_geral'),
        ]]);

        $cupom?->marcarComoUsado();
        $sessao->forceFill(['fatura_id' => $fatura->id])->save();

        if ($projeto) {
            $projeto->faturas()->attach($fatura->id, ['tipo' => 'avulso']);
        }

        return $fatura;
    }

    /**
     * Regista o recebimento de um pagamento contra uma fatura já emitida
     * DESTE projeto. Sem valor, recebe o que ainda falta na fatura. A emissão
     * (lock, teto do saldo, numeração e hash) é do ReciboService.
     *
     * @throws InvalidArgumentException|\DomainException
     */
    public function registrarRecibo(ProjetoMusical $projeto, Fatura $fatura, string $meioPagamento, ?float $valor = null): Recibo
    {
        return DB::transaction(function () use ($projeto, $fatura, $meioPagamento, $valor) {
            $pertenceAoProjeto = (int) $fatura->empresa_id === (int) $projeto->empresa_id
                && $projeto->faturas()->where('faturas.id', $fatura->id)->exists();

            if (! $pertenceAoProjeto) {
                throw new InvalidArgumentException('A fatura não pertence a este projeto.');
            }

            if ($valor === null) {
                // Por omissão recebe o que falta (e não o total da fatura, que
                // seria recusado se já houver recebimentos parciais).
                $jaRecebido = Recibo::withoutGlobalScopes()
                    ->where('empresa_id', $fatura->empresa_id)
                    ->where('fatura_id', $fatura->id)
                    ->where('estado', 'emitido')
                    ->get(['valor'])
                    ->sum(fn ($recibo) => Dinheiro::centimos($recibo->valor));

                $valor = Dinheiro::formatar(Dinheiro::centimos($fatura->valor_total) - $jaRecebido);
            }

            $recibo = $this->recibos()->emitir(
                (int) $fatura->empresa_id,
                (int) $fatura->cliente_id,
                $valor,
                $meioPagamento,
                (int) $fatura->id,
            );

            $projeto->recibos()->attach($recibo->id);

            return $recibo;
        });
    }

    protected function recibos(): ReciboService
    {
        return $this->reciboService ?? app(ReciboService::class);
    }

    /**
     * @param  array<int, array{descricao: string, quantidade: float, preco_unitario: float, taxa_iva: float}>  $linhas
     */
    protected function criarEEmitirFatura(Empresa $empresa, Cliente $cliente, array $linhas): Fatura
    {
        $fatura = $this->faturaService->criarRascunho($empresa, $cliente, $linhas);

        return $this->faturaService->emitir($fatura);
    }

    protected function exigirCobrancaPorPacote(ProjetoMusical $projeto): void
    {
        if ($projeto->tipo_cobranca !== 'pacote') {
            throw new InvalidArgumentException(
                'Sinal e saldo final só se aplicam a projetos com cobrança por pacote — este projeto é cobrado por hora, sessão a sessão.'
            );
        }
    }
}
