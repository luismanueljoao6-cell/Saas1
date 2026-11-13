<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Tests\TestCase;

/** Linhas a 0%: só com motivo de isenção da tabela (taxExemptionCode, Anexo 6.4). */
class IsencaoIvaTest extends TestCase
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

        config([
            'faturacao.chave_privada_path' => $caminho,
            'faturacao.agt.ativo' => false,
            'faturacao.taxas_iva_permitidas' => [14.0, 0.0],
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja Isenta', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente', 'nif' => null]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function linha(array $extra = []): array
    {
        return [array_merge(['descricao' => 'Livro', 'quantidade' => 1, 'preco_unitario' => '100.00', 'taxa_iva' => 0, 'motivo_isencao' => 'M13'], $extra)];
    }

    public function test_linha_isenta_sem_motivo_e_recusada(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('exige o motivo de isenção');

        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['motivo_isencao' => null]));
    }

    public function test_motivo_desconhecido_e_recusado(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('não consta da tabela');

        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['motivo_isencao' => 'ZZZ']));
    }

    public function test_motivo_numa_linha_com_iva_e_recusado(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('só as linhas isentas');

        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['taxa_iva' => 14]));
    }

    public function test_taxa_omitida_ou_vazia_nunca_se_torna_isencao_em_silencio(): void
    {
        foreach ([['taxa_iva' => ''], ['taxa_iva' => null]] as $extra) {
            try {
                app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha($extra));
                $this->fail('Devia ter recusado a linha sem taxa.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('taxa de IVA', $e->getMessage());
            }
        }
    }

    public function test_preco_em_falta_ou_vazio_nao_vira_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('preço unitário');

        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['preco_unitario' => '']));
    }

    public function test_isencao_valida_e_gravada_com_o_motivo_normalizado(): void
    {
        $fatura = app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha(['motivo_isencao' => ' m13 ']));

        $linha = $fatura->linhas()->first();

        $this->assertSame('M13', $linha->motivo_isencao);
        $this->assertSame('0.00', $fatura->valor_iva);
        $this->assertSame('100.00', $fatura->valor_total);
    }

    public function test_nota_de_credito_de_anulacao_copia_o_motivo_de_isencao(): void
    {
        $faturas = app(FaturaService::class);
        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, $this->linha()));

        $notas = app(NotaCreditoDebitoService::class);
        $nota = $notas->emitir($notas->criarParaAnularFatura($fatura, 'Erro de faturação'));

        $this->assertSame('emitida', $nota->estado);
        $this->assertSame('M13', $nota->linhas()->first()->motivo_isencao);
    }

    public function test_nota_de_credito_isenta_sem_motivo_e_recusada(): void
    {
        $faturas = app(FaturaService::class);
        $fatura = $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, $this->linha()));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('exige o motivo de isenção');

        app(NotaCreditoDebitoService::class)->criarRascunho(
            $this->empresa->id, $this->cliente->id, 'credito', 'Devolução', $this->linha(['motivo_isencao' => null]), $fatura->id,
        );
    }

    public function test_com_agt_ativa_a_isencao_so_e_aceite_com_a_tabela_confirmada(): void
    {
        config(['faturacao.agt.ativo' => true, 'faturacao.motivos_isencao_confirmados' => false]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('FATURACAO_MOTIVOS_ISENCAO_CONFIRMADOS');

        app(FaturaService::class)->criarRascunho($this->empresa, $this->cliente, $this->linha());
    }

    public function test_tabela_de_motivos_contem_os_codigos_publicados_no_anexo_agt(): void
    {
        $motivos = (array) config('faturacao.motivos_isencao', []);

        $esperados = [
            'M10', 'M11', 'M12', 'M13', 'M14', 'M15', 'M16', 'M17', 'M18', 'M19',
            'M20', 'M21', 'M22', 'M23', 'M24', 'M80', 'M81', 'M82', 'M83', 'M84',
            'M85', 'M86', 'M30', 'M31', 'M32', 'M33', 'M34', 'M35', 'M36', 'M37',
            'M38', 'M90', 'M91', 'M92', 'M93', 'M00', 'M02', 'M04',
        ];

        $this->assertSame($esperados, array_keys($motivos));
        $this->assertArrayNotHasKey('M94', $motivos);
        $this->assertSame('NS', $motivos['M02']['tipo']);
        $this->assertSame('NS', $motivos['M04']['tipo']);
        $this->assertSame('REGIME', $motivos['M00']['tipo']);
        $this->assertStringContainsString('alínea b)', $motivos['M11']['mencao']);
    }

    public function test_codigo_m00_de_regime_nao_pode_ser_usado_como_isencao_de_linha(): void
    {
        $this->expectException(\\DomainException::class);
        $this->expectExceptionMessage('enquadramento de regime');

        app(FaturaService::class)->criarRascunho(
            $this->empresa,
            $this->cliente,
            $this->linha(['motivo_isencao' => 'M00']),
        );
    }

    public function test_codigo_m02_e_guardado_como_nao_sujeicao(): void
    {
        $fatura = app(FaturaService::class)->criarRascunho(
            $this->empresa,
            $this->cliente,
            $this->linha(['motivo_isencao' => 'M02']),
        );

        $this->assertSame('M02', $fatura->linhas()->first()->motivo_isencao);
        $this->assertSame('0.00', $fatura->valor_iva);
    }

}
