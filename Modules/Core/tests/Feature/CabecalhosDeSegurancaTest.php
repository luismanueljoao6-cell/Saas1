<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Core\Http\Middleware\CabecalhosDeSeguranca;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CabecalhosDeSegurancaTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginas_web_levam_os_cabecalhos_basicos(): void
    {
        $this->withoutVite();

        $this->get(route('core.login'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_respostas_de_erro_tambem_levam_os_cabecalhos(): void
    {
        // 401 da API: o middleware auth responde antes dos middlewares de grupo.
        $this->getJson('/api/core/empresa')
            ->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $this->get('/rota-que-nao-existe')
            ->assertNotFound()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_nao_substitui_cabecalhos_definidos_pela_rota(): void
    {
        $resposta = (new CabecalhosDeSeguranca)->handle(
            Request::create('/portal/x'),
            fn () => new Response('ok', 200, ['Referrer-Policy' => 'no-referrer']),
        );

        $this->assertSame('no-referrer', $resposta->headers->get('Referrer-Policy'));
        $this->assertSame('SAMEORIGIN', $resposta->headers->get('X-Frame-Options'));
    }

    public function test_hsts_so_em_producao_e_so_sobre_https(): void
    {
        $middleware = new CabecalhosDeSeguranca;
        $seguir = fn () => new Response('ok');
        $https = Request::create('https://exemplo.test/painel');

        $this->assertFalse($middleware->handle($https, $seguir)->headers->has('Strict-Transport-Security'));

        $this->app['env'] = 'production';

        $this->assertSame('max-age=31536000', $middleware->handle($https, $seguir)->headers->get('Strict-Transport-Security'));
        $this->assertFalse($middleware->handle(Request::create('http://exemplo.test/painel'), $seguir)->headers->has('Strict-Transport-Security'));
    }
}
