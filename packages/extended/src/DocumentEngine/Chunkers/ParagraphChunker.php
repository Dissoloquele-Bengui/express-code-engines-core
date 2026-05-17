<?php
declare(strict_types=1);
namespace ExpressCodeEngines\Extended\DocumentEngine\Chunkers;
use ExpressCodeEngines\Shared\Contracts\DocumentChunkerInterface;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
/**
 * Splits text into chunks by paragraph boundaries.
 * Merges short paragraphs to reach target_size, splits oversized ones at sentences.
 */
final class ParagraphChunker implements DocumentChunkerInterface
{
    public function __construct(
        private readonly int $targetSize = 500,
        private readonly int $maxSize    = 1000,
        private readonly int $overlap    = 50,
    ) {}
    public function chunk(string $text, string $documentId, array $meta = []): array
    {
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $text))));
        if (empty($paragraphs)) return [];
        $chunks = []; $buffer = ''; $index = 0;
        foreach ($paragraphs as $para) {
            $candidate = $buffer === '' ? $para : $buffer . "\n\n" . $para;
            if (strlen($candidate) > $this->maxSize && $buffer !== '') {
                $new = $this->flush($buffer, $documentId, $meta, $index); $chunks = array_merge($chunks, $new); $index += count($new);
                $buffer = $para;
            } elseif (strlen($candidate) >= $this->targetSize) {
                $buffer = $candidate;
                $new = $this->flush($buffer, $documentId, $meta, $index); $chunks = array_merge($chunks, $new); $index++;
                $buffer = $this->overlap > 0 ? substr($buffer, -$this->overlap) : '';
            } else { $buffer = $candidate; }
        }
        if (trim($buffer) !== '' && strlen($buffer) > $this->overlap) {
            $chunks[] = new DocumentChunk(trim($buffer), $index, $documentId, $meta);
        }
        return $chunks;
    }
    private function flush(string $buf, string $docId, array $meta, int $idx): array
    {
        $text = trim($buf);
        if ($text === '') return [];
        if (strlen($text) <= $this->maxSize) return [new DocumentChunk($text, $idx, $docId, $meta)];
        $sentences = array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/', $text)));
        $chunks = []; $b = ''; $i = $idx;
        foreach ($sentences as $s) {
            $c = $b === '' ? $s : $b . ' ' . $s;
            if (strlen($c) >= $this->targetSize) {
                if ($b !== '') { $chunks[] = new DocumentChunk(trim($b), $i++, $docId, $meta); }
                $b = $s;
            } else { $b = $c; }
        }
        if (trim($b) !== '') $chunks[] = new DocumentChunk(trim($b), $i, $docId, $meta);
        return $chunks;
    }
}
