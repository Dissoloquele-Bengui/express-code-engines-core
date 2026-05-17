<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A single message in a conversation history.
 * Used in multi-turn completions and ChatEngine sessions.
 */
final class AiMessage
{
    public function __construct(
        /** 'system' | 'user' | 'assistant' */
        public readonly string $role,
        public readonly string $content,
    ) {}

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
