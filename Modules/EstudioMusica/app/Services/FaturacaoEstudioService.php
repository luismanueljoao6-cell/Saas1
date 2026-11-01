<?php

namespace Modules\EstudioMusica\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Models\Empresa;
use Modules\EstudioMusica\Models\CupomDesconto;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Models\SessaoEstudio;
use Modules\Faturacao\Contracts\FaturacaoGateway;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Support\Dinheiro;

/**
 * Ponte entre o EstudioMusica e o motor de faturação do Faturacao (secção
 * E). Nunca duplica a lógica fiscal: todos os documentos passam pelo gateway
 * público do módulo Faturação (com lock na fatura,
 * teto do saldo e numeração/hash sem lacunas).
 *
 * Todo o dinheiro é calculado em cêntimos inteiros (Dinheiro), nunca em float.
 * Os métodos que emitem lançam InvalidArgumentException (pedido inválido para este
 * projeto/sessão) ou DomainException (o motor de faturação recusou: limite mensal do
 * plano, subscrição inativa...) — quem chama tem de apanhar as duas.
 */
class FaturacaoEstudioService
{

    protected FaturacaoGateway $faturacao;

    public function __construct(FaturacaoGateway $faturacao)
    {
        $this->faturacao = $faturacao;
    }

    /**
     * Fatura de sinal/adiantamento (secção E: "30% a 50% para confirmar a
     * agenda"). Só faz sentido para projetos por PACOTE — um projeto por
     * hora não tem um total conhecido antes das sessões acontecerem (ver
     * ProjetoMusical::valorTotalEstimado()), por isso é cobrado sessão a
     * sessão em cobrarSessao(), sem sinal em separado.
     *
     * @throws InvalidArgumentException|DomainException
     */
    public function gerarFaturaSinal(ProjetoMusical $projeto): Fatura
    {
        $this->exigirCobrancaPorPacote($projeto);

        // Sinal/adiantamento não pode ser emitido como FT. A AGT prevê FA;
        // aguardar o suporte fiscal explícito desse tipo no módulo Faturação.
        if (config('faturacao.agt.ativo')) {
            throw new DomainException('A faturação de sinais do Estúdio ainda não está habilitada com a AGT ativa: o adiantamento deve usar o tipo FA, não uma fatura FT normal.');
        }

        return DB::transaction(function () use ($projeto) {
            // Lock na linha do projeto: um duplo clique (ou dois pedidos em
            // simultâneo) não pode emitir dois sinais — um documento fiscal
            // emitido não se apaga, só se anula com nota de crédito.
            $projeto = ProjetoMusical::query()->lockForUpdate()->findOrFail($projeto->id);

            $this->exigirProjetoAtivo($projeto);

            // "Viva" = emitida e não totalmente creditada: um sinal anulado por nota de crédito
            // pode ser emitido outra vez.
            if ($projeto->faturasAtivas('sinal')->isNotEmpty()) {
                throw new InvalidArgumentException('O sinal deste projeto já foi emitido.');
            }

            $percentual = $projeto->percentualSinalEfetivo();
            $sinal = $this->percentagemEmCentimos(Dinheiro::centimos($projeto->valor_pacote), $percentual);

            if ($sinal <= 0) {
                throw new InvalidArgumentException('O valor do sinal tem de ser superior a zero — confirma o valor do pacote e a percentagem do sinal.');
            }

            $fatura = $this->criarEEmitirFatura($projeto->empresa, $projeto->cliente, [[
                'descricao' => "Sinal ({$percentual}%) - Projeto \"{$projeto->nome}\"",
                'quantidade' => 1,
                'preco_unitario' => Dinheiro::formatar($sinal),
                'taxa_iva' => (float) config('faturacao.taxa_iva_geral'),
            ]]);

            $projeto->faturas()->attach($fatura->id, ['tipo' => 'sinal']);

            return $fatura;
        });
    }

