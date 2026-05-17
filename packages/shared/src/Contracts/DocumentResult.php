<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;

/**
 * Result of document processing — text chunks ready for indexing.
 */
final class DocumentResult
{
    /**
     * @param  DocumentChunk[]  $chunks
     */
    public function __construct(
        public readonly bool   $success,
        public readonly array  $chunks       = [],
        public readonly string $rawText      = '',
        public readonly int    $pageCount    = 0,
        public readonly array  $meta         = [],
        public readonly ?string $errorCode   = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function ok(array $chunks, string $rawText, int $pageCount = 0, array $meta = []): self
    {
        return new self(success: true, chunks: $chunks, rawText: $rawText, pageCount: $pageCount, meta: $meta);
    }

    public static function failed(string $code, string $message): self
    {
        return new self(success: false, errorCode: $code, errorMessage: $message);
    }

    public function isFailed(): bool { return ! $this->success; }

    public function chunkCount(): int { return count($this->chunks); }
}
