#!/usr/bin/env bash
# Fase 3 (parte B) — correr na raiz do repositório, depois do script de edições principal
set -euo pipefail
python3 - . <<'PYEOF'
import sys, pathlib

raiz = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else '.')


def sub(caminho, antigo, novo):
    f = raiz / caminho
    t = f.read_text(encoding='utf-8')
    if t.count(antigo) != 1:
        sys.exit(f'ERRO em {caminho}: trecho encontrado {t.count(antigo)} vez(es): {antigo[:100]}')
    f.write_text(t.replace(antigo, novo), encoding='utf-8')
    print('ok', caminho)


# 1) getValorTotalDocumento(): float não tem nenhum chamador (nem em testes) e põe um float num contrato fiscal.
sub('Modules/Faturacao/app/Contracts/DocumentoFiscalInterface.php',
    "    public function getValorTotalDocumento(): float;\n\n", "")
for modelo, campo in (('Fatura', 'valor_total'), ('Recibo', 'valor'), ('NotaCreditoDebito', 'valor_total')):
    sub(f'Modules/Faturacao/app/Models/{modelo}.php',
        f"    public function getValorTotalDocumento(): float\n    {{\n        return (float) $this->{campo};\n    }}\n\n", "")

# 2) Docblock órfão (com floats) colado em cima de eliminarRascunho() nas notas.
sub('Modules/Faturacao/app/Services/NotaCreditoDebitoService.php',
    "    /**\n     * @param  array<int, array{descricao: string, quantidade: float, preco_unitario: float, preco_unitario_base?: float, taxa_iva: float, operation_type?: string|null}>  $linhas\n     */\n    /** Elimina uma nota ainda em rascunho;",
    "    /** Elimina uma nota ainda em rascunho;")
sub('Modules/Faturacao/app/Services/NotaCreditoDebitoService.php',
    "    public function criarRascunho(\n",
    "    /**\n     * @param  array<int, array{descricao: string, quantidade: int|string, preco_unitario: int|string, preco_unitario_base?: int|string|null, taxa_iva: int|string, motivo_isencao?: string|null, operation_type?: string|null}>  $linhas\n     */\n    public function criarRascunho(\n")
print('Edições B aplicadas.')
PYEOF
echo "Parte B aplicada. Corre: vendor/bin/pint --dirty && php artisan test --filter=Faturacao"
