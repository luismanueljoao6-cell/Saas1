<?php

namespace Modules\Armazem\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Armazem\Models\Armazem;
use Modules\Armazem\Models\ArmazemInventario;
use Modules\Armazem\Models\ArmazemInventarioLinha;
use Modules\Armazem\Models\ArmazemStockSaldo;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Produto;

class ArmazemInventarioService
{
    public function __construct(private readonly ArmazemStockService $stock) {}

    public function abrir(
        Empresa $empresa,
        Armazem $armazem,
        array $produtoIds,
        ?int $utilizadorId = null,
        ?string $observacoes = null
    ): ArmazemInventario {
        $ids = array_values(array_unique(array_filter(array_map('intval', $produtoIds))));

        if ($ids === [] || count($ids) !== count($produtoIds) || min($ids) <= 0) {
            throw new InvalidArgumentException('Seleciona produtos válidos, sem repetições, para contar.');
        }

        return DB::transaction(function () use ($empresa, $armazem, $ids, $utilizadorId, $observacoes): ArmazemInventario {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $local = Armazem::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('ativo', true)
                ->whereKey($armazem->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($utilizadorId !== null && User::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)->whereKey($utilizadorId)->exists() === false) {
                throw new DomainException('O utilizador não pertence à empresa.');
            }

            $aberto = ArmazemInventario::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('armazem_id', $local->id)
                ->where('estado', 'em_contagem')
                ->exists();

            if ($aberto) {
                throw new DomainException('Já existe um inventário em contagem neste armazém.');
            }

            $produtos = Produto::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('ativo', true)
                ->where('controla_stock', true)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get();

            if ($produtos->count() !== count($ids)) {
                throw new DomainException('Um ou mais produtos não pertencem à empresa ou não controlam stock.');
            }

            $inventario = ArmazemInventario::create([
                'empresa_id' => $empresa->id,
                'armazem_id' => $local->id,
                'aberto_por' => $utilizadorId,
                'estado' => 'em_contagem',
                'observacoes' => $observacoes,
                'aberto_em' => now(),
            ]);

            $saldos = ArmazemStockSaldo::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('armazem_id', $local->id)
                ->whereIn('produto_id', $ids)
                ->get()
                ->keyBy('produto_id');

            foreach ($produtos as $produto) {
                $inventario->linhas()->create([
                    'empresa_id' => $empresa->id,
                    'produto_id' => $produto->id,
                    'quantidade_sistema' => $saldos->get($produto->id)?->quantidade ?? '0.000',
                ]);
            }

            return $inventario->load('linhas.produto', 'armazem');
        }, attempts: 3);
    }

