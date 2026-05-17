<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ReactionEngine\Conditions;

use ExpressCodeEngines\Shared\DTOs\ReactionContext;

/**
 * Evaluates a simple "field operator value" condition string
 * against a ReactionContext payload.
 *
 * Deliberately extracted from the ReactionEngine so it can be
 * tested and reused independently.
 *
 * Supported operators: ==, !=, >, >=, <, <=
 *
 * Wildcard matching for events: 'order.*' matches 'order.submitted', 'order.approved', etc.
 */
final class ConditionEvaluator
{
    /**
     * Returns true if the onlyIf condition is satisfied.
     * A null condition always passes.
     */
    public function passes(?string $condition, ReactionContext $context): bool
    {
        if ($condition === null) {
            return true;
        }

        if (! preg_match('/^(\S+)\s*(==|!=|>=|<=|>|<)\s*(.+)$/', trim($condition), $matches)) {
            // Malformed condition — pass rather than silently blocking all reactions
            return true;
        }

        [, $field, $operator, $rawValue] = $matches;

        $actual   = $context->resolve($field);
        $expected = $this->cast(trim($rawValue));

        return $this->compare($actual, $operator, $expected);
    }

    /**
     * Returns true if the event name matches the definition's event pattern.
     * Supports '*' as a single-segment wildcard.
     *
     * e.g. 'order.*' matches 'order.submitted' but not 'invoice.paid'
     */
    public function eventMatches(string $pattern, string $event): bool
    {
        if ($pattern === $event) {
            return true;
        }

        if (! str_contains($pattern, '*')) {
            return false;
        }

        $regex = '/^' . str_replace(['.', '*'], ['\.', '[^.]+'], $pattern) . '$/';

        return (bool) preg_match($regex, $event);
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=='  => $actual == $expected,
            '!='  => $actual != $expected,
            '>'   => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            '>='  => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            '<'   => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            '<='  => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            default => false,
        };
    }

    private function cast(string $raw): mixed
    {
        if ($raw === 'null')  return null;
        if ($raw === 'true')  return true;
        if ($raw === 'false') return false;

        if (preg_match("/^['\"](.+)['\"]$/", $raw, $m)) {
            return $m[1];
        }

        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
    }
}
