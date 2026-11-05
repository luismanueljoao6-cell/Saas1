<?php

namespace Modules\Faturacao\Tests\Feature;

use Modules\Faturacao\Contracts\FaturacaoGateway;
use Modules\Faturacao\Services\FaturacaoGatewayService;
use Tests\TestCase;

class FaturacaoGatewayTest extends TestCase
{
    public function test_o_contrato_publico_e_resolvido_pelo_contentor_laravel(): void
    {
        $this->assertInstanceOf(FaturacaoGatewayService::class, app(FaturacaoGateway::class));
    }
}
