<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\BusinessRuleEngine;

use ExpressCodeEngines\Shared\Contracts\BusinessRuleEngineInterface;
use ExpressCodeEngines\Shared\DTOs\RuleContext;
use ExpressCodeEngines\Shared\DTOs\RuleDefinition;
use ExpressCodeEngines\Shared\ValueObjects\BREResponse;

/**
 * Evaluates declarative business rules in priority order.
 *
 * Evaluation pipeline per rule:
 *   1. Check onlyIf guard (skip rule if condition not met)
 *   2. Evaluate condition via the registered RuleEvaluator
 *   3. Interpret result against severity (deny / warn / allow)
 *   4. Collect denials, warnings, approved effects
 *
 * The engine never executes side effects — it returns approvedEffects
 * for the Action to dispatch after DB::commit().
 */
final class BusinessRuleEngine implements BusinessRuleEngineInterface
{
    public function __construct(
        private readonly RuleEvaluatorRegistry $registry,
    ) {}

    /**
     * @param  RuleDefinition[]  $rules
     */
    public function evaluate(RuleContext $context, array $rules, bool $failFast = false): BREResponse
    {
        $sorted = $this->sortByPriority($rules);

        $denials        = [];
        $warnings       = [];
        $approvedEffects = [];

        foreach ($sorted as $rule) {
            // Guard: onlyIf condition
            if ($rule->onlyIf !== null && ! $this->evaluateOnlyIf($rule->onlyIf, $context)) {
                continue;
            }

            $conditionMet = $this->registry->evaluate($rule, $context);

            match ($rule->severity) {
                'deny' => $conditionMet && ($denials[] = [
                    'rule_id' => $rule->id,
                    'message' => $rule->message,
                ]),

                'warn' => $conditionMet && ($warnings[] = [
                    'rule_id' => $rule->id,
                    'message' => $rule->message,
                ]),

                'allow' => $conditionMet && array_push($approvedEffects, ...$rule->effects),

                default => null,
            };

            if ($failFast && ! empty($denials)) {
                break;
            }
        }

        return new BREResponse(
            denials:        $denials,
            warnings:       $warnings,
            approvedEffects: array_unique($approvedEffects),
        );
    }

    /**
     * @param  RuleDefinition[]  $rules
     * @return RuleDefinition[]
     */
    private function sortByPriority(array $rules): array
    {
        usort($rules, fn (RuleDefinition $a, RuleDefinition $b) => $b->priority <=> $a->priority);

        return $rules;
    }

    /**
     * Evaluates a simple onlyIf expression.
     * Format: "field operator value" — e.g. "order.type == 'international'"
     * Delegates to the same evaluator as a comparison rule.
     */
    private function evaluateOnlyIf(string $expression, RuleContext $context): bool
    {
        // Parse "field operator value" — keeps the guard syntax simple and safe
        // Supported operators: ==, !=, >, >=, <, <=
        if (! preg_match('/^(\S+)\s*(==|!=|>=|<=|>|<)\s*(.+)$/', trim($expression), $matches)) {
            return true; // malformed guard — don't silently block rules
        }

        [, $field, $operator, $rawValue] = $matches;

        $actual   = $context->resolve($field);
        $expected = $this->castGuardValue(trim($rawValue));

        return Evaluators\ComparisonEvaluator::compare($actual, $operator, $expected);
    }

    private function castGuardValue(string $raw): mixed
    {
        if ($raw === 'null')  return null;
        if ($raw === 'true')  return true;
        if ($raw === 'false') return false;

        // Quoted string: 'value' or "value"
        if (preg_match("/^['\"](.+)['\"]$/", $raw, $m)) {
            return $m[1];
        }

        // Numeric
        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        return $raw;
    }
}
