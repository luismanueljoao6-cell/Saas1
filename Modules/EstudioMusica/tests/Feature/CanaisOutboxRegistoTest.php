<?php

namespace Modules\EstudioMusica\Tests\Feature;

use Modules\Core\Notifications\NotificationChannelRegistry;
use Modules\EstudioMusica\Providers\EstudioMusicaServiceProvider;
use RuntimeException;
use Tests\TestCase;

class CanaisOutboxRegistoTest extends TestCase
{
    public function test_canal_mal_configurado_nao_impede_o_arranque_e_so_falha_quando_usado(): void
    {
        $registry = new NotificationChannelRegistry;
        $this->app->instance(NotificationChannelRegistry::class, $registry);
        config(['estudiomusica.canal_whatsapp' => 'canal-que-nao-existe']);

        // Equivale ao que o boot() do provider faz: não pode lançar exceção.
        (fn () => $this->registarCanaisOutbox())->call(new EstudioMusicaServiceProvider($this->app));

        $this->assertTrue($registry->has('estudio.whatsapp'));
        $this->assertTrue($registry->has('estudio.sms'));
        $this->assertTrue($registry->has('estudio.email'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canal-que-nao-existe');

        $registry->get('estudio.whatsapp');
    }
}
