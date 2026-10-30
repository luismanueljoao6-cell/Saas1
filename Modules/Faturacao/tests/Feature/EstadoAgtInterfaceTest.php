<?php

namespace Modules\Faturacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Support\Servico;
use Modules\Faturacao\Models\AgtSubmissao;
use Modules\Faturacao\Models\Cliente;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Services\AgtSerieService;
use Modules\Faturacao\Services\FaturaService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Estado do documento na AGT à vista (só «Válido» é confirmação fiscal) e correção, pela
 * interface, de uma fatura que a AGT marcou como inválida ou rejeitada.
 */
class EstadoAgtInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;

    protected Cliente $cliente;

    protected User $admin;

    protected User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

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
                'seriesFEResult' => ['seriesCode' => $r['documentType'].'6325S2C', 'authorizedQuantity' => '1000', 'firstDocumentNo' => '1', 'lastDocumentNo' => '1000'],
            ], 200),
            '*registarFactura' => Http::response(['requestID' => '202500000010689', 'errorList' => []], 200),
        ]);

        $this->empresa = Empresa::create(['nome_comercial' => 'Loja AGT', 'nif' => '5001636863']);
        $this->empresa->aderirServicos([Servico::Faturacao]);
        $this->cliente = Cliente::create(['empresa_id' => $this->empresa->id, 'nome' => 'Cliente Teste', 'nif' => null]);

        app(AgtSerieService::class)->solicitar($this->empresa, 'FT', now()->year);

        $this->admin = $this->criarUtilizador('admin@agt.ao', true);
        $this->operador = $this->criarUtilizador('operador@agt.ao', false);
    }

    protected function gerarChave(string $caminho): string
    {
        $recurso = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($recurso, $pem);
        file_put_contents($caminho, $pem);

        return $caminho;
    }

    protected function criarUtilizador(string $email, bool $administrador): User
    {
        $utilizador = User::create(['empresa_id' => $this->empresa->id, 'name' => $email, 'email' => $email, 'password' => 'Senha-Forte-123!']);

        if ($administrador) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($this->empresa->id);
            $utilizador->assignRole(Role::firstOrCreate(['name' => 'Administrador', 'guard_name' => 'web', 'empresa_id' => $this->empresa->id]));
        }

        return $utilizador;
    }

    protected function faturaEmitida(): Fatura
    {
        $faturas = app(FaturaService::class);

        return $faturas->emitir($faturas->criarRascunho($this->empresa, $this->cliente, [
            ['descricao' => 'Serviço Alfa', 'quantidade' => 1, 'preco_unitario' => '1000.00', 'taxa_iva' => 14],
        ]));
    }

    protected function definirEstado(Fatura $fatura, string $estado, array $erros = []): void
    {
        AgtSubmissao::query()->where('documento_id', $fatura->id)->firstOrFail()->update(['estado' => $estado, 'erros' => $erros]);
    }

    public function test_pendente_nao_e_confirmacao_fiscal(): void
    {
        $fatura = $this->faturaEmitida();

        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
            ->assertOk()
            ->assertSee('Estado na AGT')
            ->assertSee('Pendente de envio')
            ->assertSee('ainda não confirmou');
    }

    public function test_so_valido_e_confirmado_e_sem_botao_de_correcao(): void
    {
        $fatura = $this->faturaEmitida();
        $this->definirEstado($fatura, AgtSubmissao::VALIDO);

        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
            ->assertOk()
            ->assertSee('Estado na AGT')
            ->assertDontSee('ainda não confirmou')
            ->assertDontSee('Criar rascunho de correção');
    }

    public function test_invalida_mostra_os_erros_e_o_botao_de_correcao(): void
    {
        $fatura = $this->faturaEmitida();
        $this->definirEstado($fatura, AgtSubmissao::INVALIDO, [['codigo' => 'E21', 'descricao' => 'Valor incoerente na linha 1']]);

        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
            ->assertOk()
            ->assertSee('E21 Valor incoerente na linha 1')
            ->assertSee('Criar rascunho de correção');
    }

    public function test_corrigir_cria_o_rascunho_e_liga_os_dois_documentos(): void
    {
        $fatura = $this->faturaEmitida();
        $this->definirEstado($fatura, AgtSubmissao::REJEITADO, [['codigo' => 'E07', 'descricao' => 'Rejeitada']]);

        $resposta = $this->actingAs($this->admin)->post(route('faturacao.faturas.corrigir', $fatura->id));

        $rascunho = Fatura::withoutGlobalScopes()->whereNotNull('rejeitado_documento_no')->firstOrFail();
        $resposta->assertRedirect(route('faturacao.faturas.editar', $rascunho->id))->assertSessionHas('sucesso');
        $this->assertSame($fatura->numero_documento, $rascunho->rejeitado_documento_no);
        $this->assertSame('rascunho', $rascunho->estado);

        // O rascunho diz o que corrige; a fatura rejeitada passa a apontar para ele, sem novo botão.
        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $rascunho->id))
            ->assertOk()->assertSee('corrige o documento')->assertSee($fatura->numero_documento);

        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
            ->assertOk()->assertSee('Corrigida por')->assertSee('rascunho #'.$rascunho->id)->assertDontSee('Criar rascunho de correção');

        // Clicar outra vez não duplica nada.
        $this->actingAs($this->admin)->post(route('faturacao.faturas.corrigir', $fatura->id))->assertRedirect(route('faturacao.faturas.editar', $rascunho->id));
        $this->assertSame(2, Fatura::withoutGlobalScopes()->count());

        // Emitida, a correção ganha número novo e passa a ser a "Corrigida por" com o número.
        $this->actingAs($this->admin)->post(route('faturacao.faturas.emitir', $rascunho->id))->assertSessionHas('sucesso');
        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
            ->assertOk()->assertSee($rascunho->fresh()->numero_documento);
    }

    public function test_so_documentos_invalidos_ou_rejeitados_se_corrigem(): void
    {
        $fatura = $this->faturaEmitida();

        $this->actingAs($this->admin)->post(route('faturacao.faturas.corrigir', $fatura->id))->assertSessionHas('erro');
        $this->assertSame(1, Fatura::withoutGlobalScopes()->count());
    }

    public function test_so_o_administrador_corrige(): void
    {
        $fatura = $this->faturaEmitida();
        $this->definirEstado($fatura, AgtSubmissao::INVALIDO);

        $this->actingAs($this->operador)->post(route('faturacao.faturas.corrigir', $fatura->id))->assertForbidden();
        $this->assertSame(1, Fatura::withoutGlobalScopes()->count());
    }

    public function test_sem_comunicacao_agt_nao_ha_caixa_de_estado(): void
    {
        $fatura = $this->faturaEmitida();
        config(['faturacao.agt.ativo' => false]);

        $this->actingAs($this->admin)->get(route('faturacao.faturas.mostrar', $fatura->id))
            ->assertOk()->assertDontSee('Estado na AGT');
    }

    public function test_as_listas_mostram_o_estado_na_agt(): void
    {
        $fatura = $this->faturaEmitida();
        $this->definirEstado($fatura, AgtSubmissao::VALIDO);

        $this->actingAs($this->admin)->get(route('faturacao.faturas.index'))
            ->assertOk()->assertSee('AGT: Válido');
    }
}
