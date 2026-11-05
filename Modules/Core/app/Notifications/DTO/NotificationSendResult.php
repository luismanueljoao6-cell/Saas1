<?php

namespace Modules\Core\Notifications\DTO;

final readonly class NotificationSendResult
{
    public function __construct(
        public bool $accepted,
        public ?string $provider = null,
        public ?string $providerMessageId = null,
        public ?int $responseCode = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}

    public static function accepted(
        string $provider,
        ?string $providerMessageId = null,
        ?int $responseCode = null,
        array $metadata = [],
    ): self {
        return new self(true, $provider, $providerMessageId, $responseCode, null, null, $metadata);
    }

    public static function rejected(
        string $provider,
        string $errorMessage,
        ?string $errorCode = null,
        ?int $responseCode = null,
        array $metadata = [],
    ): self {
        return new self(false, $provider, null, $responseCode, $errorCode, $errorMessage, $metadata);
    }
}
