<?php

namespace Modules\Faturacao\Support;

use Illuminate\Support\Collection;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\NotaCreditoDebito;

/**
 * Congela os dados fiscais operacionais usados no momento de emissão.
 * Não substitui o payload assinado nem determina o enquadramento legal.
 */
final class SnapshotFiscalDocumento
{
    public static function capturar(Fatura|NotaCreditoDebito $documento): array
    {
        $empresa = Empresa::withoutGlobalScopes()->findOrFail($documento->empresa_id);
        $faturacao = ConfiguracaoFiscalEmpresa::obter((int) $empresa->id);
        $linhas = $documento->linhas()->get();
        $catalogo = (array) config('faturacao.motivos_isencao', []);

        $snapshotLinhas = $linhas->map(function ($linha) use ($catalogo): array {
            $codigo = strtoupper(trim((string) $linha->motivo_isencao));
            $motivo = $codigo !== '' ? ($catalogo[$codigo] ?? null) : null;

            return [
                'descricao' => (string) $linha->descricao,
                'quantidade' => (string) $linha->quantidade,
                'preco_unitario' => (string) $linha->preco_unitario,
                'preco_unitario_base' => (string) ($linha->preco_unitario_base ?? $linha->preco_unitario),
                'taxa_iva' => (string) $linha->taxa_iva,
                'valor_sem_iva' => (string) $linha->valor_sem_iva,
                'valor_iva' => (string) $linha->valor_iva,
                'valor_total' => (string) $linha->valor_total,
                'operation_type' => $linha->operation_type,
                'motivo_isencao' => $codigo !== '' ? [
                    'codigo' => $codigo,
                    'tipo' => $motivo['tipo'] ?? null,
                    'mencao' => $motivo['mencao'] ?? null,
                    'descricao' => $motivo['descricao'] ?? null,
                ] : null,
            ];
        })->values()->all();

        $origem = null;
        if ($documento instanceof NotaCreditoDebito && $documento->fatura_id) {
            $faturaOriginal = Fatura::withoutGlobalScopes()
                ->where('empresa_id', $documento->empresa_id)
                ->find($documento->fatura_id);

            $origem = $faturaOriginal?->snapshot_fiscal;
        }

        return [
            'versao' => 1,
            'capturado_em' => now()->toIso8601String(),
            'emitente' => [
                'empresa_id' => (int) $empresa->id,
                'nome_comercial' => $empresa->nome_comercial,
                'nome_legal' => $empresa->nome_legal,
                'nif' => $empresa->nif,
                'regime_fiscal_empresa' => $empresa->regime_fiscal,
                'morada' => $empresa->morada,
                'municipio' => $empresa->municipio,
                'provincia' => $empresa->provincia,
            ],
            'configuracao_fiscal' => [
                'regime_iva' => $faturacao['regime_iva'],
                'taxa_iva_padrao' => $faturacao['taxa_iva_padrao'],
                'motivos_isencao_ativos' => $faturacao['motivos_isencao_ativos'],
                'taxas_iva_permitidas' => array_values((array) config('faturacao.taxas_iva_permitidas', [])),
                'motivos_isencao_confirmados' => (bool) config('faturacao.motivos_isencao_confirmados', false),
            ],
            'linhas' => $snapshotLinhas,
            'documento_origem' => $origem,
        ];
    }
}
