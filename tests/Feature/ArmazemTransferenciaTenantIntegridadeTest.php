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

class ArmazemTransferenciaTenantIntegridadeTest extends TestCase
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
            'Teste de integridade multiempresa',
        );
    }

    private function rececao(Empresa $empresa, ArmazemTransferencia $transferencia): int
    {
        return (int) DB::table('armazem_transferencia_rececoes')->insertGetId([
            'empresa_id' => $empresa->id,
            'transferencia_id' => $transferencia->id,
            'chave_idempotencia' => 'teste-'.uniqid(),
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

        $this->fail('A base de dados devia impedir a associação entre empresas diferentes.');
    }

    public function test_transferencia_nao_pode_referenciar_armazem_de_outra_empresa(): void
    {
        [$empresaA, $origemA] = $this->preparar('630100301');
        [, $origemB] = $this->preparar('630100302');

        $this->assertViolacao(fn () => DB::table('armazem_transferencias')->insert([
            'empresa_id' => $empresaA->id,
            'armazem_origem_id' => $origemA->id,
            'armazem_destino_id' => $origemB->id,
            'estado' => 'rascunho',
            'motivo' => 'Teste de tenant cruzado',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_linha_de_transferencia_nao_pode_referenciar_produto_de_outra_empresa(): void
    {
        [$empresaA, $origemA, $destinoA, $produtoA] = $this->preparar('630100303');
        [, , , $produtoB] = $this->preparar('630100304');
        $transferencia = $this->transferencia($empresaA, $origemA, $destinoA, $produtoA);

        $this->assertViolacao(fn () => DB::table('armazem_transferencia_linhas')->insert([
            'empresa_id' => $empresaA->id,
            'transferencia_id' => $transferencia->id,
            'produto_id' => $produtoB->id,
            'quantidade' => '1.000',
            'quantidade_recebida' => '0.000',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_linha_de_transferencia_nao_pode_referenciar_transferencia_de_outra_empresa(): void
    {
        [$empresaA, $origemA, $destinoA, $produtoA] = $this->preparar('630100305');
        [$empresaB, $origemB, $destinoB, $produtoB] = $this->preparar('630100306');
        $transferenciaB = $this->transferencia($empresaB, $origemB, $destinoB, $produtoB);

        $this->assertViolacao(fn () => DB::table('armazem_transferencia_linhas')->insert([
            'empresa_id' => $empresaA->id,
            'transferencia_id' => $transferenciaB->id,
            'produto_id' => $produtoA->id,
            'quantidade' => '1.000',
            'quantidade_recebida' => '0.000',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_rececao_nao_pode_referenciar_transferencia_de_outra_empresa(): void
    {
        [$empresaA] = $this->preparar('630100307');
        [$empresaB, $origemB, $destinoB, $produtoB] = $this->preparar('630100308');
        $transferenciaB = $this->transferencia($empresaB, $origemB, $destinoB, $produtoB);

        $this->assertViolacao(fn () => DB::table('armazem_transferencia_rececoes')->insert([
            'empresa_id' => $empresaA->id,
            'transferencia_id' => $transferenciaB->id,
            'chave_idempotencia' => 'rececao-cruzada-0001',
            'payload_hash' => str_repeat('b', 64),
            'finalizada' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_linha_de_rececao_nao_pode_referenciar_rececao_de_outra_empresa(): void
    {
        [$empresaA, $origemA, $destinoA, $produtoA] = $this->preparar('630100309');
        [$empresaB, $origemB, $destinoB, $produtoB] = $this->preparar('630100310');
        $transferenciaA = $this->transferencia($empresaA, $origemA, $destinoA, $produtoA);
        $transferenciaB = $this->transferencia($empresaB, $origemB, $destinoB, $produtoB);
        $rececaoB = $this->rececao($empresaB, $transferenciaB);

        $this->assertViolacao(fn () => DB::table('armazem_transferencia_rececao_linhas')->insert([
            'empresa_id' => $empresaA->id,
            'transferencia_id' => $transferenciaA->id,
            'rececao_id' => $rececaoB,
            'transferencia_linha_id' => $transferenciaA->linhas->first()->id,
            'quantidade_recebida' => '0.000',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_linha_de_rececao_nao_pode_referenciar_linha_de_transferencia_de_outra_empresa(): void
    {
        [$empresaA, $origemA, $destinoA, $produtoA] = $this->preparar('630100311');
        [$empresaB, $origemB, $destinoB, $produtoB] = $this->preparar('630100312');
        $transferenciaA = $this->transferencia($empresaA, $origemA, $destinoA, $produtoA);
        $transferenciaB = $this->transferencia($empresaB, $origemB, $destinoB, $produtoB);
        $rececaoA = $this->rececao($empresaA, $transferenciaA);
        $linhaB = $transferenciaB->linhas->first();

        $this->assertViolacao(fn () => DB::table('armazem_transferencia_rececao_linhas')->insert([
            'empresa_id' => $empresaA->id,
            'transferencia_id' => $transferenciaA->id,
            'rececao_id' => $rececaoA,
            'transferencia_linha_id' => $linhaB->id,
            'quantidade_recebida' => '0.000',
            'quantidade_em_falta' => '0.000',
            'quantidade_danificada' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }
}
