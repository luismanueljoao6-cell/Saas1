<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Empresa;
use Modules\Core\Models\User;
use Modules\Core\Services\DoisFatoresService;
use Tests\TestCase;

/**
 * Página de definições de segurança: ativar, confirmar, regenerar e desativar o 2FA.
 * O serviço em si está em DoisFatoresServicoTest; o desafio no login em DesafioDoisFatoresTest.
 */
class SegurancaContaDoisFatoresTest extends TestCase
{
    use RefreshDatabase;

    protected const SENHA = 'Senha-Forte-123!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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

    protected function criarUtilizador(string $nif = '570000001'): User
    {
        $empresa = Empresa::create(['nome_comercial' => 'Empresa '.$nif, 'nif' => $nif]);

        return User::create([
            'empresa_id' => $empresa->id,
            'name' => 'Utilizador Segurança',
            'email' => 'u'.$nif.'@teste.ao',
            'password' => self::SENHA,
        ]);
    }

    /** @return array{0: User, 1: string} utilizador e segredo */
    protected function comDoisFatoresAtivos(string $nif = '570000001'): array
    {
        $utilizador = $this->criarUtilizador($nif);
        $segredo = $this->servico()->prepararAtivacao($utilizador);

        $this->assertNotNull($this->servico()->confirmarAtivacao($utilizador, $this->codigoNoPasso($segredo)));

        return [$utilizador->fresh(), $segredo];
    }

    protected function codigoNoPasso(string $segredo, int $deslocamento = 0): string
    {
        return $this->servico()->codigo($segredo, $this->servico()->passoDe(now()->timestamp) + $deslocamento);
    }

    /** Um código de 6 dígitos que NÃO está na janela aceite (passos -1, 0 e +1). */
    protected function codigoErrado(string $segredo): string
    {
        $validos = array_map(fn (int $d) => $this->codigoNoPasso($segredo, $d), [-1, 0, 1]);

        foreach (['000000', '111111', '222222', '333333'] as $candidato) {
            if (! in_array($candidato, $validos, true)) {
                return $candidato;
            }
        }

        return '999999'; // inalcançável: no máximo 3 dos 4 candidatos acima são válidos
    }

    /** @return list<string> */
    protected function codigosNaPagina(string $html): array
    {
        preg_match_all('/\b[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}\b/', $html, $encontrados);

        return array_values(array_unique($encontrados[0]));
    }

    public function test_visitante_e_enviado_para_o_login(): void
    {
        $this->get(route('core.seguranca.index'))->assertRedirect(route('core.login'));

        $this->post(route('core.seguranca.2fa.iniciar'), ['password' => self::SENHA])
            ->assertRedirect(route('core.login'));
    }

    public function test_pagina_sem_2fa_oferece_ativar_e_nao_cria_segredo(): void
    {
        $utilizador = $this->criarUtilizador();

        $this->actingAs($utilizador)
            ->get(route('core.seguranca.index'))
            ->assertOk()
            ->assertSee('Ativar verificação em dois passos');

        $this->assertNull($utilizador->fresh()->two_factor_secret);
    }

    public function test_iniciar_exige_a_palavra_passe_certa(): void
    {
        $utilizador = $this->criarUtilizador();

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.2fa.iniciar'), ['password' => 'errada'])
            ->assertSessionHasErrors('password');

        $this->assertNull($utilizador->fresh()->two_factor_secret);
    }

    public function test_iniciar_cria_segredo_pendente_e_a_pagina_mostra_a_chave_e_o_uri(): void
    {
        $utilizador = $this->criarUtilizador();

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.2fa.iniciar'), ['password' => self::SENHA])
            ->assertRedirect(route('core.seguranca.index'));

        $segredo = $utilizador->fresh()->two_factor_secret;

        $this->assertNotNull($segredo);
        $this->assertFalse($utilizador->fresh()->temDoisFatoresAtivos());

        $this->get(route('core.seguranca.index'))
            ->assertOk()
            ->assertSee(trim(chunk_split($segredo, 4, ' ')))
            ->assertSee('otpauth://totp/', false)
            ->assertSee('Confirmar e ativar');
    }

    public function test_iniciar_repetido_mantem_o_mesmo_segredo(): void
    {
        $utilizador = $this->criarUtilizador();

        $this->actingAs($utilizador)->post(route('core.seguranca.2fa.iniciar'), ['password' => self::SENHA]);
        $primeiro = $utilizador->fresh()->two_factor_secret;

        $this->post(route('core.seguranca.2fa.iniciar'), ['password' => self::SENHA]);

        $this->assertSame($primeiro, $utilizador->fresh()->two_factor_secret);
    }

    public function test_iniciar_com_2fa_ja_ativo_devolve_erro_sem_mexer_no_segredo(): void
    {
        [$utilizador, $segredo] = $this->comDoisFatoresAtivos();

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.2fa.iniciar'), ['password' => self::SENHA])
            ->assertRedirect(route('core.seguranca.index'))
            ->assertSessionHas('erro');

        $this->assertSame($segredo, $utilizador->fresh()->two_factor_secret);
    }

    public function test_confirmar_com_codigo_errado_nao_ativa(): void
    {
        $utilizador = $this->criarUtilizador();
        $segredo = $this->servico()->prepararAtivacao($utilizador);

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.2fa.confirmar'), ['codigo' => $this->codigoErrado($segredo)])
            ->assertSessionHasErrors('codigo');

        $this->assertFalse($utilizador->fresh()->temDoisFatoresAtivos());
    }

