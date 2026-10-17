#!/usr/bin/env bash
# Fase 1 — correções da auditoria do módulo Faturação (Saas1, branch main).
# Correr na RAIZ do repositório:  bash fase1-faturacao.sh
# Não faz commit. Cada alteração falha de forma ruidosa se o texto original não bater.
set -euo pipefail

[ -f artisan ] && [ -d Modules/Faturacao ] || { echo "Corre na raiz do Saas1."; exit 1; }
command -v python3 >/dev/null || { echo "python3 em falta."; exit 1; }

python3 - <<'PY'
import pathlib, sys

def sub(path, old, new):
    p = pathlib.Path(path)
    s = p.read_text(encoding="utf-8")
    n = s.count(old)
    if n != 1:
        sys.exit(f"ERRO {path}: trecho encontrado {n}x (esperado 1x):\n{old[:120]}")
    p.write_text(s.replace(old, new), encoding="utf-8")
    print(f"OK  {path}")

# ---------------------------------------------------------------- 1. Chave genérica (P0)
f = "Modules/Faturacao/app/Services/AssinaturaFiscalService.php"
p = pathlib.Path(f)
s = p.read_text(encoding="utf-8")
inicio = "    protected function caminhoDaChave(?int $empresaId): string\n"
if s.count(inicio) != 1:
    sys.exit("ERRO: caminhoDaChave() não encontrado")
novo = r'''    protected function caminhoDaChave(?int $empresaId): string
    {
        if ($empresaId !== null) {
            $nif = (string) Empresa::query()->whereKey($empresaId)->value('nif');
            // Só caracteres seguros no nome do ficheiro (sem '/', '..' etc.).
            $nif = preg_replace('/[^A-Za-z0-9]/', '', $nif) ?? '';

            $porEmpresa = rtrim((string) config('faturacao.chaves_dir'), '/\\').DIRECTORY_SEPARATOR.($nif !== '' ? $nif : 'SEM-NIF').'.pem';

            if ($nif !== '' && is_file($porEmpresa)) {
                return $porEmpresa;
            }

            // Um documento fiscal de uma empresa nunca pode ser assinado com a
            // chave genérica fora de desenvolvimento/testes.
            if (! $this->permiteChaveGenerica()) {
                throw AssinaturaFiscalException::chavePrivadaNaoEncontrada($porEmpresa);
            }
        }

        return (string) config('faturacao.chave_privada_path');
    }

    /** A chave por omissão só é aceite em local/testing e com a AGT desligada. */
    protected function permiteChaveGenerica(): bool
    {
        return ! config('faturacao.agt.ativo')
            && app()->environment(['local', 'testing']);
    }
}
'''
p.write_text(s[: s.index(inicio)] + novo, encoding="utf-8")
print(f"OK  {f}")

# ---------------------------------------------------------------- 2. Dinheiro (P1)
f = "Modules/Faturacao/app/Support/Dinheiro.php"
sub(f, "final class Dinheiro\n{\n",
    "final class Dinheiro\n{\n"
    "    /** Máx. de quantidade × preço (milésimos × cêntimos) para não haver overflow de int. */\n"
    "    private const LIMITE_LINHA = 900_000_000_000_000_000;\n\n")

sub(f, "        $taxa = self::escalar($taxaIva, 2); // 14.00 -> 1400\n",
    r'''        $taxa = self::escalar($taxaIva, 2); // 14.00 -> 1400

        if ($milesimos <= 0) {
            throw new \InvalidArgumentException('A quantidade tem de ser positiva.');
        }

        if ($preco < 0) {
            throw new \InvalidArgumentException('O preço unitário não pode ser negativo.');
        }

        if ($taxa < 0 || $taxa > 10000) {
            throw new \InvalidArgumentException('A taxa de IVA tem de estar entre 0 e 100.');
        }

        if ($preco > 0 && $milesimos > intdiv(self::LIMITE_LINHA, $preco)) {
            throw new \InvalidArgumentException('O valor da linha excede o limite permitido.');
        }
''')

