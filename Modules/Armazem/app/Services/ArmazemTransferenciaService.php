<?php

namespace Modules\Armazem\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Armazem\Models\Armazem;
use Modules\Armazem\Models\ArmazemTransferencia;
use Modules\Armazem\Models\ArmazemTransferenciaLinha;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Produto;

class ArmazemTransferenciaService
{
    public function __construct(private readonly ArmazemStockService $stock)
    {
    }

    /**
     * Cria um rascunho. Cada produto só pode aparecer uma vez na transferência.
     *
     * @param array<int, array{produto_id:int, quantidade:string|int|float}> $linhas
     */
    public function criar(
        Empresa $empresa,
        Armazem $origem,
        Armazem $destino,
        array $linhas,
        string $motivo,
        ?int $utilizadorId = null,
        ?string $observacoes = null
    ): ArmazemTransferencia {
        $motivo = trim($motivo);

        if ($motivo === '' || mb_strlen($motivo) > 255) {
            throw new InvalidArgumentException('Indica um motivo com até 255 caracteres.');
        }

        if ($linhas === []) {
            throw ValidationException::withMessages(['linhas' => 'Adiciona pelo menos um produto à transferência.']);
        }

        return DB::transaction(function () use (
            $empresa, $origem, $destino, $linhas, $motivo, $utilizadorId, $observacoes
        ): ArmazemTransferencia {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $armazens = Armazem::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereIn('id', [$origem->id, $destino->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $origemValidada = $armazens->get($origem->id);
            $destinoValidado = $armazens->get($destino->id);

            if ($origemValidada === null || $destinoValidado === null) {
                throw new DomainException('Os dois armazéns devem pertencer à mesma empresa.');
            }

            if ((int) $origemValidada->id === (int) $destinoValidado->id) {
                throw new DomainException('O armazém de origem e o de destino devem ser diferentes.');
            }

            if ($origemValidada->ativo === false || $destinoValidado->ativo === false) {
                throw new DomainException('A origem e o destino devem estar ativos.');
            }

            if ($utilizadorId !== null && User::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)->whereKey($utilizadorId)->exists() === false) {
                throw new DomainException('O utilizador não pertence à empresa.');
            }

            $normalizadas = [];
            foreach ($linhas as $linha) {
                $produtoId = (int) ($linha['produto_id'] ?? 0);
                $quantidade = $this->normalizarQuantidade((string) ($linha['quantidade'] ?? ''));

                if ($quantidade <= 0) {
                    throw new InvalidArgumentException('A quantidade de cada produto deve ser superior a zero.');
                }

                if ($produtoId <= 0 || isset($normalizadas[$produtoId])) {
                    throw ValidationException::withMessages([
                        'linhas' => 'Cada produto deve ser válido e aparecer apenas uma vez.',
                    ]);
                }

                $normalizadas[$produtoId] = $quantidade;
            }

            $produtos = Produto::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereIn('id', array_keys($normalizadas))
                ->where('controla_stock', true)
                ->get()
                ->keyBy('id');

            if ($produtos->count() !== count($normalizadas)) {
                throw new DomainException('Um ou mais produtos não pertencem à empresa ou não controlam stock.');
            }

            $transferencia = ArmazemTransferencia::create([
                'empresa_id' => $empresa->id,
                'armazem_origem_id' => $origemValidada->id,
                'armazem_destino_id' => $destinoValidado->id,
                'criado_por' => $utilizadorId,
                'estado' => 'rascunho',
                'motivo' => $motivo,
                'observacoes' => $observacoes,
            ]);

            foreach ($normalizadas as $produtoId => $quantidade) {
                $transferencia->linhas()->create([
                    'empresa_id' => $empresa->id,
                    'produto_id' => $produtoId,
                    'quantidade' => $this->formatar($quantidade),
                    'quantidade_recebida' => '0.000',
                ]);
            }

            return $transferencia
                ->setRelation('linhas', $this->obterLinhas($empresa, $transferencia, false))
                ->load(['origem', 'destino']);
        }, attempts: 3);
    }

    public function expedir(Empresa $empresa, ArmazemTransferencia $transferencia, ?int $utilizadorId = null): ArmazemTransferencia
    {
        return DB::transaction(function () use ($empresa, $transferencia, $utilizadorId): ArmazemTransferencia {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $alvo = ArmazemTransferencia::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($transferencia->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($alvo->estado === 'em_transito') {
                return $alvo->setRelation('linhas', $this->obterLinhas($empresa, $alvo, false));
            }

            if ($alvo->estado !== 'rascunho') {
                throw new DomainException('Só é possível expedir transferências em rascunho.');
            }

            $this->validarUtilizador($empresa, $utilizadorId);
            $linhas = $this->obterLinhas($empresa, $alvo);

            if ($linhas->isEmpty()) {
                throw new DomainException('Não é possível expedir uma transferência sem linhas.');
            }

            foreach ($linhas as $linha) {
                $this->stock->saida(
                    $empresa,
                    $this->obterArmazem($empresa, $alvo->armazem_origem_id),
                    $this->obterProduto($empresa, $linha->produto_id),
                    (string) $linha->quantidade,
                    'Transferência '.$alvo->id.' expedida',
                    $utilizadorId,
                    'transferencia:'.$alvo->id.':linha:'.$linha->id.':expedicao'
                );
            }

            $alvo->forceFill([
                'estado' => 'em_transito',
                'expedido_por' => $utilizadorId,
                'expedido_em' => now(),
            ])->save();

            return $alvo
                ->setRelation('linhas', $linhas->load('produto'))
                ->load(['origem', 'destino']);
        }, attempts: 3);
    }

    public function receber(Empresa $empresa, ArmazemTransferencia $transferencia, ?int $utilizadorId = null): ArmazemTransferencia
    {
        return DB::transaction(function () use ($empresa, $transferencia, $utilizadorId): ArmazemTransferencia {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $alvo = ArmazemTransferencia::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($transferencia->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($alvo->estado === 'recebida') {
                return $alvo->setRelation('linhas', $this->obterLinhas($empresa, $alvo, false));
            }

            if ($alvo->estado !== 'em_transito') {
                throw new DomainException('Só é possível receber uma transferência que esteja em trânsito.');
            }

            $this->validarUtilizador($empresa, $utilizadorId);
            $linhas = $this->obterLinhas($empresa, $alvo);

            foreach ($linhas as $linha) {
                $this->stock->entrada(
                    $empresa,
                    $this->obterArmazem($empresa, $alvo->armazem_destino_id),
                    $this->obterProduto($empresa, $linha->produto_id),
                    (string) $linha->quantidade,
                    'Transferência '.$alvo->id.' recebida',
                    $utilizadorId,
                    'transferencia:'.$alvo->id.':linha:'.$linha->id.':rececao'
                );

                $linha->forceFill(['quantidade_recebida' => $linha->quantidade])->save();
            }

            $alvo->forceFill([
                'estado' => 'recebida',
                'recebido_por' => $utilizadorId,
                'recebido_em' => now(),
            ])->save();

            return $alvo
                ->setRelation('linhas', $linhas->load('produto'))
                ->load(['origem', 'destino']);
        }, attempts: 3);
    }

    public function cancelar(Empresa $empresa, ArmazemTransferencia $transferencia): ArmazemTransferencia
    {
        return DB::transaction(function () use ($empresa, $transferencia): ArmazemTransferencia {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $alvo = ArmazemTransferencia::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($transferencia->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($alvo->estado === 'cancelada') {
                return $alvo;
            }

            if ($alvo->estado !== 'rascunho') {
                throw new DomainException('Só é possível cancelar transferências ainda em rascunho.');
            }

            $alvo->forceFill(['estado' => 'cancelada'])->save();

            return $alvo;
        }, attempts: 3);
    }

    /**
     * Consulta explícita por empresa: processos internos podem executar sem
     * tenant HTTP, cenário em que a TenantScope bloqueia queries implícitas.
     */
    private function obterLinhas(Empresa $empresa, ArmazemTransferencia $transferencia, bool $bloquear = true)
    {
        $query = ArmazemTransferenciaLinha::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('transferencia_id', $transferencia->id)
            ->with('produto')
            ->orderBy('id');

        if ($bloquear) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function obterArmazem(Empresa $empresa, int $id): Armazem
    {
        return Armazem::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('ativo', true)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function obterProduto(Empresa $empresa, int $id): Produto
    {
        return Produto::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('controla_stock', true)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function validarUtilizador(Empresa $empresa, ?int $utilizadorId): void
    {
        if ($utilizadorId !== null && User::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)->whereKey($utilizadorId)->exists() === false) {
            throw new DomainException('O utilizador não pertence à empresa.');
        }
    }

    private function validarTenant(Empresa $empresa): void
    {
        $tenant = app(TenantManager::class);

        if ($tenant->has() && $tenant->emBypass() === false && $tenant->id() !== (int) $empresa->id) {
            throw new DomainException('O contexto atual não corresponde à empresa da transferência.');
        }
    }

    private function normalizarQuantidade(string $valor): int
    {
        $valor = trim($valor);

        if (preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,3}))?$/', $valor, $partes) !== 1) {
            throw new InvalidArgumentException('Quantidade inválida. Usa até três casas decimais.');
        }

        return ((int) $partes[1] * 1000) + (int) str_pad($partes[2] ?? '', 3, '0');
    }

    private function formatar(int $valor): string
    {
        return intdiv($valor, 1000).'.'.str_pad((string) ($valor % 1000), 3, '0', STR_PAD_LEFT);
    }
}
