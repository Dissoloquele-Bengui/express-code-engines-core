<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

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
