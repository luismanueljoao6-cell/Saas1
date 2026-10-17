#!/usr/bin/env bash
# Correções confirmadas da auditoria do módulo Faturação (Saas1, branch main).
#
# Uso (na raiz do repositório, com a árvore limpa e em main):
#   bash corrigir_faturacao.sh
#   SKIP_TESTS=1 bash corrigir_faturacao.sh     # não corre a suite de testes
#
# O script NÃO faz push. Se os testes passarem, faz um commit local.
# Se alguma âncora não for encontrada, aborta sem alterar nenhum ficheiro.
set -euo pipefail

[ -d Modules/Faturacao ] || { echo "ERRO: corre este script na raiz do Saas1."; exit 1; }
command -v python3 >/dev/null || { echo "ERRO: python3 não encontrado."; exit 1; }

ramo="$(git rev-parse --abbrev-ref HEAD)"
[ "$ramo" = "main" ] || { echo "ERRO: estás em '$ramo'; muda para main."; exit 1; }

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "ERRO: há alterações por commitar. Faz commit ou stash primeiro."
  exit 1
fi

git pull --ff-only origin main

echo "==> A aplicar patches"
python3 - <<'PY'
import pathlib, sys

textos = {}

def ler(caminho):
    if caminho not in textos:
        textos[caminho] = pathlib.Path(caminho).read_text(encoding="utf-8")
    return textos[caminho]

def patch(caminho, antigo, novo):
    t = ler(caminho)
    if novo in t:
        print(f"  = já aplicado: {caminho}")
        return
    if t.count(antigo) != 1:
        sys.exit(f"ERRO: âncora não encontrada (ou ambígua) em {caminho}:\n{antigo[:160]}")
    textos[caminho] = t.replace(antigo, novo)
    print(f"  + {caminho}")

S = "Modules/Faturacao/app/Services/"

# ---------------------------------------------------------------------------
# 1) AssinaturaFiscalService — nunca usar a chave genérica em produção / AGT ativa
# ---------------------------------------------------------------------------
f = S + "AssinaturaFiscalService.php"

patch(f,
r'''    protected function caminhoDaChave(?int $empresaId): string
    {
        if ($empresaId !== null) {''',
r'''    protected function caminhoDaChave(?int $empresaId): string
    {
        $porEmpresa = null;

        if ($empresaId !== null) {''')

patch(f,
r'''        return (string) config('faturacao.chave_privada_path');''',
r'''        if ($this->permiteChaveGenerica()) {
            return (string) config('faturacao.chave_privada_path');
        }

        // Produção ou comunicação AGT ativa: nunca assinar com a chave genérica.
        throw AssinaturaFiscalException::chavePrivadaNaoEncontrada(
            $porEmpresa ?? '(chave da empresa: NIF em falta)'
        );
    }

    /**
     * A chave genérica só serve para desenvolvimento/testes: nunca em
     * produção e, com a AGT ativa, só se for pedido explicitamente
     * (faturacao.permitir_chave_generica, por omissão false).
     */
    protected function permiteChaveGenerica(): bool
    {
        if (app()->isProduction()) {
            return false;
        }

        return ! config('faturacao.agt.ativo')
            || (bool) config('faturacao.permitir_chave_generica', false);''')

# ---------------------------------------------------------------------------
# 2) Dinheiro — rejeitar entradas inválidas, negativas e overflow
# ---------------------------------------------------------------------------
f = "Modules/Faturacao/app/Support/Dinheiro.php"

patch(f,
r'''            return (int) round(((float) $valor) * (10 ** $casas));
        }
''',
r'''            throw new \InvalidArgumentException('Valor numérico inválido: "'.mb_substr($texto, 0, 40).'".');
        }

        if (strlen(ltrim($m[2], '0')) > 15) {
            throw new \InvalidArgumentException('Valor numérico demasiado grande.');
        }
''')

patch(f,
r'''        $milesimos = self::escalar($quantidade, 3);
        $preco = self::centimos($precoUnitario);
        $taxa = self::escalar($taxaIva, 2); // 14.00 -> 1400

        $semIva = intdiv($milesimos * $preco + 500, 1000);
        $iva = intdiv($semIva * $taxa + 9999, 10000); // por excesso (AGT)''',
r'''        if (trim((string) $quantidade) === '') {
            throw new \InvalidArgumentException('A quantidade é obrigatória.');
        }

        if (trim((string) $precoUnitario) === '') {
            throw new \InvalidArgumentException('O preço unitário é obrigatório.');
        }

        $milesimos = self::escalar($quantidade, 3);
        $preco = self::centimos($precoUnitario);
        $taxa = self::escalar($taxaIva, 2); // 14.00 -> 1400

        if ($milesimos <= 0) {
            throw new \InvalidArgumentException('A quantidade tem de ser positiva.');
        }

        if ($preco < 0) {
            throw new \InvalidArgumentException('O preço unitário não pode ser negativo.');
        }

        if ($taxa < 0 || $taxa > 10000) {
            throw new \InvalidArgumentException('A taxa de IVA tem de estar entre 0 e 100.');
        }

        // Evita overflow silencioso de int (PHP converte para float).
        if ($preco > 0 && $milesimos > intdiv(PHP_INT_MAX - 500, $preco)) {
            throw new \InvalidArgumentException('Valor da linha demasiado grande.');
        }

        $semIva = intdiv($milesimos * $preco + 500, 1000);

        if ($taxa > 0 && $semIva > intdiv(PHP_INT_MAX - 9999, $taxa)) {
            throw new \InvalidArgumentException('Valor da linha demasiado grande.');
        }

        $iva = intdiv($semIva * $taxa + 9999, 10000); // por excesso (AGT)''')

