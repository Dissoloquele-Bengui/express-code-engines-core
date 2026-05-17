<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\OrchestrationEngine;

use Illuminate\Contracts\Container\Container;
use ExpressCodeEngines\Shared\Contracts\OrchestrationEngineInterface;
use ExpressCodeEngines\Shared\DTOs\OrchestrationFlow;
use ExpressCodeEngines\Shared\DTOs\OrchestrationStep;
use ExpressCodeEngines\Shared\ValueObjects\OrchestrationResult;

/**
 * Executes multi-step orchestration flows with automatic compensation.
 *
 * Execution model:
 *   1. Execute steps in order, accumulating payload
 *   2. On step failure: run compensations in REVERSE order (LIFO Saga)
 *   3. Return OrchestrationResult with full execution log
 *
 * Step handler contract:
 *   The handler class must expose:
 *     execute(array $input, array $fullPayload): array
 *   The returned array is merged into the accumulated payload.
 *
 * Compensation handler contract:
 *   The compensation class must expose:
 *     compensate(array $stepInput, array $accumulatedPayload): void
 *
 * This engine does NOT manage database transactions.
 * Individual steps are responsible for their own transactions.
 * The OrchestrationEngine coordinates flow logic only.
 */
final class OrchestrationEngine implements OrchestrationEngineInterface
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function run(OrchestrationFlow $flow, array $initialPayload = []): OrchestrationResult
    {
        $payload        = $initialPayload;
        $completed      = []; // [step => resolved input] for compensation
        $log            = [];
        $completedCount = 0;

        try { Log::info("[OrchestrationEngine] Starting flow '{$flow->key}'.", [
            'steps' => count($flow->steps),
        ]); } catch (\Throwable $__e) {}

        foreach ($flow->steps as $step) {
            $stepInput = $this->resolveInput($step, $payload);
            $startTime = microtime(true);
            $attempts  = 0;
            $maxTries  = $step->retries + 1;
            $succeeded = false;
            $lastError = null;

            while ($attempts < $maxTries) {
                $attempts++;

                try {
                    $handler = $this->container->make($step->handler);
                    $result  = $handler->execute($stepInput, $payload);

                    if (! is_array($result)) {
                        $result = [];
                    }

                    // Merge step output into accumulated payload
                    $payload = array_merge($payload, $result);
                    $succeeded = true;
                    break;

                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();

                    try { Log::warning("[OrchestrationEngine] Step '{$step->id}' failed (attempt {$attempts}/{$maxTries}).", [
                        'flow'  => $flow->key,
                        'error' => $lastError,
                    ]); } catch (\Throwable $__e) {}

                    if ($attempts < $maxTries) {
                        usleep(500_000 * $attempts); // 0.5s * attempt
                    }
                }
            }

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($succeeded) {
                $completed[]    = [$step, $stepInput];
                $completedCount++;

                $log[] = [
                    'step_id'     => $step->id,
                    'success'     => true,
                    'duration_ms' => $durationMs,
                    'attempts'    => $attempts,
                    'error'       => null,
                ];

                try { Log::info("[OrchestrationEngine] Step '{$step->id}' completed.", [
                    'flow'        => $flow->key,
                    'duration_ms' => $durationMs,
                ]); } catch (\Throwable $__e) {}

            } else {
                $log[] = [
                    'step_id'     => $step->id,
                    'success'     => false,
                    'duration_ms' => $durationMs,
                    'attempts'    => $attempts,
                    'error'       => $lastError,
                ];

                if ($step->continueOnFailure) {
                    try { Log::warning("[OrchestrationEngine] Step '{$step->id}' failed, continuing.", [
                        'flow' => $flow->key,
                    ]); } catch (\Throwable $__e) {}
                    continue;
                }

                // Compensate in reverse order
                $compensatedCount = $this->compensate($completed, $payload, $flow->key);

                try { Log::error("[OrchestrationEngine] Flow '{$flow->key}' failed at step '{$step->id}'.", [
                    'compensated' => $compensatedCount,
                    'error'       => $lastError,
                ]); } catch (\Throwable $__e) {}

                return OrchestrationResult::failed(
                    stepId:           $step->id,
                    message:          $lastError ?? 'Unknown error.',
                    payload:          $payload,
                    completedSteps:   $completedCount,
                    compensatedSteps: $compensatedCount,
                    log:              $log,
                );
            }
        }

        try { Log::info("[OrchestrationEngine] Flow '{$flow->key}' completed.", [
            'steps' => $completedCount,
        ]); } catch (\Throwable $__e) {}

        return OrchestrationResult::success(
            payload:        $payload,
            completedSteps: $completedCount,
            log:            $log,
        );
    }

    /**
     * Run compensations in LIFO order (reverse of execution).
     * Failures in compensations are logged but never re-thrown.
     */
    private function compensate(array $completed, array $payload, string $flowKey): int
    {
        $count   = 0;
        $reversed = array_reverse($completed);

        foreach ($reversed as [$step, $stepInput]) {
            /** @var OrchestrationStep $step */
            if ($step->compensation === null) {
                continue;
            }

            try {
                $compensator = $this->container->make($step->compensation);
                $compensator->compensate($stepInput, $payload);
                $count++;

                try { Log::info("[OrchestrationEngine] Compensated step '{$step->id}'.", ['flow' => $flowKey]); } catch (\Throwable $__e) {}

            } catch (\Throwable $e) {
                // Compensation failures are critical — log at error level
                // but never throw (that would mask the original failure)
                try { Log::error("[OrchestrationEngine] Compensation for '{$step->id}' failed.", [
                    'flow'  => $flowKey,
                    'error' => $e->getMessage(),
                ]); } catch (\Throwable $__e) {}
            }
        }

        return $count;
    }

    /**
     * Resolves step input from the accumulated payload using the input mapping.
     * If no input mapping defined, passes the full accumulated payload.
     */
    private function resolveInput(OrchestrationStep $step, array $payload): array
    {
        if (empty($step->input)) {
            return $payload;
        }

        $resolved = [];

        foreach ($step->input as $inputKey => $payloadPath) {
            $value = $this->resolvePath($payloadPath, $payload);
            $resolved[$inputKey] = $value;
        }

        return $resolved;
    }

    private function resolvePath(string $path, array $data): mixed
    {
        $segments = explode('.', $path);
        $value    = $data;

        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }
}
