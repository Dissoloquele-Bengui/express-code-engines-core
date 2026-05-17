<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * The data context passed into the ConstraintEngine.
 * Carries the entity being validated and any pre-loaded data.
 */
final class ConstraintContext
{
    public function __construct(
        /** Fully-qualified entity class name, e.g. App\Models\Order */
        public readonly string $entityClass,

        /** The data array being validated (not yet persisted) */
        public readonly array $data,

        /** Optional: the existing record ID if this is an update */
        public readonly int|string|null $existingId = null,

        /** Optional: extra context for checkers (e.g. tenant_id, user_id) */
        public readonly array $meta = [],
    ) {}
}
