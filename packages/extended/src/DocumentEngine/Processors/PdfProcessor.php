<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\DocumentEngine\Processors;

use ExpressCodeEngines\Shared\Contracts\DocumentProcessorInterface;

/**
 * Processes PDF files using the pdftotext CLI tool (poppler-utils).
 *
 * Falls back gracefully when pdftotext is not available:
 * returns an empty string and logs a warning rather than failing.
 *
 * For production: consider using a cloud OCR service (AWS Textract,
 * Google Document AI) via a custom DocumentProcessorInterface.
 */
final class PdfProcessor implements DocumentProcessorInterface
{
    public function supports(string $mimeType): bool
    {
        return $mimeType === 'application/pdf';
    }

    public function extract(string $source): string
    {
        if (! is_file($source)) {
            return '';
        }

        // Check if pdftotext is available
        exec('which pdftotext 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            \Illuminate\Support\Facades\try { Log::warning(
                '[PdfProcessor] pdftotext not found. Install poppler-utils for PDF text extraction.',
                ['file' => $source]
            ); } catch (\Throwable $__e) {}
            return '';
        }

        $escaped = escapeshellarg($source);
        $text    = shell_exec("pdftotext -layout {$escaped} -");

        return $text ? trim($text) : '';
    }
}
