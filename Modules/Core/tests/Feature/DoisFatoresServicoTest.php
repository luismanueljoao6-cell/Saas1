<?php

namespace Modules\Core\Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\DoisFatoresService;
use Tests\TestCase;

class DoisFatoresServicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 12:00:10');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function servico(): DoisFatoresService
    {
        return app(DoisFatoresService::class);
    }

    protected function utilizador(string $email = 'u@teste.ao'): User
    {
        $empresa = Empresa::firstOrCreate(['nif' => '540000001'], ['nome_comercial' => 'Empresa 540000001']);

        return User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Teste',
            'email' => $email,
            'password' => 'Senha-Forte-123!',
        ]);
    }

    protected function codigoNoPasso(string $segredo, int $deslocamento = 0): string
    {
        return $this->servico()->codigo($segredo, $this->servico()->passoDe(now()->timestamp) + $deslocamento);
    }

    /** @return array{0: string, 1: list<string>} segredo e códigos de recuperação */
    protected function ativar(User $utilizador): array
    {
        $segredo = $this->servico()->prepararAtivacao($utilizador);
        $codigos = $this->servico()->confirmarAtivacao($utilizador, $this->codigoNoPasso($segredo));

        $this->assertNotNull($codigos);

        return [$segredo, $codigos];
    }

    protected function bruto(User $utilizador, string $coluna): ?string
    {
        return DB::table('users')->where('id', $utilizador->id)->value($coluna);
    }

    public function test_o_segredo_pendente_fica_cifrado_e_e_reutilizado(): void
    {
        $utilizador = $this->utilizador();

        $primeiro = $this->servico()->prepararAtivacao($utilizador);
        $segundo = $this->servico()->prepararAtivacao($utilizador->fresh());

        $this->assertSame($primeiro, $segundo);

        $bruto = $this->bruto($utilizador, 'two_factor_secret');
        $this->assertNotSame($primeiro, $bruto);
        $this->assertSame($primeiro, Crypt::decryptString($bruto));
        $this->assertFalse($utilizador->fresh()->temDoisFatoresAtivos());
    }

    public function test_segredo_pendente_nao_serve_para_entrar(): void
    {
        $utilizador = $this->utilizador();
        $segredo = $this->servico()->prepararAtivacao($utilizador);

        $this->assertFalse($this->servico()->validarCodigo($utilizador, $this->codigoNoPasso($segredo)));
    }

    public function test_confirmar_com_codigo_errado_ou_fora_da_janela_nao_ativa(): void
    {
        $utilizador = $this->utilizador();
        $segredo = $this->servico()->prepararAtivacao($utilizador);

        $this->assertNull($this->servico()->confirmarAtivacao($utilizador, '000000'));
        $this->assertNull($this->servico()->confirmarAtivacao($utilizador, $this->codigoNoPasso($segredo, -2)));
        $this->assertNull($this->servico()->confirmarAtivacao($utilizador, $this->codigoNoPasso($segredo, 2)));
        $this->assertFalse($utilizador->fresh()->temDoisFatoresAtivos());
    }

    public function test_confirmar_ativa_e_devolve_codigos_guardados_so_como_hash(): void
    {
        $utilizador = $this->utilizador();

        [, $codigos] = $this->ativar($utilizador);

        $this->assertCount(8, $codigos);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $codigos[0]);
        $this->assertTrue($utilizador->fresh()->temDoisFatoresAtivos());
        $this->assertSame(8, $this->servico()->codigosRestantes($utilizador->fresh()));

        $guardado = Crypt::decryptString($this->bruto($utilizador, 'two_factor_recovery_codes'));

        foreach ($codigos as $codigo) {
            $this->assertStringNotContainsString($codigo, $guardado);
            $this->assertStringNotContainsString(str_replace('-', '', $codigo), $guardado);
        }
    }

    public function test_um_codigo_so_pode_ser_usado_uma_vez(): void
    {
        $utilizador = $this->utilizador();
        [$segredo] = $this->ativar($utilizador); // gasta o código do passo atual

        $this->assertFalse($this->servico()->validarCodigo($utilizador, $this->codigoNoPasso($segredo)));
        $this->assertTrue($this->servico()->validarCodigo($utilizador, $this->codigoNoPasso($segredo, 1)));
        $this->assertFalse($this->servico()->validarCodigo($utilizador, $this->codigoNoPasso($segredo, 1)));
        $this->assertFalse($this->servico()->validarCodigo($utilizador, $this->codigoNoPasso($segredo, 0)));
    }

    public function test_janela_de_tolerancia_de_um_intervalo(): void
    {
        $utilizador = $this->utilizador();
        $segredo = $this->servico()->prepararAtivacao($utilizador);
        $this->servico()->confirmarAtivacao($utilizador, $this->codigoNoPasso($segredo, -1));

        $utilizador = $utilizador->fresh();
        $this->assertTrue($utilizador->temDoisFatoresAtivos());
        $this->assertFalse($this->servico()->validarCodigo($utilizador, $this->codigoNoPasso($segredo, -2)));
        $this->assertTrue($this->servico()->validarCodigo($utilizador, $this->codigoNoPasso($segredo, 1)));
        $this->assertFalse($this->servico()->validarCodigo($utilizador, $this->codigoNoPasso($segredo, 2)));
    }

    public function test_codigo_de_recuperacao_so_serve_uma_vez_e_ignora_formato(): void
    {
        $utilizador = $this->utilizador();
        [, $codigos] = $this->ativar($utilizador);

        $this->assertTrue($this->servico()->usarCodigoRecuperacao($utilizador, strtolower(str_replace('-', '', $codigos[0]))));
        $this->assertFalse($this->servico()->usarCodigoRecuperacao($utilizador, $codigos[0]));
        $this->assertFalse($this->servico()->usarCodigoRecuperacao($utilizador, 'AAAAA-AAAAA'));
        $this->assertFalse($this->servico()->usarCodigoRecuperacao($utilizador, ''));
        $this->assertSame(7, $this->servico()->codigosRestantes($utilizador->fresh()));
    }

    public function test_regenerar_invalida_os_codigos_antigos(): void
    {
        $utilizador = $this->utilizador();
        [, $antigos] = $this->ativar($utilizador);

        $novos = $this->servico()->regenerarCodigosRecuperacao($utilizador);

        $this->assertCount(8, $novos);
        $this->assertFalse($this->servico()->usarCodigoRecuperacao($utilizador, $antigos[0]));
        $this->assertTrue($this->servico()->usarCodigoRecuperacao($utilizador, $novos[0]));
    }

    public function test_regenerar_sem_2fa_ativo_devolve_null(): void
    {
        $this->assertNull($this->servico()->regenerarCodigosRecuperacao($this->utilizador()));
    }

    public function test_desativar_limpa_tudo(): void
    {
        $utilizador = $this->utilizador();
        [$segredo, $codigos] = $this->ativar($utilizador);

        $this->servico()->desativar($utilizador);

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_ultimo_passo'] as $coluna) {
            $this->assertNull($this->bruto($utilizador, $coluna), $coluna);
        }

        $this->assertFalse($this->servico()->validarCodigo($utilizador, $this->codigoNoPasso($segredo, 1)));
        $this->assertFalse($this->servico()->usarCodigoRecuperacao($utilizador, $codigos[0]));
    }

    public function test_ativar_duas_vezes_e_recusado(): void
    {
        $utilizador = $this->utilizador();
        $this->ativar($utilizador);

        $this->expectException(DomainException::class);
        $this->servico()->prepararAtivacao($utilizador->fresh());
    }

    public function test_uri_otpauth_tem_emissor_segredo_e_parametros(): void
    {
        config(['app.name' => 'Saas1']);

        $uri = $this->servico()->uriOtpauth('u@teste.ao', 'JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('otpauth://totp/Saas1:u%40teste.ao?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Saas1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
