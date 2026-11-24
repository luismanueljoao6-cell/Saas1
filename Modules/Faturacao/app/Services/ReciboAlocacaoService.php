<?php

namespace Modules\Faturacao\Services;

use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\FaturaLinha;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\GuardaEmissao;
use Throwable;

/**
 * Regista, de forma atómica e idempotente, as alocações de um recibo (AR/RG) às linhas das
 * faturas de origem: a fotografia fiscal imutável de onde o `paymentReceipt` há de ser montado.
 *
 * O que faz: valida empresa, recibo, faturas e linhas; bloqueia por ordem (recibo, faturas, linhas);
 * impede a sobre-alocação por linha e exige que a soma dos brutos iguale o valor do recibo.
 * O que NÃO faz: não decide o rateio de um pagamento entre líquido e IVA (isso vem do chamador,
 * em cêntimos inteiros), não emite nem assina o recibo e não desbloqueia RG/AR.
 *
 * Limite conhecido: o disponível de cada linha é medido contra os valores originais da linha e as
 * alocações já feitas; a interacção com notas de crédito/débito por linha fica por definir.
 */
class ReciboAlocacaoService
{
    /** Tipos que, segundo a documentação AGT consultada, levam `paymentReceipt` em vez de `lines`. */
    public const TIPOS_COM_PAYMENT_RECEIPT = ['AR', 'RG'];

    private const LIMITE_CENTIMOS = 900_000_000_000_000_000;

