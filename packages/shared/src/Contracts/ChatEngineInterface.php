<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;

/**
 * Contextual assistant with Retrieval-Augmented Generation (RAG).
 *
 * Pipeline per message:
 *   1. Load conversation history from session store
 *   2. Retrieve relevant context via SearchEngine (structured data)
 *   3. Retrieve relevant chunks via DocumentEngine (document vectors)
 *   4. Apply DynamicPolicyEngine to filter context by user permissions
 *   5. Build prompt: persona system + context + history + user message
 *   6. Call AiEngine::complete()
 *   7. Extract citations from retrieved sources
 *   8. Persist assistant reply to session store
 *   9. Return ChatResponse with answer + sources + usage
 *
 * RAG is MANDATORY for business data — the engine never answers from
 * model knowledge alone when context retrieval is configured.
 */
interface ChatEngineInterface
{
    public function chat(ChatMessage $message): ChatResponse;

    /**
     * Clear session history for a given session ID.
     */
    public function clearSession(string $sessionId): void;
}
