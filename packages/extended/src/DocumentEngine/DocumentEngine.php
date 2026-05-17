<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\DocumentEngine;

use Illuminate\Support\Str;
use ExpressCodeEngines\Shared\Contracts\AiEngineInterface;
use ExpressCodeEngines\Shared\Contracts\DocumentChunkerInterface;
use ExpressCodeEngines\Shared\Contracts\DocumentEngineInterface;
use ExpressCodeEngines\Shared\Contracts\DocumentProcessorInterface;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;
use ExpressCodeEngines\Shared\ValueObjects\DocumentResult;

/**
 * Processes documents into text chunks for RAG retrieval.
 *
 * Pipeline per process() call:
 *   1. Resolve the correct processor by file extension
 *   2. Extract raw text (returns null on failure — non-fatal)
 *   3. Chunk the text via the configured chunker
 *   4. Generate embeddings via AiEngine (when available)
 *   5. Persist chunks to the DocumentChunkStore
 *   6. Return DocumentResult with id, chunk count and embedded flag
 *
 * Constraints:
 *   - Extraction failure does NOT block the operation — returns empty result
 *   - Embedding is optional — skipped when AiEngine is null or returns []
 *   - Always async in production — never call inline during uploads
 *   - The engine never persists the original file — only the chunks
 */
final class DocumentEngine implements DocumentEngineInterface
{
    /**
     * @param  DocumentProcessorInterface[]  $processors
     */
    public function __construct(
        private readonly array                   $processors,
        private readonly DocumentChunkerInterface $chunker,
        private readonly DocumentChunkStore      $store,
        private readonly ?AiEngineInterface      $ai            = null,
        private readonly string                  $embedProvider = 'openai',
    ) {}

    public function process(DocumentInput $input): DocumentResult
    {
        $documentId = $this->generateDocumentId($input);

        try {
            // Step 1: Select processor
            $extension = strtolower(pathinfo($input->source, PATHINFO_EXTENSION));
            $processor = $this->resolveProcessor($extension);

            if ($processor === null) {
                return DocumentResult::failed(
                    'UNSUPPORTED_FORMAT',
                    "No processor registered for extension '.{$extension}'.",
                );
            }

            // Step 2: Extract text
            $text = $processor->extract($input->source);

            if ($text === null) {
                return DocumentResult::failed(
                    'EXTRACTION_FAILED',
                    "Text extraction returned null for '{$input->source}'.",
                );
            }

            if (trim($text) === '') {
                try { Log::warning('[DocumentEngine] Empty text extracted.', [
                    'source'    => basename($input->source),
                    'extension' => $extension,
                ]); } catch (\Throwable $__e) {}
            }

            // Step 3: Chunk
            $meta   = array_merge($input->meta, [
                'entity_class' => $input->entityClass,
                'entity_id'    => $input->entityId,
            ]);

            $chunks = $this->chunker->chunk($text, $documentId, $meta);

            // Step 4: Embed (optional)
            $embedded = false;

            if ($this->ai !== null && ! empty($chunks)) {
                $chunks   = $this->generateEmbeddings($chunks);
                $embedded = ! empty(array_filter($chunks, fn (DocumentChunk $c) => $c->embedding !== null));
            }

            // Step 5: Persist
            $this->store->saveMany($chunks);

            try { Log::info('[DocumentEngine] Document processed.', [
                'document_id' => $documentId,
                'source'      => basename($input->source),
                'chunks'      => count($chunks),
                'embedded'    => $embedded,
            ]); } catch (\Throwable $__e) {}

            return DocumentResult::ok(
                documentId: $documentId,
                text:       $text,
                chunkCount: count($chunks),
                embedded:   $embedded,
                meta:       $meta,
            );

        } catch (\Throwable $e) {
            try { Log::error('[DocumentEngine] Processing failed.', [
                'source'    => $input->source,
                'exception' => $e->getMessage(),
            ]); } catch (\Throwable $__e) {}

            return DocumentResult::failed('PROCESSING_ERROR', $e->getMessage());
        }
    }

    /**
     * @param  string[]  $entityClasses
     * @return DocumentChunk[]
     */
    public function retrieve(string $query, array $entityClasses = [], int $topK = 5): array
    {
        // Use semantic search if AiEngine is available
        if ($this->ai !== null) {
            $embedding = $this->ai->embed($query, $this->embedProvider);

            if (! empty($embedding)) {
                $chunks = $this->store->findByEmbedding($embedding, $entityClasses, $topK);

                if (! empty($chunks)) {
                    return $chunks;
                }
            }
        }

        // Fallback: keyword search
        return $this->store->findByKeyword($query, $entityClasses, $topK);
    }

    public function delete(string $documentId): bool
    {
        return $this->store->deleteByDocumentId($documentId);
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    private function resolveProcessor(string $extension): ?DocumentProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if (in_array($extension, $processor->supportedExtensions(), true)) {
                return $processor;
            }
        }

        return null;
    }

    /**
     * @param  DocumentChunk[]  $chunks
     * @return DocumentChunk[]
     */
    private function generateEmbeddings(array $chunks): array
    {
        $result = [];

        foreach ($chunks as $chunk) {
            $embedding = $this->ai->embed($chunk->text, $this->embedProvider);

            $result[] = new DocumentChunk(
                text:       $chunk->text,
                index:      $chunk->index,
                documentId: $chunk->documentId,
                meta:       $chunk->meta,
                embedding:  ! empty($embedding) ? $embedding : null,
            );
        }

        return $result;
    }

    private function generateDocumentId(DocumentInput $input): string
    {
        return md5($input->source . $input->entityClass . $input->entityId . microtime());
    }
}
