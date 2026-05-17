<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\WorkflowEngine;

use ExpressCodeEngines\Shared\Contracts\WorkflowEngineInterface;
use Carbon\Carbon;
use ExpressCodeEngines\Shared\Contracts\WorkflowGuardInterface;
use ExpressCodeEngines\Shared\DTOs\TransitionRequest;
use ExpressCodeEngines\Shared\DTOs\WorkflowDefinition;
use ExpressCodeEngines\Shared\DTOs\TransitionDefinition;
use ExpressCodeEngines\Shared\ValueObjects\TransitionResult;

/**
 * Manages state machines and validates transitions for entities.
 *
 * The engine:
 *   1. Loads the WorkflowDefinition for the entity class
 *   2. Verifies the requested transition exists from the current state
 *   3. Runs all guards — ALL must pass
 *   4. Returns TransitionResult in memory (never persists)
 *
 * Persistence and post-action dispatch are the Action's responsibility.
 */
final class WorkflowEngine implements WorkflowEngineInterface
{
    /**
     * @param  array<string, WorkflowDefinition>  $definitions  keyed by entity class
     * @param  WorkflowGuardInterface[]            $guards
     */
    public function __construct(
        private readonly array    $definitions,
        private readonly iterable $guards = [],
    ) {}

    public function transition(TransitionRequest $request): TransitionResult
    {
        $definition = $this->resolveDefinition($request->entityClass);

        if ($definition === null) {
            return TransitionResult::deny(
                "No workflow defined for '{$request->entityClass}'.",
            );
        }

        $transitionDef = $definition->find($request->currentState, $request->transition);

        if ($transitionDef === null) {
            return TransitionResult::deny(
                "Transition '{$request->transition}' is not allowed from state '{$request->currentState}'.",
            );
        }

        // Run all guards — fail on first denial
        foreach ($transitionDef->guards as $guardExpression) {
            $guard = $this->resolveGuard($guardExpression);

            if ($guard === null) {
                return TransitionResult::deny(
                    "No guard handler registered for: '{$guardExpression}'.",
                );
            }

            if (! $guard->passes($guardExpression, $request)) {
                return TransitionResult::deny(
                    "Guard '{$guardExpression}' denied the transition.",
                );
            }
        }

        return TransitionResult::allow(
            newState:    $transitionDef->to,
            postActions: $transitionDef->postActions,
            metadata:    array_merge($transitionDef->metadata, [
                'transitioned_at' => Carbon::now()->toISOString(),
                'from'            => $request->currentState,
                'to'              => $transitionDef->to,
                'actor_id'        => $request->user?->id ?? null,
                'reason'          => $request->reason,
            ]),
        );
    }

    /**
     * @return string[]  transition names (= target states) available from current state
     */
    public function availableTransitions(string $entityClass, string $currentState, ?object $user = null): array
    {
        $definition = $this->resolveDefinition($entityClass);

        if ($definition === null) {
            return [];
        }

        return array_map(
            fn (TransitionDefinition $t) => $t->to,
            $definition->transitionsFrom($currentState),
        );
    }

    private function resolveDefinition(string $entityClass): ?WorkflowDefinition
    {
        return $this->definitions[$entityClass] ?? null;
    }

    private function resolveGuard(string $expression): ?WorkflowGuardInterface
    {
        foreach ($this->guards as $guard) {
            if ($guard->supports($expression)) {
                return $guard;
            }
        }

        return null;
    }
}
