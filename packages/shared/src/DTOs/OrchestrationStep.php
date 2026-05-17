<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * Defines a single step in an orchestration flow.
 */
final class OrchestrationStep
{
    public function __construct(
        /** Step identifier — used in logs, compensation references, and idempotency keys */
        public readonly string $id,

        /**
         * Fully-qualified Action or Service class to invoke.
         * Must expose an execute(array $payload): array method.
         * The returned array is merged into the flow payload for subsequent steps.
         */
        public readonly string $handler,

        /**
         * Input mapping: step input key → payload path.
         * Controls which subset of the accumulated payload is passed to this step.
         * Empty = pass the full accumulated payload.
         * e.g. ['order_id' => 'order.id', 'customer_email' => 'customer.email']
         */
        public readonly array $input = [],

        /**
         * Compensation handler to call if THIS step needs to be rolled back.
         * Must expose a compensate(array $context): void method.
         * e.g. 'App\Compensations\CancelOrderCompensation'
         */
        public readonly ?string $compensation = null,

        /** Whether to continue the flow if this step fails (default: false = stop) */
        public readonly bool $continueOnFailure = false,

        /** Retry attempts for this step (0 = no retry) */
        public readonly int $retries = 0,
    ) {}

    public static function fromArray(array $config): self
    {
        return new self(
            id:                $config['id'],
            handler:           $config['handler'],
            input:             $config['input']               ?? [],
            compensation:      $config['compensation']        ?? null,
            continueOnFailure: $config['continue_on_failure'] ?? false,
            retries:           $config['retries']             ?? 0,
        );
    }
}
