<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

/**
 * The result of a single AiEngine call.
 * Always returned — never throws on provider failure.
 */
final class AiResponse
{
    private function __construct(
        public readonly bool    $success,

        /** The raw text content from the provider */
        public readonly ?string $content      = null,

        /**
         * Parsed structured data when jsonMode was enabled.
         * Null if parsing failed or jsonMode was false.
         */
        public readonly ?array  $data         = null,

        public readonly string  $provider     = '',
        public readonly string  $model        = '',

        /** Token usage for cost tracking */
        public readonly int     $inputTokens  = 0,
        public readonly int     $outputTokens = 0,

        /** Estimated cost in USD (based on provider pricing config) */
        public readonly float   $estimatedCost = 0.0,

        public readonly ?string $errorCode    = null,
        public readonly ?string $errorMessage = null,

        /** Which attempt succeeded (1 = first try) */
        public readonly int     $attempts     = 1,
    ) {}

    public static function ok(
        string  $content,
        string  $provider,
        string  $model,
        int     $inputTokens,
        int     $outputTokens,
        float   $estimatedCost = 0.0,
        ?array  $data          = null,
        int     $attempts      = 1,
    ): self {
        return new self(
            success:       true,
            content:       $content,
            data:          $data,
            provider:      $provider,
            model:         $model,
            inputTokens:   $inputTokens,
            outputTokens:  $outputTokens,
            estimatedCost: $estimatedCost,
            attempts:      $attempts,
        );
    }

    public static function failed(string $code, string $message, int $attempts = 1): self
    {
        return new self(
            success:      false,
            errorCode:    $code,
            errorMessage: $message,
            attempts:     $attempts,
        );
    }

    public function isFailed(): bool  { return ! $this->success; }
    public function totalTokens(): int { return $this->inputTokens + $this->outputTokens; }
}