    /**
     * @param  array<int, array{fatura_linha_id: int, liquido_centimos: int, imposto_centimos: int}>  $alocacoes
     * @param  string  $chave  chave de idempotência (8-64 caracteres: letras, números, _ e -)
     * @return array<int, array<string, mixed>> alocações persistidas, por `sequencia`
     *
     * @throws Throwable
     */
    public function registar(int $empresaId, int $reciboId, array $alocacoes, string $chave): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $chave)) {
            throw new InvalidArgumentException('Chave de idempotência inválida (8-64 caracteres: letras, números, _ e -).');
        }

        $pedido = $this->normalizar($alocacoes);

        return DB::transaction(function () use ($empresaId, $reciboId, $pedido, $chave) {
            GuardaEmissao::garantir($empresaId);

            // O recibo é bloqueado primeiro: serializa pedidos repetidos ou concorrentes do mesmo
            // recibo, e só depois se procura a chave (o perdedor da corrida encontra o que o
            // vencedor gravou e devolve-o em vez de falhar).
            $recibo = Recibo::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->whereKey($reciboId)
                ->lockForUpdate()
                ->first();

            if (! $recibo) {
                throw new DomainException('O recibo tem de existir nesta empresa.');
            }

            if (($existente = $this->resolverExistente($empresaId, $chave, $reciboId, $pedido)) !== null) {
                return $existente;
            }

            if (! in_array($recibo->tipoDocumentoFiscal(), self::TIPOS_COM_PAYMENT_RECEIPT, true)) {
                throw new DomainException('Só os recibos AR e RG têm alocações por linha (paymentReceipt).');
            }

            if ($recibo->estado !== 'rascunho') {
                throw new DomainException('As alocações só se registam enquanto o recibo é rascunho: depois de emitido a fotografia fiscal é imutável.');
            }

            if (DB::table('recibo_alocacoes')->where('recibo_id', $reciboId)->lockForUpdate()->value('id') !== null) {
                throw new DomainException('Este recibo já tem as suas alocações registadas com outra chave.');
            }

            $ids = array_column($pedido, 'fatura_linha_id');

            [$faturas, $linhas] = $this->bloquearOrigens($empresaId, $ids);

            foreach ($faturas as $fatura) {
                if (! $fatura->estaEmitido()) {
                    throw new DomainException("A fatura {$fatura->id} tem de estar emitida para ser regularizada por um recibo.");
                }

                if ((int) $fatura->cliente_id !== (int) $recibo->cliente_id) {
                    throw new DomainException('As faturas de origem têm de ser do mesmo cliente do recibo.');
                }
            }

            $posicoes = $this->posicoesNasFaturas($faturas->keys()->all());
            $jaAlocado = $this->jaAlocadoPorLinha($empresaId, $ids);

            $registos = [];
            $totalBruto = 0;

            foreach ($pedido as $item) {
                $linha = $linhas->get($item['fatura_linha_id']);
                $fatura = $faturas->get((int) $linha->fatura_id);
                $numero = $posicoes[$linha->id];
                $anterior = $jaAlocado[$linha->id] ?? ['liquido' => 0, 'imposto' => 0, 'bruto' => 0];

                $this->garantirDisponivel('líquido', $item['liquido_centimos'], Dinheiro::centimos($linha->valor_sem_iva), $anterior['liquido'], $numero, $fatura);
                $this->garantirDisponivel('imposto', $item['imposto_centimos'], Dinheiro::centimos($linha->valor_iva), $anterior['imposto'], $numero, $fatura);

                $bruto = $item['liquido_centimos'] + $item['imposto_centimos'];
                $this->garantirDisponivel('bruto', $bruto, Dinheiro::centimos($linha->valor_total), $anterior['bruto'], $numero, $fatura);

                $totalBruto += $bruto;
                $registos[] = [
                    'empresa_id' => $empresaId,
                    'recibo_id' => $reciboId,
                    'fatura_id' => (int) $linha->fatura_id,
                    'fatura_linha_id' => (int) $linha->id,
                    'numero_linha_fiscal' => $numero,
                    'liquido_centimos' => $item['liquido_centimos'],
                    'imposto_centimos' => $item['imposto_centimos'],
                    'bruto_centimos' => $bruto,
                    'chave' => $chave,
                ];
            }

            $valorRecibo = Dinheiro::centimos($recibo->valor);

            if ($totalBruto !== $valorRecibo) {
                throw new DomainException(sprintf(
                    'A soma dos brutos alocados (%s) tem de ser igual ao valor do recibo (%s).',
                    Dinheiro::formatar($totalBruto),
                    Dinheiro::formatar($valorRecibo),
                ));
            }

            // Ordem estável, independente da ordem em que o chamador enviou as linhas.
            usort($registos, fn (array $a, array $b) => [$a['fatura_id'], $a['numero_linha_fiscal']] <=> [$b['fatura_id'], $b['numero_linha_fiscal']]);

            $agora = now();
            foreach ($registos as $i => $registo) {
                $registos[$i] = $registo + ['sequencia' => $i + 1, 'created_at' => $agora, 'updated_at' => $agora];
            }

            try {
                DB::table('recibo_alocacoes')->insert($registos);
            } catch (UniqueConstraintViolationException $e) {
                if (($resultado = $this->resolverExistente($empresaId, $chave, $reciboId, $pedido)) !== null) {
                    return $resultado;
                }

                throw $e;
            }

            Log::info('Alocações de recibo registadas', [
                'recibo_id' => $reciboId,
                'empresa_id' => $empresaId,
                'linhas' => count($registos),
                'bruto_centimos' => $totalBruto,
            ]);

            return $this->doRecibo($reciboId);
        }, attempts: 5);
    }

    /**
     * Alocações do recibo, por `sequencia`. Leitura normal: fora de uma transação lê o confirmado;
     * dentro de uma transação MySQL pode usar um instantâneo antigo (ver jaAlocadoPorLinha).
     *
     * @return array<int, array<string, mixed>>
     */
    public function doRecibo(int $reciboId): array
    {
        return DB::table('recibo_alocacoes')
            ->where('recibo_id', $reciboId)
            ->orderBy('sequencia')
            ->get()
            ->map(fn ($linha) => (array) $linha)
            ->all();
    }

    /**
     * @param  array<int, mixed>  $alocacoes
     * @return array<int, array{fatura_linha_id: int, liquido_centimos: int, imposto_centimos: int}>
     */
    protected function normalizar(array $alocacoes): array
    {
        if ($alocacoes === []) {
            throw new InvalidArgumentException('Indica pelo menos uma alocação.');
        }

        $resultado = [];
        $vistas = [];

        foreach (array_values($alocacoes) as $i => $item) {
            $n = $i + 1;

            foreach (['fatura_linha_id', 'liquido_centimos', 'imposto_centimos'] as $campo) {
                if (! is_array($item) || ! array_key_exists($campo, $item) || ! is_int($item[$campo])) {
                    throw new InvalidArgumentException("Alocação {$n}: '{$campo}' tem de ser um inteiro (valores em cêntimos, nunca float).");
                }
            }

            $linhaId = $item['fatura_linha_id'];
            $liquido = $item['liquido_centimos'];
            $imposto = $item['imposto_centimos'];

            if ($linhaId <= 0) {
                throw new InvalidArgumentException("Alocação {$n}: linha inválida.");
            }

            if ($liquido < 0 || $imposto < 0 || $liquido > self::LIMITE_CENTIMOS || $imposto > self::LIMITE_CENTIMOS) {
                throw new InvalidArgumentException("Alocação {$n}: os montantes não podem ser negativos nem exceder o limite.");
            }

            if ($liquido + $imposto <= 0) {
                throw new InvalidArgumentException("Alocação {$n}: o valor alocado tem de ser superior a zero.");
            }

            if (isset($vistas[$linhaId])) {
                throw new InvalidArgumentException("Alocação {$n}: a linha {$linhaId} aparece repetida; junta os montantes numa só alocação.");
            }

            $vistas[$linhaId] = true;
            $resultado[] = ['fatura_linha_id' => $linhaId, 'liquido_centimos' => $liquido, 'imposto_centimos' => $imposto];
        }

        usort($resultado, fn (array $a, array $b) => $a['fatura_linha_id'] <=> $b['fatura_linha_id']);

        return $resultado;
    }

    /**
     * Bloqueia primeiro as faturas (por id) e depois as linhas (por id), sempre na mesma ordem
     * para não haver deadlocks com recibos e abatimentos, que também bloqueiam por fatura.
     *
     * @param  array<int, int>  $ids
     * @return array{0: Collection<int, Fatura>, 1: Collection<int, FaturaLinha>}
     */
    protected function bloquearOrigens(int $empresaId, array $ids): array
    {
        $faturaIds = FaturaLinha::query()->whereIn('id', $ids)->distinct()->orderBy('fatura_id')->pluck('fatura_id')->map(fn ($v) => (int) $v)->all();

        $faturas = Fatura::withoutGlobalScopes()
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $faturaIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $linhas = FaturaLinha::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $emFalta = count($ids) !== $linhas->count() || $faturas->count() !== count($faturaIds);
        $alheia = $linhas->contains(fn (FaturaLinha $linha) => ! $faturas->has((int) $linha->fatura_id));

        if ($emFalta || $alheia) {
            throw new DomainException('Todas as linhas indicadas têm de existir e pertencer a faturas desta empresa.');
        }

        return [$faturas, $linhas];
    }

    /**
     * Posição (base 1) de cada linha na sua fatura, pela mesma ordem do AgtDocumentoBuilder
     * (`ordem`; o `id` só desempata para a numeração ser determinística).
     *
     * @param  array<int, int>  $faturaIds
     * @return array<int, int> linha_id => posição
     */
    protected function posicoesNasFaturas(array $faturaIds): array
    {
        $posicoes = [];
        $contador = [];

        $linhas = FaturaLinha::query()
            ->whereIn('fatura_id', $faturaIds)
            ->orderBy('fatura_id')
            ->orderBy('ordem')
            ->orderBy('id')
            ->get(['id', 'fatura_id']);

        foreach ($linhas as $linha) {
            $contador[$linha->fatura_id] = ($contador[$linha->fatura_id] ?? 0) + 1;
            $posicoes[(int) $linha->id] = $contador[$linha->fatura_id];
        }

        return $posicoes;
    }

    /**
     * Já alocado por linha, ignorando recibos anulados (libertam o disponível).
     *
     * LEITURA BLOQUEANTE de propósito: em MySQL (REPEATABLE READ) um SELECT normal usa o
     * instantâneo aberto na primeira leitura da transação, ou seja, ANTES de esperar pelos locks, e
     * não vê o que outra transação confirmou entretanto. Com um SUM normal duas alocações
     * concorrentes viam a linha livre e sobre-alocavam-na (provado em
     * ReciboAlocacaoConcorrenciaRealTest). O lock das linhas já serializa; a leitura bloqueante
     * garante que se lê o que ficou confirmado.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{liquido: int, imposto: int, bruto: int}>
     */
    protected function jaAlocadoPorLinha(int $empresaId, array $ids): array
    {
        $alocacoes = DB::table('recibo_alocacoes')
            ->where('empresa_id', $empresaId)
            ->whereIn('fatura_linha_id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['fatura_linha_id', 'recibo_id', 'liquido_centimos', 'imposto_centimos', 'bruto_centimos']);

        // Um recibo anulado nunca volta atrás: se a leitura estiver desactualizada só pode
        // contar de mais (recusa de menos), nunca deixar sobre-alocar.
        $anulados = $alocacoes->isEmpty() ? [] : DB::table('recibos')
            ->whereIn('id', $alocacoes->pluck('recibo_id')->unique()->all())
            ->where('estado', 'anulado')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $resultado = [];
        foreach ($alocacoes as $alocacao) {
            if (isset($anulados[(int) $alocacao->recibo_id])) {
                continue;
            }

            $linha = (int) $alocacao->fatura_linha_id;
            $resultado[$linha] ??= ['liquido' => 0, 'imposto' => 0, 'bruto' => 0];
            $resultado[$linha]['liquido'] += (int) $alocacao->liquido_centimos;
            $resultado[$linha]['imposto'] += (int) $alocacao->imposto_centimos;
            $resultado[$linha]['bruto'] += (int) $alocacao->bruto_centimos;
        }

        return $resultado;
    }

    protected function garantirDisponivel(string $componente, int $pedido, int $total, int $jaAlocado, int $numeroLinha, Fatura $fatura): void
    {
        $disponivel = max(0, $total - $jaAlocado);

        if ($pedido > $disponivel) {
            throw new DomainException(sprintf(
                'A alocação à linha %d da fatura %s excede o %s ainda disponível (%s).',
                $numeroLinha,
                $fatura->numero_documento ?? "#{$fatura->id}",
                $componente,
                Dinheiro::formatar($disponivel),
            ));
        }
    }

    /**
     * @param  array<int, array{fatura_linha_id: int, liquido_centimos: int, imposto_centimos: int}>  $pedido
     * @return array<int, array<string, mixed>>|null null quando a chave ainda não foi usada
     */
    protected function resolverExistente(int $empresaId, string $chave, int $reciboId, array $pedido): ?array
    {
        $existentes = DB::table('recibo_alocacoes')
            ->where('empresa_id', $empresaId)
            ->where('chave', $chave)
            ->orderBy('fatura_linha_id')
            ->lockForUpdate()
            ->get();

        if ($existentes->isEmpty()) {
            return null;
        }

        $gravado = $existentes->map(fn ($linha) => [
            'recibo_id' => (int) $linha->recibo_id,
            'fatura_linha_id' => (int) $linha->fatura_linha_id,
            'liquido_centimos' => (int) $linha->liquido_centimos,
            'imposto_centimos' => (int) $linha->imposto_centimos,
        ])->all();

        $esperado = array_map(fn (array $item) => ['recibo_id' => $reciboId] + $item, $pedido);

        if ($gravado !== $esperado) {
            throw new DomainException('Chave de idempotência reutilizada com dados diferentes.');
        }

        // Devolve o que a leitura bloqueante viu (não um SELECT normal, que pode ainda estar no
        // instantâneo antigo e vir vazio).
        return $existentes
            ->map(fn ($linha) => (array) $linha)
            ->sortBy('sequencia')
            ->values()
            ->all();
    }
}
