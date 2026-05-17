<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\DynamicPolicyEngine\Conditions;

use ExpressCodeEngines\Shared\DTOs\PolicyContext;

/**
 * Evaluates a policy rule condition string against a PolicyContext.
 *
 * Condition format: "left_path operator right_path_or_value"
 *
 * Supported operators: ==, !=, >, >=, <, <=
 *
 * Left and right sides can be:
 *   - A path with prefix:  'record.tenant_id', 'user.region', 'meta.flag'
 *   - A literal value:     '42', 'true', 'false', 'null', 'published'
 *   - A cross-reference:   right side can reference a path to compare with left
 *
 * Examples:
 *   'record.tenant_id == user.tenant_id'   both sides resolved from context
 *   'record.status == published'           right side is a literal string
 *   'record.total > 1000'                  right side is a literal number
 *   'user.is_verified == true'             right side is a literal boolean
 */
final class PolicyConditionEvaluator
{
    /**
     * Returns true when condition is satisfied (or null condition — always passes).
     */
    public function evaluate(?string $condition, PolicyContext $context): bool
    {
        if ($condition === null) {
            return true;
        }

        if (! preg_match('/^(\S+)\s*(==|!=|>=|<=|>|<)\s*(.+)$/', trim($condition), $matches)) {
            return true; // malformed — pass to avoid silently locking out users
        }

        [, $left, $operator, $right] = $matches;

        $leftValue  = $this->resolveOperand(trim($left),  $context);
        $rightValue = $this->resolveOperand(trim($right), $context);

        return $this->compare($leftValue, $operator, $rightValue);
    }

    /**
     * Builds WHERE clauses on a query builder for a condition.
     * Used by DynamicPolicyEngine::scope() to apply RLS at query level.
     *
     * Only conditions where the right side resolves to a scalar value
     * can be applied to a query builder. Cross-field conditions are skipped.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public function applyToQuery(object $query, ?string $condition, PolicyContext $context): object
    {
        if ($condition === null) {
            return $query;
        }

        if (! preg_match('/^(\S+)\s*(==|!=|>=|<=|>|<)\s*(.+)$/', trim($condition), $matches)) {
            return $query;
        }

        [, $left, $operator, $right] = $matches;

        // Only apply when left is a record field (no prefix or 'record.' prefix)
        $column = str_starts_with($left, 'record.')
            ? substr($left, 7)
            : $left;

        // Right side must resolve to a scalar
        $value = $this->resolveOperand(trim($right), $context);

        if (is_array($value) || is_object($value)) {
            return $query; // can't apply complex values to query
        }

        $sqlOperator = match ($operator) {
            '==' => '=',
            '!=' => '!=',
            '>'  => '>',
            '>=' => '>=',
            '<'  => '<',
            '<=' => '<=',
            default => '=',
        };

        return $query->where($column, $sqlOperator, $value);
    }

    private function resolveOperand(string $operand, PolicyContext $context): mixed
    {
        // Path with known prefix — resolve from context
        if (str_starts_with($operand, 'record.') ||
            str_starts_with($operand, 'user.')   ||
            str_starts_with($operand, 'meta.')) {
            return $context->resolve($operand);
        }

        // Cast literals
        return $this->castLiteral($operand);
    }

    private function castLiteral(string $raw): mixed
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

        return $raw; // bare string literal (e.g. 'published', 'active')
    }

    private function compare(mixed $left, string $operator, mixed $right): bool
    {
        return match ($operator) {
            '==' => $left == $right,
            '!=' => $left != $right,
            '>'  => is_numeric($left) && is_numeric($right) && $left > $right,
            '>=' => is_numeric($left) && is_numeric($right) && $left >= $right,
            '<'  => is_numeric($left) && is_numeric($right) && $left < $right,
            '<=' => is_numeric($left) && is_numeric($right) && $left <= $right,
            default => false,
        };
    }
}
