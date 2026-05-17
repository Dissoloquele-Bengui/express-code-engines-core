<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\BusinessRuleEngine\Evaluators;

use ExpressCodeEngines\Core\BusinessRuleEngine\RuleEvaluatorInterface;
use ExpressCodeEngines\Shared\DTOs\RuleContext;

/**
 * Fires when the string value matches the given regex pattern.
 *
 * params:
 *   pattern  string  full PCRE pattern including delimiters, e.g. '/^[A-Z]{2}\d{4}$/'
 */
final class RegexEvaluator implements RuleEvaluatorInterface
{
    public function evaluate(mixed $value, array $params, RuleContext $context): bool
    {
        $pattern = $params['pattern'] ?? null;

        if ($pattern === null || ! is_string($value)) {
            return false;
        }

        // Suppress warnings — invalid regex returns false, not an exception
        // Rule fires (denial) when value does NOT match the expected pattern
        return ! @preg_match($pattern, $value);
    }
}