sub(f, r'''            return (int) round(((float) $valor) * (10 ** $casas));
        }
''', r'''            throw new \InvalidArgumentException("Valor numérico inválido: '{$texto}'.");
        }

        if (strlen(ltrim($m[2], '0')) > 12) {
            throw new \InvalidArgumentException('Valor numérico demasiado grande.');
        }
''')

# ---------------------------------------------------------------- 3. Notas de crédito/débito (P1)
f = "Modules/Faturacao/app/Services/NotaCreditoDebitoService.php"
sub(f, "use Modules\\Faturacao\\Support\\Dinheiro;\n",
    "use Modules\\Faturacao\\Support\\Dinheiro;\nuse Modules\\Faturacao\\Support\\GuardaEmissao;\n")

sub(f, r'''        if (trim($motivo) === '') {
            throw new \InvalidArgumentException('O motivo é obrigatório.');
        }
''', r'''        if (trim($motivo) === '') {
            throw new \InvalidArgumentException('O motivo é obrigatório.');
        }

        if ($tipo === 'credito' && $faturaId === null) {
            throw new \DomainException('Uma nota de crédito exige a fatura de origem.');
        }
''')

sub(f, r'''                if ($nota->estaEmitido()) {
                    return $nota->fresh('linhas');
                }
''', r'''                if ($nota->estaEmitido()) {
                    return $nota->fresh('linhas');
                }

                GuardaEmissao::garantir((int) $nota->empresa_id);
''')

sub(f, r'''        if ($nota->tipo !== 'credito' || ! $nota->fatura_id) {
            return;
        }
''', r'''        if ($nota->tipo !== 'credito') {
            return;
        }

        if (! $nota->fatura_id) {
            throw new \DomainException('Uma nota de crédito exige a fatura de origem.');
        }
''')

# ---------------------------------------------------------------- 4. Séries por estabelecimento (P1)
f = "Modules/Faturacao/app/Services/NumeracaoService.php"
sub(f, "    public function proximoNumero(int $empresaId, string $tipoDocumento, string $prefixo = 'A'): array\n",
    "    public function proximoNumero(int $empresaId, string $tipoDocumento, string $prefixo = 'A', ?string $estabelecimento = null): array\n")

sub(f, r'''        return $this->tenantManager->semTenant(function () use ($empresaId, $tipoDocumento, $prefixo) {
            return DB::transaction(function () use ($empresaId, $tipoDocumento, $prefixo) {
                $serie = $this->obterOuCriarSerie($empresaId, $tipoDocumento, $prefixo, comLock: true);
''', r'''        return $this->tenantManager->semTenant(function () use ($empresaId, $tipoDocumento, $prefixo, $estabelecimento) {
            return DB::transaction(function () use ($empresaId, $tipoDocumento, $prefixo, $estabelecimento) {
                $serie = $this->obterOuCriarSerie($empresaId, $tipoDocumento, $prefixo, comLock: true, estabelecimento: $estabelecimento);
''')

sub(f, r'''    public function obterOuCriarSerie(int $empresaId, string $tipoDocumento, string $prefixo = 'A', bool $comLock = false): Serie
    {
        return $this->tenantManager->semTenant(function () use ($empresaId, $tipoDocumento, $prefixo, $comLock) {
''', r'''    public function obterOuCriarSerie(int $empresaId, string $tipoDocumento, string $prefixo = 'A', bool $comLock = false, ?string $estabelecimento = null): Serie
    {
        return $this->tenantManager->semTenant(function () use ($empresaId, $tipoDocumento, $prefixo, $comLock, $estabelecimento) {
''')

sub(f, "                return $this->serieAgtAtual($empresaId, $tipoDocumento, $ano, $comLock);\n",
    "                return $this->serieAgtAtual($empresaId, $tipoDocumento, $ano, $comLock, $estabelecimento);\n")

