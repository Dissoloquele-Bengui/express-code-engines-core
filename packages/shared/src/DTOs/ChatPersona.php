<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A persona — system prompt + model + behaviour config.
 */
final class ChatPersona
{
    public function __construct(
        public readonly string  $key,
        public readonly string  $systemPrompt,
        public readonly string  $modelKey,          // references AiModelConfig::key
        public readonly int     $maxHistoryMessages = 10,
        public readonly int     $maxContextChunks   = 5,
        public readonly bool    $citeSources        = true,
        public readonly float   $temperature        = 0.4,
    ) {}

    public static function fromArray(string $key, array $config): self
    {
        return new self(
            key:                $key,
            systemPrompt:       $config['system_prompt'],
            modelKey:           $config['model']                  ?? 'default',
            maxHistoryMessages: $config['max_history_messages']   ?? 10,
            maxContextChunks:   $config['max_context_chunks']     ?? 5,
            citeSources:        $config['cite_sources']           ?? true,
            temperature:        $config['temperature']            ?? 0.4,
        );
    }
}
