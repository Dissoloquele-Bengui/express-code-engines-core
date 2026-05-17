<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\DocumentEngine;

use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;

/**
 * DocumentChunkStore using MySQL 9.0+ native VECTOR type.
 *
 * MySQL 9.0 introduced native vector storage and similarity search.
 * This provides an alternative to pgvector for teams using MySQL/MariaDB.
 *
 * Prerequisites:
 *   MySQL >= 9.0.0
 *
 * Migration:
 *   $table->id();
 *   $table->string('document_id')->index();
 *   $table->unsignedInteger('chunk_index');
 *   $table->longText('text');
 *   $table->fullText('text');                              ← for keyword fallback
 *   $table->vector('embedding', 384)->nullable();         ← dimension must match your model
 *   $table->json('meta')->nullable();
 *   $table->timestamps();
 *   $table->unique(['document_id', 'chunk_index']);
 *
 * Vector index (MySQL 9.0):
 *   DB::statement('ALTER TABLE document_chunks ADD VECTOR INDEX idx_embedding (embedding)');
 *   -- or in migration: $table->vectorIndex('embedding');  (Laravel 11.x+)
 *
 * Supported functions:
 *   VECTOR_DISTANCE(v1, v2, 'COSINE')   — cosine distance (lower = more similar)
 *   VECTOR_DISTANCE(v1, v2, 'EUCLIDEAN') — Euclidean distance
 *   STRING_TO_VECTOR('[0.1,0.2,...]')    — parse vector from string
 *   VECTOR_TO_STRING(embedding)          — serialise vector to string
 *
 * Dimension note:
 *   Set $dimensions to match your embedding model:
 *   - OpenAI text-embedding-3-small:     1536
 *   - BAAI/bge-small-en-v1.5:            384
 *   - BAAI/bge-base-en-v1.5:             768
 *   - all-MiniLM-L6-v2:                  384
 *   - nomic-embed-text (Ollama):         768
 */
final class MySqlVectorChunkStore extends DocumentChunkStore
{
    public function __construct(
        private readonly string $table      = 'document_chunks',
        private readonly int    $dimensions = 384,
    ) {}

    /**
     * @param  DocumentChunk[]  $chunks
     */
    public function saveMany(array $chunks): void
    {
        if (empty($chunks)) {
            return;
        }

        foreach ($chunks as $chunk) {
            try {
                $embeddingExpr = $chunk->embedding !== null
                    ? DB::raw("STRING_TO_VECTOR('" . $this->formatVector($chunk->embedding) . "')")
                    : null;

                DB::table($this->table)->upsert(
                    [[
                        'document_id' => $chunk->documentId,
                        'chunk_index' => $chunk->index,
                        'text'        => $chunk->text,
                        'embedding'   => $embeddingExpr,
                        'meta'        => json_encode($chunk->meta),
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]],
                    uniqueBy: ['document_id', 'chunk_index'],
                    update:   ['text', 'embedding', 'meta', 'updated_at'],
                );
            } catch (\Throwable $e) {
                try { Log::error('[MySqlVectorChunkStore] Save failed.', [
                    'document_id' => $chunk->documentId,
                    'error'       => $e->getMessage(),
                ]); } catch (\Throwable $__e) {}
            }
        }
    }

    /**
     * Cosine similarity search using MySQL's VECTOR_DISTANCE function.
     *
     * MySQL 9.0 vector index automatically used when the query matches the indexed column.
     * For approximate nearest neighbour, ensure the VECTOR INDEX is created.
     *
     * @param  float[]   $queryEmbedding
     * @param  string[]  $entityClasses
     * @return DocumentChunk[]
     */
    public function findByEmbedding(array $queryEmbedding, array $entityClasses = [], int $topK = 5): array
    {
        if (empty($queryEmbedding)) {
            return [];
        }

        try {
            $vectorLiteral = $this->formatVector($queryEmbedding);

            $query = DB::table($this->table)
                ->selectRaw(
                    "*, VECTOR_DISTANCE(embedding, STRING_TO_VECTOR(?), 'COSINE') AS distance",
                    [$vectorLiteral],
                )
                ->whereNotNull('embedding')
                ->orderBy('distance')
                ->limit($topK);

            if (! empty($entityClasses)) {
                $query->where(function ($q) use ($entityClasses) {
                    foreach ($entityClasses as $class) {
                        $q->orWhereRaw("JSON_EXTRACT(meta, '$.entity_class') = ?", [$class]);
                    }
                });
            }

            $rows = $query->get();

            return $rows->map(function (object $row): DocumentChunk {
                $meta = json_decode($row->meta ?? '{}', true) ?? [];
                return new DocumentChunk(
                    text:       $row->text,
                    index:      $row->chunk_index,
                    documentId: $row->document_id,
                    meta:       $meta,
                );
            })->all();

        } catch (\Throwable $e) {
            try { Log::error('[MySqlVectorChunkStore] Vector search failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return $this->findByKeyword('', $entityClasses, $topK);
        }
    }

    /**
     * Full-text keyword search using MySQL MATCH AGAINST.
     * Requires a FULLTEXT index on the 'text' column.
     *
     * @param  string[]  $entityClasses
     * @return DocumentChunk[]
     */
    public function findByKeyword(string $query, array $entityClasses = [], int $topK = 5): array
    {
        if (trim($query) === '') {
            return [];
        }

        try {
            // MySQL FULLTEXT natural language search
            $dbQuery = DB::table($this->table)
                ->selectRaw("*, MATCH(text) AGAINST(? IN NATURAL LANGUAGE MODE) AS relevance", [$query])
                ->whereRaw("MATCH(text) AGAINST(? IN NATURAL LANGUAGE MODE)", [$query])
                ->orderByDesc('relevance')
                ->limit($topK);

            if (! empty($entityClasses)) {
                $dbQuery->where(function ($q) use ($entityClasses) {
                    foreach ($entityClasses as $class) {
                        $q->orWhereRaw("JSON_EXTRACT(meta, '$.entity_class') = ?", [$class]);
                    }
                });
            }

            $rows = $dbQuery->get();

            return $rows->map(function (object $row): DocumentChunk {
                $meta = json_decode($row->meta ?? '{}', true) ?? [];
                return new DocumentChunk(
                    text:       $row->text,
                    index:      $row->chunk_index,
                    documentId: $row->document_id,
                    meta:       $meta,
                );
            })->all();

        } catch (\Throwable $e) {
            try { Log::error('[MySqlVectorChunkStore] Keyword search failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return [];
        }
    }

    public function deleteByDocumentId(string $documentId): bool
    {
        try {
            DB::table($this->table)->where('document_id', $documentId)->delete();
            return true;
        } catch (\Throwable $e) {
            try { Log::error('[MySqlVectorChunkStore] Delete failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return false;
        }
    }

    /**
     * Formats a float array as a MySQL vector string: '[0.1,0.2,0.3]'
     * Compatible with STRING_TO_VECTOR() function.
     *
     * @param  float[]|string  $vector  float array or JSON string
     */
    private function formatVector(array|string $vector): string
    {
        if (is_string($vector)) {
            // Already a JSON string from storage
            return $vector;
        }

        return '[' . implode(',', array_map(
            fn (float $v) => number_format($v, 8, '.', ''),
            $vector,
        )) . ']';
    }
}
