<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

final class OrchestrationResult
{
    public function __construct(
        public readonly bool   $success,

        /**
         * The accumulated payload after all successful steps.
         * Each step's returned array is merged into this.
         */
        public readonly array  $payload,

        /** Number of steps that completed successfully */
        public readonly int    $completedSteps,

        /** Number of compensation steps that ran (on failure) */
        public readonly int    $compensatedSteps,

        /**
         * Per-step execution log.
         * @var array<array{step_id: string, success: bool, duration_ms: int, error: ?string}>
         */
        public readonly array  $log = [],

        /** The step ID where failure occurred (null if all succeeded) */
        public readonly ?string $failedAt      = null,
        public readonly ?string $errorMessage  = null,
    ) {}

    public static function success(array $payload, int $completedSteps, array $log): self
    {
        return new self(
            success:          true,
            payload:          $payload,
            completedSteps:   $completedSteps,
            compensatedSteps: 0,
            log:              $log,
        );
    }

    public static function failed(
        string $stepId,
        string $message,
        array  $payload,
        int    $completedSteps,
        int    $compensatedSteps,
        array  $log,
    ): self {
        return new self(
            success:          false,
            payload:          $payload,
            completedSteps:   $completedSteps,
            compensatedSteps: $compensatedSteps,
            log:              $log,
            failedAt:         $stepId,
            errorMessage:     $message,
        );
    }

    public function isFailed(): bool { return ! $this->success; }
}
