<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\DocumentEngine\Processors;

use ExpressCodeEngines\Shared\Contracts\DocumentProcessorInterface;

/**
 * Processes Markdown files (.md).
 * Strips Markdown syntax to return clean prose for embedding.
 */
final class MarkdownProcessor implements DocumentProcessorInterface
{
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['text/markdown', 'text/x-markdown'], true);
    }

    public function extract(string $source): string
    {
        $raw = is_file($source) ? (file_get_contents($source) ?: '') : $source;

        // Strip common Markdown syntax to leave clean prose
        $text = preg_replace('/#{1,6}\s+/', '', $raw);           // headings
        $text = preg_replace('/\*\*(.+?)\*\*/', '$1', $text);    // bold
        $text = preg_replace('/\*(.+?)\*/', '$1', $text);         // italic
        $text = preg_replace('/`{1,3}[^`]*`{1,3}/', '', $text);  // code
        $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text); // links
        $text = preg_replace('/!\[[^\]]*\]\([^\)]+\)/', '', $text);    // images
        $text = preg_replace('/^\s*[-*+]\s+/m', '', $text);       // list items

        return trim($text);
    }
}
