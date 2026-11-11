<?php

namespace Modules\Core\Tests\Unit;

use Modules\Core\Notifications\NotificationRetryPolicy;
use PHPUnit\Framework\TestCase;

class NotificationRetryPolicyTest extends TestCase
{
    public function test_classifica_cada_situacao_da_tabela_de_tratamento(): void
    {
        $this->assertSame(NotificationRetryPolicy::BLOCKED, NotificationRetryPolicy::classify('invalid_credentials'));
        $this->assertSame(NotificationRetryPolicy::BLOCKED, NotificationRetryPolicy::classify(null, 401));
        $this->assertSame(NotificationRetryPolicy::BLOCKED, NotificationRetryPolicy::classify(null, 403));
        $this->assertSame(NotificationRetryPolicy::BLOCKED, NotificationRetryPolicy::classify('apd_transfer_not_authorized'));
        $this->assertSame(NotificationRetryPolicy::PERMANENT, NotificationRetryPolicy::classify('invalid_recipient'));
        $this->assertSame(NotificationRetryPolicy::PERMANENT, NotificationRetryPolicy::classify('invalid_payload'));
        $this->assertSame(NotificationRetryPolicy::PERMANENT, NotificationRetryPolicy::classify(null, 422));
        $this->assertSame(NotificationRetryPolicy::RATE_LIMITED, NotificationRetryPolicy::classify('rate_limited'));
        $this->assertSame(NotificationRetryPolicy::RATE_LIMITED, NotificationRetryPolicy::classify(null, 429));
        $this->assertSame(NotificationRetryPolicy::RETRY, NotificationRetryPolicy::classify(null, 503));
        $this->assertSame(NotificationRetryPolicy::RETRY, NotificationRetryPolicy::classify('channel_rejected'));
        $this->assertSame(NotificationRetryPolicy::RETRY, NotificationRetryPolicy::classify(null));
    }

    public function test_o_codigo_de_erro_tem_prioridade_sobre_o_estado_http(): void
    {
        $this->assertSame(NotificationRetryPolicy::PERMANENT, NotificationRetryPolicy::classify('invalid_recipient', 503));
    }

    public function test_so_permanentes_e_bloqueios_ficam_sem_retry(): void
    {
        $this->assertTrue(NotificationRetryPolicy::semRetry(NotificationRetryPolicy::PERMANENT));
        $this->assertTrue(NotificationRetryPolicy::semRetry(NotificationRetryPolicy::BLOCKED));
        $this->assertFalse(NotificationRetryPolicy::semRetry(NotificationRetryPolicy::RETRY));
        $this->assertFalse(NotificationRetryPolicy::semRetry(NotificationRetryPolicy::RATE_LIMITED));
    }

    public function test_backoff_exponencial_com_limite_e_espera_minima_no_limite_do_fornecedor(): void
    {
        $this->assertSame(30, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RETRY, 1));
        $this->assertSame(120, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RETRY, 3));
        $this->assertSame(3600, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RETRY, 20));

        $this->assertSame(60, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RATE_LIMITED, 1));
        $this->assertSame(300, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RATE_LIMITED, 1, ['retry_after' => 300]));
        $this->assertSame(3600, NotificationRetryPolicy::atrasoSegundos(NotificationRetryPolicy::RATE_LIMITED, 1, ['retry_after' => 99999]));
    }
}
