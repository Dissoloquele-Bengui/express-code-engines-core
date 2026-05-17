<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

/**
 * The result of processing a document through the DocumentEngine.
 */
final class DocumentResult
{
    private function __construct(
        public readonly bool    $success,

        /** Unique document identifier (stored for RAG lookups) */
        public readonly ?string $documentId  = null,

        /** Extracted plain text from the document */
        public readonly ?string $text        = null,

        /** Number of chunks produced */
        public readonly int     $chunkCount  = 0,

        /** Whether embeddings were generated for the chunks */
        public readonly bool    $embedded    = false,

        public readonly array   $meta        = [],

        public readonly ?string $errorCode   = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function ok(
        string $documentId,
        string $text,
        int    $chunkCount,
        bool   $embedded  = false,
        array  $meta      = [],
    ): self {
        return new self(
            success:    true,
            documentId: $documentId,
            text:       $text,
            chunkCount: $chunkCount,
            embedded:   $embedded,
            meta:       $meta,
        );
    }

    public static function failed(string $code, string $message): self
    {
        return new self(success: false, errorCode: $code, errorMessage: $message);
    }

    public function isFailed(): bool { return ! $this->success; }
}
