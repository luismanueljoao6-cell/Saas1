<?php

namespace Modules\Core\Tests\Feature;

use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Faturacao\Jobs\GerarSafTJob;
use ReflectionClass;
use Tests\TestCase;

/**
 * Se o retry_after for menor que o timeout de um job, outro worker pega no mesmo
 * job enquanto ainda corre (envio duplicado). Ver DB_QUEUE_RETRY_AFTER no .env.
 */
class QueueRetryAfterTest extends TestCase
{
    public function test_retry_after_da_fila_de_base_de_dados_excede_o_timeout_dos_jobs_longos(): void
    {
        $classes = [DispararCampanhaJob::class];

        if (class_exists(GerarSafTJob::class)) {
            $classes[] = GerarSafTJob::class;
        }

        $retryAfter = (int) config('queue.connections.database.retry_after');

        foreach ($classes as $classe) {
            $timeout = (int) (new ReflectionClass($classe))->getDefaultProperties()['timeout'];

            $this->assertGreaterThan($timeout, $retryAfter, "retry_after ({$retryAfter}) tem de exceder o timeout de {$classe} ({$timeout}).");
        }
    }
}
