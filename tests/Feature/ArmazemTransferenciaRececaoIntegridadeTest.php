<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Armazem\Models\Armazem;
use Modules\Armazem\Models\ArmazemTransferencia;
use Modules\Armazem\Services\ArmazemService;
use Modules\Armazem\Services\ArmazemTransferenciaService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\Produto;
use Tests\TestCase;

class ArmazemTransferenciaRececaoIntegridadeTest extends TestCase
{
    use RefreshDatabase;

    private function preparar(string $nif): array
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa '.$nif,
            'nif' => $nif,
        ]);
        $empresa->aderirServicos([Servico::Faturacao]);
        app(TenantManager::class)->set((int) $empresa->id);

        $produto = Produto::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Produto '.$nif,
            'unidade' => 'un',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14',
            'ativo' => true,
            'controla_stock' => true,
        ]);

        $armazens = app(ArmazemService::class);
        $origem = $armazens->obterPrincipal($empresa);
        $destino = $armazens->criar($empresa, 'DEST-'.$nif, 'Destino '.$nif);

        return [$empresa, $origem, $destino, $produto];
    }

    private function transferencia(Empresa $empresa, Armazem $origem, Armazem $destino, Produto $produto): ArmazemTransferencia
    {
        app(TenantManager::class)->set((int) $empresa->id);

        return app(ArmazemTransferenciaService::class)->criar(
            $empresa,
            $origem,
            $destino,
            [['produto_id' => $produto->id, 'quantidade' => '1.000']],
            'Teste de integridade da receção',
        );
    }

    private function rececao(Empresa $empresa, ArmazemTransferencia $transferencia): int
    {
        return (int) DB::table('armazem_transferencia_rececoes')->insertGetId([
            'empresa_id' => $empresa->id,
            'transferencia_id' => $transferencia->id,
            'chave_idempotencia' => 'teste-'.$transferencia->id.'-'.uniqid(),
            'payload_hash' => str_repeat('a', 64),
            'finalizada' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertViolacao(callable $operacao): void
    {
        try {
            $operacao();
        } catch (QueryException $exception) {
            $this->assertMatchesRegularExpression(
                '/foreign key constraint (?:fails|failed)/i',
                $exception->getMessage(),
                'O teste só deve passar quando a FK composta rejeitar a referência inválida.'
            );

            return;
        }

        $this->fail('A base de dados devia impedir associações entre linhas e receções de transferências diferentes.');
    }

    public function test_migracao_substitui_fks_antigas_por_fks_compostas_da_mesma_transferencia(): void
    {
        $chaves = collect(Schema::getForeignKeys('armazem_transferencia_rececao_linhas'));

        $this->assertTrue($chaves->contains(
            fn (array $chave): bool => ($chave['columns'] ?? []) === [
                'empresa_id',
                'transferencia_id',
                'rececao_id',
            ] && ($chave['foreign_table'] ?? null) === 'armazem_transferencia_rececoes'
                && ($chave['foreign_columns'] ?? []) === ['empresa_id', 'transferencia_id', 'id']
        ));

        $this->assertTrue($chaves->contains(
            fn (array $chave): bool => ($chave['columns'] ?? []) === [
                'empresa_id',
                'transferencia_id',
                'transferencia_linha_id',
            ] && ($chave['foreign_table'] ?? null) === 'armazem_transferencia_linhas'
                && ($chave['foreign_columns'] ?? []) === ['empresa_id', 'transferencia_id', 'id']
        ));

        $this->assertFalse($chaves->contains(
            fn (array $chave): bool => in_array(
                $chave['columns'] ?? [],
                [
                    ['empresa_id', 'rececao_id'],
                    ['empresa_id', 'transferencia_linha_id'],
                ],
                true
            )
        ));
    }

    public function test_linha_de_recepcao_nao_pode_usar_linha_de_outra_transferencia_da_mesma_empresa(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100313');
        $transferenciaA = $this->transferencia($empresa, $origem, $destino, $produto);
        $transferenciaB = $this->transferencia($empresa, $origem, $destino, $produto);
        $rececaoA = $this->rececao($empresa, $transferenciaA);
        $linhaB = $transferenciaB->linhas->first();

        $this->assertViolacao(fn () => DB::table('armazem_transferencia_rececao_linhas')->insert([
            'empresa_id' => $empresa->id,
            'transferencia_id' => $transferenciaA->id,
            'rececao_id' => $rececaoA,
            'transferencia_linha_id' => $linhaB->id,
            'quantidade_recebida' => '1.000',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_linha_de_recepcao_nao_pode_indicar_transferencia_diferente_da_recepcao(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100314');
        $transferenciaA = $this->transferencia($empresa, $origem, $destino, $produto);
        $transferenciaB = $this->transferencia($empresa, $origem, $destino, $produto);
        $rececaoA = $this->rececao($empresa, $transferenciaA);
        $linhaB = $transferenciaB->linhas->first();

        $this->assertViolacao(fn () => DB::table('armazem_transferencia_rececao_linhas')->insert([
            'empresa_id' => $empresa->id,
            'transferencia_id' => $transferenciaB->id,
            'rececao_id' => $rececaoA,
            'transferencia_linha_id' => $linhaB->id,
            'quantidade_recebida' => '1.000',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_servico_persiste_transferencia_na_linha_de_recepcao(): void
    {
        [$empresa, $origem, $destino, $produto] = $this->preparar('630100315');
        $transferencia = $this->transferencia($empresa, $origem, $destino, $produto);
        $transferencia->forceFill(['estado' => 'em_transito'])->save();
        $linha = $transferencia->linhas->first();

        app(ArmazemTransferenciaService::class)->registarRececaoParcial(
            $empresa,
            $transferencia,
            [[
                'linha_id' => (int) $linha->id,
                'quantidade_recebida' => '1.000',
                'quantidade_em_falta' => '0.000',
                'quantidade_danificada' => '0.000',
            ]],
            true,
            'rececao-servico-'.$transferencia->id,
        );

        $registo = DB::table('armazem_transferencia_rececao_linhas as rl')
            ->join('armazem_transferencia_rececoes as r', 'r.id', '=', 'rl.rececao_id')
            ->where('rl.empresa_id', $empresa->id)
            ->where('r.transferencia_id', $transferencia->id)
            ->select(['rl.transferencia_id', 'rl.transferencia_linha_id'])
            ->first();

        $this->assertNotNull($registo);
        $this->assertSame((int) $transferencia->id, (int) $registo->transferencia_id);
        $this->assertSame((int) $linha->id, (int) $registo->transferencia_linha_id);
    }
}
