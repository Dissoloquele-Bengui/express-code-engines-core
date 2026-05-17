<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

final class ValidationResult
{
    private function __construct(
        public readonly bool $passed,

        /** @var array<array{type: string, message: string, field?: string}> */
        public readonly array $violations = [],
    ) {}

    public static function pass(): self
    {
        return new self(passed: true);
    }

    public static function fail(array $violations): self
    {
        return new self(passed: false, violations: $violations);
    }

    public function firstMessage(): ?string
    {
        return $this->violations[0]['message'] ?? null;
    }
}
