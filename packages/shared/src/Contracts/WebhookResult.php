<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\IntegrationEngine\Webhooks;

use Illuminate\Http\Request;

/**
 * Result of processing an inbound webhook.
 */
final class WebhookResult
{
    private function __construct(
        public readonly string  $status,    // 'accepted', 'rejected', 'failed'
        public readonly ?string $eventType = null,
        public readonly bool    $handled   = false,
        public readonly ?string $reason    = null,
    ) {}

    public static function accepted(string $eventType, bool $handled): self
    {
        return new self(status: 'accepted', eventType: $eventType, handled: $handled);
    }

    public static function rejected(string $reason): self
    {
        return new self(status: 'rejected', reason: $reason);
    }

    public static function failed(string $eventType, string $reason): self
    {
        return new self(status: 'failed', eventType: $eventType, reason: $reason);
    }

    public function wasAccepted(): bool { return $this->status === 'accepted'; }

    public function toHttpStatus(): int
    {
        return match ($this->status) {
            'accepted' => 200,
            'rejected' => 401,
            'failed'   => 500,
            default    => 200,
        };
    }
}
