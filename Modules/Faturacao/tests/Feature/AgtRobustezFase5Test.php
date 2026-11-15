<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Exceptions\AgtException;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AgtAssinaturaService;
use Modules\Faturacao\Services\AgtCorrecaoService;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\AgtSubmissaoService;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Services\FaturaService;
use Tests\TestCase;

/** Fase 5 (auditoria): robustez da comunicação com a AGT. Não prova conformidade fiscal. */
class AgtRobustezFase5Test extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected string $dirChaves;

    protected bool $falharRede = false;

    /** @var array{0: string, 1: string} */
    protected array $intervaloSerie = ['1', '1000'];

    protected function setUp(): void
    {
        parent::setUp();

        $privadaEmpresa = $this->gerarChavePrivada();
        $privadaSoftware = $this->gerarChavePrivada();

        $this->dirChaves = sys_get_temp_dir().'/agt-f5-chaves-'.uniqid();
        mkdir($this->dirChaves);
        copy($privadaEmpresa, $this->dirChaves.'/5001636863.pem');
        $this->beforeApplicationDestroyed(function () {
            @unlink($this->dirChaves.'/5001636863.pem');
            @rmdir($this->dirChaves);
        });

        config([
            'faturacao.chave_privada_path' => $privadaEmpresa,
            'faturacao.chaves_dir' => $this->dirChaves,
            'faturacao.agt.ativo' => true,
            'faturacao.agt.ambiente' => 'homologacao',
            'faturacao.agt.utilizador' => 'produtor',
            'faturacao.agt.palavra_passe' => 's3nh@',
            'faturacao.agt.software.chave_privada_path' => $privadaSoftware,
            'faturacao.agt.software.signature_version' => 1,
            'faturacao.saft_numero_certificado' => 'C_134',
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        Http::fake([
            '*solicitarSerie' => fn (Request $r) => Http::response([
                'resultCode' => 1,
                'errorList' => [''],
                'seriesFEResult' => [
                    'seriesCode' => $r['documentType'].'6325S2C',
                    'authorizedQuantity' => '1000',
                    'firstDocumentNo' => $this->intervaloSerie[0],
                    'lastDocumentNo' => $this->intervaloSerie[1],
                ],
            ], 200),
            '*registarFactura' => fn () => $this->falharRede
                ? throw new ConnectionException('timeout')
                : Http::response(['requestID' => '202500000010689', 'errorList' => []], 200),
            '*obterEstado' => Http::response(['resultCode' => '8'], 200),
        ]);
    }

    protected function gerarChavePrivada(): string
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);

        $caminho = sys_get_temp_dir().'/agt-f5-priv-'.uniqid().'.pem';
        file_put_contents($caminho, $pem);
        $this->beforeApplicationDestroyed(fn () => @unlink($caminho));

        return $caminho;
    }

    protected function emitirFatura(): Fatura
    {
        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);

        $servico = app(FaturaService::class);

        return $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14],
        ]));
    }

    /** @return Collection<int, Request> */
    protected function pedidosPara(string $servico): Collection
    {
        return collect(Http::recorded())
            ->map(fn ($par) => $par[0])
            ->filter(fn (Request $pedido) => str_contains($pedido->url(), $servico))
            ->values();
    }

    public function test_chave_do_emissor_em_falta_nao_aborta_a_consulta_de_estados(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        unlink($this->dirChaves.'/5001636863.pem');
        app()->forgetInstance(AssinaturaFiscalService::class);
        app()->forgetInstance(AgtAssinaturaService::class);
        app()->forgetInstance(AgtSubmissaoService::class);

        $res = app(AgtSubmissaoService::class)->consultarEstados();

        $this->assertSame(1, $res['pendentes']);
        $this->assertSame(AgtSubmissao::ENVIADO, AgtSubmissao::query()->firstOrFail()->estado);
        $this->assertCount(0, $this->pedidosPara('obterEstado'));
    }

    public function test_pedidos_a_aguardar_intervencao_sao_consultados_com_intervalo_maior(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $submissao->update([
            'estado' => AgtSubmissao::AGUARDA_INTERVENCAO,
            'erros' => [['codigo' => 'SEM_RESPOSTA', 'descricao' => 'teste']],
            'ultima_consulta_em' => now()->subMinutes(5),
        ]);

        app(AgtSubmissaoService::class)->consultarEstados();
        $this->assertCount(0, $this->pedidosPara('obterEstado'), 'Cinco minutos depois ainda não é altura de consultar.');

        $submissao->update(['ultima_consulta_em' => now()->subHours(2)]);

        app(AgtSubmissaoService::class)->consultarEstados();
        $this->assertCount(1, $this->pedidosPara('obterEstado'));
    }

    public function test_correcao_de_fatura_rejeitada_preserva_tipo_de_operacao_e_preco_base(): void
    {
        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);

        $servico = app(FaturaService::class);
        $fatura = $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, [[
            'descricao' => 'Serviço com desconto',
            'quantidade' => 2,
            'preco_unitario_base' => '1000.00',
            'preco_unitario' => '900.00',
            'taxa_iva' => 14,
            'operation_type' => 'TB',
        ]]));

        $submissao = AgtSubmissao::query()->where('documento_id', $fatura->id)->firstOrFail();
        $submissao->update(['estado' => AgtSubmissao::REJEITADO, 'erros' => [['codigo' => 'E16', 'descricao' => 'teste']]]);

        $rascunho = app(AgtCorrecaoService::class)->criarRascunhoDeCorrecao($submissao);
        $linha = $rascunho->linhas->first();

        $this->assertSame('TB', $linha->operation_type);
        $this->assertEqualsWithDelta(1000.0, (float) $linha->preco_unitario_base, 0.001);
        $this->assertEqualsWithDelta(900.0, (float) $linha->preco_unitario, 0.001);
    }

    public function test_serie_com_intervalo_incoerente_e_recusada(): void
    {
        $this->intervaloSerie = ['5', '2'];

        $this->expectException(AgtException::class);

        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);
    }

    public function test_falha_de_rede_aplica_backoff_e_nao_reenvia_antes_da_hora(): void
    {
        $this->emitirFatura();

        $this->falharRede = true;
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame(AgtSubmissao::PENDENTE, $submissao->estado);
        $this->assertNotNull($submissao->proxima_tentativa_em);
        $this->assertTrue($submissao->proxima_tentativa_em->isFuture());

        $antes = $this->pedidosPara('registarFactura')->count();

        $this->falharRede = false;
        app(AgtSubmissaoService::class)->enviarPendentes();
        $this->assertCount($antes, $this->pedidosPara('registarFactura'), 'Antes da hora de nova tentativa não há pedido à AGT.');

        $submissao->update(['proxima_tentativa_em' => now()->subSecond()]);

        $this->assertSame(1, app(AgtSubmissaoService::class)->enviarPendentes()['enviados']);
    }

    public function test_esgotadas_as_tentativas_passa_a_erro_sem_nova_tentativa_agendada(): void
    {
        config(['faturacao.agt.max_tentativas' => 2, 'faturacao.agt.backoff_base' => 0]);
        $this->emitirFatura();

        $this->falharRede = true;
        app(AgtSubmissaoService::class)->enviarPendentes();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame(AgtSubmissao::ERRO, $submissao->estado);
        $this->assertSame('REDE', $submissao->erros[0]['codigo']);
        $this->assertNull($submissao->proxima_tentativa_em);
    }
}
