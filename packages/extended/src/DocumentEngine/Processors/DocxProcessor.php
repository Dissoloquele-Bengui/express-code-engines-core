<?php
declare(strict_types=1);
namespace ExpressCodeEngines\Extended\DocumentEngine\Processors;
use ExpressCodeEngines\Shared\Contracts\DocumentProcessorInterface;
/** Extracts text from DOCX files. Requires phpoffice/phpword. */
final class DocxProcessor implements DocumentProcessorInterface
{
    public function supportedExtensions(): array { return ['docx']; }
    public function extract(string $path): ?string
    {
        if (! file_exists($path)) { try { Log::warning('[DocxProcessor] File not found.', ['path' => $path]); } catch (\Throwable $__e) {} return null; }
        if (! class_exists(\PhpOffice\PhpWord\IOFactory::class)) { try { Log::warning('[DocxProcessor] phpoffice/phpword not installed.'); } catch (\Throwable $__e) {} return null; }
        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($path); $parts = [];
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) $parts[] = $element->getText();
                }
            }
            return implode("\n", array_filter($parts));
        } catch (\Throwable $e) { try { Log::error('[DocxProcessor] Failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {} return null; }
    }
}
