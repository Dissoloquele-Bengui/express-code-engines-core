<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\ConstraintContext;
use ExpressCodeEngines\Shared\ValueObjects\ValidationResult;

/**
 * Validates hard physical/temporal integrity constraints.
 *
 * This is the FIRST call in any Action, before BRE or persistence.
 * It never persists data and never knows about presentation.
 *
 * Implementations may inject a ConstraintCheckerInterface to perform
 * DB lookups — the engine itself does not query the database directly.
 */
interface ConstraintEngineInterface
{
    /**
     * @param  ConstraintDefinition[]  $definitions
     */
    public function validate(ConstraintContext $context, array $definitions): ValidationResult;
}
