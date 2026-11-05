<?php

namespace Modules\Core\Tests\Unit;

use InvalidArgumentException;
use Modules\Core\Models\NotificationOutbox;
use Modules\Core\Notifications\Contracts\NotificationChannel;
use Modules\Core\Notifications\DTO\NotificationSendResult;
use Modules\Core\Notifications\NotificationChannelRegistry;
use PHPUnit\Framework\TestCase;

class NotificationChannelRegistryTest extends TestCase
{
    public function test_registered_channel_can_be_resolved_by_identifier(): void
    {
        $channel = new class implements NotificationChannel
        {
            public function identifier(): string
            {
                return 'fake';
            }

            public function send(NotificationOutbox $notification): NotificationSendResult
            {
                return NotificationSendResult::accepted('fake', 'message-123', 202);
            }
        };

        $registry = new NotificationChannelRegistry;
        $registry->register($channel);

        $this->assertTrue($registry->has('fake'));
        $this->assertSame($channel, $registry->get('fake'));
    }

    public function test_unknown_channel_is_not_silently_resolved(): void
    {
        $registry = new NotificationChannelRegistry;

        $this->assertFalse($registry->has('missing'));
        $this->expectException(InvalidArgumentException::class);

        $registry->get('missing');
    }

    public function test_rejected_result_preserves_provider_error_information(): void
    {
        $result = NotificationSendResult::rejected(
            'fake',
            'Limite temporário do fornecedor.',
            'rate_limited',
            429,
            ['retry_after' => 30],
        );

        $this->assertFalse($result->accepted);
        $this->assertSame('fake', $result->provider);
        $this->assertSame('rate_limited', $result->errorCode);
        $this->assertSame(429, $result->responseCode);
        $this->assertSame(['retry_after' => 30], $result->metadata);
    }
}
