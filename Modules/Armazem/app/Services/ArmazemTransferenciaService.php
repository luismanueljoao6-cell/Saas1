<?php

namespace Modules\Armazem\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Armazem\Models\Armazem;
use Modules\Armazem\Models\ArmazemTransferencia;
use Modules\Armazem\Models\ArmazemTransferenciaLinha;
use Modules\Armazem\Models\ArmazemTransferenciaRececao;
use Modules\Armazem\Models\ArmazemTransferenciaRececaoLinha;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Produto;

class ArmazemTransferenciaService
{
    private readonly ArmazemStockService $stock;

    public function __construct(ArmazemStockService $stock)
    {
        $this->stock = $stock;
    }

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

            if (in_array($alvo->estado, ['recebida', 'recebida_com_divergencia'], true)) {
                return $alvo->setRelation('linhas', $this->obterLinhas($empresa, $alvo, false));
            }

            if (in_array($alvo->estado, ['em_transito', 'parcialmente_recebida'], true) === false) {
                throw new DomainException('Só é possível receber uma transferência que esteja em trânsito.');
            }

            $linhas = $this->obterLinhas($empresa, $alvo);
            $dados = [];

            foreach ($linhas as $linha) {
                $enviada = $this->normalizarQuantidade((string) $linha->quantidade);
                $recebida = $this->normalizarQuantidade((string) $linha->quantidade_recebida);
                $falta = $this->normalizarQuantidade((string) $linha->quantidade_em_falta);
                $danificada = $this->normalizarQuantidade((string) $linha->quantidade_danificada);
                $restante = $enviada - $recebida - $falta - $danificada;

                if ($restante > 0) {
                    $dados[] = [
                        'linha_id' => (int) $linha->id,
                        'quantidade_recebida' => $this->formatar($restante),
                        'quantidade_em_falta' => '0.000',
                        'quantidade_danificada' => '0.000',
                    ];
                }
            }

