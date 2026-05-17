<?php
declare(strict_types=1);
namespace ExpressCodeEngines\Extended\DocumentEngine\Processors;
use ExpressCodeEngines\Shared\Contracts\DocumentProcessorInterface;
/** Extracts text from plain text files (.txt, .md, .csv). */
final class TextProcessor implements DocumentProcessorInterface
{
    public function supportedExtensions(): array { return ['txt', 'md', 'csv']; }
    public function extract(string $path): ?string
    {
        if (! file_exists($path)) { try { Log::warning('[TextProcessor] File not found.', ['path' => $path]); } catch (\Throwable $__e) {} return null; }
        $content = file_get_contents($path);
        return $content !== false ? $content : null;
    }
}
