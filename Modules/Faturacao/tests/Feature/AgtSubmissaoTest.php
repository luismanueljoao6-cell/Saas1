<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\AgtSubmissaoService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Jws;
use Modules\Faturacao\Support\NumeroJson;
use Tests\TestCase;

class AgtSubmissaoTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    /** @var array<string, mixed> */
    protected array $respostaRegistar = ['requestID' => '202500000010689', 'errorList' => []];

    /** @var array<string, mixed> */
    protected array $respostaEstado = ['resultCode' => '8'];

    protected bool $falharRede = false;

    protected bool $falharRedeEstado = false;

    protected function setUp(): void
    {
        parent::setUp();

        [$privadaEmpresa] = $this->gerarPar();
        [$privadaSoftware] = $this->gerarPar();

        config([
            'faturacao.chave_privada_path' => $privadaEmpresa,
            'faturacao.chaves_dir' => $this->instalarChaveDaEmpresa($privadaEmpresa),
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

        $this->fingirAgt();
    }

    /** Cria {chaves_dir}/{NIF}.pem — com a AGT ativa a chave genérica já não é aceite. */
    protected function instalarChaveDaEmpresa(string $privadaEmpresa, string $nif = '5001636863'): string
    {
        $dir = sys_get_temp_dir().'/agt-chaves-'.uniqid();
        mkdir($dir);
        copy($privadaEmpresa, "{$dir}/{$nif}.pem");

        $this->beforeApplicationDestroyed(function () use ($dir, $nif) {
            @unlink("{$dir}/{$nif}.pem");
            @rmdir($dir);
        });

        return $dir;
    }

    /** @return array{0: string} */
    protected function gerarPar(): array
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $privadaPem);

        $privada = sys_get_temp_dir().'/agt-priv-'.uniqid().'.pem';
        file_put_contents($privada, $privadaPem);
        $this->beforeApplicationDestroyed(fn () => @unlink($privada));

        return [$privada];
    }

    /**
     * Simula a AGT uma só vez (um novo Http::fake() apagaria os pedidos
     * gravados e as respostas antigas ganhariam às novas); as respostas lidas
     * aqui vêm das propriedades, que cada teste pode alterar.
     */
    protected function fingirAgt(): void
    {
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
            '*registarFactura' => fn () => $this->falharRede
                ? throw new ConnectionException('timeout')
                : Http::response($this->respostaRegistar, isset($this->respostaRegistar['requestID']) ? 200 : 400),
            '*obterEstado' => fn () => $this->falharRedeEstado
                ? throw new ConnectionException('timeout')
                : Http::response($this->respostaEstado, 200),
        ]);
    }

    protected function emitirFatura(): Fatura
    {
        foreach (['FT', 'RC'] as $tipo) {
            app(AgtSerieService::class)->solicitar($this->empresa, $tipo, now()->year);
        }

        $servico = app(FaturaService::class);
        $linhas = [['descricao' => 'Serviço', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]];

        return $servico->emitir($servico->criarRascunho($this->empresa, $this->cliente, $linhas));
    }

    protected function pedidoPara(string $servico): Request
    {
        return collect(Http::recorded())->map(fn ($par) => $par[0])->first(fn (Request $r) => str_contains($r->url(), $servico));
    }

    public function test_emitir_com_agt_ativa_cria_submissao_pendente(): void
    {
        $fatura = $this->emitirFatura();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('pendente', $submissao->estado);
        $this->assertSame('FT FT6325S2C/1', $submissao->documento_no);
        $this->assertSame($fatura->id, $submissao->documento_id);
    }

    public function test_enviar_pendentes_monta_o_documento_segundo_a_especificacao(): void
    {
        $fatura = $this->emitirFatura();

        $res = app(AgtSubmissaoService::class)->enviarPendentes();

        $this->assertSame(1, $res['enviados']);
        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('enviado', $submissao->estado);
        $this->assertSame('202500000010689', $submissao->request_id);

        $dados = $this->pedidoPara('registarFactura')->data();
        $this->assertSame('2.0', $dados['schemaVersion']);
        $this->assertSame('5001636863', $dados['taxRegistrationNumber']);
        $this->assertSame(1, $dados['numberOfEntries']);
        $this->assertSame('C_134', $dados['softwareInfo']['softwareInfoDetail']['softwareValidationNumber']);
        $this->assertSame(1, $dados['softwareInfo']['softwareInfoDetail']['signatureVersion']);

        $documento = $dados['documents'][0];
        $this->assertSame('FT FT6325S2C/1', $documento['documentNo']);
        $this->assertSame('N', $documento['documentStatus']);
        $this->assertSame($fatura->hash, $documento['jwsDocumentSignature']);
        $this->assertSame('FT', $documento['documentType']);
        $this->assertSame('999999999', $documento['customerTaxID']);
        $this->assertSame('AO', $documento['customerCountry']);
        $this->assertSame(['taxPayable' => 140, 'netTotal' => 1000, 'grossTotal' => 1140], $documento['documentTotals']);

        $linha = $documento['lines'][0];
        $this->assertSame(1, $linha['lineNumber']);
        $this->assertSame('SG', $linha['operationType']);
        $this->assertSame(1, $linha['quantity']);
        $this->assertSame(1000, $linha['unitPriceBase']);
        $this->assertSame(1000, $linha['creditAmount']);
        $this->assertArrayNotHasKey('debitAmount', $linha);
        $this->assertSame(0, $linha['settlementAmount']);
        $this->assertSame(
            ['taxType' => 'IVA', 'taxCountryRegion' => 'AO', 'taxCode' => 'NOR', 'taxPercentage' => 14, 'taxContribution' => 140],
            $linha['taxes'][0],
        );

        // O que se envia é exactamente o objecto que foi assinado.
        $assinado = json_decode(Jws::payload($documento['jwsDocumentSignature']), true);
        $this->assertSame($assinado['documentTotals'], $documento['documentTotals']);
        $this->assertSame($assinado['documentNo'], $documento['documentNo']);
    }

    public function test_recibo_rc_nao_e_enviado_como_payment_receipt(): void
    {
        $fatura = $this->emitirFatura();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tipo RG');

        app(ReciboService::class)->emitir($this->empresa->id, $this->cliente->id, '500.00', 'dinheiro', $fatura->id);
    }

    public function test_tipo_de_operacao_da_linha_e_preservado_no_payload_agt(): void
    {
        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);

        $servico = app(FaturaService::class);
        $fatura = $servico->criarRascunho($this->empresa, $this->cliente, [[
            'descricao' => 'Material de produção',
            'quantidade' => 1,
            'preco_unitario' => 1000,
            'taxa_iva' => 14,
            'operation_type' => 'TB',
        ]]);
        $servico->emitir($fatura);

        app(AgtSubmissaoService::class)->enviarPendentes();

        $documento = $this->pedidoPara('registarFactura')->data()['documents'][0];

        $this->assertSame('TB', $documento['lines'][0]['operationType']);
    }

    public function test_desconto_da_linha_e_enviado_com_preco_base_e_settlement_amount(): void
    {
        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);

        $servico = app(FaturaService::class);
        $fatura = $servico->criarRascunho($this->empresa, $this->cliente, [[
            'descricao' => 'Serviço com desconto',
            'quantidade' => 2,
            'preco_unitario_base' => '1000.00',
            'preco_unitario' => '900.00',
            'taxa_iva' => 14,
            'operation_type' => 'SG',
        ]]);
        $servico->emitir($fatura);

        app(AgtSubmissaoService::class)->enviarPendentes();

        $linha = $this->pedidoPara('registarFactura')->data()['documents'][0]['lines'][0];

        $this->assertEqualsWithDelta(1000.00, $linha['unitPriceBase'], 0.0001);
        $this->assertEqualsWithDelta(900.00, $linha['unitPrice'], 0.0001);
        $this->assertEqualsWithDelta(200.00, $linha['settlementAmount'], 0.0001);
    }

    public function test_estado_valido_e_invalido_sao_registados(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $this->respostaEstado = [
            'resultCode' => '0',
            'documentStatusList' => [['documentNo' => 'FT FT6325S2C/1', 'documentStatus' => 'V']],
        ];

        $res = app(AgtSubmissaoService::class)->consultarEstados();

        $this->assertSame(1, $res['validos']);
        $this->assertSame('valido', AgtSubmissao::query()->firstOrFail()->estado);
        $this->assertNotNull(AgtSubmissao::query()->firstOrFail()->validado_em);
    }

    public function test_documento_invalido_guarda_os_erros_da_agt(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $this->respostaEstado = [
            'resultCode' => '2',
            'documentStatusList' => [[
                'documentNo' => 'FT FT6325S2C/1',
                'documentStatus' => 'I',
                'errorList' => [['errorCode' => 'E22', 'errorDescription' => 'Total de impostos não coincide']],
            ]],
        ];

        app(AgtSubmissaoService::class)->consultarEstados();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('invalido', $submissao->estado);
        $this->assertSame('E22', $submissao->erros[0]['codigo']);
    }

    public function test_processamento_em_curso_nao_e_consultado_de_novo_antes_do_intervalo(): void
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);
        $servico->enviarPendentes();

        $servico->consultarEstados();
        $servico->consultarEstados();

        $consultas = collect(Http::recorded())->filter(fn ($par) => str_contains($par[0]->url(), 'obterEstado'));
        $this->assertCount(1, $consultas);
        $this->assertSame('enviado', AgtSubmissao::query()->firstOrFail()->estado);
    }

    public function test_pedido_de_estado_leva_jws_signature_do_emissor(): void
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);
        $servico->enviarPendentes();
        $servico->consultarEstados();

        $dados = $this->pedidoPara('obterEstado')->data();

        $this->assertSame('202500000010689', $dados['requestID']);
        $this->assertSame(
            '{"taxRegistrationNumber":"5001636863","requestID":"202500000010689"}',
            Jws::payload($dados['jwsSignature']),
        );
    }

    public function test_rejeicao_por_documento_marca_so_esse_documento(): void
    {
        $this->respostaRegistar = ['errorList' => [['idError' => 'E16', 'descriptionError' => 'Soma a crédito inválida', 'documentNo' => 'FT FT6325S2C/1']]];
        $this->emitirFatura();

        $res = app(AgtSubmissaoService::class)->enviarPendentes();

        $this->assertSame(1, $res['rejeitados']);
        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('rejeitado', $submissao->estado);
        $this->assertSame('E16', $submissao->erros[0]['codigo']);
        $this->assertSame('FT FT6325S2C/1', $submissao->erros[0]['documento']);
    }

    public function test_falha_de_rede_mantem_pendente_e_conta_tentativas(): void
    {
        $this->emitirFatura();

        $this->falharRede = true;
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('pendente', $submissao->estado);
        $this->assertSame(1, $submissao->tentativas);
        $this->assertNotEmpty($submissao->submission_uuid);
        $this->assertNotNull($submissao->submission_timestamp);
        $this->assertNull($submissao->enviado_em);
    }

    public function test_retentativa_de_rede_reutiliza_uuid_e_timestamp_originais(): void
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);

        $this->falharRede = true;
        $servico->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $uuidOriginal = $submissao->submission_uuid;
        $timestampOriginal = $submissao->submission_timestamp->copy();

        $this->falharRede = false;
        $servico->enviarPendentes();

        $submissao->refresh();
        $this->assertSame(AgtSubmissao::ENVIADO, $submissao->estado);
        $this->assertSame($uuidOriginal, $submissao->submission_uuid);
        $this->assertTrue($timestampOriginal->equalTo($submissao->submission_timestamp));
        $this->assertNotNull($submissao->enviado_em);

        $pedidos = collect(Http::recorded())
            ->map(fn ($par) => $par[0])
            ->filter(fn (Request $pedido) => str_contains($pedido->url(), 'registarFactura'));
        $ultimo = $pedidos->last();

        $this->assertSame($uuidOriginal, $ultimo->data()['submissionUUID']);
        $this->assertSame($timestampOriginal->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'), $ultimo->data()['submissionTimeStamp']);
    }

    public function test_payload_divergente_nao_reutiliza_o_mesmo_uuid(): void
    {
        $this->emitirFatura();
        $this->falharRede = true;
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $submissao->update(['submission_payload_hash' => str_repeat('0', 64)]);
        $pedidosAntes = count(Http::recorded());

        $this->falharRede = false;
        app(AgtSubmissaoService::class)->enviarPendentes();

        $submissao->refresh();
        $this->assertSame(AgtSubmissao::AGUARDA_INTERVENCAO, $submissao->estado);
        $this->assertSame('PAYLOAD_DIVERGENTE', $submissao->erros[0]['codigo']);
        $this->assertCount($pedidosAntes, Http::recorded(), 'Um payload diferente nunca deve ser reenviado com o UUID antigo.');
    }

    public function test_documento_que_nao_pode_ir_para_a_agt_fica_em_erro_sem_bloquear_o_envio(): void
    {
        $this->emitirFatura();

        // Taxa sem código de imposto AGT configurado: o documento não pode ser montado.
        config(['faturacao.agt.codigos_taxa' => []]);

        $res = app(AgtSubmissaoService::class)->enviarPendentes();

        $this->assertSame(1, $res['erros']);
        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame('erro', $submissao->estado);
        $this->assertSame('LOCAL', $submissao->erros[0]['codigo']);
        $this->assertStringContainsString('codigos_taxa', $submissao->erros[0]['descricao']);
    }

    public function test_enviado_sem_resposta_ha_muito_tempo_fica_a_aguardar_intervencao(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();
        AgtSubmissao::query()->update(['enviado_em' => now()->subHours(25)]);

        app(AgtSubmissaoService::class)->consultarEstados();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame(AgtSubmissao::AGUARDA_INTERVENCAO, $submissao->estado);
        $this->assertSame('SEM_RESPOSTA', $submissao->erros[0]['codigo']);
    }

    public function test_submissao_a_aguardar_intervencao_com_request_id_continua_a_ser_consultada(): void
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);
        $servico->enviarPendentes();
        AgtSubmissao::query()->update(['enviado_em' => now()->subHours(25)]);

        $this->respostaEstado = [
            'resultCode' => '0',
            'documentStatusList' => [['documentNo' => 'FT FT6325S2C/1', 'documentStatus' => 'V']],
        ];

        $res = $servico->consultarEstados();

        $this->assertSame(1, $res['validos']);
        $this->assertSame(AgtSubmissao::VALIDO, AgtSubmissao::query()->firstOrFail()->estado);
    }

    public function test_enviado_recentemente_continua_a_aguardar_a_agt(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        app(AgtSubmissaoService::class)->consultarEstados();

        $this->assertSame(AgtSubmissao::ENVIADO, AgtSubmissao::query()->firstOrFail()->estado);
    }

    public function test_falha_de_rede_na_consulta_nao_rebenta_e_mantem_enviado(): void
    {
        $this->emitirFatura();
        app(AgtSubmissaoService::class)->enviarPendentes();

        $this->falharRedeEstado = true;
        $res = app(AgtSubmissaoService::class)->consultarEstados();

        $this->assertSame(1, $res['pendentes']);
        $this->assertSame(AgtSubmissao::ENVIADO, AgtSubmissao::query()->firstOrFail()->estado);
    }

    public function test_reenviar_aceita_erro_tecnico_e_volta_a_pendente(): void
    {
        $this->emitirFatura();
        $submissao = AgtSubmissao::query()->firstOrFail();
        $submissao->update(['estado' => AgtSubmissao::ERRO, 'tentativas' => 3, 'erros' => [['codigo' => 'REDE', 'descricao' => 'timeout']]]);

        $nova = app(AgtSubmissaoService::class)->reenviar($submissao);

        $this->assertSame(AgtSubmissao::PENDENTE, $nova->estado);
        $this->assertSame(0, $nova->tentativas);
        $this->assertSame(1, app(AgtSubmissaoService::class)->enviarPendentes()['enviados']);
    }

    public function test_submissao_com_request_id_nao_pode_ser_reenviada(): void
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);
        $servico->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();

        try {
            $servico->reenviar($submissao);
            $this->fail('Uma submissão com requestID não pode ser enviada de novo.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('consulta o estado original', $e->getMessage());
        }

        $this->assertSame(AgtSubmissao::ENVIADO, $submissao->fresh()->estado);
    }

    public function test_documento_invalido_ou_rejeitado_nao_pode_ser_reenviado(): void
    {
        $this->emitirFatura();
        $submissao = AgtSubmissao::query()->firstOrFail();

        foreach ([AgtSubmissao::INVALIDO, AgtSubmissao::REJEITADO, AgtSubmissao::VALIDO, AgtSubmissao::PENDENTE] as $estado) {
            $submissao->update(['estado' => $estado]);

            try {
                app(AgtSubmissaoService::class)->reenviar($submissao);
                $this->fail("Reenvio de '{$estado}' devia ser recusado.");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('Só se pode reenviar uma submissão em erro técnico', $e->getMessage());
            }
        }
    }

    /** Leva a submissão a PAYLOAD_DIVERGENTE pelo caminho real: timeout, corpo que muda, nova tentativa. */
    protected function submissaoComPayloadDivergente(): AgtSubmissao
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);

        $this->falharRede = true;
        $servico->enviarPendentes();

        AgtSubmissao::query()->firstOrFail()->update(['submission_payload_hash' => str_repeat('0', 64)]);

        $this->falharRede = false;
        $servico->enviarPendentes();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame(AgtSubmissao::AGUARDA_INTERVENCAO, $submissao->estado);
        $this->assertSame('PAYLOAD_DIVERGENTE', $submissao->erros[0]['codigo']);

        return $submissao;
    }

    public function test_payload_divergente_pode_ser_reenviado_com_uuid_novo(): void
    {
        $submissao = $this->submissaoComPayloadDivergente();
        $uuidAntigo = $submissao->submission_uuid;
        $servico = app(AgtSubmissaoService::class);

        $nova = $servico->reenviar($submissao);

        $this->assertSame(AgtSubmissao::PENDENTE, $nova->estado);
        $this->assertNull($nova->submission_uuid);
        $this->assertNull($nova->submission_timestamp);
        $this->assertNull($nova->submission_payload_hash);
        $this->assertNull($nova->request_id);
        $this->assertNull($nova->erros);
        $this->assertSame(0, $nova->tentativas);

        $this->assertSame(1, $servico->enviarPendentes()['enviados']);

        $enviada = AgtSubmissao::query()->firstOrFail();
        $this->assertSame(AgtSubmissao::ENVIADO, $enviada->estado);
        $this->assertNotNull($enviada->request_id);
        $this->assertNotSame($uuidAntigo, $enviada->submission_uuid);

        $ultimo = collect(Http::recorded())
            ->map(fn ($par) => $par[0])
            ->filter(fn (Request $pedido) => str_contains($pedido->url(), 'registarFactura'))
            ->last();

        $this->assertSame($enviada->submission_uuid, $ultimo->data()['submissionUUID']);
        $this->assertNotSame($uuidAntigo, $ultimo->data()['submissionUUID']);
    }

    public function test_a_aguardar_intervencao_com_request_id_ou_por_outro_motivo_nao_se_reenvia(): void
    {
        $this->emitirFatura();
        $servico = app(AgtSubmissaoService::class);
        $servico->enviarPendentes();

        AgtSubmissao::query()->update(['enviado_em' => now()->subHours(25)]);
        $servico->consultarEstados();

        $submissao = AgtSubmissao::query()->firstOrFail();
        $this->assertSame(AgtSubmissao::AGUARDA_INTERVENCAO, $submissao->estado);
        $this->assertNotNull($submissao->request_id);

        try {
            $servico->reenviar($submissao);
            $this->fail('Com requestID a AGT já conhece o pedido: consulta-se, não se reenvia.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('consulta o estado original', $e->getMessage());
        }

        // Sem requestID, mas por um motivo que não é PAYLOAD_DIVERGENTE: também recusado.
        $submissao->update(['request_id' => null]);

        $this->expectException(\DomainException::class);

        $servico->reenviar($submissao->fresh());
    }

    public function test_aguardam_intervencao_lista_so_o_que_o_reenvio_aceita(): void
    {
        $this->emitirFatura();
        $submissao = AgtSubmissao::query()->firstOrFail();
        $servico = app(AgtSubmissaoService::class);

        $casos = [
            [AgtSubmissao::ERRO, null, 'REDE', 1],
            [AgtSubmissao::AGUARDA_INTERVENCAO, null, 'PAYLOAD_DIVERGENTE', 1],
            [AgtSubmissao::AGUARDA_INTERVENCAO, null, 'SEM_RESPOSTA', 0],
            [AgtSubmissao::ERRO, '202500000010689', 'E99', 0],
            [AgtSubmissao::ENVIADO, '202500000010689', null, 0],
            [AgtSubmissao::PENDENTE, null, null, 0],
        ];

        foreach ($casos as [$estado, $requestId, $codigo, $esperadas]) {
            $submissao->update([
                'estado' => $estado,
                'request_id' => $requestId,
                'erros' => $codigo === null ? null : [['codigo' => $codigo, 'descricao' => 'teste']],
            ]);

            $this->assertCount($esperadas, $servico->aguardamIntervencao(), "{$estado} / {$codigo}");
        }

        $submissao->update(['estado' => AgtSubmissao::ERRO, 'request_id' => null, 'erros' => [['codigo' => 'REDE', 'descricao' => 'timeout']]]);

        $this->assertCount(1, $servico->aguardamIntervencao($this->empresa->id));
        $this->assertCount(0, $servico->aguardamIntervencao($this->empresa->id + 1));
    }

    public function test_comando_lista_as_paradas_e_reenvia_por_id(): void
    {
        $this->emitirFatura();
        $submissao = AgtSubmissao::query()->firstOrFail();

        $this->assertSame(0, Artisan::call('faturacao:agt-reenviar'));
        $this->assertStringContainsString('Nenhuma submissão', Artisan::output());

        $submissao->update(['estado' => AgtSubmissao::ERRO, 'tentativas' => 5, 'erros' => [['codigo' => 'REDE', 'descricao' => 'timeout']]]);

        $this->assertSame(0, Artisan::call('faturacao:agt-reenviar'));
        $this->assertStringContainsString('FT FT6325S2C/1', Artisan::output());

        $this->assertSame(0, Artisan::call('faturacao:agt-reenviar', ['submissao' => $submissao->id]));
        $this->assertSame(AgtSubmissao::PENDENTE, $submissao->fresh()->estado);
        $this->assertSame(1, app(AgtSubmissaoService::class)->enviarPendentes()['enviados']);
    }

    public function test_comando_exige_confirmacao_para_payload_divergente_e_recusa_o_que_nao_se_reenvia(): void
    {
        $submissao = $this->submissaoComPayloadDivergente();

        // Sem confirmação (modo não interactivo): nada muda.
        $this->assertSame(1, Artisan::call('faturacao:agt-reenviar', ['submissao' => $submissao->id, '--no-interaction' => true]));
        $this->assertSame(AgtSubmissao::AGUARDA_INTERVENCAO, $submissao->fresh()->estado);

        $this->assertSame(0, Artisan::call('faturacao:agt-reenviar', ['submissao' => $submissao->id, '--force' => true]));
        $this->assertSame(AgtSubmissao::PENDENTE, $submissao->fresh()->estado);
        $this->assertNull($submissao->fresh()->submission_uuid);

        // Já pendente: o serviço recusa e o comando devolve erro.
        $this->assertSame(1, Artisan::call('faturacao:agt-reenviar', ['submissao' => $submissao->id, '--force' => true]));
        $this->assertStringContainsString('Só se pode reenviar', Artisan::output());

        $this->assertSame(1, Artisan::call('faturacao:agt-reenviar', ['submissao' => 99999]));
    }

    public function test_so_o_estado_valido_conta_como_confirmacao_fiscal(): void
    {
        $this->assertFalse((new AgtSubmissao(['estado' => AgtSubmissao::ENVIADO]))->confirmadoFiscalmente());
        $this->assertTrue((new AgtSubmissao(['estado' => AgtSubmissao::VALIDO]))->confirmadoFiscalmente());
        $this->assertSame('A aguardar intervenção', (new AgtSubmissao(['estado' => AgtSubmissao::AGUARDA_INTERVENCAO]))->rotulo());
    }

    public function test_numero_json_de_decimal_remove_zeros_a_direita(): void
    {
        $this->assertSame('250', NumeroJson::deDecimal('250.00')->literal);
        $this->assertSame('0.5', NumeroJson::deDecimal('0.500')->literal);
        $this->assertSame('14', NumeroJson::deDecimal('14.00')->literal);
        $this->assertSame('0', NumeroJson::deDecimal('0.000')->literal);
        $this->assertSame('1.25', NumeroJson::deDecimal('1.250')->literal);
    }
}
