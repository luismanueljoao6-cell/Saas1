<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migra os saldos antigos para o armazém principal apenas quando não existe
     * saldo por armazém. Se os dois ledgers já tiverem valores divergentes, a
     * migração aborta antes de escrever: nunca soma nem escolhe um valor por
     * suposição.
     */
    public function up(): void
    {
        $legados = DB::table('stock_saldos')
            ->orderBy('empresa_id')
            ->orderBy('produto_id')
            ->get();

        $seeds = [];
        $conflitos = [];

        foreach ($legados as $legado) {
            $armazemSaldos = DB::table('armazem_stock_saldos')
                ->where('empresa_id', $legado->empresa_id)
                ->where('produto_id', $legado->produto_id)
                ->get(['quantidade']);

            $totalArmazens = 0;
            foreach ($armazemSaldos as $saldo) {
                $totalArmazens += $this->emMilhares((string) $saldo->quantidade);
            }

            $totalLegado = $this->emMilhares((string) $legado->quantidade);

            if ($armazemSaldos->isEmpty()) {
                $seeds[] = [
                    'empresa_id' => (int) $legado->empresa_id,
                    'produto_id' => (int) $legado->produto_id,
                    'quantidade' => (string) $legado->quantidade,
                ];
                continue;
            }

            if ($totalArmazens !== $totalLegado) {
                $conflitos[] = sprintf(
                    'empresa=%d produto=%d legado=%s armazens=%s',
                    $legado->empresa_id,
                    $legado->produto_id,
                    $this->formatar($totalLegado),
                    $this->formatar($totalArmazens),
                );
            }
        }

        $totaisArmazens = [];
        foreach (DB::table('armazem_stock_saldos')->orderBy('empresa_id')->orderBy('produto_id')->get() as $saldo) {
            $chave = $saldo->empresa_id.':'.$saldo->produto_id;
            $totaisArmazens[$chave] = ($totaisArmazens[$chave] ?? 0)
                + $this->emMilhares((string) $saldo->quantidade);
        }
        foreach ($totaisArmazens as $chave => $total) {
            if ($total > 99_999_999_999_999) {
                $conflitos[] = 'saldo agregado ultrapassa DECIMAL(14,3): '.$chave;
            }
        }

        if ($conflitos !== []) {
            throw new RuntimeException(
                "Migração de stock interrompida: existem saldos divergentes. "
                ."Nenhum saldo foi alterado. Reconcilia os seguintes pares empresa/produto e volta a executar:\n"
                .implode("\n", array_slice($conflitos, 0, 50))
            );
        }

        $principais = [];
        foreach ($seeds as $seed) {
            $empresaId = $seed['empresa_id'];

            if (! isset($principais[$empresaId])) {
                $principal = DB::table('armazens')
                    ->where('empresa_id', $empresaId)
                    ->where('ativo', true)
                    ->where('principal', true)
                    ->first();

                if (! $principal) {
                    $principal = DB::table('armazens')
                        ->where('empresa_id', $empresaId)
                        ->where('ativo', true)
                        ->orderBy('id')
                        ->first();
                }

                if (! $principal) {
                    $codigoExiste = DB::table('armazens')
                        ->where('empresa_id', $empresaId)
                        ->where('codigo', 'PRINCIPAL')
                        ->exists();

                    if ($codigoExiste) {
                        DB::table('armazens')
                            ->where('empresa_id', $empresaId)
                            ->where('codigo', 'PRINCIPAL')
                            ->update(['principal' => true, 'ativo' => true, 'updated_at' => now()]);
                        $principal = DB::table('armazens')
                            ->where('empresa_id', $empresaId)
                            ->where('codigo', 'PRINCIPAL')
                            ->first();
                    } else {
                        $id = DB::table('armazens')->insertGetId([
                            'empresa_id' => $empresaId,
                            'codigo' => 'PRINCIPAL',
                            'nome' => 'Armazém principal',
                            'principal' => true,
                            'ativo' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $principal = DB::table('armazens')->where('id', $id)->first();
                    }
                } else {
                    DB::table('armazens')
                        ->where('empresa_id', $empresaId)
                        ->where('id', '!=', $principal->id)
                        ->update(['principal' => false, 'updated_at' => now()]);
                    DB::table('armazens')
                        ->where('id', $principal->id)
                        ->update(['principal' => true, 'updated_at' => now()]);
                }

                // Garante exatamente um principal ativo por empresa.
                DB::table('armazens')
                    ->where('empresa_id', $empresaId)
                    ->where('id', '!=', $principal->id)
                    ->update(['principal' => false, 'updated_at' => now()]);
                DB::table('armazens')
                    ->where('id', $principal->id)
                    ->update(['principal' => true, 'ativo' => true, 'updated_at' => now()]);

                $principais[$empresaId] = (int) $principal->id;
            }

            DB::table('armazem_stock_saldos')->insert([
                'empresa_id' => $empresaId,
                'armazem_id' => $principais[$empresaId],
                'produto_id' => $seed['produto_id'],
                'quantidade' => $seed['quantidade'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Recalcula a tabela antiga como projeção agregada, sem a usar como
        // fonte de verdade depois desta migração.
        $agregados = [];
        foreach (DB::table('armazem_stock_saldos')->orderBy('empresa_id')->orderBy('produto_id')->get() as $saldo) {
            $chave = $saldo->empresa_id.':'.$saldo->produto_id;
            $agregados[$chave] ??= [
                'empresa_id' => (int) $saldo->empresa_id,
                'produto_id' => (int) $saldo->produto_id,
                'quantidade' => 0,
            ];
            $agregados[$chave]['quantidade'] += $this->emMilhares((string) $saldo->quantidade);
        }

        foreach ($agregados as $agregado) {
            $valores = [
                'quantidade' => $this->formatar($agregado['quantidade']),
                'updated_at' => now(),
            ];
            $atualizado = DB::table('stock_saldos')
                ->where('empresa_id', $agregado['empresa_id'])
                ->where('produto_id', $agregado['produto_id'])
                ->update($valores);

            if ($atualizado === 0) {
                DB::table('stock_saldos')->insertOrIgnore([
                    'empresa_id' => $agregado['empresa_id'],
                    'produto_id' => $agregado['produto_id'],
                    'quantidade' => $valores['quantidade'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Os saldos são dados operacionais e podem já ter sido movimentados.
     * Não é seguro apagar ou reverter automaticamente esta reconciliação.
     */
    public function down(): void
    {
        // Intencionalmente sem reversão destrutiva.
    }

    private function emMilhares(string $valor): int
    {
        $valor = trim($valor);
        if (preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,3}))?$/', $valor, $partes) !== 1) {
            throw new RuntimeException('Foi encontrado um saldo legado com formato decimal inválido: '.$valor);
        }

        return ((int) $partes[1] * 1000) + (int) str_pad($partes[2] ?? '', 3, '0');
    }

    private function formatar(int $valor): string
    {
        return intdiv($valor, 1000).'.'.str_pad((string) ($valor % 1000), 3, '0', STR_PAD_LEFT);
    }
};
