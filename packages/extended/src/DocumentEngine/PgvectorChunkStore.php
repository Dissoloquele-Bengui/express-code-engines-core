<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\DocumentEngine;

use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;

/**
 * DocumentChunkStore backed by PostgreSQL + pgvector extension.
 *
 * Supports two ANN index strategies:
 *
 *   HNSW  (default) — Hierarchical Navigable Small World
 *     Best for: up to ~10M vectors, low latency, high recall
 *     Build time: fast. Memory: higher. Query: always fast.
 *     CREATE INDEX ON document_chunks USING hnsw (embedding vector_cosine_ops)
 *       WITH (m = 16, ef_construction = 64);
 *
 *   IVFFlat          — Inverted File Index with Flat compression
 *     Best for: > 10M vectors, lower memory, acceptable recall
 *     Requires: table must be populated BEFORE creating the index
 *     Rule of thumb: lists = sqrt(total_rows)
 *     CREATE INDEX ON document_chunks USING ivfflat (embedding vector_cosine_ops)
 *       WITH (lists = 100);
 *
 * Index selection:
 *   <= 10M vectors  → HNSW (better recall, lower latency)
 *   > 10M vectors   → IVFFlat (lower memory, configurable recall via probes)
 *   < 100K vectors  → no index (exact brute-force is faster below this threshold)
 *
 * Runtime tuning:
 *   HNSW:    SET hnsw.ef_search = 100;    (higher = better recall, slower query)
 *   IVFFlat: SET ivfflat.probes = 10;     (higher = better recall, slower query)
 *
 * Configuration:
 *   $this->app->bind(DocumentChunkStore::class, fn () =>
 *       new PgvectorChunkStore(
 *           table:      'document_chunks',
 *           dimensions: 1536,
 *           indexType:  'hnsw',
 *           efSearch:   100,   // HNSW runtime recall tuning
 *           probes:     10,    // IVFFlat runtime recall tuning
 *       )
 *   );
 */
final class PgvectorChunkStore extends DocumentChunkStore
{
    public function __construct(
        private readonly string $table      = 'document_chunks',
        private readonly int    $dimensions = 1536,

        /**
         * ANN index strategy to use at query time.
         * 'hnsw'    — for up to ~10M vectors (default, recommended)
         * 'ivfflat' — for > 10M vectors
         * 'exact'   — brute force, no index (best for < 100K vectors)
         */
        private readonly string $indexType  = 'hnsw',

        /**
         * HNSW ef_search parameter (query time).
         * Controls recall/speed tradeoff for HNSW index.
         * Range: 1–1000. Default 40 (pgvector default).
         * 100 = good recall. 200 = high recall, slower queries.
         */
        private readonly int    $efSearch   = 100,

        /**
         * IVFFlat probes parameter (query time).
         * Number of inverted lists to search. Default 1 (pgvector default, low recall).
         * Set to sqrt(lists) for good recall. e.g. lists=100 → probes=10.
         */
        private readonly int    $probes     = 10,
    ) {}

