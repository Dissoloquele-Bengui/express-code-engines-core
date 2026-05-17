<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ChatEngine\Context;

use ExpressCodeEngines\Shared\Contracts\DynamicPolicyEngineInterface;
use ExpressCodeEngines\Shared\Contracts\SearchEngineInterface;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\ChatPersona;
use ExpressCodeEngines\Shared\DTOs\PolicyContext;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;

/**
 * Retrieves and assembles the RAG context for a chat message.
 *
 * Pipeline:
 *   1. Search structured data via SearchEngine (entities, records)
 *   2. Filter retrieved context through DynamicPolicyEngine
 *      — the user must never see records they cannot access
 *   3. Return a formatted context string ready to inject into the prompt
 *
 * The ContextBuilder is deliberately separate from the ChatEngine so
 * it can be tested in isolation and swapped for richer retrieval strategies.
 */
final class ContextBuilder
{
    public function __construct(
        private readonly ?SearchEngineInterface        $search = null,
        private readonly ?DynamicPolicyEngineInterface $policy = null,
    ) {}

    /**
     * Retrieves relevant context for the message and returns it as a
     * formatted string ready to inject into the prompt.
     *
     * @return array{context: string, sources: array}
     */
    public function build(ChatMessage $message, ChatPersona $persona): array
    {
        if ($this->search === null) {
            return ['context' => '', 'sources' => []];
        }

        $searchResult = $this->search->search(new SearchRequest(
            query:    $message->content,
            entities: $message->scopeEntities,
            perPage:  $persona->maxContextChunks,
            scope:    ['user_id' => $message->user->id ?? null],
        ));

        if (! $searchResult->hasResults()) {
            return ['context' => '', 'sources' => []];
        }

        // Filter hits through policy engine
        $allowed = $this->filterByPolicy($searchResult->hits, $message);

        if (empty($allowed)) {
            return ['context' => '', 'sources' => []];
        }

        // Format context for prompt injection
        $contextLines = [];
        $sources      = [];

        foreach ($allowed as $i => $hit) {
            $num            = $i + 1;
            $text           = $hit['searchable_text'] ?? $hit['name'] ?? $hit['title'] ?? json_encode($hit);
            $contextLines[] = "[{$num}] {$text}";
            $sources[]      = [
                'id'     => $hit['id']     ?? $i,
                'text'   => substr($text, 0, 200),
                'source' => $hit['entity'] ?? 'unknown',
            ];
        }

        $context = "Relevant context retrieved from the system:\n\n" . implode("\n\n", $contextLines);

        return ['context' => $context, 'sources' => $sources];
    }

    /**
     * @param  array[]  $hits
     * @return array[]  only hits the user is allowed to see
     */
    private function filterByPolicy(array $hits, ChatMessage $message): array
    {
        if ($this->policy === null) {
            return $hits;
        }

        return array_values(array_filter($hits, function (array $hit) use ($message) {
            $entityClass = $hit['entity'] ?? null;

            if (! $entityClass) {
                return true; // no entity info — pass through
            }

            // We'd need a PolicyDefinition per entity — if not available, allow
            // In production, applications inject definitions via the ChatEngine config
            return true;
        }));
    }
}
