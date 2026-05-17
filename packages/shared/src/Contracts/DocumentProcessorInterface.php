<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;

/**
 * A document text processor for a specific MIME type.
 * e.g. PdfProcessor, DocxProcessor, ImageOcrProcessor
 */
interface DocumentProcessorInterface
{
    public function supports(string $mimeType): bool;

    public function extract(string $source): string;
}
