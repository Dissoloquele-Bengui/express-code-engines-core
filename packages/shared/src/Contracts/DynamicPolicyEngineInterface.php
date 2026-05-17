<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\PolicyContext;
use ExpressCodeEngines\Shared\DTOs\PolicyDefinition;

/**
 * Evaluates dynamic, data-driven access control rules.
 *
 * Complements Laravel's native Gates/Policies — does not replace them.
 * Designed to be called from inside a Laravel Policy method:
 *
 *   public function update(User $user, Order $order): bool
 *   {
 *       return $this->policyEngine->can(
 *           new PolicyContext(user: $user, ability: 'update', record: $order),
 *           $this->definitions[Order::class],
 *       );
 *   }
 *
 * Also provides query scoping for Row-Level Security:
 *
 *   public function queryBuilder(User $user, Builder $query): Builder
 *   {
 *       return $this->policyEngine->scope(
 *           $query,
 *           new PolicyContext(user: $user, ability: 'view'),
 *           $this->definitions[Order::class],
 *       );
 *   }
 */
interface DynamicPolicyEngineInterface
{
    /**
     * Returns true if the user is allowed to perform the ability.
     */
    public function can(PolicyContext $context, PolicyDefinition $definition): bool;

    /**
     * Applies WHERE clauses to a query builder for Row-Level Security.
     * Returns the modified builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    public function scope(object $query, PolicyContext $context, PolicyDefinition $definition): object;
}
