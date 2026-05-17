<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A single text chunk produced by the DocumentEngine.
 */
final class DocumentChunk
{
    public function __construct(
        public readonly string      $id,
        public readonly string      $text,
        public readonly int         $index,        // chunk position in the document
        public readonly array       $meta  = [],   // page number, section, etc.
        public readonly ?string     $embedding = null, // base64 or serialised vector
    ) {}
}
