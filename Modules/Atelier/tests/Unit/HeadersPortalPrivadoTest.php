<?php

namespace Modules\Atelier\Tests\Unit;

use Illuminate\Http\Request;
use Modules\Atelier\Http\Middleware\HeadersPortalPrivado;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class HeadersPortalPrivadoTest extends TestCase
{
    public function test_aplica_cabecalhos_de_privacidade_e_protecao_ao_portal(): void
    {
        $request = Request::create('/portal/atelier/123?signature=segredo');
        $middleware = new HeadersPortalPrivado;

        $response = $middleware->handle(
            $request,
            fn () => new Response('portal'),
        );

        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('private, no-store, max-age=0', $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }
}
