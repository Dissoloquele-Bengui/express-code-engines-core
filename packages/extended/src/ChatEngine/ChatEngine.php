<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ChatEngine;

use ExpressCodeEngines\Shared\Contracts\AiEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ChatEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ChatMemoryInterface;
use ExpressCodeEngines\Shared\Contracts\DocumentEngineInterface;
use ExpressCodeEngines\Shared\DTOs\AiMessage;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\ValueObjects\ChatResponse;

/**
 * Contextual AI assistant with RAG, session memory and citation support.
 *
 * Pipeline per chat() call:
 *   1. Resolve persona from registry
 *   2. Load session history from memory store
 *   3. If ragEnabled: retrieve relevant document chunks via DocumentEngine
 *   4. Build prompt: system + RAG context + history + user message
 *   5. Call AiEngine::complete()
 *   6. Extract citations from retrieved chunks referenced in the answer
 *   7. Persist user + assistant messages to session history
 *   8. Return ChatResponse with answer, sources and usage stats
 *
 * RAG is enforced when persona.ragEnabled = true.
 * Responses that bypass RAG for business-data personas are not permitted.
 *
 * The ChatEngine is stateless per call — all state lives in the memory store
 * (session history) and the document chunk store (RAG context).
 */
final class ChatEngine implements ChatEngineInterface
{
    public function __construct(
        private readonly AiEngineInterface       $ai,
        private readonly DocumentEngineInterface $documents,
        private readonly ChatMemoryInterface     $memory,
        private readonly PersonaRegistry         $personas,

        /**
         * Maximum characters of RAG context to include in the prompt.
         * Prevents context window overflow on long documents.
         */
        private readonly int $maxContextChars = 4000,
    ) {}

    public function chat(ChatMessage $message): ChatResponse
    {
        $persona = $this->personas->findOrDefault($message->persona);

        // Step 1: Load history
        $history = $this->memory->load($message->sessionId);

        // Step 2: RAG retrieval
        $chunks   = [];
        $ragContext = '';

        if ($persona->ragEnabled) {
            $chunks     = $this->documents->retrieve(
                $message->content,
                array_merge($persona->ragEntities, $message->scope ? [] : []),
                $persona->ragTopK,
            );
            $ragContext  = $this->buildRagContext($chunks);
        }

        // Step 3: Compose messages
        $messages = $this->composeMessages(
            systemPrompt: $persona->systemPrompt,
            ragContext:   $ragContext,
            history:      $history,
            userMessage:  $message->content,
        );

        // Step 4: Call AiEngine
        $aiRequest = new AiRequest(model: $persona->model, messages: $messages);
        $aiResponse = $this->ai->complete($aiRequest);

        if ($aiResponse->failed()) {
            try { Log::error('[ChatEngine] AiEngine call failed.', [
                'session'  => $message->sessionId,
                'persona'  => $message->persona,
                'error'    => $aiResponse->errorMessage,
            ]); } catch (\Throwable $__e) {}

            return ChatResponse::failed(
                $message->sessionId,
                $aiResponse->errorCode ?? 'AI_ERROR',
                $aiResponse->errorMessage ?? 'AI provider unavailable.',
            );
        }

        $answer = $aiResponse->content ?? '';

        // Step 5: Extract citations
        $sources = $persona->citeSources
            ? $this->extractCitations($answer, $chunks)
            : [];

        // Step 6: Persist to memory
        $this->memory->append($message->sessionId, AiMessage::user($message->content));
        $this->memory->append($message->sessionId, AiMessage::assistant($answer));

        try { Log::info('[ChatEngine] Response generated.', [
            'session'       => $message->sessionId,
            'persona'       => $message->persona,
            'chunks_used'   => count($chunks),
            'sources_cited' => count($sources),
            'input_tokens'  => $aiResponse->inputTokens,
            'output_tokens' => $aiResponse->outputTokens,
        ]); } catch (\Throwable $__e) {}

        return ChatResponse::ok(
            answer:        $answer,
            sessionId:     $message->sessionId,
            persona:       $message->persona,
            sources:       $sources,
            inputTokens:   $aiResponse->inputTokens,
            outputTokens:  $aiResponse->outputTokens,
            estimatedCost: $aiResponse->estimatedCost,
        );
    }

    public function clearSession(string $sessionId): void
    {
        $this->memory->clear($sessionId);
    }

    public function history(string $sessionId): array
    {
        return $this->memory->load($sessionId);
    }

    // ──────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Builds the RAG context block from retrieved chunks.
     * Truncates to maxContextChars to prevent token overflow.
     */
    private function buildRagContext(array $chunks): string
    {
        if (empty($chunks)) {
            return '';
        }

        $parts = [];
        $totalChars = 0;

        foreach ($chunks as $i => $chunk) {
            $entry = "[Source {$chunk->documentId}#{$chunk->index}]\n{$chunk->text}";

            if ($totalChars + strlen($entry) > $this->maxContextChars) {
                break;
            }

            $parts[] = $entry;
            $totalChars += strlen($entry);
        }

        return implode("\n\n---\n\n", $parts);
    }

    /**
     * @param  AiMessage[]  $history
     * @return AiMessage[]
     */
    private function composeMessages(
        string $systemPrompt,
        string $ragContext,
        array  $history,
        string $userMessage,
    ): array {
        $fullSystemPrompt = $systemPrompt;

        if ($ragContext !== '') {
            $fullSystemPrompt .= "\n\n## Context from knowledge base\n\nUse the following context to answer the user's question. Cite sources using the format [Source documentId#chunkIndex] inline in your answer when relevant.\n\n" . $ragContext;
        }

        $messages = [AiMessage::system($fullSystemPrompt)];

        // Include history (excluding any existing system messages)
        foreach ($history as $msg) {
            if ($msg->role !== 'system') {
                $messages[] = $msg;
            }
        }

        $messages[] = AiMessage::user($userMessage);

        return $messages;
    }

    /**
     * Extracts source citations from the answer text.
     * Looks for [Source documentId#chunkIndex] patterns.
     *
     * @param  DocumentChunk[]  $chunks
     * @return array<array{document_id: string, chunk_index: int, excerpt: string}>
     */
    private function extractCitations(string $answer, array $chunks): array
    {
        if (empty($chunks)) {
            return [];
        }

        preg_match_all('/\[Source\s+([^\]#]+)#(\d+)\]/', $answer, $matches);

        if (empty($matches[0])) {
            return [];
        }

        // Build a lookup map from retrieved chunks
        $chunkMap = [];
        foreach ($chunks as $chunk) {
            $chunkMap["{$chunk->documentId}#{$chunk->index}"] = $chunk;
        }

        $cited = [];
        $seen  = [];

        for ($i = 0; $i < count($matches[0]); $i++) {
            $docId  = trim($matches[1][$i]);
            $idx    = (int) $matches[2][$i];
            $key    = "{$docId}#{$idx}";

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $chunk      = $chunkMap[$key] ?? null;

            $cited[] = [
                'document_id' => $docId,
                'chunk_index' => $idx,
                'excerpt'     => $chunk ? substr($chunk->text, 0, 200) : '',
            ];
        }

        return $cited;
    }
}
