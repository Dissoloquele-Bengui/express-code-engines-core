<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\DocumentEngine\Processors;

use ExpressCodeEngines\Shared\Contracts\DocumentProcessorInterface;

/**
 * Processes HTML files by stripping tags.
 */
final class HtmlProcessor implements DocumentProcessorInterface
{
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, ['text/html', 'application/xhtml+xml'], true);
    }

    public function extract(string $source): string
    {
        $html = is_file($source) ? (file_get_contents($source) ?: '') : $source;

        // Remove scripts, styles and comments
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si',  '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
