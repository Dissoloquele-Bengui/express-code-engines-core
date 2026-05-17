<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\BusinessRuleEngine;

use ExpressCodeEngines\Core\BusinessRuleEngine\Evaluators\ComparisonEvaluator;
use ExpressCodeEngines\Core\BusinessRuleEngine\Evaluators\RangeEvaluator;
use ExpressCodeEngines\Core\BusinessRuleEngine\Evaluators\InListEvaluator;
use ExpressCodeEngines\Core\BusinessRuleEngine\Evaluators\RegexEvaluator;
use ExpressCodeEngines\Core\BusinessRuleEngine\Evaluators\CustomCallableEvaluator;
use ExpressCodeEngines\Shared\DTOs\RuleContext;
use ExpressCodeEngines\Shared\DTOs\RuleDefinition;

/**
 * Resolves the correct evaluator for a given ruleType and runs it.
 *
 * Built-in rule types:
 *   comparison       — field OP value   (==, !=, >, >=, <, <=)
 *   range            — min <= field <= max
 *   in_list          — field in [a, b, c]
 *   regex            — field matches /pattern/
 *   custom_callable  — arbitrary PHP callable, receives (mixed $value, array $params, RuleContext $ctx)
 *
 * Custom evaluators can be added via addEvaluator().
 */
final class RuleEvaluatorRegistry
{
    /** @var array<string, RuleEvaluatorInterface> */
    private array $evaluators = [];

    public function __construct()
    {
        // Register built-ins
        $this->addEvaluator('comparison',      new ComparisonEvaluator());
        $this->addEvaluator('range',           new RangeEvaluator());
        $this->addEvaluator('in_list',         new InListEvaluator());
        $this->addEvaluator('regex',           new RegexEvaluator());
        $this->addEvaluator('custom_callable', new CustomCallableEvaluator());
    }

    public function addEvaluator(string $ruleType, RuleEvaluatorInterface $evaluator): void
    {
        $this->evaluators[$ruleType] = $evaluator;
    }

    /**
     * Evaluates a rule against the context.
     * Returns true when the rule's condition IS MET (i.e. the rule fires).
     */
    public function evaluate(RuleDefinition $rule, RuleContext $context): bool
    {
        $evaluator = $this->evaluators[$rule->ruleType] ?? null;

        if ($evaluator === null) {
            // Unknown rule type — treat as triggered so it appears in denials
            // rather than silently passing
            return true;
        }

        $value = $context->resolve($rule->field);

        return $evaluator->evaluate($value, $rule->params, $context);
    }
}