    public function registarContagem(
        Empresa $empresa,
        ArmazemInventario $inventario,
        int $linhaId,
        string $quantidadeContada,
        ?int $utilizadorId = null
    ): ArmazemInventarioLinha {
        $contagem = $this->emMilhares($quantidadeContada);

        return DB::transaction(function () use ($empresa, $inventario, $linhaId, $contagem, $utilizadorId): ArmazemInventarioLinha {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $sessao = ArmazemInventario::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($inventario->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sessao->estado !== 'em_contagem') {
                throw new DomainException('Este inventário já não aceita contagens.');
            }

            if ($utilizadorId !== null && User::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)->whereKey($utilizadorId)->exists() === false) {
                throw new DomainException('O utilizador não pertence à empresa.');
            }

            $linha = ArmazemInventarioLinha::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('inventario_id', $sessao->id)
                ->whereKey($linhaId)
                ->lockForUpdate()
                ->firstOrFail();

            $sistema = $this->emMilhares((string) $linha->quantidade_sistema);
            $linha->forceFill([
                'quantidade_contada' => $this->formatar($contagem),
                'diferenca' => $this->formatar($contagem - $sistema),
                'contado_por' => $utilizadorId,
                'contado_em' => now(),
            ])->save();

            return $linha->load('produto');
        }, attempts: 3);
    }

    public function concluir(
        Empresa $empresa,
        ArmazemInventario $inventario,
        ?int $utilizadorId = null
    ): ArmazemInventario {
        return DB::transaction(function () use ($empresa, $inventario, $utilizadorId): ArmazemInventario {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $sessao = ArmazemInventario::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($inventario->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($sessao->estado, ['aguarda_aprovacao', 'concluido'], true)) {
                return $sessao->load('linhas.produto', 'armazem');
            }

            if ($sessao->estado !== 'em_contagem') {
                throw new DomainException('Só é possível enviar para aprovação um inventário em contagem.');
            }

            $utilizadorId = $this->validarUtilizador($empresa, $utilizadorId);
            $linhas = ArmazemInventarioLinha::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('inventario_id', $sessao->id)
                ->orderBy('produto_id')
                ->lockForUpdate()
                ->get();

            if ($linhas->isEmpty() || $linhas->contains(
                fn (ArmazemInventarioLinha $linha): bool => $linha->quantidade_contada === null
            )) {
                throw new DomainException('Regista a contagem de todos os produtos antes de enviar para aprovação.');
            }

            $temDivergencias = $linhas->contains(
                fn (ArmazemInventarioLinha $linha): bool => $this->emMilhares((string) ($linha->diferenca ?? '0.000')) !== 0
            );
            $momento = now();

            if ($temDivergencias) {
                $sessao->forceFill([
                    'estado' => 'aguarda_aprovacao',
                    'submetido_por' => $utilizadorId,
                    'submetido_em' => $momento,
                ])->save();
            } else {
                // Sem diferenças não há ajuste de stock que exija segregação de funções.
                $sessao->forceFill([
                    'estado' => 'concluido',
                    'submetido_por' => $utilizadorId,
                    'submetido_em' => $momento,
                    'concluido_por' => $utilizadorId,
                    'concluido_em' => $momento,
                ])->save();
            }

            return $sessao->load('linhas.produto', 'armazem');
        }, attempts: 3);
    }

    public function aprovar(
        Empresa $empresa,
        ArmazemInventario $inventario,
        int $utilizadorId
    ): ArmazemInventario {
        return DB::transaction(function () use ($empresa, $inventario, $utilizadorId): ArmazemInventario {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $sessao = ArmazemInventario::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($inventario->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sessao->estado === 'concluido') {
                return $sessao->load('linhas.produto', 'armazem');
            }

            if ($sessao->estado !== 'aguarda_aprovacao') {
                throw new DomainException('Só é possível aprovar inventários que aguardam aprovação.');
            }

            $utilizadorId = $this->validarUtilizador($empresa, $utilizadorId);
            $linhas = ArmazemInventarioLinha::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('inventario_id', $sessao->id)
                ->orderBy('produto_id')
                ->lockForUpdate()
                ->get();

            if ($linhas->isEmpty() || $linhas->contains(
                fn (ArmazemInventarioLinha $linha): bool => $linha->quantidade_contada === null
            )) {
                throw new DomainException('O inventário não contém todas as contagens necessárias.');
            }

            $this->garantirSegregacao($sessao, $linhas, $utilizadorId);

            // Estado transitório dentro da transação: permite ao serviço de stock
            // aplicar os ajustes sem reabrir a janela para movimentos concorrentes.
            $sessao->forceFill(['estado' => 'a_finalizar'])->save();

            $armazem = Armazem::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($sessao->armazem_id)
                ->firstOrFail();

            foreach ($linhas as $linha) {
                if ($this->emMilhares((string) ($linha->diferenca ?? '0.000')) === 0) {
                    continue;
                }

                $produto = Produto::withoutGlobalScopes()
                    ->where('empresa_id', $empresa->id)
                    ->whereKey($linha->produto_id)
                    ->firstOrFail();

                $this->stock->ajustar(
                    $empresa,
                    $armazem,
                    $produto,
                    (string) $linha->quantidade_contada,
                    sprintf('Inventário físico %d aprovado', $sessao->id),
                    $utilizadorId,
                    sprintf('inventario:%d:produto:%d', $sessao->id, $produto->id)
                );
            }

            $sessao->forceFill([
                'estado' => 'concluido',
                'concluido_por' => $utilizadorId,
                'concluido_em' => now(),
            ])->save();

            return $sessao->load('linhas.produto', 'armazem');
        }, attempts: 3);
    }

    public function rejeitar(
        Empresa $empresa,
        ArmazemInventario $inventario,
        int $utilizadorId,
        string $motivo
    ): ArmazemInventario {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < 10 || mb_strlen($motivo) > 2000) {
            throw new InvalidArgumentException('Indica um motivo de rejeição entre 10 e 2000 caracteres.');
        }

        return DB::transaction(function () use ($empresa, $inventario, $utilizadorId, $motivo): ArmazemInventario {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $sessao = ArmazemInventario::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($inventario->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sessao->estado === 'rejeitado') {
                return $sessao->load('linhas.produto', 'armazem');
            }

            if ($sessao->estado !== 'aguarda_aprovacao') {
                throw new DomainException('Só é possível rejeitar inventários que aguardam aprovação.');
            }

            $utilizadorId = $this->validarUtilizador($empresa, $utilizadorId);
            $linhas = ArmazemInventarioLinha::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('inventario_id', $sessao->id)
                ->orderBy('produto_id')
                ->lockForUpdate()
                ->get();

            $this->garantirSegregacao($sessao, $linhas, $utilizadorId);

            $sessao->forceFill([
                'estado' => 'rejeitado',
                'rejeitado_por' => $utilizadorId,
                'rejeitado_em' => now(),
                'motivo_rejeicao' => $motivo,
            ])->save();

            return $sessao->load('linhas.produto', 'armazem');
        }, attempts: 3);
    }

    public function cancelar(Empresa $empresa, ArmazemInventario $inventario): ArmazemInventario
    {
        return DB::transaction(function () use ($empresa, $inventario): ArmazemInventario {
            $this->validarTenant($empresa);
            Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

            $sessao = ArmazemInventario::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereKey($inventario->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sessao->estado === 'cancelado') {
                return $sessao;
            }

            if ($sessao->estado !== 'em_contagem') {
                throw new DomainException('Só é possível cancelar um inventário ainda em contagem.');
            }

            $sessao->forceFill(['estado' => 'cancelado'])->save();

            return $sessao;
        }, attempts: 3);
    }

    private function validarUtilizador(Empresa $empresa, ?int $utilizadorId): int
    {
        if ($utilizadorId === null || User::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereKey($utilizadorId)
            ->exists() === false) {
            throw new DomainException('O utilizador não pertence à empresa ou não foi identificado.');
        }

        return $utilizadorId;
    }

    private function garantirSegregacao(
        ArmazemInventario $sessao,
        \Illuminate\Support\Collection $linhas,
        int $utilizadorId
    ): void {
        $autores = collect([$sessao->aberto_por, $sessao->submetido_por])
            ->merge($linhas->pluck('contado_por'))
            ->filter(fn ($id): bool => $id !== null)
            ->map(fn ($id): int => (int) $id)
            ->unique();

        if ($autores->contains($utilizadorId)) {
            throw new DomainException('Quem abriu, submeteu ou registou contagens neste inventário não pode aprovar nem rejeitar os seus ajustes.');
        }
    }

    private function validarTenant(Empresa $empresa): void
    {
        $tenant = app(TenantManager::class);

        if ($tenant->has() && $tenant->emBypass() === false && $tenant->id() !== (int) $empresa->id) {
            throw new DomainException('O contexto atual não corresponde à empresa do inventário.');
        }
    }

    private function emMilhares(string $valor): int
    {
        $valor = trim($valor);

        if (preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,3}))?$/', $valor, $partes) !== 1) {
            throw new InvalidArgumentException('Quantidade inválida. Usa um valor não negativo com até três casas decimais.');
        }

        return ((int) $partes[1] * 1000) + (int) str_pad($partes[2] ?? '', 3, '0');
    }

    private function formatar(int $valor): string
    {
        $sinal = $valor < 0 ? '-' : '';
        $absoluto = abs($valor);

        return sprintf('%s%d.%03d', $sinal, intdiv($absoluto, 1000), $absoluto % 1000);
    }
}
