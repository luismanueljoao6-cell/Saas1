<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Exceptions\DocumentoImutavelException;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NotaCreditoDebitoService;
use Tests\TestCase;

class CadeiaFiscalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Chave RSA gerada só para este processo de teste — nunca escrita
        // em disco de forma persistente, nunca reutilizada fora daqui.
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $chavePrivadaPem);

        $caminho = sys_get_temp_dir().'/faturacao-teste-'.uniqid().'.pem';
        file_put_contents($caminho, $chavePrivadaPem);

        config(['faturacao.chave_privada_path' => $caminho]);

        $this->beforeApplicationDestroyed(function () use ($caminho) {
            @unlink($caminho);
        });
    }

    protected function criarEmpresaComCliente(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Loja Teste '.$nif, 'nif' => $nif]);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        return [$empresa, $cliente];
    }

    protected function linhaExemplo(): array
    {
        return [['descricao' => 'Serviço de exemplo', 'quantidade' => 1, 'preco_unitario' => 1000, 'taxa_iva' => 14]];
    }

    public function test_numeracao_e_sequencial_sem_lacunas_dentro_da_mesma_serie(): void
    {
        [$empresa, $cliente] = $this->criarEmpresaComCliente('300000001');
        $servico = app(FaturaService::class);

        $numeros = [];
        for ($i = 0; $i < 3; $i++) {
            $fatura = $servico->criarRascunho($empresa, $cliente, $this->linhaExemplo());
            $fatura = $servico->emitir($fatura);
            $numeros[] = $fatura->numero_sequencial;
        }

        $this->assertSame([1, 2, 3], $numeros);
    }

    public function test_hash_de_cada_fatura_encadeia_com_o_hash_da_fatura_anterior(): void
    {
        [$empresa, $cliente] = $this->criarEmpresaComCliente('300000002');
        $servico = app(FaturaService::class);

        $primeira = $servico->emitir($servico->criarRascunho($empresa, $cliente, $this->linhaExemplo()));
        $segunda = $servico->emitir($servico->criarRascunho($empresa, $cliente, $this->linhaExemplo()));

        $this->assertNull($primeira->hash_anterior, 'O primeiro documento de uma série deve ter hash_anterior vazio.');
        $this->assertNotNull($primeira->hash);
        $this->assertSame($primeira->hash, $segunda->hash_anterior);
        $this->assertNotSame($primeira->hash, $segunda->hash, 'Duas faturas diferentes nunca podem produzir o mesmo hash.');
    }

    public function test_fatura_emitida_nao_pode_ser_alterada(): void
    {
        [$empresa, $cliente] = $this->criarEmpresaComCliente('300000003');
        $servico = app(FaturaService::class);

        $fatura = $servico->emitir($servico->criarRascunho($empresa, $cliente, $this->linhaExemplo()));

        $this->expectException(DocumentoImutavelException::class);

        $fatura->update(['observacoes' => 'tentativa de alteração depois de emitida']);
    }

    public function test_fatura_emitida_nao_pode_ser_apagada(): void
    {
        [$empresa, $cliente] = $this->criarEmpresaComCliente('300000004');
        $servico = app(FaturaService::class);

        $fatura = $servico->emitir($servico->criarRascunho($empresa, $cliente, $this->linhaExemplo()));

        $this->expectException(DocumentoImutavelException::class);

        $fatura->delete();
    }

    public function test_atualizacao_em_massa_tambem_e_bloqueada_pelo_trigger_de_base_de_dados(): void
    {
        // Este teste existe especificamente porque a trait Imutavel (nível
        // de aplicação) só intercepta update()/delete() numa instância já
        // carregada — nunca uma operação em massa como
        // Fatura::where(...)->update([...]), que não dispara os eventos do
        // Eloquent. A proteção real aqui vem do trigger de base de dados
        // (ver migration 2026_03_01_000009).
        [$empresa, $cliente] = $this->criarEmpresaComCliente('300000007');
        $fatura = app(FaturaService::class)->emitir(
            app(FaturaService::class)->criarRascunho($empresa, $cliente, $this->linhaExemplo())
        );

        // Sem tenant definido, a TenantScope (fail-closed) acrescentaria
        // "1 = 0" à query: o UPDATE não apanharia nenhuma linha e o
        // trigger nunca chegaria a disparar. Com o tenant certo definido,
        // a query apanha a fatura e é o trigger que a trava.
        app(TenantManager::class)->set($empresa->id);

        $this->expectException(QueryException::class);

        Fatura::where('id', $fatura->id)->update(['observacoes' => 'tentativa de alteração em massa']);
    }

    public function test_rascunho_pode_ser_livremente_alterado_antes_de_emitido(): void
    {
        [$empresa, $cliente] = $this->criarEmpresaComCliente('300000005');
        $fatura = app(FaturaService::class)->criarRascunho($empresa, $cliente, $this->linhaExemplo());

        $fatura->update(['observacoes' => 'ainda é rascunho, isto é permitido']);

        $this->assertSame('ainda é rascunho, isto é permitido', $fatura->fresh()->observacoes);
    }

    public function test_nota_de_credito_para_anular_fatura_tem_a_sua_propria_cadeia_de_hash(): void
    {
        [$empresa, $cliente] = $this->criarEmpresaComCliente('300000006');
        $fatura = app(FaturaService::class)->emitir(
            app(FaturaService::class)->criarRascunho($empresa, $cliente, $this->linhaExemplo())
        );

        $notaServico = app(NotaCreditoDebitoService::class);
        $nota = $notaServico->emitir($notaServico->criarParaAnularFatura($fatura, 'Erro na fatura original'));

        $this->assertSame('NC', $nota->tipoDocumentoFiscal());
        $this->assertSame($fatura->id, $nota->fatura_id);
        $this->assertEquals($fatura->valor_total, $nota->valor_total);
        $this->assertNull($nota->hash_anterior, 'Primeira nota de crédito da empresa: sem documento anterior na série NC.');
        // A fatura original continua exatamente como estava — a "anulação"
        // é um documento novo, nunca uma alteração ao original.
        $this->assertSame('emitida', $fatura->fresh()->estado);
    }
}
