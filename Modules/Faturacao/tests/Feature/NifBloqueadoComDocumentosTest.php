<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Http\Requests\AtualizarEmpresaRequest;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Contracts\FaturacaoGateway;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * Fase 2: o NIF e o nome legal da empresa ficam bloqueados assim que existe QUALQUER documento
 * fiscal fora de rascunho, não só faturas emitidas (um recibo de sinal não tem fatura).
 */
class NifBloqueadoComDocumentosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-nif-bloqueado-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        config(['faturacao.chave_privada_path' => $caminho]);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));
    }

    private function temDocumentos(int $empresaId): bool
    {
        return (fn () => $this->temDocumentosFiscaisEmitidos($empresaId))->call(new AtualizarEmpresaRequest);
    }

    public function test_empresa_sem_documentos_pode_alterar_o_nif(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Sem documentos', 'nif' => '630000001']);

        $this->assertFalse($this->temDocumentos((int) $empresa->id));
    }

    public function test_rascunho_nao_bloqueia_o_nif(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Só rascunho', 'nif' => '630000002']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);

        app(FaturacaoGateway::class)->criarRascunho($empresa, $cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => '14'],
        ]);

        $this->assertFalse($this->temDocumentos((int) $empresa->id));
    }

    public function test_recibo_emitido_sem_fatura_bloqueia_o_nif(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Com recibo de sinal', 'nif' => '630000003']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);

        app(FaturacaoGateway::class)->emitirRecibo($empresa->id, $cliente->id, '100.00', 'dinheiro');

        $this->assertTrue($this->temDocumentos((int) $empresa->id));
    }

    public function test_fatura_emitida_continua_a_bloquear_o_nif(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Com fatura', 'nif' => '630000004']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente', 'nif' => null]);
        $gateway = app(FaturacaoGateway::class);

        $gateway->emitirFatura($gateway->criarRascunho($empresa, $cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => '14'],
        ]));

        $this->assertTrue($this->temDocumentos((int) $empresa->id));
    }
}
