<?php

namespace Modules\Faturacao\Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Services\SafTExportService;
use Modules\Faturacao\Services\SafTPreValidacao;
use Tests\TestCase;

/** SAF-T: recibos entram em Payments e na pré-validação (estrutura provisória). */
class SafTRecibosTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/saft-rec-'.uniqid();
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

        config([
            'faturacao.chave_privada_path' => $this->tmp.'/chave.pem',
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja SAFT', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    protected function faturaEmitida(): Fatura
    {
        $servico = app(FaturaService::class);
        $rascunho = $servico->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]);
        $servico->emitir($rascunho);

        return Fatura::withoutGlobalScopes()->findOrFail($rascunho->id);
    }

    protected function recibo(Fatura $fatura, string $valor): Recibo
    {
        return app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, $valor, 'transferencia', $fatura->id);
    }

    /** Simula dados corrompidos: tira os triggers de imutabilidade de `recibos` (só SQLite em memória, descartada no fim do teste). */
    protected function removerProtecoes(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Teste de dados corrompidos só corre em SQLite.');
        }

        foreach (DB::select("select name from sqlite_master where type = 'trigger' and tbl_name = 'recibos'") as $t) {
            DB::statement('DROP TRIGGER IF EXISTS "'.$t->name.'"');
        }
    }

    public function test_saft_inclui_os_recibos_em_payments(): void
    {
        $fatura = $this->faturaEmitida();
        $recibo = $this->recibo($fatura, '500.00');

        $xml = app(SafTExportService::class)->gerar($this->empresa, now()->startOfYear(), now()->endOfYear());

        $dom = new DOMDocument;
        $dom->loadXML(preg_replace('/ xmlns="[^"]*"/', '', $xml, 1));
        $x = new DOMXPath($dom);

        $this->assertSame('1', $x->evaluate('string(//Payments/NumberOfEntries)'));
        $this->assertSame('500.00', $x->evaluate('string(//Payments/TotalCredit)'));
        $this->assertSame($recibo->numero_documento, $x->evaluate('string(//Payment/PaymentRefNo)'));
        $this->assertSame('N', $x->evaluate('string(//Payment/PaymentStatus)'));
        $this->assertSame($fatura->numero_documento, $x->evaluate('string(//Payment/Line/SourceDocumentID/OriginatingON)'));
    }

    public function test_pre_validacao_aceita_recibos_consistentes(): void
    {
        $fatura = $this->faturaEmitida();
        $this->recibo($fatura, '300.00');
        $this->recibo($fatura, '200.00');

        $erros = app(SafTPreValidacao::class)->validar($this->empresa, now()->startOfYear(), now()->endOfYear());

        $this->assertSame([], $erros);
    }

    public function test_pre_validacao_deteta_lacuna_na_numeracao_dos_recibos(): void
    {
        $fatura = $this->faturaEmitida();
        $this->recibo($fatura, '100.00');
        $this->recibo($fatura, '100.00');
        $this->recibo($fatura, '100.00');

        $this->removerProtecoes();
        DB::table('recibos')->where('empresa_id', $this->empresa->id)->where('numero_sequencial', 2)->delete();

        $erros = app(SafTPreValidacao::class)->validar($this->empresa, now()->startOfYear(), now()->endOfYear());

        $this->assertNotEmpty(array_filter($erros, fn ($e) => str_contains($e, 'Lacuna na numeração')));
    }

    public function test_pre_validacao_deteta_recibos_acima_do_total_da_fatura(): void
    {
        $fatura = $this->faturaEmitida();
        $recibo = $this->recibo($fatura, '500.00');

        $this->removerProtecoes();
        DB::table('recibos')->where('id', $recibo->id)->update(['valor' => '5000.00']);

        $erros = app(SafTPreValidacao::class)->validar($this->empresa, now()->startOfYear(), now()->endOfYear());

        $this->assertNotEmpty(array_filter($erros, fn ($e) => str_contains($e, 'excede o total da fatura')));
    }
}
