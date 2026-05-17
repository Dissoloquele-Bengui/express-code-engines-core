<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\ConstraintDefinition;
use ExpressCodeEngines\Shared\DTOs\ConstraintContext;

/**
 * Performs the actual data-lookup for a single constraint type.
 *
 * Injected into ConstraintEngine implementations.
 * This is the ONLY place where DB queries are allowed in the constraint layer.
 *
 * Each concrete checker handles exactly one constraint type
 * (e.g. UniquenessChecker, OverlapChecker, LimitChecker).
 */
interface ConstraintCheckerInterface
{
    public function supports(string $constraintType): bool;

    /**
     * Returns true if the constraint passes (no violation found).
     */
    public function check(ConstraintDefinition $definition, ConstraintContext $context): bool;
}
