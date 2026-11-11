<?php

namespace Modules\Faturacao\Tests\Feature;

use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Serie;
use Modules\Faturacao\Services\FaturaService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ProcessosConcorrentes;
use Tests\TestCase;

/**
 * Emissão fiscal sob concorrência real (processos independentes, MySQL):
 * numeração sem lacunas nem repetições, cadeia de hash contínua e idempotência.
 * Sem RefreshDatabase de propósito (ver cabeçalho do script da fase 4).
 */
#[Group('concorrencia-real')]
class EmissaoConcorrenciaRealTest extends TestCase
{
    use ProcessosConcorrentes;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        $this->exigirOptInConcorrenciaReal();
        parent::setUp();
        $this->exigirMysqlDeTestes();

        $this->tmp = sys_get_temp_dir().'/emissao-conc-'.uniqid();
        mkdir($this->tmp);
        $this->beforeApplicationDestroyed(function () {
            foreach (glob($this->tmp.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmp);
        });

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($this->tmp.'/chave.pem', $pem);

        $this->empresa = Empresa::create(['nome_comercial' => 'Emissão Concorrente '.uniqid(), 'nif' => (string) random_int(500000000, 599999999)]);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    /** Os processos filhos não herdam o config() deste processo: passa-se por variáveis de ambiente. */
    protected function ambiente(): array
    {
        return [
            'FATURACAO_CHAVE_PRIVADA_PATH' => $this->tmp.'/chave.pem',
            'FATURACAO_AGT_ATIVO' => 'false',
        ];
    }

    protected function rascunho(string $descricao): Fatura
    {
        return app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => $descricao, 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 14],
        ]);
    }

    public function test_oito_emissoes_simultaneas_geram_numeracao_sem_lacunas_e_cadeia_de_hash_continua(): void
    {
        $rascunhos = [];
        for ($i = 1; $i <= 8; $i++) {
            $rascunhos[] = $this->rascunho("Fatura {$i}");
        }

        $resultados = $this->correrEmParalelo(
            'emitir',
            array_map(fn (Fatura $f) => ['fatura_id' => $f->id], $rascunhos),
            $this->ambiente(),
        );

        foreach ($resultados as $r) {
            $this->assertTrue($r['ok'], 'Uma emissão falhou: '.($r['erro'] ?? '?'));
        }

        $numeros = array_column($resultados, 'numero');
        sort($numeros);
        $this->assertSame(range(1, 8), $numeros, 'A numeração tem de ser 1..8, sem lacunas nem repetições.');

        $emitidas = Fatura::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->where('estado', 'emitida')
            ->orderBy('numero_sequencial')
            ->get()
            ->values();

        $this->assertCount(8, $emitidas);

        foreach ($emitidas as $i => $fatura) {
            $this->assertNotSame('', trim((string) $fatura->hash), 'Fatura emitida sem hash.');

            if ($i === 0) {
                $this->assertNull($fatura->hash_anterior);
            } else {
                $this->assertSame($emitidas[$i - 1]->hash, $fatura->hash_anterior, "Cadeia de hash interrompida na fatura {$fatura->numero_sequencial}.");
            }
        }
    }

    public function test_seis_emissoes_simultaneas_da_mesma_fatura_consomem_um_so_numero(): void
    {
        $fatura = $this->rascunho('Fatura única');

        $resultados = $this->correrEmParalelo(
            'emitir',
            array_fill(0, 6, ['fatura_id' => $fatura->id]),
            $this->ambiente(),
        );

        foreach ($resultados as $r) {
            $this->assertTrue($r['ok'], 'Uma emissão falhou: '.($r['erro'] ?? '?'));
        }

        $this->assertCount(1, array_unique(array_column($resultados, 'documento')), 'Todos os processos têm de ver o mesmo documento.');
        $this->assertSame([1], array_values(array_unique(array_column($resultados, 'numero'))));

        $serie = Serie::withoutGlobalScopes()
            ->where('empresa_id', $this->empresa->id)
            ->where('tipo_documento', 'FT')
            ->firstOrFail();

        $this->assertSame(1, (int) $serie->ultimo_numero, 'Emitir é idempotente: só um número pode ser consumido.');
    }
}