sub(f, r'''    protected function serieAgtAtual(int $empresaId, string $tipoDocumento, int $ano, bool $comLock): Serie
    {
        $query = Serie::query()
            ->where('empresa_id', $empresaId)
            ->where('tipo_documento', $tipoDocumento)
            ->where('ano', $ano)
            ->whereNotNull('agt_codigo')
''', r'''    protected function serieAgtAtual(int $empresaId, string $tipoDocumento, int $ano, bool $comLock, ?string $estabelecimento = null): Serie
    {
        // O estabelecimento é parte da autorização da série na AGT.
        $estabelecimento ??= (string) config('faturacao.agt.estabelecimento_padrao');

        $query = Serie::query()
            ->where('empresa_id', $empresaId)
            ->where('tipo_documento', $tipoDocumento)
            ->where('ano', $ano)
            ->where('estabelecimento', $estabelecimento)
            ->whereNotNull('agt_codigo')
''')
PY

# ---------------------------------------------------------------- 5. Testes novos
cat > Modules/Faturacao/tests/Unit/DinheiroValidacaoTest.php <<'PHP'
<?php

namespace Modules\Faturacao\Tests\Unit;

use Modules\Faturacao\Support\Dinheiro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DinheiroValidacaoTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: mixed, 2: mixed}> */
    public static function entradasInvalidas(): array
    {
        return [
            'quantidade texto' => ['abc', '10.00', 14],
            'preço texto' => [1, 'abc', 14],
            'taxa texto' => [1, '10.00', 'x'],
            'virgula decimal' => [1, '10,50', 14],
            'notacao cientifica' => [1, '1e3', 14],
            'quantidade zero' => [0, '10.00', 14],
            'quantidade negativa' => [-1, '10.00', 14],
            'preço negativo' => [1, '-10.00', 14],
            'taxa negativa' => [1, '10.00', -1],
            'taxa acima de 100' => [1, '10.00', 101],
            'numero gigante' => [1, '99999999999999999999.00', 14],
            'linha acima do limite' => ['999999999', '999999999999.99', 14],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_entradas_invalidas_sao_rejeitadas(mixed $quantidade, mixed $preco, mixed $taxa): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dinheiro::calcularLinha($quantidade, $preco, $taxa);
    }

    public function test_entradas_validas_continuam_a_funcionar(): void
    {
        $this->assertSame('14.00', Dinheiro::calcularLinha(1, '100.00', 14)['iva']);
        $this->assertSame('0.00', Dinheiro::calcularLinha(1, '0.00', 14)['total']);
        $this->assertSame('100.00', Dinheiro::calcularLinha(1, '100.00', 0)['total']);
    }
}
PHP
echo "OK  Modules/Faturacao/tests/Unit/DinheiroValidacaoTest.php"

# ---------------------------------------------------------------- 6. Sintaxe
if command -v php >/dev/null; then
  for f in \
    Modules/Faturacao/app/Services/AssinaturaFiscalService.php \
    Modules/Faturacao/app/Services/NotaCreditoDebitoService.php \
    Modules/Faturacao/app/Services/NumeracaoService.php \
    Modules/Faturacao/app/Support/Dinheiro.php \
    Modules/Faturacao/tests/Unit/DinheiroValidacaoTest.php; do
    php -l "$f" >/dev/null && echo "lint OK $f"
  done
fi

cat <<'MSG'

Fase 1 aplicada (sem commit). Próximos passos:
  git diff
  php artisan test Modules/Faturacao
Se falharem testes, são provavelmente testes antigos que:
  - criam notas de crédito sem fatura de origem;
  - criam Series AGT sem o campo 'estabelecimento' (tem de ser igual a faturacao.agt.estabelecimento_padrao);
  - correm com APP_ENV diferente de local/testing e sem chave por empresa.
Corrige esses testes (eles validavam o comportamento errado) e só depois faz commit.
MSG
