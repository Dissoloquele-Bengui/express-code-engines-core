<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;

/**
 * Stores and retrieves conversation history.
 * Applications implement this interface against their preferred store
 * (cache, database, Redis, etc.).
 */
interface SessionStoreInterface
{
    /**
     * @return array<array{role: 'user'|'assistant', content: string}>
     */
    public function load(string $sessionId): array;

    /**
     * @param  array<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function save(string $sessionId, array $messages): void;

    public function clear(string $sessionId): void;
}
