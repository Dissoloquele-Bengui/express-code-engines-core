<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;
use ExpressCodeEngines\Shared\ValueObjects\ChatResponse;
use ExpressCodeEngines\Shared\ValueObjects\DocumentResult;

/**
 * Splits extracted text into chunks for RAG indexing.
 */
interface DocumentChunkerInterface
{
    /**
     * @return DocumentChunk[]
     */
    public function chunk(string $text, string $documentId, array $meta = []): array;
}
