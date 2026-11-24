<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CabecalhosDeSegurancaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_as_paginas_web_levam_os_cabecalhos_basicos(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_so_aparece_sobre_https(): void
    {
        $this->get('https://localhost/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_nao_substitui_um_cabecalho_que_a_resposta_ja_tem(): void
    {
        Route::middleware('web')->get('/_teste/referrer', fn () => response('ok', 200, [
            'Referrer-Policy' => 'no-referrer',
        ]));

        $this->get('/_teste/referrer')
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_pode_ser_desligado_por_configuracao(): void
    {
        config(['core.seguranca.cabecalhos' => false]);

        $this->get('/login')
            ->assertOk()
            ->assertHeaderMissing('X-Frame-Options')
            ->assertHeaderMissing('X-Content-Type-Options');
    }
}