    public function test_confirmar_sem_segredo_pendente_falha(): void
    {
        $utilizador = $this->criarUtilizador();

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.2fa.confirmar'), ['codigo' => '123456'])
            ->assertSessionHasErrors('codigo');

        $this->assertFalse($utilizador->fresh()->temDoisFatoresAtivos());
    }

    public function test_confirmar_com_codigo_certo_ativa_e_mostra_oito_codigos_so_uma_vez(): void
    {
        $utilizador = $this->criarUtilizador();
        $segredo = $this->servico()->prepararAtivacao($utilizador);

        $resposta = $this->actingAs($utilizador)
            ->post(route('core.seguranca.2fa.confirmar'), ['codigo' => $this->codigoNoPasso($segredo)]);

        $resposta->assertOk();
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));

        $codigos = $this->codigosNaPagina($resposta->getContent());
        $this->assertCount(8, $codigos);

        $guardados = $utilizador->fresh();
        $this->assertTrue($guardados->temDoisFatoresAtivos());
        $this->assertCount(8, $guardados->two_factor_recovery_codes);

        // Na BD ficam só os HMAC, nunca o texto que o utilizador viu.
        foreach ($codigos as $codigo) {
            $this->assertNotContains($codigo, $guardados->two_factor_recovery_codes);
        }

        // Voltar à página não os mostra outra vez.
        $this->get(route('core.seguranca.index'))
            ->assertOk()
            ->assertDontSee($codigos[0])
            ->assertSee('Ativa desde');
    }

    public function test_confirmar_limita_as_tentativas_mesmo_com_o_codigo_certo_depois(): void
    {
        $utilizador = $this->criarUtilizador();
        $segredo = $this->servico()->prepararAtivacao($utilizador);
        $errado = $this->codigoErrado($segredo);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($utilizador)
                ->post(route('core.seguranca.2fa.confirmar'), ['codigo' => $errado])
                ->assertSessionHasErrors('codigo');
        }

        $this->post(route('core.seguranca.2fa.confirmar'), ['codigo' => $this->codigoNoPasso($segredo)])
            ->assertSessionHasErrors('codigo');

        $this->assertFalse($utilizador->fresh()->temDoisFatoresAtivos());
    }

    public function test_o_codigo_de_outro_utilizador_nao_ativa_o_meu_2fa(): void
    {
        $a = $this->criarUtilizador('570000010');
        $b = $this->criarUtilizador('570000011');
        $segredoA = $this->servico()->prepararAtivacao($a);
        $this->servico()->prepararAtivacao($b);

        $this->actingAs($b)
            ->post(route('core.seguranca.2fa.confirmar'), ['codigo' => $this->codigoNoPasso($segredoA)])
            ->assertSessionHasErrors('codigo');

        $this->assertFalse($a->fresh()->temDoisFatoresAtivos());
        $this->assertFalse($b->fresh()->temDoisFatoresAtivos());
    }

    public function test_regenerar_exige_palavra_passe_e_invalida_os_codigos_antigos(): void
    {
        [$utilizador] = $this->comDoisFatoresAtivos();
        $antes = $utilizador->two_factor_recovery_codes;

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.2fa.codigos'), ['password' => 'errada'])
            ->assertSessionHasErrors('password');

        $this->assertSame($antes, $utilizador->fresh()->two_factor_recovery_codes);

        $resposta = $this->post(route('core.seguranca.2fa.codigos'), ['password' => self::SENHA]);

        $resposta->assertOk();
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        $this->assertCount(8, $this->codigosNaPagina($resposta->getContent()));

        $depois = $utilizador->fresh()->two_factor_recovery_codes;
        $this->assertCount(8, $depois);
        $this->assertNotSame($antes, $depois);
        $this->assertSame([], array_intersect($antes, $depois));
    }

    public function test_regenerar_sem_2fa_ativo_e_recusado(): void
    {
        $utilizador = $this->criarUtilizador();

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.2fa.codigos'), ['password' => self::SENHA])
            ->assertRedirect(route('core.seguranca.index'))
            ->assertSessionHas('erro');
    }

    public function test_desativar_exige_palavra_passe_e_limpa_tudo(): void
    {
        [$utilizador] = $this->comDoisFatoresAtivos();

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.2fa.desativar'), ['password' => 'errada'])
            ->assertSessionHasErrors('password');

        $this->assertTrue($utilizador->fresh()->temDoisFatoresAtivos());

        $this->post(route('core.seguranca.2fa.desativar'), ['password' => self::SENHA])
            ->assertRedirect(route('core.seguranca.index'))
            ->assertSessionHas('sucesso');

        $guardado = $utilizador->fresh();
        $this->assertFalse($guardado->temDoisFatoresAtivos());
        $this->assertNull($guardado->two_factor_secret);
        $this->assertNull($guardado->two_factor_recovery_codes);
    }

    public function test_cancelar_uma_ativacao_pendente_apaga_o_segredo(): void
    {
        $utilizador = $this->criarUtilizador();
        $this->servico()->prepararAtivacao($utilizador);

        $this->actingAs($utilizador)
            ->post(route('core.seguranca.2fa.desativar'), ['password' => self::SENHA])
            ->assertRedirect(route('core.seguranca.index'));

        $this->assertNull($utilizador->fresh()->two_factor_secret);
    }

    public function test_a_pagina_nao_e_guardada_em_cache(): void
    {
        $utilizador = $this->criarUtilizador();

        $resposta = $this->actingAs($utilizador)->get(route('core.seguranca.index'));

        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
    }
}
