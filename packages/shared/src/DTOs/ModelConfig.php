<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * Configuration for a specific model call.
 * Decouples business logic from provider-specific model names.
 *
 * Example:
 *   new ModelConfig(provider: 'openai', model: 'gpt-4o', maxTokens: 1000)
 *   new ModelConfig(provider: 'anthropic', model: 'claude-sonnet-4-6', maxTokens: 2000)
 *   new ModelConfig(provider: 'ollama', model: 'llama3', maxTokens: 500)
 */
final class ModelConfig
{
    public function __construct(
        /** Provider key registered in the AiProviderRegistry */
        public readonly string $provider,

        /** Provider-specific model identifier */
        public readonly string $model,

        public readonly int   $maxTokens   = 1000,
        public readonly float $temperature = 0.2,

        /**
         * When true, forces the provider to return valid JSON.
         * The response content is guaranteed to be JSON-parseable.
         * Use with $jsonSchema to validate the structure.
         */
        public readonly bool  $jsonMode    = false,

        /**
         * JSON schema to validate the structured response against.
         * Only used when jsonMode is true.
         * e.g. ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]
         */
        public readonly ?array $jsonSchema  = null,
    ) {}

    public static function fromArray(array $config): self
    {
        return new self(
            provider:    $config['provider'],
            model:       $config['model'],
            maxTokens:   $config['max_tokens']   ?? 1000,
            temperature: $config['temperature']  ?? 0.2,
            jsonMode:    $config['json_mode']     ?? false,
            jsonSchema:  $config['json_schema']   ?? null,
        );
    }
}
