<?php

namespace Modules\Faturacao\Support;

use Modules\Faturacao\Models\Produto;

/**
 * Validação central das linhas de documentos fiscais. Chamada pelos serviços
 * (não só pelos FormRequests), para que qualquer módulo que os invoque
 * directamente tenha as mesmas garantias da interface.
 */
final class ValidacaoLinhas
{
    /**
     * @param  array<int, array<string, mixed>>  $linhas
     * @param  bool  $verificarTaxas  false em notas de crédito, que espelham a fatura original
     *
     * @throws \InvalidArgumentException dados malformados
     * @throws \DomainException regra de negócio (taxa, produto)
     */
    public static function validar(array $linhas, int $empresaId, bool $verificarTaxas = true): void
    {
        foreach (array_values($linhas) as $indice => $linha) {
            $n = $indice + 1;

            if (trim((string) ($linha['descricao'] ?? '')) === '') {
                throw new \InvalidArgumentException("Linha {$n}: a descrição é obrigatória.");
            }

            if (! array_key_exists('taxa_iva', $linha)) {
                throw new \InvalidArgumentException("Linha {$n}: a taxa de IVA é obrigatória.");
            }

            // Quantidade, preço e taxa: a própria aritmética rejeita o que é inválido.
            try {
                Dinheiro::calcularLinha($linha['quantidade'] ?? 0, $linha['preco_unitario'] ?? 0, $linha['taxa_iva']);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("Linha {$n}: ".$e->getMessage(), 0, $e);
            }

            if ($verificarTaxas) {
                self::validarTaxa((float) $linha['taxa_iva'], $n);
            }

            $produtoId = $linha['produto_id'] ?? null;

            if ($produtoId !== null && ! Produto::withoutGlobalScopes()
                ->where('empresa_id', $empresaId)
                ->whereNull('deleted_at')
                ->whereKey($produtoId)
                ->exists()) {
                throw new \DomainException("Linha {$n}: o produto não pertence a esta empresa.");
            }
        }
    }

    protected static function validarTaxa(float $taxa, int $n): void
    {
        $permitida = false;

        foreach ((array) config('faturacao.taxas_iva_permitidas', []) as $aceite) {
            if (abs((float) $aceite - $taxa) < 0.0001) {
                $permitida = true;
                break;
            }
        }

        if (! $permitida) {
            throw new \DomainException("Linha {$n}: a taxa de IVA {$taxa}% não é permitida.");
        }

        // Com a AGT ativa, uma taxa sem código AGT só falharia no envio: recusa-se já.
        if (config('faturacao.agt.ativo')) {
            $codigos = (array) config('faturacao.agt.codigos_taxa', []);

            if (! isset($codigos[number_format($taxa, 2, '.', '')])) {
                throw new \DomainException("Linha {$n}: a taxa de IVA {$taxa}% ainda não pode ser comunicada à AGT (sem código AGT configurado).");
            }
        }
    }
}
