<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Tests\TestCase;

/** Garantias extra da nota de débito sem IVA: motivo de isenção ignorado e rascunhos adulterados. */
class NotaDebitoGarantiasTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        config(['faturacao.chave_privada_path' => $caminho, 'faturacao.agt.ativo' => false]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja ND', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente ND', 'nif' => null]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function linhas(array $extra = []): array
    {
        return [array_merge(['descricao' => 'Repasse de despesas', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 0], $extra)];
    }

    public function test_motivo_de_isencao_enviado_numa_nota_de_debito_e_ignorado(): void
    {
        $nota = app(NotaCreditoDebitoService::class)->criarRascunho($this->empresa->id, $this->cliente->id, 'debito', 'Repasse', $this->linhas(['motivo_isencao' => 'M10']));

        $this->assertNull($nota->linhas()->first()->motivo_isencao);
    }

    public function test_rascunho_de_debito_com_iva_criado_por_outro_caminho_nao_e_emitido(): void
    {
        $servico = app(NotaCreditoDebitoService::class);
        $nota = $servico->criarRascunho($this->empresa->id, $this->cliente->id, 'debito', 'Repasse', $this->linhas());

        // Alguém altera o rascunho diretamente na base de dados.
        DB::table('notas_credito_debito_linhas')->where('nota_credito_debito_id', $nota->id)
            ->update(['taxa_iva' => 14, 'valor_iva' => '14.00', 'valor_total' => '114.00']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não pode liquidar IVA');

        $servico->emitir($nota);
    }

    public function test_taxa_em_falta_numa_nota_de_debito_e_recusada_com_mensagem_clara(): void
    {
        $linhas = $this->linhas();
        unset($linhas[0]['taxa_iva']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('taxa de IVA');

        app(NotaCreditoDebitoService::class)->criarRascunho($this->empresa->id, $this->cliente->id, 'debito', 'Repasse', $linhas);
    }
}
