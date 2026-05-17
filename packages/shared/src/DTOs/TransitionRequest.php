<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * Request to transition an entity from one state to another.
 * The WorkflowEngine validates guards and returns a TransitionResult.
 */
final class TransitionRequest
{
    public function __construct(
        /** Fully-qualified entity class, e.g. App\Models\Order */
        public readonly string $entityClass,

        /** Current state of the entity */
        public readonly string $currentState,

        /** Target transition (the "to" state) */
        public readonly string $transition,

        /** Entity data for field guards */
        public readonly array $data = [],

        /** The user attempting the transition (for role guards) */
        public readonly ?object $user = null,

        /** Optional reason for audit trail */
        public readonly ?string $reason = null,
    ) {}
}