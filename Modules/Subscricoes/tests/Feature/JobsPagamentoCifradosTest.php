<?php

namespace Modules\Subscricoes\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Modules\Subscricoes\Jobs\ProcessarPagamentoConfirmadoJob;
use Tests\TestCase;

/** Fase 2: o payload do gateway de pagamento não fica em claro na tabela de jobs. */
class JobsPagamentoCifradosTest extends TestCase
{
    public function test_job_de_pagamento_confirmado_cifra_o_payload_na_fila(): void
    {
        $job = new ProcessarPagamentoConfirmadoJob('proxypay', 'REF-1', ['amount' => '100.00'], '100.00');

        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
    }
}
