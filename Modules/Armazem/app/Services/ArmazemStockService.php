<?php

namespace Modules\Armazem\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Armazem\Models\Armazem;
use Modules\Armazem\Models\ArmazemStockMovimento;
use Modules\Armazem\Models\ArmazemStockSaldo;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Produto;

class ArmazemStockService
{
    public function entrada(
        Empresa $empresa,
        Armazem $armazem,
        Produto $produto,
        string $quantidade,
        string $motivo,
        ?int $utilizadorId = null,
        ?string $chaveIdempotencia = null
    ): ArmazemStockMovimento {
        return $this->movimentar(
            $empresa, $armazem, $produto, 'entrada', $quantidade,
            $motivo, $utilizadorId, $chaveIdempotencia
        );
    }

    public function saida(
        Empresa $empresa,
        Armazem $armazem,
        Produto $produto,
        string $quantidade,
        string $motivo,
        ?int $utilizadorId = null,
        ?string $chaveIdempotencia = null
    ): ArmazemStockMovimento {
        return $this->movimentar(
            $empresa, $armazem, $produto, 'saida', $quantidade,
            $motivo, $utilizadorId, $chaveIdempotencia
        );
    }

    /** A quantidade indicada é a contagem física final, não a variação. */
    public function ajustar(
        Empresa $empresa,
        Armazem $armazem,
        Produto $produto,
        string $quantidadeContada,
        string $motivo,
        ?int $utilizadorId = null,
        ?string $chaveIdempotencia = null
    ): ArmazemStockMovimento {
        $contagem = $this->emMilhares($quantidadeContada);
        $this->validarTexto($motivo, $chaveIdempotencia);

        return DB::transaction(function () use (
            $empresa, $armazem, $produto, $contagem, $motivo, $utilizadorId, $chaveIdempotencia
        ): ArmazemStockMovimento {
            [$armazemBloqueado, $produtoValidado, $saldo] = $this->prepararOperacao(
                $empresa, $armazem, $produto
            );

            $existente = $this->movimentoExistente(
                $empresa, $armazemBloqueado, $produtoValidado, 'ajuste',
                $this->formatar($contagem), trim($motivo), $chaveIdempotencia
            );

            if ($existente) {
                return $existente;
            }

            $anterior = $this->emMilhares((string) $saldo->quantidade);
            $delta = $contagem - $anterior;

            return $this->guardar(
                $empresa, $armazemBloqueado, $produtoValidado, $saldo,
                'ajuste', $delta, $anterior, $contagem, trim($motivo),
                $utilizadorId, $chaveIdempotencia
            );
        }, attempts: 3);
    }

    private function movimentar(
        Empresa $empresa,
        Armazem $armazem,
        Produto $produto,
        string $tipo,
        string $quantidade,
        string $motivo,
        ?int $utilizadorId,
        ?string $chaveIdempotencia
    ): ArmazemStockMovimento {
        $unidades = $this->emMilhares($quantidade);

        if ($unidades <= 0) {
            throw new InvalidArgumentException('A quantidade deve ser superior a zero.');
        }

        $this->validarTexto($motivo, $chaveIdempotencia);

        return DB::transaction(function () use (
            $empresa, $armazem, $produto, $tipo, $unidades, $motivo, $utilizadorId, $chaveIdempotencia
        ): ArmazemStockMovimento {
            [$armazemBloqueado, $produtoValidado, $saldo] = $this->prepararOperacao(
                $empresa, $armazem, $produto
            );

            $delta = $tipo === 'entrada' ? $unidades : -$unidades;
            $existente = $this->movimentoExistente(
                $empresa, $armazemBloqueado, $produtoValidado, $tipo,
                $this->formatar($delta), trim($motivo), $chaveIdempotencia
            );

            if ($existente) {
                return $existente;
            }

            $anterior = $this->emMilhares((string) $saldo->quantidade);
            $posterior = $anterior + $delta;

            if ($posterior < 0) {
                throw new DomainException('Stock insuficiente neste armazém.');
            }

            return $this->guardar(
                $empresa, $armazemBloqueado, $produtoValidado, $saldo,
                $tipo, $delta, $anterior, $posterior, trim($motivo),
                $utilizadorId, $chaveIdempotencia
            );
        }, attempts: 3);
    }