    /**
     * @param  DocumentChunk[]  $chunks
     */
    public function saveMany(array $chunks): void
    {
        if (empty($chunks)) {
            return;
        }

        $rows = array_map(function (DocumentChunk $chunk): array {
            return [
                'document_id' => $chunk->documentId,
                'chunk_index' => $chunk->index,
                'text'        => $chunk->text,
                'embedding'   => $chunk->embedding !== null
                    ? $this->formatVector($chunk->embedding)
                    : null,
                'meta'        => json_encode($chunk->meta),
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        }, $chunks);

        DB::table($this->table)->upsert(
            $rows,
            uniqueBy: ['document_id', 'chunk_index'],
            update:   ['text', 'embedding', 'meta', 'updated_at'],
        );
    }

    /**
     * ANN vector similarity search using pgvector.
     *
     * Sets the appropriate session-level parameter before querying:
     *   HNSW:    SET LOCAL hnsw.ef_search = N
     *   IVFFlat: SET LOCAL ivfflat.probes = N
     *
     * SET LOCAL applies only within the current transaction — safe for concurrent use.
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

            DB::transaction(function () use ($vectorLiteral, $entityClasses, $topK, &$rows) {
                // Tune index at query time within the transaction
                $this->setIndexParameter();

                $query = DB::table($this->table)
                    ->selectRaw("*, embedding <=> '{$vectorLiteral}'::vector AS distance")
                    ->whereNotNull('embedding')
                    ->orderBy('distance')
                    ->limit($topK);

                if (! empty($entityClasses)) {
                    $query->where(function ($q) use ($entityClasses) {
                        foreach ($entityClasses as $class) {
                            $q->orWhereJsonContains('meta->entity_class', $class);
                        }
                    });
                }

                $rows = $query->get();
            });

            return collect($rows)->map(fn (object $row) => $this->hydrate($row))->all();

        } catch (\Throwable $e) {
            try { Log::error('[PgvectorChunkStore] Vector search failed.', [
                'index'     => $this->indexType,
                'exception' => $e->getMessage(),
            ]); } catch (\Throwable $__e) {}
            return $this->findByKeyword('', $entityClasses, $topK);
        }
    }

    /**
     * Full-text keyword fallback using PostgreSQL ts_rank.
     * Used when embeddings are unavailable or vector search fails.
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
            $dbQuery = DB::table($this->table)
                ->whereRaw("to_tsvector('english', text) @@ plainto_tsquery('english', ?)", [$query])
                ->orderByRaw("ts_rank(to_tsvector('english', text), plainto_tsquery('english', ?)) DESC", [$query])
                ->limit($topK);

            if (! empty($entityClasses)) {
                $dbQuery->where(function ($q) use ($entityClasses) {
                    foreach ($entityClasses as $class) {
                        $q->orWhereJsonContains('meta->entity_class', $class);
                    }
                });
            }

            return $dbQuery->get()
                ->map(fn (object $row) => $this->hydrate($row))
                ->all();

        } catch (\Throwable $e) {
            try { Log::error('[PgvectorChunkStore] Keyword search failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return [];
        }
    }

    public function deleteByDocumentId(string $documentId): bool
    {
        try {
            DB::table($this->table)->where('document_id', $documentId)->delete();
            return true;
        } catch (\Throwable $e) {
            try { Log::error('[PgvectorChunkStore] Delete failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return false;
        }
    }

    /**
     * Creates the recommended index for the current strategy and row count.
     * Run once after populating data. For IVFFlat, run AFTER inserting data.
     *
     * @param  int  $totalRows  Required for IVFFlat to calculate optimal list count
     */
    public function createIndex(int $totalRows = 0): void
    {
        match ($this->indexType) {
            'hnsw' => DB::statement("
                CREATE INDEX IF NOT EXISTS {$this->table}_embedding_hnsw_idx
                ON {$this->table}
                USING hnsw (embedding vector_cosine_ops)
                WITH (m = 16, ef_construction = 64)
            "),

            'ivfflat' => DB::statement(sprintf("
                CREATE INDEX IF NOT EXISTS {$this->table}_embedding_ivfflat_idx
                ON {$this->table}
                USING ivfflat (embedding vector_cosine_ops)
                WITH (lists = %d)
            ", max(1, $totalRows > 0 ? (int) sqrt($totalRows) : 100))),

            default => null, // 'exact' — no index
        };

        try { Log::info('[PgvectorChunkStore] Index created.', [
            'table'     => $this->table,
            'type'      => $this->indexType,
            'total_rows' => $totalRows,
        ]); } catch (\Throwable $__e) {}
    }

    /**
     * Drops and recreates the index.
     * Use when switching strategies or after major data changes.
     */
    public function rebuildIndex(int $totalRows = 0): void
    {
        $indexName = match ($this->indexType) {
            'hnsw'    => "{$this->table}_embedding_hnsw_idx",
            'ivfflat' => "{$this->table}_embedding_ivfflat_idx",
            default   => null,
        };

        if ($indexName) {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");
        }

        $this->createIndex($totalRows);
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    private function setIndexParameter(): void
    {
        match ($this->indexType) {
            'hnsw'    => DB::statement("SET LOCAL hnsw.ef_search = {$this->efSearch}"),
            'ivfflat' => DB::statement("SET LOCAL ivfflat.probes = {$this->probes}"),
            default   => null,
        };
    }

    private function hydrate(object $row): DocumentChunk
    {
        $meta = json_decode($row->meta ?? '{}', true) ?? [];
        return new DocumentChunk(
            text:       $row->text,
            index:      $row->chunk_index,
            documentId: $row->document_id,
            meta:       $meta,
            embedding:  null,
        );
    }

    private function formatVector(array $vector): string
    {
        return '[' . implode(',', array_map(
            fn (float $v) => number_format($v, 6, '.', ''),
            $vector,
        )) . ']';
    }
}


    /**
     * @param  DocumentChunk[]  $chunks
     */
    public function saveMany(array $chunks): void
    {
        if (empty($chunks)) {
            return;
        }

        $rows = array_map(function (DocumentChunk $chunk): array {
            return [
                'document_id'  => $chunk->documentId,
                'chunk_index'  => $chunk->index,
                'text'         => $chunk->text,
                'embedding'    => $chunk->embedding !== null
                    ? $this->formatVector($chunk->embedding)
                    : null,
                'meta'         => json_encode($chunk->meta),
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }, $chunks);

        // Upsert by (document_id, chunk_index) — safe for reprocessing
        DB::table($this->table)->upsert(
            $rows,
            uniqueBy:  ['document_id', 'chunk_index'],
            update:    ['text', 'embedding', 'meta', 'updated_at'],
        );
    }

    /**
     * Cosine similarity search via pgvector.
     * Returns the topK most similar chunks.
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
                ->selectRaw("*, embedding <=> '{$vectorLiteral}'::vector AS distance")
                ->whereNotNull('embedding')
                ->orderBy('distance')
                ->limit($topK);

            if (! empty($entityClasses)) {
                $query->where(function ($q) use ($entityClasses) {
                    foreach ($entityClasses as $class) {
                        $q->orWhereJsonContains('meta->entity_class', $class);
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
                    embedding:  null, // don't re-hydrate vectors
                );
            })->all();

        } catch (\Throwable $e) {
            try { Log::error('[PgvectorChunkStore] Vector search failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return $this->findByKeyword('', $entityClasses, $topK); // fallback
        }
    }

    /**
     * Full-text keyword search fallback (when embeddings unavailable).
     *
     * Uses PostgreSQL full-text search (to_tsvector / plainto_tsquery)
     * rather than LIKE for better performance on large datasets.
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
            $dbQuery = DB::table($this->table)
                ->whereRaw("to_tsvector('english', text) @@ plainto_tsquery('english', ?)", [$query])
                ->orderByRaw("ts_rank(to_tsvector('english', text), plainto_tsquery('english', ?)) DESC", [$query])
                ->limit($topK);

            if (! empty($entityClasses)) {
                $dbQuery->where(function ($q) use ($entityClasses) {
                    foreach ($entityClasses as $class) {
                        $q->orWhereJsonContains('meta->entity_class', $class);
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
            try { Log::error('[PgvectorChunkStore] Keyword search failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return [];
        }
    }

    public function deleteByDocumentId(string $documentId): bool
    {
        try {
            DB::table($this->table)->where('document_id', $documentId)->delete();
            return true;
        } catch (\Throwable $e) {
            try { Log::error('[PgvectorChunkStore] Delete failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    /**
     * Formats a float array as a pgvector literal: '[0.1,0.2,0.3]'
     *
     * @param  float[]  $vector
     */
    private function formatVector(array $vector): string
    {
        return '[' . implode(',', array_map(
            fn (float $v) => number_format($v, 6, '.', ''),
            $vector,
        )) . ']';
    }
}
