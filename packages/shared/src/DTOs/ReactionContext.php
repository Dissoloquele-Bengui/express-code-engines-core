<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * The event context passed into the ReactionEngine.
 *
 * Built after DB::commit() — the entity is already persisted at this point.
 */
final class ReactionContext
{
    public function __construct(
        /**
         * The event name that triggered this reaction.
         * e.g. 'order.submitted', 'invoice.paid', 'user.registered'
         */
        public readonly string $event,

        /**
         * The entity data at the moment of the event.
         * Typically the result of $model->toArray() or a DTO.
         */
        public readonly array $payload,

        /** Optional: the user who triggered the event */
        public readonly ?object $actor = null,

        /** Optional: extra metadata (tenant_id, locale, source) */
        public readonly array $meta = [],
    ) {}

    /**
     * Safely resolves a dot-path from the payload.
     * e.g. resolve('order.total') on ['order' => ['total' => 250]]
     */
    public function resolve(string $path): mixed
    {
        $segments = explode('.', $path);
        $value    = $this->payload;

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
