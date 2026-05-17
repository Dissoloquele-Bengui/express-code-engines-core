<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\BusinessRuleEngine\Evaluators;

use ExpressCodeEngines\Core\BusinessRuleEngine\RuleEvaluatorInterface;
use ExpressCodeEngines\Shared\DTOs\RuleContext;

/**
 * Fires when the value is OUTSIDE the configured range.
 * Use with severity 'deny' to block out-of-range values.
 *
 * params:
 *   min        numeric|null  lower bound (null = no lower bound)
 *   max        numeric|null  upper bound (null = no upper bound)
 *   inclusive  bool          whether bounds are inclusive (default: true)
 */
final class RangeEvaluator implements RuleEvaluatorInterface
{
    public function evaluate(mixed $value, array $params, RuleContext $context): bool
    {
        if (! is_numeric($value)) {
            return true; // non-numeric is always out-of-range
        }

        $min       = isset($params['min']) ? (float) $params['min'] : null;
        $max       = isset($params['max']) ? (float) $params['max'] : null;
        $inclusive = $params['inclusive'] ?? true;
        $v         = (float) $value;

        $belowMin = $min !== null && ($inclusive ? $v < $min : $v <= $min);
        $aboveMax = $max !== null && ($inclusive ? $v > $max : $v >= $max);

        return $belowMin || $aboveMax;
    }
}
