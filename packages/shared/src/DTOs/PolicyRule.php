<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * Defines a single dynamic access rule.
 *
 * Rules are evaluated in priority DESC order.
 * The first matching rule's effect (allow/deny) is applied.
 * If no rule matches, the default effect of the policy is used.
 *
 * Example config array:
 * [
 *     'id'           => 'own_records_only',
 *     'ability'      => 'view',
 *     'effect'       => 'allow',
 *     'condition'    => 'record.tenant_id == user.tenant_id',
 *     'bypass_roles' => ['admin', 'super_admin'],
 *     'priority'     => 10,
 * ]
 */
final class PolicyRule
{
    public function __construct(
        public readonly string $id,

        /**
         * The Gate ability this rule applies to.
         * e.g. 'view', 'update', 'delete', 'approve'
         * Use '*' to match all abilities.
         */
        public readonly string $ability,

        /**
         * 'allow' — grants access when condition is true
         * 'deny'  — blocks access when condition is true
         */
        public readonly string $effect,

        /**
         * Condition expression evaluated against the PolicyContext.
         * Format: "path operator value"
         * Paths prefixed with 'record.' resolve against the model/data.
         * Paths prefixed with 'user.'   resolve against the user object.
         * Paths prefixed with 'meta.'   resolve against context meta.
         *
         * Examples:
         *   'record.tenant_id == user.tenant_id'   — same tenant
         *   'record.status == published'            — record must be published
         *   'user.region == meta.allowed_region'    — region match
         */
        public readonly ?string $condition,

        /**
         * Roles that bypass this rule entirely (always allowed).
         * e.g. ['admin', 'super_admin']
         */
        public readonly array $bypassRoles = [],

        /** Higher priority = evaluated first */
        public readonly int $priority = 0,
    ) {}

    public static function fromArray(array $config): self
    {
        return new self(
            id:          $config['id'],
            ability:     $config['ability']       ?? '*',
            effect:      $config['effect']        ?? 'allow',
            condition:   $config['condition']     ?? null,
            bypassRoles: $config['bypass_roles']  ?? [],
            priority:    $config['priority']      ?? 0,
        );
    }
}
