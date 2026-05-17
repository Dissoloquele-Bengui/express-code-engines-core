<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A single computation request.
 */
final class ComputationRequest
{
    public function __construct(
        /**
         * Operation name, must exist in the engine's whitelist registry.
         * Built-in: 'add', 'subtract', 'multiply', 'divide', 'sum',
         *           'average', 'round', 'percentage', 'iif', 'min', 'max'
         */
        public readonly string $operation,

        /**
         * Arguments for the operation.
         * Can be scalar values, dot-path strings (resolved against $context),
         * or nested ComputationRequest arrays for recursive evaluation.
         */
        public readonly array $args,

        /** Data context for dot-path resolution */
        public readonly array $context = [],

        /** Optional: use BCMath with this precision for financial accuracy */
        public readonly ?int $precision = null,
    ) {}
}
