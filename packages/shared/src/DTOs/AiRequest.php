<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A single AI completion request.
 */
final class AiRequest
{
    public function __construct(
        /** The user prompt — always required */
        public readonly string $prompt,

        /**
         * System instructions that shape model behaviour.
         * Merged with any persona system prompt when set.
         */
        public readonly ?string $systemPrompt = null,

        /**
         * Named model configuration key registered in the AiEngine.
         * e.g. 'gpt-4o', 'claude-sonnet', 'ollama-mistral'
         * Null = use the default model.
         */
        public readonly ?string $model = null,

        /**
         * When set, forces the model to return valid JSON matching this schema.
         * The engine validates the response and retries on schema violation.
         * e.g. ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]
         */
        public readonly ?array $jsonSchema = null,

        /** Variables substituted into the prompt template: {{variable}} */
        public readonly array $variables = [],

        /** Maximum tokens in the completion */
        public readonly int $maxTokens = 1000,

        /** Sampling temperature 0.0–2.0 */
        public readonly float $temperature = 0.7,

        /**
         * Conversation history for multi-turn context.
         * @var array<array{role: 'user'|'assistant', content: string}>
         */
        public readonly array $messages = [],
    ) {}
}
