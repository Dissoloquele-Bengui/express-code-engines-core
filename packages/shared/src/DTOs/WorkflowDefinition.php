<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * The complete workflow configuration for a single entity class.
 */
final class WorkflowDefinition
{
    /**
     * @param  TransitionDefinition[]  $transitions
     * @param  string[]                $initialStates  States a new entity can start in
     */
    public function __construct(
        public readonly string $entityClass,
        public readonly array  $transitions,
        public readonly array  $initialStates = ['draft'],
    ) {}

    /**
     * Factory from the config array format used in config/engines.php.
     *
     * Example config:
     * 'App\Models\Order' => [
     *     'initial_states' => ['draft'],
     *     'transitions'    => [
     *         ['from' => 'draft',     'to' => 'submitted', 'post_actions' => ['notify_reviewer']],
     *         ['from' => 'submitted', 'to' => 'approved',  'guards' => ['role:manager']],
     *         ['from' => 'submitted', 'to' => 'rejected',  'guards' => ['role:manager']],
     *     ],
     * ]
     */
    public static function fromArray(string $entityClass, array $config): self
    {
        $transitions = array_map(
            fn (array $t) => TransitionDefinition::fromArray($t),
            $config['transitions'] ?? [],
        );

        return new self(
            entityClass:   $entityClass,
            transitions:   $transitions,
            initialStates: $config['initial_states'] ?? ['draft'],
        );
    }

    /**
     * Find all transitions that are valid from the current state.
     *
     * @return TransitionDefinition[]
     */
    public function transitionsFrom(string $state): array
    {
        return array_values(
            array_filter(
                $this->transitions,
                fn (TransitionDefinition $t) => $t->from === $state,
            ),
        );
    }

    /**
     * Find the specific transition definition for a given (from, name) pair.
     * "name" here is the target state — transitions are identified by from→to.
     */
    public function find(string $fromState, string $toState): ?TransitionDefinition
    {
        foreach ($this->transitions as $transition) {
            if ($transition->from === $fromState && $transition->to === $toState) {
                return $transition;
            }
        }

        return null;
    }
}
