<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A single constraint rule definition.
 */
final class ConstraintDefinition
{
    public function __construct(
        /**
         * Type maps to a registered ConstraintCheckerInterface.
         * Built-in types: 'uniqueness', 'overlap', 'limit', 'dependency'
         */
        public readonly string $type,

        /** Fields to scope the check against */
        public readonly array $scopeFields = [],

        /** Human-readable message returned on violation */
        public readonly string $message = 'Constraint violation.',

        /** Arbitrary config passed directly to the checker */
        public readonly array $config = [],
    ) {}
}
