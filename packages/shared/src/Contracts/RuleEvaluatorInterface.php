<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\BusinessRuleEngine;

use ExpressCodeEngines\Shared\DTOs\RuleContext;

/**
 * Contract for a single rule type evaluator.
 *
 * Returns true when the condition IS MET (the rule fires).
 * The engine interprets the result according to severity.
 */
interface RuleEvaluatorInterface
{
    /**
     * @param  mixed       $value   The resolved value at rule->field
     * @param  array       $params  Rule-type-specific params from RuleDefinition->params
     * @param  RuleContext $context Full context, available for cross-field checks
     */
    public function evaluate(mixed $value, array $params, RuleContext $context): bool;
}
