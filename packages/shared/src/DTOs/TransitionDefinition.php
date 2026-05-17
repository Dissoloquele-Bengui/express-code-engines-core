<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * Defines a single state transition in a workflow.
 *
 * Example (array form — used in config files):
 * [
 *     'from'         => 'draft',
 *     'to'           => 'submitted',
 *     'guards'       => ['role:editor', 'field:submitted_at:null'],
 *     'post_actions' => ['notify_reviewer', 'log_transition'],
 *     'metadata'     => ['label' => 'Submit for review'],
 * ]
 */
final class TransitionDefinition
{
    public function __construct(
        /** State the entity must currently be in */
        public readonly string $from,

        /** State the entity will move to if transition is allowed */
        public readonly string $to,

        /**
         * Guards that must ALL pass for the transition to be allowed.
         * Built-in guard formats:
         *   'role:admin'              — user must have this role
         *   'field:approved_by:null'  — entity field must equal value
         *   'callable:App\Guards\X'   — static method returning bool
         */
        public readonly array $guards = [],

        /**
         * Keys returned in TransitionResult::postActions for the Action to dispatch.
         * e.g. ['notify_reviewer', 'send_confirmation_email']
         */
        public readonly array $postActions = [],

        /** Extra metadata passed to TransitionResult (for audit logs, UI labels, etc.) */
        public readonly array $metadata = [],
    ) {}

    /**
     * Convenience factory from a plain config array.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            from:        $config['from'],
            to:          $config['to'],
            guards:      $config['guards']       ?? [],
            postActions: $config['post_actions'] ?? [],
            metadata:    $config['metadata']     ?? [],
        );
    }
}
