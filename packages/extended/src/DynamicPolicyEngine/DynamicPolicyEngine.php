<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\DynamicPolicyEngine;

use Illuminate\Support\Facades\Cache;
use ExpressCodeEngines\Shared\Contracts\DynamicPolicyEngineInterface;
use ExpressCodeEngines\Shared\DTOs\PolicyContext;
use ExpressCodeEngines\Shared\DTOs\PolicyDefinition;
use ExpressCodeEngines\Shared\DTOs\PolicyRule;
use ExpressCodeEngines\Extended\DynamicPolicyEngine\Conditions\PolicyConditionEvaluator;

/**
 * Evaluates declarative, data-driven access control rules.
 *
 * can() evaluation pipeline:
 *   1. Check if user has a bypass role → immediately allow
 *   2. Iterate rules in priority DESC order
 *   3. Skip rules that don't match the requested ability
 *   4. Evaluate the rule's condition against the context
 *   5. If condition passes → apply the rule's effect (allow/deny) and stop
 *   6. If no rule matched → apply the definition's defaultEffect
 *
 * scope() pipeline:
 *   1. Collect all 'allow' rules matching the ability with no bypass active
 *   2. Apply each rule's condition as a WHERE clause on the query builder
 *   3. Rules with 'deny' effect generate whereNot clauses
 *   4. Return the modified query
 *
 * Performance: compiled rule sets are cached per user+entity+ability.
 * Cache is tagged so it can be invalidated when policies change.
 */
final class DynamicPolicyEngine implements DynamicPolicyEngineInterface
{
    public function __construct(
        private readonly PolicyConditionEvaluator $evaluator,
        private readonly int    $cacheTtl    = 300,  // seconds
        private readonly bool   $cacheEnabled = true,
    ) {}

    public function can(PolicyContext $context, PolicyDefinition $definition): bool
    {
        $cacheKey = $this->cacheKey('can', $context, $definition);

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $result = $this->evaluateCan($context, $definition);

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $result, $this->cacheTtl);
        }

        return $result;
    }

    public function scope(object $query, PolicyContext $context, PolicyDefinition $definition): object
    {
        foreach ($definition->sortedRules() as $rule) {
            // Skip rules for other abilities
            if (! $this->abilityMatches($rule->ability, $context->ability)) {
                continue;
            }

            // Bypass roles skip scoping entirely
            if ($this->userHasBypassRole($context->user, $rule->bypassRoles)) {
                return $query; // no restrictions for bypass roles
            }

            // Apply condition as WHERE clause
            $query = $this->evaluator->applyToQuery($query, $rule->condition, $context);
        }

        return $query;
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    private function evaluateCan(PolicyContext $context, PolicyDefinition $definition): bool
    {
        foreach ($definition->sortedRules() as $rule) {
            // Step 1: Bypass roles → immediately allow
            if ($this->userHasBypassRole($context->user, $rule->bypassRoles)) {
                return true;
            }

            // Step 2: Skip rules for other abilities
            if (! $this->abilityMatches($rule->ability, $context->ability)) {
                continue;
            }

            // Step 3: Evaluate condition
            $conditionMet = $this->evaluator->evaluate($rule->condition, $context);

            if (! $conditionMet) {
                continue; // condition not met — this rule doesn't fire
            }

            // Step 4: Apply effect
            return $rule->effect === 'allow';
        }

        // Step 5: No rule matched — apply default
        return $definition->defaultEffect === 'allow';
    }

    private function abilityMatches(string $ruleAbility, string $requestedAbility): bool
    {
        return $ruleAbility === '*' || $ruleAbility === $requestedAbility;
    }

    private function userHasBypassRole(object $user, array $bypassRoles): bool
    {
        if (empty($bypassRoles)) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole')) {
            return $user->hasAnyRole($bypassRoles);
        }

        if (method_exists($user, 'hasRole')) {
            foreach ($bypassRoles as $role) {
                if ($user->hasRole($role)) {
                    return true;
                }
            }
            return false;
        }

        // Fallback: direct role property
        return in_array($user->role ?? null, $bypassRoles, true);
    }

    private function cacheKey(string $type, PolicyContext $context, PolicyDefinition $definition): string
    {
        $userId   = $context->user->id ?? 'guest';
        $recordId = is_array($context->record)
            ? ($context->record['id'] ?? 'none')
            : ($context->record?->id ?? 'none');

        return implode(':', [
            'engine.policy',
            $type,
            $definition->entityClass,
            $context->ability,
            $userId,
            $recordId,
        ]);
    }
}