# ---------------------------------------------------------------------------
# 3) AgtDocumentoBuilder — sem float nos totais; produto filtrado pela empresa
# ---------------------------------------------------------------------------
f = S + "AgtDocumentoBuilder.php"

patch(f,
r'''        return NumeroJson::deCentimos((int) round(((float) $valor) * 100));''',
r'''        return NumeroJson::deCentimos(Dinheiro::centimos(is_scalar($valor) ? $valor : null));''')

patch(f,
r'''Produto::withoutGlobalScopes()->find($linha->produto_id)''',
r'''Produto::withoutGlobalScopes()->where('empresa_id', $documento->empresa_id)->find($linha->produto_id)''')

# ---------------------------------------------------------------------------
# 4) NotaCreditoDebitoService — GuardaEmissao + NC exige fatura de origem
# ---------------------------------------------------------------------------
f = S + "NotaCreditoDebitoService.php"

patch(f,
r'''use Modules\Faturacao\Support\Dinheiro;
''',
r'''use Modules\Faturacao\Support\Dinheiro;
use Modules\Faturacao\Support\GuardaEmissao;
''')

patch(f,
r'''                if ($nota->estaEmitido()) {
                    return $nota->fresh('linhas');
                }
''',
r'''                if ($nota->estaEmitido()) {
                    return $nota->fresh('linhas');
                }

                GuardaEmissao::garantir((int) $nota->empresa_id);
''')

patch(f,
r'''        if (trim($motivo) === '') {
            throw new \InvalidArgumentException('O motivo é obrigatório.');
        }
''',
r'''        if (trim($motivo) === '') {
            throw new \InvalidArgumentException('O motivo é obrigatório.');
        }

        if ($tipo === 'credito' && $faturaId === null) {
            throw new \DomainException('Uma nota de crédito tem de referir a fatura de origem.');
        }
''')

# ---------------------------------------------------------------------------
# 5) FaturaService — produtos das linhas têm de pertencer à empresa
# ---------------------------------------------------------------------------
f = S + "FaturaService.php"

patch(f,
r'''use Modules\Faturacao\Models\FaturaLinha;
''',
r'''use Modules\Faturacao\Models\FaturaLinha;
use Modules\Faturacao\Models\Produto;
''')

patch(f,
r'''        if ((int) $cliente->empresa_id !== (int) $empresa->id) {
            throw new \DomainException('O cliente não pertence a esta empresa.');
        }
''',
r'''        if ((int) $cliente->empresa_id !== (int) $empresa->id) {
            throw new \DomainException('O cliente não pertence a esta empresa.');
        }

        $produtoIds = array_values(array_unique(array_filter(
            array_map(fn ($linha) => $linha['produto_id'] ?? null, $linhas)
        )));

        if ($produtoIds !== []) {
            $validos = Produto::withoutGlobalScopes()
                ->where('empresa_id', $empresa->id)
                ->whereIn('id', $produtoIds)
                ->count();

            if ($validos !== count($produtoIds)) {
                throw new \DomainException('Há produtos que não pertencem a esta empresa.');
            }
        }
''')

for caminho, texto in textos.items():
    pathlib.Path(caminho).write_text(texto, encoding="utf-8")

print("  patches aplicados.")
PY

# Teste unitário novo (só se existir a pasta de testes do módulo).
if [ -d Modules/Faturacao/tests/Unit ]; then
  cat > Modules/Faturacao/tests/Unit/DinheiroValidacaoTest.php <<'PHP'
<?php

namespace Modules\Faturacao\Tests\Unit;

use InvalidArgumentException;
use Modules\Faturacao\Support\Dinheiro;
use PHPUnit\Framework\TestCase;

class DinheiroValidacaoTest extends TestCase
{
    public function test_calcula_linha_valida(): void
    {
        $this->assertSame(
            ['sem_iva' => '21.00', 'iva' => '2.94', 'total' => '23.94'],
            Dinheiro::calcularLinha('2', '10.50', '14')
        );
    }

    public function test_rejeita_texto_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Dinheiro::calcularLinha('abc', '10', '14');
    }

    public function test_rejeita_quantidade_zero_ou_negativa(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Dinheiro::calcularLinha('0', '10', '14');
    }

    public function test_rejeita_preco_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Dinheiro::calcularLinha('1', '-10', '14');
    }

    public function test_rejeita_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Dinheiro::calcularLinha('999999999999999', '999999999999999', '14');
    }
}
PHP
  echo "  + Modules/Faturacao/tests/Unit/DinheiroValidacaoTest.php"
fi

echo "==> php -l nos ficheiros alterados"
for ficheiro in $(git diff --name-only; git ls-files --others --exclude-standard); do
  case "$ficheiro" in *.php) php -l "$ficheiro" >/dev/null && echo "  ok $ficheiro";; esac
done

if [ "${SKIP_TESTS:-0}" != "1" ]; then
  echo "==> A correr os testes"
  if ! php artisan test --compact; then
    echo
    echo "Os testes falharam. Nada foi commitado."
    echo "Vê as alterações com:  git diff"
    echo "Para desfazer tudo:    git checkout -- . && git clean -fd Modules/Faturacao/tests"
    echo "Nota: testes antigos que assinam com a chave genérica com agt.ativo=true devem definir"
    echo "      config(['faturacao.permitir_chave_generica' => true]); testes que criam NC sem fatura"
    echo "      ou valores inválidos têm de ser ajustados."
    exit 1
  fi
fi

git add -A Modules/Faturacao
git commit -m "fix(faturacao): chave genérica só em dev, validação monetária estrita, GuardaEmissao em NC/ND, NC exige origem, produtos por empresa"

echo
echo "Concluído. Revê com 'git show' e, se estiver tudo bem:  git push origin main"
