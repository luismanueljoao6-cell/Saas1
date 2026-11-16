<?php

namespace Modules\\Faturacao\\Services;

use DomainException;
use Illuminate\\Support\\Facades\\DB;
use InvalidArgumentException;
use Modules\\Armazem\\Models\\ArmazemStockSaldo;
use Modules\\Armazem\\Services\\ArmazemService;
use Modules\\Armazem\\Services\\ArmazemStockService;
use Modules\\Core\\Models\\Empresa;
use Modules\\Core\\Services\\TenantManager;
use Modules\\Faturacao\\Models\\Produto;
use Modules\\Faturacao\\Models\\StockMovimento;

class StockService
{
    public function __construct(
        private readonly ArmazemService $armazens,
        private readonly ArmazemStockService $stockPorArmazem,
    ) {}

    public function entrada(Empresa $empresa, Produto $produto, string $quantidade, string $motivo, ?int $utilizadorId = null, ?string $chaveIdempotencia = null): StockMovimento
    {
        return $this->movimentar($empresa, $produto, 'entrada', $quantidade, $motivo, $utilizadorId, $chaveIdempotencia);
    }

    public function saida(Empresa $empresa, Produto $produto, string $quantidade, string $motivo, ?int $utilizadorId = null, ?string $chaveIdempotencia = null): StockMovimento
    {
        return $this->movimentar($empresa, $produto, 'saida', $quantidade, $motivo, $utilizadorId, $chaveIdempotencia);
    }

    /**
     * A API legada sem armazem_id opera explicitamente no armazém principal.
     * A contagem recebida é o saldo físico final desse armazém, não o total
     * agregado de todos os armazéns da empresa.
     */
    public function ajustar(Empresa $empresa, Produto $produto, string $quantidadeContada, string $motivo, ?int $utilizadorId = null, ?string $chaveIdempotencia = null): StockMovimento
    {
        $contagem = $this->emMilhares($quantidadeContada);
        $this->validarTexto($motivo, $chaveIdempotencia);

        return DB::transaction(function () use ($empresa, $produto, $contagem, $motivo, $utilizadorId, $chaveIdempotencia): StockMovimento {
            $this->validarContexto($empresa, $produto);
            $armazem = $this->armazens->obterPrincipal($empresa);
            $existente = $this->movimentoLegadoExistente($empresa, $produto, 'ajuste', null, $motivo, $chaveIdempotencia);
            if ($existente) {
                return $existente;
            }

            $saldoArmazem = ArmazemStockSaldo::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->where('armazem_id', $armazem->id)
                ->where('produto_id', $produto->id)
                ->lockForUpdate()
                ->value('quantidade') ?? '0.000';
                $antes = $this->saldoAgregado($empresa, $produto);
            $this->stockPorArmazem->ajustar(
                $empresa, $armazem, $produto, $this->formatar($contagem),
                trim($motivo), $utilizadorId, $chaveIdempotencia
            );
            $depois = $this->saldoAgregado($empresa, $produto);

            return $this->registarMovimentoLegado(
                $empresa, $produto, 'ajuste', $depois - $antes, $antes, $depois,
                $motivo, $utilizadorId, $chaveIdempotencia
            );
        }, attempts: 3);
    }

    private function movimentar(Empresa $empresa, Produto $produto, string $tipo, string $quantidade, string $motivo, ?int $utilizadorId, ?string $chaveIdempotencia): StockMovimento
    {
        $unidades = $this->emMilhares($quantidade);
        if ($unidades <= 0) {
            throw new InvalidArgumentException('A quantidade deve ser superior a zero.');
        }
        $this->validarTexto($motivo, $chaveIdempotencia);

        return DB::transaction(function () use ($empresa, $produto, $tipo, $unidades, $motivo, $utilizadorId, $chaveIdempotencia): StockMovimento {
            $this->validarContexto($empresa, $produto);
            $delta = $tipo === 'entrada' ? $unidades : -$unidades;
            $existente = $this->movimentoLegadoExistente($empresa, $produto, $tipo, $delta, $motivo, $chaveIdempotencia);
            if ($existente) {
                return $existente;
            }

            $armazem = $this->armazens->obterPrincipal($empresa);
            $antes = $this->saldoAgregado($empresa, $produto);

            if ($tipo === 'entrada') {
                $this->stockPorArmazem->entrada($empresa, $armazem, $produto, $this->formatar($unidades), trim($motivo), $utilizadorId, $chaveIdempotencia);
            } else {
                $this->stockPorArmazem->saida($empresa, $armazem, $produto, $this->formatar($unidades), trim($motivo), $utilizadorId, $chaveIdempotencia);
            }

            $depois = $this->saldoAgregado($empresa, $produto);
            return $this->registarMovimentoLegado(
                $empresa, $produto, $tipo, $depois - $antes, $antes, $depois,
                $motivo, $utilizadorId, $chaveIdempotencia
            );
        }, attempts: 3);
    }

    private function validarContexto(Empresa $empresa, Produto $produto): void
    {
        $tenant = app(TenantManager::class);
        if ($tenant->has() && ! $tenant->emBypass() && $tenant->id() !== (int) $empresa->id) {
            throw new DomainException('O contexto atual não corresponde à empresa do movimento.');
        }
        if ((int) $produto->empresa_id !== (int) $empresa->id || ! $produto->controla_stock) {
            throw new DomainException('O produto não pertence à empresa ou não controla stock.');
        }
    }

    private function movimentoLegadoExistente(Empresa $empresa, Produto $produto, string $tipo, ?int $delta, string $motivo, ?string $chave): ?StockMovimento
    {
        if ($chave === null) {
            return null;
        }
        $existente = StockMovimento::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->where('chave_idempotencia', $chave)
            ->first();
        if (! $existente) {
            return null;
        }
        $corresponde = (int) $existente->produto_id === (int) $produto->id
            && $existente->tipo === $tipo
            && $existente->motivo === trim($motivo)
            && ($delta === null || (string) $existente->quantidade === $this->formatar($delta));
        if (! $corresponde) {
            throw new DomainException('A chave de idempotência já foi utilizada noutro movimento.');
        }
        return $existente;
    }

    private function registarMovimentoLegado(Empresa $empresa, Produto $produto, string $tipo, int $delta, int $anterior, int $posterior, string $motivo, ?int $utilizadorId, ?string $chave): StockMovimento
    {
        return StockMovimento::create([
            'empresa_id' => $empresa->id,
            'produto_id' => $produto->id,
            'utilizador_id' => $utilizadorId,
            'tipo' => $tipo,
            'quantidade' => $this->formatar($delta),
            'saldo_anterior' => $this->formatar($anterior),
            'saldo_posterior' => $this->formatar($posterior),
            'motivo' => trim($motivo),
            'chave_idempotencia' => $chave,
        ]);
    }

    private function saldoAgregado(Empresa $empresa, Produto $produto): int
    {
        $saldos = DB::table('armazem_stock_saldos')
            ->where('empresa_id', $empresa->id)
            ->where('produto_id', $produto->id)
            ->get(['quantidade']);

        $total = 0;
        foreach ($saldos as $saldo) {
            $total += $this->emMilhares((string) $saldo->quantidade);
        }

        if ($total > 99_999_999_999_999) {
            throw new DomainException('O saldo agregado ultrapassa o limite decimal suportado.');
        }

        return $total;
    }

    private function validarTexto(string $motivo, ?string $chave): void
    {
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 255) {
            throw new InvalidArgumentException('Indica um motivo com até 255 caracteres.');
        }
        if ($chave !== null && (trim($chave) === '' || mb_strlen($chave) > 100)) {
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
