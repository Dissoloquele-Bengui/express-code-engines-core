<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;

/**
 * Processes documents into searchable, embeddable chunks.
 *
 * Pipeline: ingest → extract text → chunk → (embed) → return chunks
 *
 * Always async in production — never blocks file uploads.
 * OCR failures do not prevent the original file from being stored.
 */
interface DocumentEngineInterface
{
    /**
     * Process a document and return its chunks.
     * Embedding is optional — only generated when AiEngine is available.
     */
    public function process(DocumentInput $input): DocumentResult;

    /**
     * Extract raw text from a document without chunking.
     * Useful for quick summaries or pre-processing.
     */
    public function extractText(DocumentInput $input): string;
}
