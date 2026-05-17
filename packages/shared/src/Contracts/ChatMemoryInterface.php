<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;
use ExpressCodeEngines\Shared\ValueObjects\ChatResponse;
use ExpressCodeEngines\Shared\ValueObjects\DocumentResult;

/**
 * Persists and retrieves chat session history.
 * Implementations: cache-based (default), database, Redis.
 */
interface ChatMemoryInterface
{
    /** @return \ExpressCodeEngines\Shared\DTOs\AiMessage[] */
    public function load(string $sessionId): array;

    /** @param \ExpressCodeEngines\Shared\DTOs\AiMessage[] $messages */
    public function save(string $sessionId, array $messages): void;

    public function append(string $sessionId, \ExpressCodeEngines\Shared\DTOs\AiMessage $message): void;

    public function clear(string $sessionId): void;
}
