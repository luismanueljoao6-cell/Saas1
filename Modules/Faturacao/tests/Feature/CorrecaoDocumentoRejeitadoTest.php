<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AgtCorrecaoService;
use Modules\Faturacao\Services\AgtDocumentoBuilder;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\AgtSubmissaoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Support\Jws;
use Tests\TestCase;

/**
 * Fatura inválida/rejeitada pela AGT: a correção é um documento NOVO (regra E46),
 * enviado com documentStatus "C" e rejectedDocumentNo.
 */
class CorrecaoDocumentoRejeitadoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir().'/agt-chaves-'.uniqid();
        mkdir($dir);
        $privadaEmpresa = $this->gerarChave($dir.'/5001636863.pem');
        $privadaSoftware = $this->gerarChave(sys_get_temp_dir().'/agt-sw-'.uniqid().'.pem');
        $this->beforeApplicationDestroyed(function () use ($dir, $privadaSoftware) {
            @unlink("{$dir}/5001636863.pem");
            @rmdir($dir);
            @unlink($privadaSoftware);
        });

        config([
            'faturacao.chave_privada_path' => $privadaEmpresa,
            'faturacao.chaves_dir' => $dir,
            'faturacao.agt.ativo' => true,
            'faturacao.agt.ambiente' => 'homologacao',
            'faturacao.agt.utilizador' => 'produtor',
            'faturacao.agt.palavra_passe' => 's3nh@',
            'faturacao.agt.software.chave_privada_path' => $privadaSoftware,
            'faturacao.agt.software.signature_version' => null,
            'faturacao.saft_numero_certificado' => 'C_134',
        ]);

        Http::fake([
            '*solicitarSerie' => fn (Request $r) => Http::response([
                'resultCode' => 1,
                'errorList' => [''],
                'seriesFEResult' => [
                    'seriesCode' => $r['documentType'].'6325S2C',
                    'authorizedQuantity' => '1000',
                    'firstDocumentNo' => '1',
                    'lastDocumentNo' => '1000',
                ],
            ], 200),
            '*registarFactura' => Http::response(['requestID' => '202500000010689', 'errorList' => []], 200),
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        foreach (['FT', 'NC'] as $tipo) {
            app(AgtSerieService::class)->solicitar($this->empresa, $tipo, now()->year);
        }
    }

    protected function gerarChave(string $caminho): string
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($caminho, $pem);

        return $caminho;
    }

    protected function emitirFatura(): Fatura
    {
        $faturas = app(FaturaService::class);

        return $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço A', 'quantidade' => 2, 'preco_unitario' => '500.00', 'taxa_iva' => 14],
            ['descricao' => 'Serviço B', 'quantidade' => 1, 'preco_unitario' => '250.00', 'taxa_iva' => 14],
        ], 'Observação original'));
    }

    protected function submissaoRejeitada(Fatura $fatura, string $estado = AgtSubmissao::INVALIDO): AgtSubmissao
    {
        $submissao = AgtSubmissao::query()->where('documento_id', $fatura->id)->firstOrFail();
        $submissao->update(['estado' => $estado, 'erros' => [['codigo' => 'E21', 'descricao' => 'Valor incoerente']]]);

        return $submissao->fresh();
    }

    public function test_cria_o_rascunho_de_correcao_com_as_linhas_da_fatura_rejeitada(): void
    {
        $original = $this->emitirFatura();
        $submissao = $this->submissaoRejeitada($original);

        $rascunho = app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($submissao);

        $this->assertSame('rascunho', $rascunho->estado);
        $this->assertNotSame($original->id, $rascunho->id);
        $this->assertSame($original->numero_documento, $rascunho->rejeitado_documento_no);
        $this->assertSame($original->cliente_id, $rascunho->cliente_id);
        $this->assertSame('Observação original', $rascunho->observacoes);
        $this->assertSame($original->valor_total, $rascunho->valor_total);
        $this->assertSame(
            $original->linhas->map->only(['descricao', 'quantidade', 'preco_unitario', 'taxa_iva'])->all(),
            $rascunho->linhas->map->only(['descricao', 'quantidade', 'preco_unitario', 'taxa_iva'])->all(),
        );

        // A fatura rejeitada fica intacta na cadeia fiscal.
        $this->assertSame('emitida', $original->fresh()->estado);
    }

    public function test_e_idempotente(): void
    {
        $original = $this->emitirFatura();
        $submissao = $this->submissaoRejeitada($original, AgtSubmissao::REJEITADO);
        $servico = app(AgtCorrecaoService::class);

        $primeira = $servico->criarRascunhoDeCorrecao($submissao);
        $segunda = $servico->criarRascunhoDeCorrecao($submissao);

        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(2, Fatura::withoutGlobalScopes()->count());
    }

    public function test_a_correcao_emitida_tem_numero_novo_e_segue_como_documentstatus_c(): void
    {
        $original = $this->emitirFatura();
        $rascunho = app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($this->submissaoRejeitada($original));

        $correcao = app(FaturaService::class)->emitir($rascunho);

        $this->assertNotSame($original->numero_documento, $correcao->numero_documento);

        $documento = json_decode(Jws::canonico(app(AgtDocumentoBuilder::class)->construir($correcao)), true);

        $this->assertSame('C', $documento['documentStatus']);
        $this->assertSame($original->numero_documento, $documento['rejectedDocumentNo']);
        $this->assertSame($correcao->numero_documento, $documento['documentNo']);

        // Uma fatura normal continua com documentStatus N e sem rejectedDocumentNo.
        $normal = json_decode(Jws::canonico(app(AgtDocumentoBuilder::class)->construir($original->fresh())), true);
        $this->assertSame('N', $normal['documentStatus']);
        $this->assertArrayNotHasKey('rejectedDocumentNo', $normal);
    }

    public function test_o_envio_leva_documentstatus_c_e_o_numero_rejeitado(): void
    {
        $original = $this->emitirFatura();
        $rascunho = app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($this->submissaoRejeitada($original));
        $correcao = app(FaturaService::class)->emitir($rascunho);

        app(AgtSubmissaoService::class)->enviarPendentes();

        $doc = collect(Http::recorded())
            ->map(fn ($par) => $par[0])
            ->filter(fn (Request $r) => str_contains($r->url(), 'registarFactura'))
            ->flatMap(fn (Request $r) => $r->data()['documents'] ?? [])
            ->firstWhere('documentNo', $correcao->numero_documento);

        $this->assertNotNull($doc, 'A correção devia ter seguido para a AGT.');
        $this->assertSame('C', $doc['documentStatus']);
        $this->assertSame($original->numero_documento, $doc['rejectedDocumentNo']);
    }

    public function test_so_documentos_invalidos_ou_rejeitados_podem_ser_corrigidos(): void
    {
        $original = $this->emitirFatura();
        $submissao = AgtSubmissao::query()->where('documento_id', $original->id)->firstOrFail();

        foreach ([AgtSubmissao::PENDENTE, AgtSubmissao::ENVIADO, AgtSubmissao::VALIDO, AgtSubmissao::ERRO, AgtSubmissao::AGUARDA_INTERVENCAO] as $estado) {
            $submissao->update(['estado' => $estado]);

            try {
                app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($submissao->fresh());
                $this->fail("A correção de '{$estado}' devia ser recusada.");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('inválidos ou rejeitados', $e->getMessage());
            }
        }

        $this->assertSame(1, Fatura::withoutGlobalScopes()->count());
    }

    public function test_outros_tipos_de_documento_nao_sao_corrigidos_automaticamente(): void
    {
        $original = $this->emitirFatura();
        $submissao = $this->submissaoRejeitada($original);
        $submissao->update(['documento_type' => 'Modules\\Faturacao\\Models\\NotaCreditoDebito']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('só existe para faturas');

        app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($submissao->fresh());
    }

    public function test_comando_cria_o_rascunho_e_recusa_submissoes_inexistentes(): void
    {
        $original = $this->emitirFatura();
        $submissao = $this->submissaoRejeitada($original);

        $this->assertSame(0, Artisan::call('faturacao:agt-corrigir', ['submissao' => $submissao->id]));
        $this->assertStringContainsString('documentStatus C', Artisan::output());
        $this->assertSame(1, Fatura::withoutGlobalScopes()->whereNotNull('rejeitado_documento_no')->count());

        $this->assertSame(1, Artisan::call('faturacao:agt-corrigir', ['submissao' => 99999]));
    }
}
