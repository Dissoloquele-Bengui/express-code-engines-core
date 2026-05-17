<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\OrchestrationEngine\Steps;

/**
 * Contract for a single orchestration step handler.
 *
 * Each step receives a subset of the accumulated flow payload,
 * executes its logic, and returns a StepResult.
 *
 * Returned data is merged into the flow payload and made
 * available to subsequent steps.
 *
 * Rules:
 *   - Step handlers must never throw — catch internally and return StepResult::failed()
 *   - Step handlers must be idempotent when idempotency_key_path is configured
 *   - Step handlers do NOT manage DB::transaction() — that belongs inside the handler's logic
 */
interface StepHandlerInterface
{
    /**
     * Execute the step.
     *
     * @param  array  $payload  Subset of the accumulated flow payload (via step input mapping)
     */
    public function execute(array $payload): StepResult;

    /**
     * Compensate this step's side-effects.
     * Called in reverse order when a subsequent step fails.
     *
     * @param  array  $payload  The payload at the time this step completed
     */
    public function compensate(array $payload): void;
}

/**
 * Result of a single step execution.
 */
final class StepResult
{
    private function __construct(
        public readonly bool    $success,

        /**
         * Data to merge into the flow payload for subsequent steps.
         * Keys here will be available to all later steps.
         */
        public readonly array   $data         = [],

        public readonly ?string $errorMessage = null,
        public readonly ?string $errorCode    = null,
    ) {}

    public static function ok(array $data = []): self
    {
        return new self(success: true, data: $data);
    }

    public static function failed(string $message, string $code = 'STEP_FAILED'): self
    {
        return new self(success: false, errorMessage: $message, errorCode: $code);
    }

    public function isFailed(): bool { return ! $this->success; }
}
