<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\ReactionContext;
use ExpressCodeEngines\Shared\DTOs\ReactionDefinition;
use ExpressCodeEngines\Shared\DTOs\NotificationRequest;
use ExpressCodeEngines\Shared\ValueObjects\ReactionSummary;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

/**
 * Result of a single handler execution.
 */
final class HandlerResult
{
    private function __construct(
        public readonly bool    $success,
        public readonly ?string $errorMessage = null,
        public readonly array   $data         = [],
    ) {}

    public static function ok(array $data = []): self
    {
        return new self(success: true, data: $data);
    }

    public static function failed(string $message): self
    {
        return new self(success: false, errorMessage: $message);
    }

    public function isFailed(): bool
    {
        return ! $this->success;
    }
}
