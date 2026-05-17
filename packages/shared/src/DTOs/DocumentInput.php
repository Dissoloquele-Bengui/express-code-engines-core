<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A document submitted for processing.
 */
final class DocumentInput
{
    public function __construct(
        /**
         * Absolute file path or URL to the document.
         * Supported: PDF, DOCX, TXT, MD, HTML, images (PNG/JPG for OCR)
         */
        public readonly string $source,

        /** MIME type — used to select the correct processor */
        public readonly string $mimeType,

        /** Entity this document belongs to (for context + policy filtering) */
        public readonly ?string $entityClass = null,
        public readonly int|string|null $entityId = null,

        /** Chunking strategy: 'page', 'paragraph', 'fixed', 'semantic' */
        public readonly string $chunkStrategy = 'paragraph',

        /** Target chunk size in tokens (for 'fixed' and 'semantic' strategies) */
        public readonly int $chunkSize = 512,

        /** Token overlap between consecutive chunks */
        public readonly int $chunkOverlap = 64,

        /** Arbitrary metadata stored alongside the document */
        public readonly array $meta = [],
    ) {}
}