    /**
     * Fatura do saldo restante (valor do pacote menos o que já foi
     * faturado — sinal incluído, líquido de notas de crédito). Idem: só para
     * projetos por pacote.
     *
     * @throws InvalidArgumentException|DomainException
     */
    public function gerarFaturaSaldoFinal(ProjetoMusical $projeto): Fatura
    {
        $this->exigirCobrancaPorPacote($projeto);

        return DB::transaction(function () use ($projeto) {
            // Mesmo lock do sinal — e o "restante" só é calculado DEPOIS de
            // o obter, para o segundo de dois pedidos simultâneos já ver a
            // fatura do primeiro e acabar em "sem saldo por faturar".
            $projeto = ProjetoMusical::query()->lockForUpdate()->findOrFail($projeto->id);

            $this->exigirProjetoAtivo($projeto);

            // Tudo sem IVA: o IVA é acrescentado pela fatura (sinal + saldo = pacote + IVA).
            $restante = Dinheiro::centimos($projeto->valor_pacote) - $projeto->faturadoSemIvaCentimos();

            if ($restante <= 0) {
                throw new InvalidArgumentException('Este projeto já não tem saldo por faturar.');
            }

            $fatura = $this->criarEEmitirFatura($projeto->empresa, $projeto->cliente, [[
                'descricao' => "Saldo final - Projeto \"{$projeto->nome}\"",
                'quantidade' => 1,
                'preco_unitario' => Dinheiro::formatar($restante),
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
     *
     * O valor é calculado ao cêntimo a partir dos MINUTOS (minutos × preço/hora ÷ 60) e vai
     * numa só linha — antes a duração era arredondada a milésimos de hora (20 min = 0,333 h)
     * e o desconto aplicado ao preço unitário, o que fazia perder cêntimos em cada sessão.
     *
     * @throws InvalidArgumentException|DomainException
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
        $minutos = $sessao->duracaoMinutos();
        $precoHora = Dinheiro::centimos($sala->preco_hora);
        $total = intdiv($minutos * $precoHora + 30, 60);

        // Sala gratuita ou sessão sem duração: não há nada a cobrar, e um
        // documento fiscal de valor zero não faz sentido.
        if ($total <= 0) {
            return null;
        }

        $descricao = sprintf(
            'Sessão de estúdio - %s - %s (%s a %s/h)',
            $sala->nome,
            $sessao->rotuloTipoServico(),
            $this->duracaoLegivel($minutos),
            Dinheiro::formatar($precoHora),
        );

        $precoUnitarioBase = $total;

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
            $liquido = $total - $this->percentagemEmCentimos($total, $percentual);

            if ($liquido > 0) {
                $total = $liquido;
                $descricao .= ' — desconto '.rtrim(rtrim(number_format($percentual, 2, '.', ''), '0'), '.')."% (cupão {$cupom->codigo})";
            } else {
                $cupom = null; // desconto total: não gasta o cupão numa fatura que não se emite
            }
        }

        $fatura = $this->criarEEmitirFatura($sessao->empresa, $sessao->cliente, [[
            'descricao' => $descricao,
            'quantidade' => 1,
            'preco_unitario' => Dinheiro::formatar($total),
            'preco_unitario_base' => Dinheiro::formatar($precoUnitarioBase),
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
     * DESTE projeto. Sem valor, recebe o que ainda falta na fatura (já deduzidas as notas
     * de crédito e os recibos anteriores). A emissão (lock, teto do saldo, numeração e
     * hash) é responsabilidade do FaturacaoGateway.
     *
     * @throws InvalidArgumentException|DomainException
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
                // seria recusado se já houver recebimentos parciais ou notas de crédito).
                $emFalta = $projeto->saldoDaFaturaCentimos($fatura);

                if ($emFalta <= 0) {
                    throw new InvalidArgumentException('Esta fatura já não tem valor por receber.');
                }

                $valor = Dinheiro::formatar($emFalta);
            }

            $recibo = $this->faturacao->emitirRecibo(
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


    /**
     * @param  array<int, array{descricao: string, quantidade: int|float, preco_unitario: string|float, taxa_iva: float}>  $linhas
     */
    protected function criarEEmitirFatura(Empresa $empresa, Cliente $cliente, array $linhas): Fatura
    {
        $fatura = $this->faturacao->criarRascunho($empresa, $cliente, $linhas);

        return $this->faturacao->emitirFatura($fatura);
    }

    /** $percentual% de um valor em cêntimos, arredondado a meio para cima (40,00% de 100000,00 → 40000,00). */
    protected function percentagemEmCentimos(int $centimos, float $percentual): int
    {
        $pontosBase = (int) round($percentual * 100);

        return intdiv($centimos * $pontosBase + 5000, 10000);
    }

    /** 80 → "1h20min"; 60 → "1h"; 45 → "45min". */
    protected function duracaoLegivel(int $minutos): string
    {
        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        return match (true) {
            $horas === 0 => "{$resto}min",
            $resto === 0 => "{$horas}h",
            default => "{$horas}h{$resto}min",
        };
    }

    protected function exigirProjetoAtivo(ProjetoMusical $projeto): void
    {
        if ($projeto->estado === 'cancelado') {
            throw new InvalidArgumentException('Um projeto cancelado não pode ser faturado.');
        }
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