            return $this->registarRececao(
                $empresa,
                $alvo,
                $dados,
                true,
                'rececao-integral:'.$alvo->id,
                $utilizadorId
            );
        }, attempts: 3);
    }

    public function registarRececaoParcial(
        Empresa $empresa,
        ArmazemTransferencia $transferencia,
        array $linhas,
        bool $finalizar,
        string $chaveIdempotencia,
        ?int $utilizadorId = null
    ): ArmazemTransferencia {
        return $this->registarRececao(
            $empresa,
            $transferencia,
            $linhas,
            $finalizar,
            $chaveIdempotencia,
            $utilizadorId
        );
    }

    private function registarRececao(
        Empresa $empresa,
        ArmazemTransferencia $transferencia,
        array $linhas,
        bool $finalizar,
        string $chaveIdempotencia,
        ?int $utilizadorId
    ): ArmazemTransferencia {
        $chaveIdempotencia = trim($chaveIdempotencia);

        if ($chaveIdempotencia === '' || mb_strlen($chaveIdempotencia) > 100) {
            throw new InvalidArgumentException('A chave de receção é inválida.');
        }

        if ($linhas === [] && $finalizar === false) {
            throw new InvalidArgumentException('Indica pelo menos uma linha para registar a receção.');
        }

        $normalizadas = [];
        $vistos = [];
        foreach ($linhas as $linha) {
            $linhaId = (int) ($linha['linha_id'] ?? 0);
            $recebida = $this->normalizarQuantidade((string) ($linha['quantidade_recebida'] ?? '0'));
            $falta = $this->normalizarQuantidade((string) ($linha['quantidade_em_falta'] ?? '0'));
            $danificada = $this->normalizarQuantidade((string) ($linha['quantidade_danificada'] ?? '0'));

            if ($linhaId <= 0 || isset($vistos[$linhaId])) {
                throw new InvalidArgumentException('As linhas da receção devem ser válidas e não repetidas.');
            }
            $vistos[$linhaId] = true;

            if (($recebida + $falta + $danificada) <= 0) {
                continue;
            }

            $normalizadas[$linhaId] = [
                'recebida' => $recebida,
                'falta' => $falta,
                'danificada' => $danificada,
            ];
        }

        if ($normalizadas === [] && $finalizar === false) {
            throw new InvalidArgumentException('Regista uma quantidade recebida, em falta ou danificada.');
        }

        ksort($normalizadas);
        $payloadHash = hash('sha256', json_encode([
            'transferencia_id' => (int) $transferencia->id,
            'finalizar' => $finalizar,
            'linhas' => $normalizadas,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $empresa, $transferencia, $normalizadas, $finalizar, $chaveIdempotencia, $utilizadorId, $payloadHash
        ): ArmazemTransferencia {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $alvo = ArmazemTransferencia::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($transferencia->id)
                ->lockForUpdate()
                ->firstOrFail();

            $rececaoExistente = ArmazemTransferenciaRececao::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('chave_idempotencia', $chaveIdempotencia)
                ->first();

            if ($rececaoExistente) {
                if ((int) $rececaoExistente->transferencia_id !== (int) $alvo->id
                    || hash_equals($rececaoExistente->payload_hash, $payloadHash) === false) {
                    throw new DomainException('A chave de idempotência já foi utilizada com outros dados.');
                }

                return $alvo->setRelation('linhas', $this->obterLinhas($empresa, $alvo, false));
            }

            if (in_array($alvo->estado, ['em_transito', 'parcialmente_recebida'], true) === false) {
                throw new DomainException('A transferência não está disponível para receção.');
            }

            $this->validarUtilizador($empresa, $utilizadorId);
            $linhasTransferencia = $this->obterLinhas($empresa, $alvo);
            $porId = $linhasTransferencia->keyBy('id');

            foreach ($normalizadas as $linhaId => $quantidades) {
                $linha = $porId->get($linhaId);

                if ($linha === null) {
                    throw new DomainException('Uma das linhas não pertence a esta transferência.');
                }

                $enviada = $this->normalizarQuantidade((string) $linha->quantidade);
                $jaRecebida = $this->normalizarQuantidade((string) $linha->quantidade_recebida);
                $jaEmFalta = $this->normalizarQuantidade((string) $linha->quantidade_em_falta);
                $jaDanificada = $this->normalizarQuantidade((string) $linha->quantidade_danificada);
                $total = $jaRecebida + $jaEmFalta + $jaDanificada
                    + $quantidades['recebida'] + $quantidades['falta'] + $quantidades['danificada'];

                if ($total > $enviada) {
                    throw new DomainException('A receção ultrapassa a quantidade expedida do produto '.$linha->produto_id.'.');
                }
            }

            if ($finalizar) {
                foreach ($linhasTransferencia as $linha) {
                    $quantidades = $normalizadas[(int) $linha->id] ?? [
                        'recebida' => 0,
                        'falta' => 0,
                        'danificada' => 0,
                    ];
                    $total = $this->normalizarQuantidade((string) $linha->quantidade_recebida)
                        + $this->normalizarQuantidade((string) $linha->quantidade_em_falta)
                        + $this->normalizarQuantidade((string) $linha->quantidade_danificada)
                        + $quantidades['recebida'] + $quantidades['falta'] + $quantidades['danificada'];

                    if ($total !== $this->normalizarQuantidade((string) $linha->quantidade)) {
                        throw new DomainException('Para finalizar, todas as quantidades expedidas devem estar classificadas como recebidas, em falta ou danificadas.');
                    }
                }
            }

            $rececao = ArmazemTransferenciaRececao::create([
                'empresa_id' => $empresa->id,
                'transferencia_id' => $alvo->id,
                'chave_idempotencia' => $chaveIdempotencia,
                'payload_hash' => $payloadHash,
                'utilizador_id' => $utilizadorId,
                'finalizada' => $finalizar,
            ]);

            foreach ($normalizadas as $linhaId => $quantidades) {
                $linha = $porId->get($linhaId);

                ArmazemTransferenciaRececaoLinha::create([
                    'empresa_id' => $empresa->id,
                    'rececao_id' => $rececao->id,
                    'transferencia_linha_id' => $linha->id,
                    'quantidade_recebida' => $this->formatar($quantidades['recebida']),
                    'quantidade_em_falta' => $this->formatar($quantidades['falta']),
                    'quantidade_danificada' => $this->formatar($quantidades['danificada']),
                ]);

                if ($quantidades['recebida'] > 0) {
                    $this->stock->entrada(
                        $empresa,
                        $this->obterArmazem($empresa, $alvo->armazem_destino_id),
                        $this->obterProduto($empresa, $linha->produto_id),
                        $this->formatar($quantidades['recebida']),
                        'Receção da transferência '.$alvo->id.' · TRC-'.$rececao->id,
                        $utilizadorId,
                        'transferencia:'.$alvo->id.':rececao:'.$rececao->id.':linha:'.$linha->id
                    );
                }

                $linha->forceFill([
                    'quantidade_recebida' => $this->formatar(
                        $this->normalizarQuantidade((string) $linha->quantidade_recebida) + $quantidades['recebida']
                    ),
                    'quantidade_em_falta' => $this->formatar(
                        $this->normalizarQuantidade((string) $linha->quantidade_em_falta) + $quantidades['falta']
                    ),
                    'quantidade_danificada' => $this->formatar(
                        $this->normalizarQuantidade((string) $linha->quantidade_danificada) + $quantidades['danificada']
                    ),
                ])->save();
            }

            $temDivergencia = false;
            foreach ($linhasTransferencia as $linha) {
                if ($this->normalizarQuantidade((string) $linha->quantidade_em_falta) > 0
                    || $this->normalizarQuantidade((string) $linha->quantidade_danificada) > 0) {
                    $temDivergencia = true;
                    break;
                }
            }

            if ($finalizar) {
                $alvo->forceFill([
                    'estado' => $temDivergencia ? 'recebida_com_divergencia' : 'recebida',
                    'recebido_por' => $utilizadorId,
                    'recebido_em' => now(),
                ])->save();
            } else {
                $alvo->forceFill(['estado' => 'parcialmente_recebida'])->save();
            }

            return $alvo
                ->setRelation('linhas', $this->obterLinhas($empresa, $alvo, false))
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
