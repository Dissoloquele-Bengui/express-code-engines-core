<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A single reaction definition — one event trigger + one handler invocation.
 *
 * Example config array:
 * [
 *     'event'   => 'order.submitted',
 *     'handler' => 'notify',
 *     'params'  => ['template' => 'order_submitted', 'recipient_path' => 'customer.email'],
 *     'only_if' => 'order.total > 100',
 *     'async'   => true,
 * ]
 */
final class ReactionDefinition
{
    public function __construct(
        /**
         * The event this reaction listens to.
         * Supports wildcards: 'order.*' matches 'order.submitted', 'order.approved', etc.
         */
        public readonly string $event,

        /**
         * Handler key registered in the ReactionHandlerRegistry.
         * Built-in: 'notify', 'dispatch_job', 'update_field', 'webhook'
         */
        public readonly string $handler,

        /** Handler-specific parameters */
        public readonly array $params = [],

        /**
         * Simple condition to gate this reaction.
         * Format: "field operator value" — same syntax as BRE onlyIf.
         * e.g. "order.total > 100", "user.verified == true"
         * Resolved against ReactionContext::payload.
         */
        public readonly ?string $onlyIf = null,

        /**
         * Whether to dispatch this handler asynchronously via Laravel Queue.
         * Default: true — reactions should never block the response.
         */
        public readonly bool $async = true,

        /**
         * Queue name for async dispatch. Null = default queue.
         */
        public readonly ?string $queue = null,

        /**
         * Delay in seconds before dispatching (async only).
         */
        public readonly int $delaySeconds = 0,
    ) {}

    /**
     * Convenience factory from a plain config array.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            event:        $config['event'],
            handler:      $config['handler'],
            params:       $config['params']        ?? [],
            onlyIf:       $config['only_if']       ?? null,
            async:        $config['async']          ?? true,
            queue:        $config['queue']          ?? null,
            delaySeconds: $config['delay_seconds'] ?? 0,
        );
    }
}
