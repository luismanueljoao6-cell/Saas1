<?php

namespace Tests\Unit\Faturacao;

use Modules\Faturacao\Services\AbatimentoAdiantamentoService;
use Tests\TestCase;

class AbatimentoAdiantamentoValidacaoTest extends TestCase
{
    private function servico(): AbatimentoAdiantamentoService
    {
        return $this->app->make(AbatimentoAdiantamentoService::class);
    }

    public function test_recusa_valor_nao_positivo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->servico()->abater(1, 10, 11, '0.00', 'chave-12345');
    }

    public function test_recusa_chave_de_idempotencia_invalida(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->servico()->abater(1, 10, 11, '100.00', 'x');
    }

    public function test_recusa_abater_o_documento_nele_proprio(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->servico()->abater(1, 10, 10, '100.00', 'chave-12345');
    }
}
