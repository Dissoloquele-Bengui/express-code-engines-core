<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

final class TransitionResult
{
    private function __construct(
        public readonly bool   $allowed,
        public readonly ?string $newState    = null,

        /**
         * Actions the Action/Listener should execute after persisting the new state.
         * e.g. ['send_approval_email', 'notify_manager']
         * @var string[]
         */
        public readonly array  $postActions  = [],

        /** Structured metadata (timestamp, actor, reason) for the audit log */
        public readonly array  $metadata     = [],

        public readonly ?string $denialReason = null,
    ) {}

    public static function allow(string $newState, array $postActions = [], array $metadata = []): self
    {
        return new self(
            allowed:     true,
            newState:    $newState,
            postActions: $postActions,
            metadata:    $metadata,
        );
    }

    public static function deny(string $reason): self
    {
        return new self(allowed: false, denialReason: $reason);
    }

    public function wasAllowed(): bool
    {
        return $this->allowed;
    }
}
