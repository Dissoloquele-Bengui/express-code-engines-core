<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A configured AI model — provider + model name + defaults.
 */
final class AiModelConfig
{
    public function __construct(
        public readonly string  $key,
        public readonly string  $provider,     // 'openai', 'anthropic', 'ollama', 'azure'
        public readonly string  $model,        // e.g. 'gpt-4o', 'claude-3-5-sonnet-20241022'
        public readonly int     $maxTokens    = 2000,
        public readonly float   $temperature  = 0.7,
        public readonly ?string $baseUrl      = null, // for Ollama / Azure custom endpoints
        public readonly ?string $apiKeyEnv    = null, // env var holding the API key
    ) {}

    public static function fromArray(string $key, array $config): self
    {
        return new self(
            key:         $key,
            provider:    $config['provider'],
            model:       $config['model'],
            maxTokens:   $config['max_tokens']  ?? 2000,
            temperature: $config['temperature'] ?? 0.7,
            baseUrl:     $config['base_url']    ?? null,
            apiKeyEnv:   $config['api_key_env'] ?? null,
        );
    }
}
