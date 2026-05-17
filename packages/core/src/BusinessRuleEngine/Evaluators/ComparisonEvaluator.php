<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\BusinessRuleEngine\Evaluators;

use ExpressCodeEngines\Core\BusinessRuleEngine\RuleEvaluatorInterface;
use ExpressCodeEngines\Shared\DTOs\RuleContext;

/**
 * Evaluates: field OPERATOR value
 *
 * params:
 *   operator  string  ==, !=, ===, !==, >, >=, <, <=
 *   value     mixed   expected value; prefix with '@' to resolve as dot-path
 *                     e.g. '@order.credit_limit' compares field against another context value
 *
 * The rule fires (returns true) when the condition IS MET.
 */
final class ComparisonEvaluator implements RuleEvaluatorInterface
{
    public function evaluate(mixed $value, array $params, RuleContext $context): bool
    {
        $operator = $params['operator'] ?? '==';
        $expected = $params['value']    ?? null;

        // Cross-field reference: '@order.credit_limit'
        if (is_string($expected) && str_starts_with($expected, '@')) {
            $expected = $context->resolve(substr($expected, 1));
        }

        return self::compare($value, $operator, $expected);
    }

    public static function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=='  => $actual == $expected,
            '!='  => $actual != $expected,
            '===' => $actual === $expected,
            '!==' => $actual !== $expected,
            '>'   => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            '>='  => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            '<'   => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            '<='  => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            default => false,
        };
    }
}
