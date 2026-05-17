<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\BusinessRuleEngine\Evaluators;

use ExpressCodeEngines\Core\BusinessRuleEngine\RuleEvaluatorInterface;
use ExpressCodeEngines\Shared\DTOs\RuleContext;

/**
 * Fires when value IS in the list (default) or is NOT in the list (negate: true).
 *
 * params:
 *   values  array  list of allowed/blocked values (strict comparison)
 *   negate  bool   false → fires when value IS in list (deny blocked values)
 *                  true  → fires when value is NOT in list (deny unknown types)
 */
final class InListEvaluator implements RuleEvaluatorInterface
{
    public function evaluate(mixed $value, array $params, RuleContext $context): bool
    {
        $list   = $params['values'] ?? [];
        $negate = $params['negate'] ?? false;
        $inList = in_array($value, $list, strict: true);

        return $negate ? ! $inList : $inList;
    }
}
