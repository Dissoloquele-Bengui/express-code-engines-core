<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\DocumentEngine\Processors;

use ExpressCodeEngines\Shared\Contracts\DocumentProcessorInterface;

/**
 * Processes plain text files (.txt).
 */
final class PlainTextProcessor implements DocumentProcessorInterface
{
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['text/plain', 'text/csv'], true);
    }

    public function extract(string $source): string
    {
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            return file_get_contents($source) ?: '';
        }

        return is_file($source) ? (file_get_contents($source) ?: '') : '';
    }
}
