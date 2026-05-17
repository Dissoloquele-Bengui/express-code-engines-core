<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

/**
 * Result of a single notification delivery attempt.
 *
 * One NotificationRequest can produce multiple NotificationResults
 * (one per channel).
 */
final class NotificationResult
{
    private function __construct(
        public readonly bool    $success,
        public readonly string  $channel,
        public readonly ?string $messageId    = null,
        public readonly ?string $errorCode    = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function sent(string $channel, ?string $messageId = null): self
    {
        return new self(success: true, channel: $channel, messageId: $messageId);
    }

    public static function failed(string $channel, string $code, string $message): self
    {
        return new self(
            success:      false,
            channel:      $channel,
            errorCode:    $code,
            errorMessage: $message,
        );
    }

    public function isFailed(): bool
    {
        return ! $this->success;
    }
}
