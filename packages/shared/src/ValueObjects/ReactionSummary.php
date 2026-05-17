<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

/**
 * Summary of all handler dispatches for a single react() call.
 *
 * Always returned even if some handlers failed.
 * The engine never throws — failures are recorded here.
 */
final class ReactionSummary
{
    public function __construct(
        /** Total number of definitions that matched the event */
        public readonly int   $matched,

        /** Number of handlers dispatched (sync or queued) */
        public readonly int   $dispatched,

        /** Number of sync handlers that failed (async failures are not tracked here) */
        public readonly int   $failed,

        /**
         * Per-handler results (sync only — async results are not available).
         * @var array<array{handler: string, success: bool, error: ?string}>
         */
        public readonly array $results = [],
    ) {}

    public static function empty(): self
    {
        return new self(matched: 0, dispatched: 0, failed: 0);
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    public function allSucceeded(): bool
    {
        return $this->failed === 0 && $this->dispatched > 0;
    }
}
