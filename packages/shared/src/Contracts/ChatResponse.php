<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;

/**
 * Result of a ChatEngine message — answer + citations + usage.
 */
final class ChatResponse
{
    /**
     * @param  array<array{id: string, text: string, source: string}>  $sources
     */
    public function __construct(
        public readonly bool   $success,
        public readonly string $answer,
        public readonly array  $sources      = [],  // cited chunks/documents
        public readonly array  $usage        = [],  // tokens, cost
        public readonly ?string $errorCode   = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function ok(string $answer, array $sources = [], array $usage = []): self
    {
        return new self(success: true, answer: $answer, sources: $sources, usage: $usage);
    }

    public static function failed(string $code, string $message): self
    {
        return new self(success: false, answer: '', errorCode: $code, errorMessage: $message);
    }

    public function isFailed(): bool { return ! $this->success; }

    public function hasSources(): bool { return ! empty($this->sources); }
}
