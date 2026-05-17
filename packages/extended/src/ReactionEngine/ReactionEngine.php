<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ReactionEngine;

use ExpressCodeEngines\Shared\Contracts\ReactionEngineInterface;
use ExpressCodeEngines\Shared\DTOs\ReactionContext;
use ExpressCodeEngines\Shared\DTOs\ReactionDefinition;
use ExpressCodeEngines\Shared\ValueObjects\ReactionSummary;
use ExpressCodeEngines\Extended\ReactionEngine\Conditions\ConditionEvaluator;

/**
 * Orchestrates event-driven side-effects.
 *
 * Processing pipeline for each react() call:
 *   1. Filter definitions matching the event (with wildcard support)
 *   2. Evaluate onlyIf condition for each matched definition
 *   3. Resolve the handler from the registry
 *   4. Dispatch async (queued) or execute sync
 *   5. Isolate failures — a failed handler never stops the others
 *   6. Return ReactionSummary with full results
 *
 * This engine is always called AFTER DB::commit().
 * It never touches the database directly.
 */
final class ReactionEngine implements ReactionEngineInterface
{
    public function __construct(
        private readonly ReactionHandlerRegistry $registry,
        private readonly ConditionEvaluator      $conditions,
    ) {}

    /**
     * @param  ReactionDefinition[]  $definitions
     */
    public function react(ReactionContext $context, array $definitions): ReactionSummary
    {
        $matched    = 0;
        $dispatched = 0;
        $failed     = 0;
        $results    = [];

        foreach ($definitions as $definition) {
            // Step 1: Does this definition match the event?
            if (! $this->conditions->eventMatches($definition->event, $context->event)) {
                continue;
            }

            $matched++;

            // Step 2: Does the onlyIf condition pass?
            if (! $this->conditions->passes($definition->onlyIf, $context)) {
                continue;
            }

            // Step 3: Resolve the handler
            $handler = $this->registry->resolve($definition->handler);

            if ($handler === null) {
                $failed++;
                $results[] = [
                    'handler' => $definition->handler,
                    'success' => false,
                    'error'   => "No handler registered for key '{$definition->handler}'.",
                ];

                try { Log::warning('[ReactionEngine] Unknown handler.', [
                    'event'   => $context->event,
                    'handler' => $definition->handler,
                ]); } catch (\Throwable $__e) {}

                continue;
            }

            // Step 4: Dispatch
            try {
                if ($definition->async) {
                    $handler->dispatchAsync(
                        $context,
                        $definition->params,
                        $definition->queue,
                        $definition->delaySeconds,
                    );

                    $dispatched++;
                    $results[] = ['handler' => $definition->handler, 'success' => true, 'async' => true];
                } else {
                    // Step 5: Sync — isolate failure
                    $result = $handler->handle($context, $definition->params);

                    $dispatched++;

                    if ($result->isFailed()) {
                        $failed++;
                        try { Log::error('[ReactionEngine] Sync handler failed.', [
                            'event'   => $context->event,
                            'handler' => $definition->handler,
                            'error'   => $result->errorMessage,
                        ]); } catch (\Throwable $__e) {}
                    }

                    $results[] = [
                        'handler' => $definition->handler,
                        'success' => $result->success,
                        'error'   => $result->errorMessage,
                        'async'   => false,
                    ];
                }
            } catch (\Throwable $e) {
                $dispatched++;
                // Step 5: Catch-all — engine never propagates exceptions
                $failed++;
                $results[] = [
                    'handler' => $definition->handler,
                    'success' => false,
                    'error'   => $e->getMessage(),
                    'async'   => $definition->async,
                ];

                try { Log::error('[ReactionEngine] Handler threw an exception.', [
                    'event'     => $context->event,
                    'handler'   => $definition->handler,
                    'exception' => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]); } catch (\Throwable $__e) {}
            }
        }

        return new ReactionSummary(
            matched:    $matched,
            dispatched: $dispatched,
            failed:     $failed,
            results:    $results,
        );
    }

    private function logSafe(string $message, array $context = []): void
    {
        $msg = $message;
        if ($context) {
            $msg .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        if (function_exists('logger')) {
            logger()->error($msg);
        }
    }
}
