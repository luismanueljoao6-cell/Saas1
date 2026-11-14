<?php

namespace Modules\Faturacao\Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\FaturaLinha;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\NotaCreditoDebito;
use Modules\Faturacao\Models\NotaCreditoDebitoLinha;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\SafTExportService;
use Modules\Faturacao\Services\SafTPreValidacao;
use Modules\Faturacao\Support\Dinheiro;
use Tests\TestCase;

/** Fase 3: regras fiscais do SAF-T que não dependem do XSD, tipo de documento e namespace. */
class SafTPreValidacaoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir().'/saft-pre-'.uniqid();
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
            'faturacao.taxas_iva_permitidas' => [14.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Fase3', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    /** @param  array<string, mixed>  $extra */
    protected function linhaOk(array $extra = []): array
    {
        return array_merge([
            'descricao' => 'Serviço',
            'quantidade' => '1.000',
            'preco_unitario' => '100.00',
            'taxa_iva' => '14.00',
            'valor_sem_iva' => '100.00',
            'valor_iva' => '14.00',
            'valor_total' => '114.00',
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  list<array<string, mixed>>|null  $linhas
     */
    protected function fatura(array $atributos = [], ?array $linhas = null): Fatura
    {
        $fatura = new Fatura(array_merge([
            'empresa_id' => 1,
            'serie_id' => 1,
            'tipo_documento' => 'FT',
            'numero_sequencial' => 1,
            'numero_documento' => 'FT A2026/1',
            'estado' => 'emitida',
            'valor_sem_iva' => '100.00',
            'valor_iva' => '14.00',
            'valor_total' => '114.00',
            'hash' => 'h1',
            'hash_anterior' => null,
            'chave_versao' => 1,
        ], $atributos));

        $fatura->data_hora_sistema = now();
        $fatura->setRelation('linhas', collect(array_map(
            fn (array $l) => new FaturaLinha($l),
            $linhas ?? [$this->linhaOk()]
        )));

        return $fatura;
    }

    protected function servico(): SafTPreValidacao
    {
        return app(SafTPreValidacao::class);
    }

    protected function xml(): string
    {
        return app(SafTExportService::class)->gerar($this->empresa, now()->startOfYear(), now()->endOfYear());
    }

    protected function emitir(string $descricao, bool $adiantamento = false): Fatura
    {
        $faturas = app(FaturaService::class);
        $linhas = [['descricao' => $descricao, 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 14]];

        $rascunho = $adiantamento
            ? $faturas->criarRascunhoAdiantamento($this->empresa, $this->cliente, $linhas)
            : $faturas->criarRascunho($this->empresa, $this->cliente, $linhas);

        return $faturas->emitir($rascunho);
    }

    // --- Regras sobre um documento -------------------------------------

    public function test_documento_consistente_nao_tem_erros(): void
    {
        $this->assertSame([], $this->servico()->verificarDocumento($this->fatura()));
    }

    public function test_totais_que_nao_batem_com_as_linhas_sao_detetados(): void
    {
        $erros = $this->servico()->verificarDocumento($this->fatura(['valor_total' => '120.00']));

        $this->assertNotEmpty($erros);
        $this->assertStringContainsString('difere da soma das linhas', implode(' ', $erros));
    }

    public function test_documento_sem_hash_e_detetado(): void
    {
        $erros = $this->servico()->verificarDocumento($this->fatura(['hash' => '']));

        $this->assertStringContainsString('sem hash', implode(' ', $erros));
    }

    public function test_valores_da_linha_que_nao_batem_com_o_recalculo_sao_detetados(): void
    {
        // 100.00 a 14% => IVA 14.00; a linha diz 13.00 (e o documento acompanha, para isolar o erro da linha).
        $linha = $this->linhaOk(['valor_iva' => '13.00', 'valor_total' => '113.00']);
        $erros = $this->servico()->verificarDocumento($this->fatura(['valor_iva' => '13.00', 'valor_total' => '113.00'], [$linha]));

        $this->assertStringContainsString('recálculo', implode(' ', $erros));
    }

    public function test_taxa_sem_codigo_saft_e_detetada_com_mensagem_clara(): void
    {
        config(['faturacao.agt.codigos_taxa' => []]);

        $erros = $this->servico()->verificarDocumento($this->fatura());

        $this->assertStringContainsString('código SAF-T', implode(' ', $erros));
    }

    public function test_linhas_de_notas_de_credito_e_debito_tambem_sao_recalculadas(): void
    {
        $nota = new NotaCreditoDebito([
            'tipo' => 'credito',
            'numero_documento' => 'NC A2026/1',
            'valor_sem_iva' => '100.00',
            'valor_iva' => '13.00',
            'valor_total' => '113.00',
            'hash' => 'h-nc',
            'chave_versao' => 1,
        ]);
        $nota->data_hora_sistema = now();
        $linha = new NotaCreditoDebitoLinha($this->linhaOk([
            'valor_iva' => '13.00',
            'valor_total' => '113.00',
        ]));
        $nota->setRelation('linhas', collect([$linha]));

        $erros = $this->servico()->verificarDocumento($nota);

        $this->assertStringContainsString('recálculo', implode(' ', $erros));
    }

    public function test_calculo_proporcional_usa_divisao_inteira_segura(): void
    {
        $resultado = Dinheiro::multiplicarDividirPorExcesso(
            123456789012345,
            23456789,
            123456789,
        );

        $this->assertSame(23456789002346, $resultado);
    }

    public function test_calculo_proporcional_recusa_overflow_em_vez_de_corromper_valor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \Modules\Faturacao\Support\Dinheiro::multiplicarDividirPorExcesso(
            PHP_INT_MAX,
            PHP_INT_MAX,
            1,
        );
    }

    public function test_criacao_de_fatura_recusa_empresa_diferente_do_tenant_ativo(): void
    {
        $outraEmpresa = Empresa::create(['nome_comercial' => 'Outra Loja', 'nif' => '5001636864']);
        $outroCliente = Cliente::create(['empresa_id' => $outraEmpresa->id, 'nome' => 'Outro Cliente', 'nif' => null]);
        app(TenantManager::class)->set((int) $this->empresa->id);

        $this->expectException(\DomainException::class);

        app(FaturaService::class)->criarRascunhoAdiantamento(
            $outraEmpresa,
            $outroCliente,
            [['descricao' => 'Adiantamento', 'quantidade' => '1.000', 'preco_unitario' => '100.00', 'taxa_iva' => '14.00']],
        );
    }

    // --- Continuidade da série ----------------------------------------

    public function test_sequencia_correta_com_cadeia_de_hash_nao_tem_erros(): void
    {
        $anterior = [];

        $this->assertSame([], $this->servico()->verificarContinuidade($this->fatura(), $anterior));
        $this->assertSame([], $this->servico()->verificarContinuidade(
            $this->fatura(['numero_sequencial' => 2, 'numero_documento' => 'FT A2026/2', 'hash' => 'h2', 'hash_anterior' => 'h1']),
            $anterior
        ));
    }

    public function test_lacuna_na_numeracao_e_detetada(): void
    {
        $anterior = [];
        $this->servico()->verificarContinuidade($this->fatura(), $anterior);

        $erros = $this->servico()->verificarContinuidade(
            $this->fatura(['numero_sequencial' => 3, 'numero_documento' => 'FT A2026/3', 'hash' => 'h3', 'hash_anterior' => 'h2']),
            $anterior
        );

        $this->assertStringContainsString('Lacuna', implode(' ', $erros));
    }

    public function test_cadeia_de_hash_interrompida_e_detetada(): void
    {
        $anterior = [];
        $this->servico()->verificarContinuidade($this->fatura(), $anterior);

        $erros = $this->servico()->verificarContinuidade(
            $this->fatura(['numero_sequencial' => 2, 'numero_documento' => 'FT A2026/2', 'hash' => 'h2', 'hash_anterior' => 'outro']),
            $anterior
        );

        $this->assertStringContainsString('Cadeia de hash interrompida', implode(' ', $erros));
    }

    // --- Configuração ----------------------------------------------------

    public function test_taxa_permitida_sem_codigo_e_erro_de_configuracao(): void
    {
        config(['faturacao.taxas_iva_permitidas' => [14.0, 7.0]]);

        $erros = $this->servico()->validarConfiguracao();

        $this->assertCount(1, $erros);
        $this->assertStringContainsString('7.00', $erros[0]);
    }

    // --- Com base de dados -----------------------------------------------

    public function test_periodo_sem_documentos_nao_tem_erros(): void
    {
        $this->assertSame([], $this->servico()->validar($this->empresa, now()->startOfYear(), now()->endOfYear()));
    }

    public function test_documentos_emitidos_pelo_sistema_passam_a_pre_validacao(): void
    {
        $this->emitir('Primeira');
        $this->emitir('Segunda');

        $this->assertSame([], $this->servico()->validar($this->empresa, now()->startOfYear(), now()->endOfYear()));
    }

    public function test_fatura_de_adiantamento_sai_no_saft_com_o_seu_tipo(): void
    {
        $this->emitir('Venda normal');
        $this->emitir('Adiantamento', adiantamento: true);

        $dom = new DOMDocument;
        $dom->loadXML($this->xml());
        $xp = new DOMXPath($dom);

        $this->assertSame(1, (int) $xp->evaluate('count(//Invoice[InvoiceType="FT"])'));
        $this->assertSame(1, (int) $xp->evaluate('count(//Invoice[InvoiceType="FA"])'));
    }

    // --- Namespace -------------------------------------------------------

    public function test_sem_namespace_configurado_o_ficheiro_fica_como_antes(): void
    {
        $this->emitir('Venda');

        $this->assertStringContainsString('<AuditFile>', $this->xml());
    }

    public function test_namespace_configurado_aplica_se_a_todo_o_ficheiro(): void
    {
        config(['faturacao.saft_namespace' => 'urn:teste:saft']);
        $this->emitir('Venda');

        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML($this->xml()));

        $this->assertSame('urn:teste:saft', $dom->documentElement->namespaceURI);
        $this->assertSame(1, $dom->getElementsByTagNameNS('urn:teste:saft', 'Invoice')->length);
        $this->assertSame(1, $dom->getElementsByTagNameNS('urn:teste:saft', 'Header')->length);
    }

    public function test_namespace_com_caracteres_invalidos_e_recusado(): void
    {
        config(['faturacao.saft_namespace' => 'urn:"><x']);

        $this->expectException(\RuntimeException::class);
        $this->xml();
    }
}
