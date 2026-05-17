<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A single business rule definition.
 */
final class RuleDefinition
{
    public function __construct(
        public readonly string $id,

        /**
         * Built-in types: 'comparison', 'range', 'in_list', 'regex',
         * 'custom_callable', 'expression'
         */
        public readonly string $ruleType,

        /** dot-path to the value being evaluated, e.g. 'order.total' */
        public readonly string $field,

        /** Rule-type-specific parameters */
        public readonly array $params,

        /**
         * 'deny'  → blocks the action (added to denials)
         * 'warn'  → advisory only (added to warnings)
         * 'allow' → adds an approved effect if condition is met
         */
        public readonly string $severity = 'deny',

        /** Higher = evaluated first */
        public readonly int $priority = 0,

        /** Human-readable message when this rule triggers */
        public readonly string $message = '',

        /**
         * If severity is 'allow', these effect keys are returned in BREResponse::approvedEffects
         * for the Action to dispatch after commit.
         */
        public readonly array $effects = [],

        /** Optional: rule only applies when this condition is also true */
        public readonly ?string $onlyIf = null,
    ) {}
}
