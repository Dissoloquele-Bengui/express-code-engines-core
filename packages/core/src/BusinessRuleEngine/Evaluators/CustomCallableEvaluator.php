<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\BusinessRuleEngine\Evaluators;

use ExpressCodeEngines\Core\BusinessRuleEngine\RuleEvaluatorInterface;
use ExpressCodeEngines\Shared\DTOs\RuleContext;

/**
 * Delegates evaluation to a PHP callable registered by the application.
 *
 * params:
 *   callable  string|array  'App\Rules\MyRule::check' or ['App\Rules\MyRule', 'check']
 *                           The callable receives (mixed $value, array $params, RuleContext $ctx)
 *                           and must return bool.
 *
 * Design decision: closures are NOT supported.
 * Closures can't be serialised, audited, or referenced by name in config arrays.
 * Use a static method on a named class instead — it's auditable and testable in isolation.
 */
final class CustomCallableEvaluator implements RuleEvaluatorInterface
{
    public function evaluate(mixed $value, array $params, RuleContext $context): bool
    {
        $callable = $params['callable'] ?? null;

        if ($callable === null) {
            return false;
        }

        // Resolve 'ClassName::method' string to array form
        if (is_string($callable) && str_contains($callable, '::')) {
            $callable = explode('::', $callable, 2);
        }

        if (! is_callable($callable)) {
            return false;
        }

        return (bool) call_user_func($callable, $value, $params, $context);
    }
}
