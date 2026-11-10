<?php

namespace Modules\Atelier\Tests\Feature;

use Modules\Core\Notifications\NotificationChannelRegistry;
use Tests\TestCase;

class CanaisOutboxRegistoTest extends TestCase
{
    public function test_canais_do_atelier_ficam_registados_no_arranque(): void
    {
        $registry = app(NotificationChannelRegistry::class);

        $this->assertTrue($registry->has('atelier.whatsapp'));
        $this->assertTrue($registry->has('atelier.sms'));
    }
}
