<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Tests\TestCase;

class AliasesMiddlewareTest extends TestCase
{
    public function test_aliases_do_spatie_usados_nas_rotas_estao_registados(): void
    {
        $aliases = app('router')->getMiddleware();

        foreach (['permission', 'role', 'role_or_permission', 'tenant', 'subscricao.ativa', 'portal.cliente'] as $alias) {
            $this->assertArrayHasKey($alias, $aliases, "Alias de middleware em falta: {$alias}");
        }
    }
}
