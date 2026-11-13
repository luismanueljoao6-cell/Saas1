<?php

namespace Modules\Faturacao\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Produto;
use Modules\Faturacao\Models\StockMovimento;
use Modules\Faturacao\Models\StockSaldo;

class StockService
{
    public function entrada(Empresa $empresa, Produto $produto, string $quantidade, string $motivo, ?int $utilizadorId = null, ?string $chaveIdempotencia = null): StockMovimento
    {
        return $this->movimentar($empresa, $produto, 'entrada', $quantidade, $motivo, $utilizadorId, $chaveIdempotencia);
    }

    public function saida(Empresa $empresa, Produto $produto, string $quantidade, string $motivo, ?int $utilizadorId = null, ?string $chaveIdempotencia = null): StockMovimento
    {
        return $this->movimentar($empresa, $produto, 'saida', $quantidade, $motivo, $utilizadorId, $chaveIdempotencia);
    }

    /** A quantidade recebida é a contagem física final, não a variação. */
    public function ajustar(Empresa $empresa, Produto $produto, string $quantidadeContada, string $motivo, ?int $utilizadorId = null, ?string $chaveIdempotencia = null): StockMovimento
    {
        $contagem = $this->emMilhares($quantidadeContada);
        $motivo = trim($motivo);

        return DB::transaction(function () use ($empresa, $produto, $contagem, $motivo, $utilizadorId, $chaveIdempotencia) {
            if ($chaveIdempotencia !== null) {
                if (trim($chaveIdempotencia) === '' || mb_strlen($chaveIdempotencia) > 100) {
                    throw new InvalidArgumentException('A chave de idempotência deve ter entre 1 e 100 caracteres.');
                }

                $existente = StockMovimento::withoutGlobalScopes()
                    ->where('empresa_id', $empresa->id)
                    ->where('chave_idempotencia', $chaveIdempotencia)
                    ->first();

                if ($existente) {
                    if ((int) $existente->produto_id !== (int) $produto->id
                        || $existente->tipo !== 'ajuste'
                        || $existente->motivo !== $motivo
                        || (string) $existente->saldo_posterior !== $this->formatar($contagem)) {
                        throw new DomainException('A chave de idempotência já foi utilizada noutro movimento.');
                    }

                    return $existente;
                }
            }

            $saldo = $this->saldoBloqueado($empresa, $produto);
            $anterior = $this->emMilhares((string) $saldo->quantidade);
            $delta = $contagem - $anterior;

            return $this->guardarMovimento($empresa, $produto, 'ajuste', $delta, $anterior, $contagem, $motivo, $utilizadorId, $chaveIdempotencia);
        }, attempts: 3);
    }

    protected function movimentar(Empresa $empresa, Produto $produto, string $tipo, string $quantidade, string $motivo, ?int $utilizadorId, ?string $chaveIdempotencia): StockMovimento
    {
        $unidades = $this->emMilhares($quantidade);
        if ($unidades <= 0) {
            throw new InvalidArgumentException('A quantidade deve ser superior a zero.');
        }

        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 255) {
            throw new InvalidArgumentException('Indica um motivo com até 255 caracteres.');
        }

        if ($chaveIdempotencia !== null && (trim($chaveIdempotencia) === '' || mb_strlen($chaveIdempotencia) > 100)) {
            throw new InvalidArgumentException('A chave de idempotência deve ter entre 1 e 100 caracteres.');
        }

        return DB::transaction(function () use ($empresa, $produto, $tipo, $unidades, $motivo, $utilizadorId, $chaveIdempotencia) {
            $saldo = $this->saldoBloqueado($empresa, $produto);

            // Verificar a chave depois do lock do saldo e antes de calcular a nova saída.
            // Assim, repetir uma operação já concluída não falha por falta de stock atual.
            if ($chaveIdempotencia !== null) {
                $existente = StockMovimento::withoutGlobalScopes()
                    ->where('empresa_id', $empresa->id)
                    ->where('chave_idempotencia', $chaveIdempotencia)
                    ->first();

                if ($existente) {
                    $deltaEsperado = $tipo === 'entrada' ? $unidades : -$unidades;
                    if ((int) $existente->produto_id !== (int) $produto->id
                        || $existente->tipo !== $tipo
                        || (string) $existente->quantidade !== $this->formatar($deltaEsperado)
                        || $existente->motivo !== $motivo) {
                        throw new DomainException('A chave de idempotência já foi utilizada noutro movimento.');
                    }

                    return $existente;
                }
            }

            $anterior = $this->emMilhares((string) $saldo->quantidade);
            $posterior = $tipo === 'entrada' ? $anterior + $unidades : $anterior - $unidades;

            if ($posterior < 0) {
                throw new DomainException('Stock insuficiente para esta saída.');
            }

            return $this->guardarMovimento($empresa, $produto, $tipo, $tipo === 'entrada' ? $unidades : -$unidades, $anterior, $posterior, $motivo, $utilizadorId, $chaveIdempotencia);
        }, attempts: 3);
    }

    protected function saldoBloqueado(Empresa $empresa, Produto $produto): StockSaldo
    {
        $tenant = app(TenantManager::class);
        if ($tenant->has() && ! $tenant->emBypass() && $tenant->id() !== (int) $empresa->id) {
            throw new DomainException('O contexto atual não corresponde à empresa do movimento.');
        }

        if ((int) $produto->empresa_id !== (int) $empresa->id || ! $produto->controla_stock) {
            throw new DomainException('O produto não pertence à empresa ou não controla stock.');
        }

        $agora = now();
        DB::table('stock_saldos')->insertOrIgnore([
            'empresa_id' => $empresa->id,
            'produto_id' => $produto->id,
            'quantidade' => '0.000',
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        return StockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('produto_id', $produto->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    protected function guardarMovimento(Empresa $empresa, Produto $produto, string $tipo, int $delta, int $anterior, int $posterior, string $motivo, ?int $utilizadorId, ?string $chaveIdempotencia): StockMovimento
    {
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 255) {
            throw new InvalidArgumentException('Indica um motivo com até 255 caracteres.');
        }

        if ($chaveIdempotencia !== null) {
            if (trim($chaveIdempotencia) === '' || mb_strlen($chaveIdempotencia) > 100) {
                throw new InvalidArgumentException('A chave de idempotência deve ter entre 1 e 100 caracteres.');
            }

            $existente = StockMovimento::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('chave_idempotencia', $chaveIdempotencia)
                ->first();

            if ($existente) {
                if ((int) $existente->produto_id !== (int) $produto->id
                    || $existente->tipo !== $tipo
                    || (string) $existente->quantidade !== $this->formatar($delta)
                    || $existente->motivo !== $motivo) {
                    throw new DomainException('A chave de idempotência já foi utilizada noutro movimento.');
                }

                return $existente;
            }
        }

        $saldo = StockSaldo::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('produto_id', $produto->id)
            ->lockForUpdate()
            ->firstOrFail();

        $movimento = StockMovimento::create([
            'empresa_id' => $empresa->id,
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
