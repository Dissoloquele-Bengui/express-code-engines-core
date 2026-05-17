<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A policy definition — the complete set of rules for one entity class.
 */
final class PolicyDefinition
{
    /**
     * @param  PolicyRule[]  $rules
     */
    public function __construct(
        public readonly string $entityClass,

        /** @var PolicyRule[] */
        public readonly array  $rules,

        /**
         * What happens when no rule matches.
         * 'deny' (default) — reject access unless explicitly allowed
         * 'allow'          — grant access unless explicitly denied
         */
        public readonly string $defaultEffect = 'deny',
    ) {}

    public static function fromArray(string $entityClass, array $config): self
    {
        $rules = array_map(
            fn (array $r) => PolicyRule::fromArray($r),
            $config['rules'] ?? [],
        );

        return new self(
            entityClass:   $entityClass,
            rules:         $rules,
            defaultEffect: $config['default_effect'] ?? 'deny',
        );
    }

    /** @return PolicyRule[] sorted by priority DESC */
    public function sortedRules(): array
    {
        $rules = $this->rules;
        usort($rules, fn (PolicyRule $a, PolicyRule $b) => $b->priority <=> $a->priority);
        return $rules;
    }
}
