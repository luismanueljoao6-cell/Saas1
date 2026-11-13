<?php

namespace Modules\Faturacao\Support;

use Modules\Core\Models\Empresa;

/**
 * Resolve preferências fiscais por tenant sem alterar o catálogo legal global.
 * Os valores guardados em empresas.configuracoes são preferências operacionais,
 * nunca substituem as validações centrais nem a confirmação da AGT.
 */
final class ConfiguracaoFiscalEmpresa
{
    /** @return array<string, mixed> */
    public static function obter(int $empresaId): array
    {
        $empresa = Empresa::withoutGlobalScopes()->find($empresaId);
        $configuracao = (array) data_get($empresa?->configuracoes, 'faturacao', []);

        return [
            'regime_iva' => $configuracao['regime_iva'] ?? 'geral',
            'taxa_iva_padrao' => (string) ($configuracao['taxa_iva_padrao'] ?? config('faturacao.taxa_iva_geral')),
            'motivos_isencao_ativos' => array_values($configuracao['motivos_isencao_ativos'] ?? array_keys((array) config('faturacao.motivos_isencao', []))),
        ];
    }

    public static function taxaPadrao(int $empresaId): string
    {
        $fiscal = self::obter($empresaId);
        $taxa = (float) $fiscal['taxa_iva_padrao'];
        $permitidas = array_map('floatval', (array) config('faturacao.taxas_iva_permitidas', []));

        return collect($permitidas)->contains(fn (float $permitida) => abs($permitida - $taxa) < 0.0001)
            ? number_format($taxa, 2, '.', '')
            : number_format((float) config('faturacao.taxa_iva_geral'), 2, '.', '');
    }

    /** @return array<string, array<string, mixed>> */
    public static function motivosDisponiveis(int $empresaId): array
    {
        $ativos = self::obter($empresaId)['motivos_isencao_ativos'];
        $catalogo = (array) config('faturacao.motivos_isencao', []);

        return array_filter(
            $catalogo,
            fn (array $motivo, string $codigo) => in_array($codigo, $ativos, true)
                && in_array($motivo['tipo'] ?? null, ['ISE', 'NS'], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
