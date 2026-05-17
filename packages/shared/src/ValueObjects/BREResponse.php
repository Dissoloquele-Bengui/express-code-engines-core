<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

final class BREResponse
{
    public function __construct(
        /** @var array<array{rule_id: string, message: string}> */
        public readonly array $denials = [],

        /** @var array<array{rule_id: string, message: string}> */
        public readonly array $warnings = [],

        /**
         * Effect keys approved for dispatch after DB::commit().
         * The Action decides HOW to dispatch them.
         * @var string[]
         */
        public readonly array $approvedEffects = [],
    ) {}

    public function passed(): bool
    {
        return empty($this->denials);
    }

    public function hasDenials(): bool
    {
        return ! empty($this->denials);
    }

    public function hasWarnings(): bool
    {
        return ! empty($this->warnings);
    }

    public function firstDenialMessage(): ?string
    {
        return $this->denials[0]['message'] ?? null;
    }
}