    /**
     * O bloqueio da empresa serializa operações desta primeira versão e evita
     * corrida na criação do saldo e na verificação da chave idempotente.
     * Poderá ser afinado para bloqueios por produto após testes de carga.
     */
    private function prepararOperacao(Empresa $empresa, Armazem $armazem, Produto $produto): array
    {
        $this->validarTenant($empresa);

        Empresa::query()->whereKey($empresa->id)->lockForUpdate()->firstOrFail();

        $armazemBloqueado = Armazem::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereKey($armazem->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $armazemBloqueado->ativo) {
            throw new DomainException('Não é possível movimentar stock num armazém inativo.');
        }

        $produtoValidado = Produto::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereKey($produto->id)
            ->firstOrFail();

        if (! $produtoValidado->controla_stock) {
            throw new DomainException('O produto não está configurado para controlar stock.');
        }

        DB::table('armazem_stock_saldos')->insertOrIgnore([
            'empresa_id' => $empresa->id,
            'armazem_id' => $armazemBloqueado->id,
            'produto_id' => $produtoValidado->id,
            'quantidade' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saldo = ArmazemStockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('armazem_id', $armazemBloqueado->id)
            ->where('produto_id', $produtoValidado->id)
            ->lockForUpdate()
            ->firstOrFail();

        return [$armazemBloqueado, $produtoValidado, $saldo];
    }

    private function movimentoExistente(
        Empresa $empresa,
        Armazem $armazem,
        Produto $produto,
        string $tipo,
        string $quantidadeEsperada,
        string $motivo,
        ?string $chaveIdempotencia
    ): ?ArmazemStockMovimento {
        if ($chaveIdempotencia === null) {
            return null;
        }

        $existente = ArmazemStockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('chave_idempotencia', $chaveIdempotencia)
            ->first();

        if (! $existente) {
            return null;
        }

        $corresponde = (int) $existente->armazem_id === (int) $armazem->id
            && (int) $existente->produto_id === (int) $produto->id
            && $existente->tipo === $tipo
            && $existente->motivo === $motivo;

        if ($tipo === 'ajuste') {
            $corresponde = $corresponde
                && (string) $existente->saldo_posterior === $quantidadeEsperada;
        } else {
            $corresponde = $corresponde
                && (string) $existente->quantidade === $quantidadeEsperada;
        }

        if (! $corresponde) {
            throw new DomainException('A chave de idempotência já foi utilizada noutro movimento.');
        }

        return $existente;
    }

    private function guardar(
        Empresa $empresa,
        Armazem $armazem,
        Produto $produto,
        ArmazemStockSaldo $saldo,
        string $tipo,
        int $delta,
        int $anterior,
        int $posterior,
        string $motivo,
        ?int $utilizadorId,
        ?string $chaveIdempotencia
    ): ArmazemStockMovimento {
        if ($utilizadorId !== null && ! User::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereKey($utilizadorId)
            ->exists()) {
            throw new DomainException('O utilizador do movimento não pertence à empresa.');
        }

        $movimento = ArmazemStockMovimento::create([
            'empresa_id' => $empresa->id,
            'armazem_id' => $armazem->id,
            'produto_id' => $produto->id,
            'utilizador_id' => $utilizadorId,
            'tipo' => $tipo,
            'quantidade' => $this->formatar($delta),
            'saldo_anterior' => $this->formatar($anterior),
            'saldo_posterior' => $this->formatar($posterior),
            'motivo' => $motivo,
            'chave_idempotencia' => $chaveIdempotencia,
        ]);

        $saldo->update(['quantidade' => $this->formatar($posterior)]);

        return $movimento;
    }

    private function validarTenant(Empresa $empresa): void
    {
        $tenant = app(TenantManager::class);

        if ($tenant->has() && ! $tenant->emBypass() && $tenant->id() !== (int) $empresa->id) {
            throw new DomainException('O contexto atual não corresponde à empresa do movimento.');
        }
    }

    private function validarTexto(string $motivo, ?string $chaveIdempotencia): void
    {
        $motivo = trim($motivo);

        if ($motivo === '' || mb_strlen($motivo) > 255) {
            throw new InvalidArgumentException('Indica um motivo com até 255 caracteres.');
        }

        if ($chaveIdempotencia !== null
            && (trim($chaveIdempotencia) === '' || mb_strlen($chaveIdempotencia) > 100)) {
            throw new InvalidArgumentException('A chave de idempotência deve ter entre 1 e 100 caracteres.');
        }
    }

    private function emMilhares(string $valor): int
    {
        $valor = trim($valor);

        if (! preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,3}))?$/', $valor, $partes)) {
            throw new InvalidArgumentException('Quantidade inválida. Usa até três casas decimais.');
        }

        return ((int) $partes[1] * 1000) + (int) str_pad($partes[2] ?? '', 3, '0');
    }

    private function formatar(int $valor): string
    {
        $sinal = $valor < 0 ? '-' : '';
        $absoluto = abs($valor);

        return $sinal.intdiv($absoluto, 1000).'.'.str_pad((string) ($absoluto % 1000), 3, '0', STR_PAD_LEFT);
    }
}
