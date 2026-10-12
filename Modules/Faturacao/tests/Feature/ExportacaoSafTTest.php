<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Modules\Faturacao\Services\SafTExportService;
use Tests\TestCase;

class ExportacaoSafTTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Emitir uma fatura exige chave privada de assinatura fiscal. No CI
        // não existe chave real, por isso geramos uma só para este processo
        // de teste — nunca escrita de forma persistente, nunca reutilizada.
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $chavePrivadaPem);

        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $chavePrivadaPem);

        config(['faturacao.chave_privada_path' => $caminho]);
        // Os exemplos de teste são serviços sem produto cadastrado.
        config(['faturacao.saft_tipo_produto_padrao' => 'S']);

        $this->beforeApplicationDestroyed(function () use ($caminho) {
            @unlink($caminho);
        });
    }

    /**
     * Reproduz exatamente o bug real: um worker de filas não tem tenant
     * "ambiente" nenhum. Sem o bypass no GerarSafTJob, esta chamada
     * devolveria um XML sem clientes nem faturas — mesmo com dados na
     * base de dados — porque a TenantScope (fail-closed) bloquearia as
     * queries de Cliente/Produto/Fatura por trás do
     * SafTExportService::gerar().
     */
    public function test_saft_inclui_dados_mesmo_sem_tenant_ambiente_definido(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa SAF-T', 'nome_legal' => 'Empresa SAF-T, Lda.', 'nif' => '4000000001', 'morada' => 'Rua de teste, 10', 'municipio' => 'Luanda', 'provincia' => 'Luanda']);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente SAF-T', 'nif' => '4000000002', 'morada' => 'Rua do cliente, 20', 'municipio' => 'Luanda', 'provincia' => 'Luanda']);

        app(FaturaService::class)->emitir(
            app(FaturaService::class)->criarRascunho($empresa, $cliente, [
                ['descricao' => 'Serviço', 'tipo_saft' => 'S', 'quantidade' => 1, 'preco_unitario' => 5000, 'taxa_iva' => 14],
            ])
        );

        // Confirma que NÃO há tenant definido neste teste (tal como num
        // worker de filas real) antes de gerar o XML.
        $this->assertFalse(app(TenantManager::class)->has());

        $xml = app(TenantManager::class)->semTenant(fn () => app(SafTExportService::class)->gerar(
            $empresa,
            now()->startOfMonth(),
            now()->endOfMonth(),
        ));

        $this->assertStringContainsString('Cliente SAF-T', $xml);
        $this->assertStringContainsString('<NumberOfEntries>1</NumberOfEntries>', $xml);
        $this->assertStringContainsString($empresa->nif, $xml);
        $this->assertStringContainsString('xmlns="urn:OECD:StandardAuditFile-Tax:AO_1.01_01"', $xml);
        $this->assertStringContainsString('<AuditFileVersion>1.01_01</AuditFileVersion>', $xml);
        $this->assertStringContainsString('<DocumentStatus>', $xml);
        $this->assertStringContainsString('<SpecialRegimes>', $xml);
        $this->assertStringContainsString('<DocumentTotals>', $xml);
        $this->assertStringContainsString('<BillingAddress>', $xml);
        $this->assertStringContainsString('<TaxTable>', $xml);
        $this->assertStringContainsString('<ProductType>S</ProductType>', $xml);
    }

    public function test_saft_inclui_referencia_da_fatura_original_na_nota_de_credito(): void
    {
        $empresa = Empresa::create([
            'nome_comercial' => 'Empresa SAF-T',
            'nome_legal' => 'Empresa SAF-T, Lda.',
            'nif' => '4000000001',
            'morada' => 'Rua de teste, 10',
            'municipio' => 'Luanda',
            'provincia' => 'Luanda',
        ]);
        $cliente = Cliente::create([
            'empresa_id' => $empresa->id,
            'nome' => 'Cliente SAF-T',
            'nif' => '4000000002',
            'morada' => 'Rua do cliente, 20',
            'municipio' => 'Luanda',
            'provincia' => 'Luanda',
        ]);

        $faturaService = app(FaturaService::class);
        $fatura = $faturaService->emitir($faturaService->criarRascunho($empresa, $cliente, [
            ['descricao' => 'Serviço', 'tipo_saft' => 'S', 'quantidade' => 1, 'preco_unitario' => 5000, 'taxa_iva' => 14],
        ]));

        $notaService = app(NotaCreditoDebitoService::class);
        $nota = $notaService->criarParaAnularFatura($fatura, 'Correção de teste');
        $notaService->emitir($nota);

        $xml = app(SafTExportService::class)->gerar(
            $empresa,
            now()->startOfMonth(),
            now()->endOfMonth(),
        );

        $this->assertStringContainsString('<References>', $xml);
        $this->assertStringContainsString('<Reference>'.$fatura->numero_documento.'</Reference>', $xml);
        $this->assertStringContainsString('<Reason>Correção de teste</Reason>', $xml);
    }

    /**
     * O modo validado tem de falhar fechado enquanto o gerador não cumprir
     * o XSD: nunca pode devolver silenciosamente um XML estruturalmente inválido.
     */
    public function test_gerar_validado_bloqueia_quando_faltam_dados_fiscais_obrigatorios(): void
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa SAF-T', 'nif' => '400000001']);

        try {
            app(SafTExportService::class)->gerarValidado(
                $empresa,
                now()->startOfMonth(),
                now()->endOfMonth(),
            );

            $this->fail('A exportação validada não devia devolver XML que não cumpre o XSD.');
        } catch (\RuntimeException $erro) {
            $this->assertStringContainsString('dados fiscais obrigatórios', $erro->getMessage());
        }
    }

}
