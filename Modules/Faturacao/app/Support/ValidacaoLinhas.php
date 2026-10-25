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
     * @param  bool  $verificarTaxas  false em notas de crédito (espelham a fatura original) e de débito (não liquidam IVA)
     * @param  bool  $exigirMotivoIsencao  false em notas de débito: não há liquidação de imposto e o código de não sujeição vem da configuração
     *
     * @throws \InvalidArgumentException dados malformados
     * @throws \DomainException regra de negócio (taxa, isenção, produto)
     */
    public static function validar(array $linhas, int $empresaId, bool $verificarTaxas = true, bool $exigirMotivoIsencao = true): void
    {
        foreach (array_values($linhas) as $indice => $linha) {
            $n = $indice + 1;

            if (trim((string) ($linha['descricao'] ?? '')) === '') {
                throw new \InvalidArgumentException("Linha {$n}: a descrição é obrigatória.");
            }

            // Sem valores por omissão: um preço ou uma taxa em falta nunca se tornam 0 em silêncio.
            $obrigatorios = [
                'quantidade' => 'a quantidade é obrigatória',
                'preco_unitario' => 'o preço unitário é obrigatório',
                'taxa_iva' => 'a taxa de IVA é obrigatória',
            ];

            foreach ($obrigatorios as $campo => $mensagem) {
                $valor = $linha[$campo] ?? null;

                if (! is_scalar($valor) || trim((string) $valor) === '') {
                    throw new \InvalidArgumentException("Linha {$n}: {$mensagem}.");
                }
            }

            // Quantidade, preço e taxa: a própria aritmética rejeita o que é inválido.
            try {
                Dinheiro::calcularLinha($linha['quantidade'], $linha['preco_unitario'], $linha['taxa_iva']);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("Linha {$n}: ".$e->getMessage(), 0, $e);
            }

            if ($verificarTaxas) {
                self::validarTaxa((float) $linha['taxa_iva'], $n);
            }

            if ($exigirMotivoIsencao) {
                self::validarMotivoIsencao((float) $linha['taxa_iva'], $linha['motivo_isencao'] ?? null, $n);
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

    /** Código de motivo normalizado (maiúsculas, sem espaços); null se vazio. */
    public static function normalizarMotivo(mixed $motivo): ?string
    {
        if (! is_string($motivo) || trim($motivo) === '') {
            return null;
        }

        return strtoupper(trim($motivo));
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
        // (A taxa 0 não tem taxCode próprio: identifica-se pelo motivo de isenção.)
        if (config('faturacao.agt.ativo') && $taxa > 0) {
            $codigos = (array) config('faturacao.agt.codigos_taxa', []);

            if (! isset($codigos[number_format($taxa, 2, '.', '')])) {
                throw new \DomainException("Linha {$n}: a taxa de IVA {$taxa}% ainda não pode ser comunicada à AGT (sem código AGT configurado).");
            }
        }
    }

    /** Linhas a 0% exigem motivo de isenção da tabela; linhas com imposto não levam motivo. */
    protected static function validarMotivoIsencao(float $taxa, mixed $bruto, int $n): void
    {
        if ($bruto !== null && ! is_string($bruto)) {
            throw new \InvalidArgumentException("Linha {$n}: o motivo de isenção tem de ser um código (ex.: M10).");
        }

        $motivo = self::normalizarMotivo($bruto);

        if ($taxa > 0) {
            if ($motivo !== null) {
                throw new \DomainException("Linha {$n}: só as linhas isentas (IVA 0%) levam motivo de isenção.");
            }

            return;
        }

        if ($motivo === null) {
            throw new \DomainException("Linha {$n}: uma linha isenta (IVA 0%) exige o motivo de isenção.");
        }

        if (! isset(((array) config('faturacao.motivos_isencao', []))[$motivo])) {
            throw new \DomainException("Linha {$n}: o motivo de isenção '{$motivo}' não consta da tabela (faturacao.motivos_isencao).");
        }

        // Nada de códigos por confirmar a caminho da AGT.
        if (config('faturacao.agt.ativo') && ! config('faturacao.motivos_isencao_confirmados')) {
            throw new \DomainException("Linha {$n}: com a AGT ativa, as isenções só são aceites depois de confirmares a tabela de motivos de isenção (FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS).");
        }
    }
}
