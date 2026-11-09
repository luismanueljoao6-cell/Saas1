#!/usr/bin/env bash
set -Eeuo pipefail
ROOT=/workspaces/Saas1
BRANCH=feat/faturacao-fiscal-core
cd "$ROOT"
[[ "$(git branch --show-current)" == "$BRANCH" ]] || { echo "ERRO: executa apenas na branch $BRANCH"; exit 1; }
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP=".backup-correcao-monetaria-$STAMP"
FILES=(
 Modules/Faturacao/app/Support/Dinheiro.php
 Modules/Faturacao/tests/Feature/RegrasDeNegocioTest.php
 Modules/Faturacao/config/config.php
 Modules/Faturacao/app/Models/Produto.php
 Modules/Atelier/app/Services/PedidoFaturacaoService.php
 Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php
 Modules/EstudioMusica/app/Models/ProjetoMusical.php
 Modules/EstudioMusica/config/config.php
)
for f in "${FILES[@]}"; do [[ -f "$f" ]] || { echo "Ficheiro ausente: $f"; exit 1; }; mkdir -p "$BACKUP/$(dirname "$f")"; cp -p "$f" "$BACKUP/$f"; done
export BACKUP
SUCCESS=0
rollback_on_failure() {
  if [[ "$SUCCESS" != "1" ]]; then
    echo "Falha detetada. A repor os ficheiros originais a partir de $BACKUP ..."
    for f in "${FILES[@]}"; do cp -p "$BACKUP/$f" "$f"; done
    echo "Reposição concluída. O backup foi mantido em $BACKUP."
  fi
}
trap rollback_on_failure EXIT
python3 <<'PY'
from pathlib import Path
import os

B = Path(os.environ['BACKUP'])
def rep(file, old, new, label):
    p = Path(file); s = p.read_text(); n = s.count(old)
    if n != 1: raise SystemExit(f'ABORTADO: {label}: esperado 1 bloco, encontrado {n} em {file}.')
    p.write_text(s.replace(old, new, 1)); print('[OK]', label)

# Motor monetário
# Reintroduz float apenas nas assinaturas públicas para que o método consiga
# detetar e rejeitar o tipo antes de qualquer coerção/conversão.
for sig in [
    ('public static function centimos(string|int|null $valor): int', 'public static function centimos(string|int|float|null $valor): int'),
    ('public static function pontosBase(string|int|null $percentagem): int', 'public static function pontosBase(string|int|float|null $percentagem): int'),
    ('public static function milesimos(string|int|null $valor): int', 'public static function milesimos(string|int|float|null $valor): int'),
    ('public static function calcularLinha(string|int $quantidade, string|int $precoUnitario, string|int $taxaIva): array', 'public static function calcularLinha(string|int|float $quantidade, string|int|float $precoUnitario, string|int|float $taxaIva): array'),
]:
    f = 'Modules/Faturacao/app/Support/Dinheiro.php'; t = Path(f).read_text()
    if sig[0] in t: rep(f, sig[0], sig[1], 'assinatura monetária aceita float só para o rejeitar')
    elif sig[1] not in t: raise SystemExit('ABORTADO: assinatura inesperada: ' + sig[0])

rep('Modules/Faturacao/app/Support/Dinheiro.php',
'''    public static function formatar(int $centimos): string
    {
        $sinal = $centimos < 0 ? '-' : '';
        $abs = abs($centimos);''',
'''    public static function formatar(int $centimos): string
    {
        if ($centimos === PHP_INT_MIN) {
            throw new \\InvalidArgumentException('Valor monetário fora do limite permitido.');
        }

        $sinal = $centimos < 0 ? '-' : '';
        $abs = abs($centimos);''', 'formatar protege PHP_INT_MIN')
rep('Modules/Faturacao/app/Support/Dinheiro.php',
'''        $semIva = intdiv($milesimos * $preco + 500, 1000);
        $iva = intdiv($semIva * $taxa + 9999, 10000); // por excesso (AGT)

        return [''',
'''        $semIva = intdiv($milesimos * $preco + 500, 1000);

        // Arredondamento por excesso sem multiplicar o montante total pela taxa.
        $quociente = intdiv($semIva, 10000);
        $resto = $semIva % 10000;
        $iva = ($quociente * $taxa) + intdiv(($resto * $taxa) + 9999, 10000);

        return [''', 'IVA calculado sem multiplicação perigosa')
rep('Modules/Faturacao/app/Support/Dinheiro.php',
'''    private static function escalar(string|int|float|null $valor, int $casas): int
    {
        if ($valor === null || $valor === '') {
            return 0;
        }

        $texto = is_float($valor) ? number_format($valor, $casas + 4, '.', '') : trim((string) $valor);''',
'''    private static function escalar(string|int|float|null $valor, int $casas): int
    {
        if (is_float($valor)) {
            throw new \\InvalidArgumentException('Não são aceites valores float para cálculos monetários. Usa uma string decimal ou um inteiro.');
        }

        if ($valor === null || $valor === '') {
            return 0;
        }

        $texto = trim((string) $valor);''', 'float rejeitado no conversor monetário')

