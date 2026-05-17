<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\RuleContext;
use ExpressCodeEngines\Shared\DTOs\RuleDefinition;
use ExpressCodeEngines\Shared\ValueObjects\BREResponse;

/**
 * Evaluates declarative business rules with priority, severity and side-effect approval.
 *
 * Rules are evaluated in priority DESC order.
 * The engine never executes approved effects — it only returns them for
 * the Action or ReactionEngine to dispatch after DB::commit().
 *
 * Evaluation modes:
 *  - full (default): evaluates all rules, collects all denials/warnings
 *  - fail_fast: stops at first denial
 */
interface BusinessRuleEngineInterface
{
    /**
     * @param  RuleDefinition[]  $rules
     */
    public function evaluate(RuleContext $context, array $rules, bool $failFast = false): BREResponse;
}
