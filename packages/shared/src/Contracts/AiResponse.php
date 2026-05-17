<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;

/**
 * Result of a single AI completion.
 */
final class AiResponse
{
    public function __construct(
        public readonly bool    $success,
        public readonly ?string $content       = null,
        public readonly ?array  $structured    = null, // parsed JSON when jsonSchema was set
        public readonly ?string $errorCode     = null,
        public readonly ?string $errorMessage  = null,
        public readonly string  $provider      = '',
        public readonly string  $model         = '',
        public readonly int     $promptTokens  = 0,
        public readonly int     $completionTokens = 0,
        public readonly float   $estimatedCost = 0.0,
    ) {}

    public static function ok(
        string  $content,
        string  $provider,
        string  $model,
        int     $promptTokens,
        int     $completionTokens,
        float   $estimatedCost = 0.0,
        ?array  $structured    = null,
    ): self {
        return new self(
            success:          true,
            content:          $content,
            structured:       $structured,
            provider:         $provider,
            model:            $model,
            promptTokens:     $promptTokens,
            completionTokens: $completionTokens,
            estimatedCost:    $estimatedCost,
        );
    }

    public static function failed(string $code, string $message): self
    {
        return new self(success: false, errorCode: $code, errorMessage: $message);
    }

    public function isFailed(): bool { return ! $this->success; }

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
