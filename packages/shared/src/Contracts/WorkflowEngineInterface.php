<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\TransitionRequest;
use ExpressCodeEngines\Shared\ValueObjects\TransitionResult;

/**
 * Manages state machines and transitions for STATUS_DEPENDENT / APPROVABLE entities.
 *
 * The engine:
 *  - validates that the current state allows the requested transition
 *  - checks role/policy guards if configured
 *  - returns the new state + post_actions in memory only
 *
 * The engine NEVER persists. The Action is responsible for:
 *  - saving the new state via Repository
 *  - dispatching post_actions (usually via ReactionEngine)
 *  - logging the transition
 */
interface WorkflowEngineInterface
{
    public function transition(TransitionRequest $request): TransitionResult;

    /**
     * Returns all valid transitions from the current state for a given entity/user.
     *
     * @return string[]
     */
    public function availableTransitions(string $entityClass, string $currentState, ?object $user = null): array;
}