# Fixtures e teste de float
rep('Modules/Faturacao/tests/Feature/RegrasDeNegocioTest.php',
'''    protected function linha(float $preco = 1000): array
    {
        return [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => $preco, 'taxa_iva' => 14]];
    }

    protected function faturaEmitida(Empresa $e, Cliente $c, float $preco = 1000): Fatura''',
'''    protected function linha(string|int $preco = '1000.00'): array
    {
        return [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => (string) $preco, 'taxa_iva' => '14.00']];
    }

    protected function faturaEmitida(Empresa $e, Cliente $c, string|int $preco = '1000.00'): Fatura''', 'fixtures de faturação usam decimais exatos')
rep('Modules/Faturacao/tests/Feature/RegrasDeNegocioTest.php',
'''        $this->assertSame(101, Dinheiro::centimos('1.005'));
        $this->assertSame(29, Dinheiro::centimos('0.285'));
        $this->assertSame(101, Dinheiro::centimos(1.005));
        $this->assertSame(12345, Dinheiro::centimos('123.45'));
        $this->assertSame(-250, Dinheiro::centimos('-2.50'));
        $this->assertSame(0, Dinheiro::centimos(null));
    }''',
'''        $this->assertSame(101, Dinheiro::centimos('1.005'));
        $this->assertSame(29, Dinheiro::centimos('0.285'));
        $this->assertSame(12345, Dinheiro::centimos('123.45'));
        $this->assertSame(-250, Dinheiro::centimos('-2.50'));
        $this->assertSame(0, Dinheiro::centimos(null));
    }

    public function test_float_e_rejeitado_no_motor_monetario(): void
    {
        $this->expectException(\\InvalidArgumentException::class);
        Dinheiro::centimos(1.005);
    }''', 'teste passa a exigir rejeição de float')

# Atelier: garantir que float chega à validação de Dinheiro e é rejeitado.
f = 'Modules/Atelier/app/Services/PedidoFaturacaoService.php'; t = Path(f).read_text()
old_sig = 'public function registarSinal(Pedido $pedido, string|int $valor, string $meioPagamento): Recibo'
new_sig = 'public function registarSinal(Pedido $pedido, string|int|float $valor, string $meioPagamento): Recibo'
if old_sig in t: rep(f, old_sig, new_sig, 'assinatura do sinal rejeita float através de Dinheiro')
elif new_sig not in t: raise SystemExit('ABORTADO: assinatura registarSinal inesperada')

# Atelier: comparar cêntimos e normalizar antes do gateway
rep('Modules/Atelier/app/Services/PedidoFaturacaoService.php',
'''            if (Dinheiro::centimos($valor) > Dinheiro::centimos($pedido->valorTotalComIva())) {
                throw new PedidoException('O sinal não pode exceder o valor total do pedido.');
            }

            $recibo = $this->faturacao->emitirRecibo(
                (int) $pedido->empresa_id,
                (int) $pedido->cliente_id,
                $valor,''',
'''            $valorCentimos = Dinheiro::centimos($valor);

            if ($valorCentimos > $pedido->totalComIvaEmCentimos()) {
                throw new PedidoException('O sinal não pode exceder o valor total do pedido.');
            }

            $recibo = $this->faturacao->emitirRecibo(
                (int) $pedido->empresa_id,
                (int) $pedido->cliente_id,
                Dinheiro::formatar($valorCentimos),''', 'Atelier usa total e pagamento em cêntimos inteiros')

# Estúdio: cálculo de percentagem e recibos
rep('Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php',
'''        $minutos = $sessao->duracaoMinutos();
        $precoHora = Dinheiro::centimos($sala->preco_hora);
        $total = intdiv($minutos * $precoHora + 30, 60);''',
'''        $minutos = $sessao->duracaoMinutos();
        $precoHora = Dinheiro::centimos($sala->preco_hora);

        if ($minutos < 0 || $precoHora < 0) {
            throw new InvalidArgumentException('A duração e o preço por hora não podem ser negativos.');
        }
        if ($precoHora > 0 && $minutos > intdiv(PHP_INT_MAX - 30, $precoHora)) {
            throw new InvalidArgumentException('O valor da sessão excede o limite monetário permitido.');
        }
        $total = intdiv($minutos * $precoHora + 30, 60);''', 'sessão protegida contra overflow')
