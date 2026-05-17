<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

use ExpressCodeEngines\Shared\DTOs\IntegrationRequest;
use ExpressCodeEngines\Shared\ValueObjects\IntegrationResult;

/**
 * Result of a single integration call attempt.
 */
final class IntegrationResult
{
    public function __construct(
        public readonly bool    $success,
        public readonly int     $statusCode,
        public readonly array   $response     = [],
        public readonly ?string $errorCode    = null,
        public readonly ?string $errorMessage = null,
        public readonly int     $attempts     = 1,
        public readonly int     $durationMs   = 0,
    ) {}

    public static function ok(int $statusCode, array $response, int $attempts = 1, int $durationMs = 0): self
    {
        return new self(
            success:    true,
            statusCode: $statusCode,
            response:   $response,
            attempts:   $attempts,
            durationMs: $durationMs,
        );
    }

    public static function failed(
        int     $statusCode,
        string  $code,
        string  $message,
        int     $attempts   = 1,
        int     $durationMs = 0,
    ): self {
        return new self(
            success:      false,
            statusCode:   $statusCode,
            errorCode:    $code,
            errorMessage: $message,
            attempts:     $attempts,
            durationMs:   $durationMs,
        );
    }

    public function isFailed(): bool { return ! $this->success; }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }
}
