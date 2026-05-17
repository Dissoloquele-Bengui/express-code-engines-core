<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

final class ComputationResult
{
    private function __construct(
        public readonly bool   $success,
        public readonly mixed  $value,
        public readonly ?string $errorCode    = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function ok(mixed $value): self
    {
        return new self(success: true, value: $value);
    }

    public static function error(string $code, string $message): self
    {
        return new self(success: false, value: null, errorCode: $code, errorMessage: $message);
    }

    public function isError(): bool
    {
        return ! $this->success;
    }
}