rep('Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php',
'''            $recibo = $this->faturacao->emitirRecibo(
                (int) $fatura->empresa_id,
                (int) $fatura->cliente_id,
                $valor,''',
'''            $valorCentimos = Dinheiro::centimos($valor);
            if ($valorCentimos <= 0) {
                throw new InvalidArgumentException('O valor do recibo tem de ser superior a zero.');
            }

            $recibo = $this->faturacao->emitirRecibo(
                (int) $fatura->empresa_id,
                (int) $fatura->cliente_id,
                Dinheiro::formatar($valorCentimos),''', 'recibo do Estúdio normalizado e validado')
rep('Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php',
'''    protected function percentagemEmCentimos(int $centimos, string|int|float $percentual): int
    {
        $pontosBase = Dinheiro::pontosBase($percentual);

        return intdiv($centimos * $pontosBase + 5000, 10000);
    }''',
'''    protected function percentagemEmCentimos(int $centimos, string|int|float $percentual): int
    {
        if ($centimos < 0) {
            throw new InvalidArgumentException('A base da percentagem não pode ser negativa.');
        }
        $pontosBase = Dinheiro::pontosBase($percentual);
        if ($pontosBase < 0 || $pontosBase > 10000) {
            throw new InvalidArgumentException('A percentagem tem de estar entre 0 e 100.');
        }

        // Half-up sem multiplicar o montante total pela percentagem.
        $quociente = intdiv($centimos, 10000);
        $resto = $centimos % 10000;
        return ($quociente * $pontosBase) + intdiv(($resto * $pontosBase) + 5000, 10000);
    }''', 'percentagens validadas e calculadas sem overflow')
rep('Modules/EstudioMusica/app/Services/FaturacaoEstudioService.php',
'@param  array<int, array{descricao: string, quantidade: int|float, preco_unitario: string, taxa_iva: string}>  $linhas',
'@param  array<int, array{descricao: string, quantidade: int|string, preco_unitario: string, taxa_iva: string}>  $linhas', 'PHPDoc sem float em quantidades')

# Modelos e configs decimais
rep('Modules/EstudioMusica/app/Models/ProjetoMusical.php',
'''    public function percentualSinalEfetivo(): float
    {
        return (float) ($this->percentual_sinal ?? config('estudiomusica.percentual_sinal_padrao'));
    }''',
'''    public function percentualSinalEfetivo(): string
    {
        $valor = $this->percentual_sinal ?? config('estudiomusica.percentual_sinal_padrao');
        if (is_float($valor)) {
            throw new \\InvalidArgumentException('A percentagem do sinal deve ser configurada como string decimal ou inteiro.');
        }
        return (string) $valor;
    }''', 'percentual de sinal sem float')
rep('Modules/Faturacao/app/Models/Produto.php',
'''    public function taxaIvaEfetiva(): float
    {
        return $this->taxa_iva !== null
            ? (float) $this->taxa_iva
            : (float) config('faturacao.taxa_iva_geral');
    }''',
'''    public function taxaIvaEfetiva(): string
    {
        $valor = $this->taxa_iva ?? config('faturacao.taxa_iva_geral');
        if (is_float($valor)) {
            throw new \\InvalidArgumentException('A taxa de IVA deve ser configurada como string decimal ou inteiro.');
        }
        return (string) $valor;
    }''', 'taxa IVA efetiva sem float')
rep('Modules/EstudioMusica/config/config.php', "'percentual_sinal_padrao' => (float) env('ESTUDIOMUSICA_PERCENTUAL_SINAL_PADRAO', 40.0),", "'percentual_sinal_padrao' => (string) env('ESTUDIOMUSICA_PERCENTUAL_SINAL_PADRAO', '40.00'),", 'config sinal decimal')
rep('Modules/EstudioMusica/config/config.php', "'percentual_desconto_aniversario' => (float) env('ESTUDIOMUSICA_DESCONTO_ANIVERSARIO', 15.0),", "'percentual_desconto_aniversario' => (string) env('ESTUDIOMUSICA_DESCONTO_ANIVERSARIO', '15.00'),", 'config desconto decimal')
rep('Modules/Faturacao/config/config.php', "'taxa_iva_geral' => (float) env('FATURACAO_TAXA_IVA_GERAL', 14.0),", "'taxa_iva_geral' => (string) env('FATURACAO_TAXA_IVA_GERAL', '14.00'),", 'config IVA decimal')
print('Substituições concluídas.')
PY

echo '===== SINTAXE PHP ====='
for f in "${FILES[@]}"; do php -l "$f"; done
echo '===== DIFF CHECK ====='
git diff --check
echo '===== TESTE FOCADO ====='
if [[ -x vendor/bin/phpunit ]]; then vendor/bin/phpunit Modules/Faturacao/tests/Feature/RegrasDeNegocioTest.php; else php artisan test Modules/Faturacao/tests/Feature/RegrasDeNegocioTest.php; fi
echo '===== ESTADO FINAL ====='
git status --short
echo "Backup: $BACKUP"
echo 'Sem commit, push ou merge.'
SUCCESS=1
