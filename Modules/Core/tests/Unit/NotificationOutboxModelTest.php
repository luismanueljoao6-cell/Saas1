<?php

namespace Modules\Core\Tests\Unit;

use Modules\Core\Models\NotificationOutbox;
use Tests\TestCase;

class NotificationOutboxModelTest extends TestCase
{
    public function test_recipient_and_payload_are_encrypted_at_rest_by_model_casts(): void
    {
        $notification = new NotificationOutbox;
        $notification->recipient = '+244923000000';
        $notification->payload = [
            'subject' => 'Lembrete',
            'body' => 'A sessão começa amanhã.',
        ];

        $this->assertSame('+244923000000', $notification->recipient);
        $this->assertSame('Lembrete', $notification->payload['subject']);
        $this->assertSame('A sessão começa amanhã.', $notification->payload['body']);

        $attributes = $notification->getAttributes();

        $this->assertNotSame('+244923000000', $attributes['recipient']);
        $this->assertStringNotContainsString('A sessão começa amanhã.', $attributes['payload']);
    }

    public function test_terminal_states_are_classified_consistently(): void
    {
        $notification = new NotificationOutbox;

        foreach ([
            NotificationOutbox::STATUS_DELIVERED,
            NotificationOutbox::STATUS_READ,
            NotificationOutbox::STATUS_FAILED,
            NotificationOutbox::STATUS_DEAD_LETTER,
            NotificationOutbox::STATUS_CANCELLED,
        ] as $status) {
            $notification->status = $status;
            $this->assertTrue($notification->isTerminal());
        }

        $notification->status = NotificationOutbox::STATUS_ACCEPTED;

        $this->assertFalse($notification->isTerminal());
    }
}
