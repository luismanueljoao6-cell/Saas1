<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\EstudioMusica\Models\ProjetoMusical;
use Modules\EstudioMusica\Services\FaturacaoEstudioService;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Tests\TestCase;

/**
 * registrarRecibo usa faturas emitidas a sério (não forceFill): o que se
 * testa aqui é a emissão do recibo, com as validações do ReciboService.
 */
class RegistoReciboTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected ProjetoMusical $projeto;

    protected Fatura $fatura;

    protected function setUp(): void
    {
        parent::setUp();

        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $chavePrivadaPem);

        $caminho = sys_get_temp_dir().'/estudio-recibo-'.uniqid().'.pem';
        file_put_contents($caminho, $chavePrivadaPem);
        config(['faturacao.chave_privada_path' => $caminho]);

        $this->beforeApplicationDestroyed(function () use ($caminho) {
            @unlink($caminho);
        });

        $this->empresa = Empresa::create(['nome_comercial' => 'Estúdio Recibos', 'nif' => '400000301']);
        app(TenantManager::class)->set($this->empresa->id);

        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Artista']);

        $this->projeto = ProjetoMusical::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'nome' => 'Álbum',
            'tipo_cobranca' => 'pacote',
            'valor_pacote' => 1000,
        ]);

        $faturas = app(FaturaService::class);
        $this->fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Pacote', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14],
        ]));

        $this->projeto->faturas()->attach($this->fatura->id, ['tipo' => 'avulso']); // total 1140
    }

    protected function servico(): FaturacaoEstudioService
    {
        return app(FaturacaoEstudioService::class);
    }

    public function test_sem_valor_o_recibo_recebe_o_saldo_da_fatura(): void
    {
        $recibo = $this->servico()->registrarRecibo($this->projeto, $this->fatura, 'multicaixa');

        $this->assertEquals(1140.00, (float) $recibo->valor);
        $this->assertTrue($recibo->estaEmitido());
        $this->assertCount(1, $this->projeto->recibos);
    }

    public function test_recebimento_parcial_e_depois_o_restante_por_omissao(): void
    {
        $this->servico()->registrarRecibo($this->projeto, $this->fatura, 'multicaixa', 400.0);

        $restante = $this->servico()->registrarRecibo($this->projeto, $this->fatura, 'multicaixa');

        $this->assertEquals(740.00, (float) $restante->valor);
        $this->assertTrue($this->projeto->fresh()->financeiramenteQuitado());
    }

    public function test_recibo_acima_do_saldo_da_fatura_e_rejeitado(): void
    {
        $this->servico()->registrarRecibo($this->projeto, $this->fatura, 'multicaixa');

        $this->expectException(\DomainException::class);

        $this->servico()->registrarRecibo($this->projeto, $this->fatura, 'multicaixa', 1.0);
    }

    public function test_valor_zero_ou_negativo_e_rejeitado(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->servico()->registrarRecibo($this->projeto, $this->fatura, 'multicaixa', 0.0);
    }

    public function test_fatura_que_nao_e_do_projeto_e_rejeitada(): void
    {
        $outro = ProjetoMusical::create([
            'empresa_id' => $this->empresa->id,
            'cliente_id' => $this->cliente->id,
            'nome' => 'Outro projeto',
            'tipo_cobranca' => 'pacote',
            'valor_pacote' => 1000,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->servico()->registrarRecibo($outro, $this->fatura, 'multicaixa');
    }
}
