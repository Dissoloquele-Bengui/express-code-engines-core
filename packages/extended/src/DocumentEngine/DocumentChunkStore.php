<?php
declare(strict_types=1);
namespace ExpressCodeEngines\Extended\DocumentEngine;
use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
/**
 * Persists document chunks and retrieves them for RAG.
 * Table: document_chunks (document_id, chunk_index, text, embedding JSON, meta JSON)
 * Vector search: cosine similarity in PHP (replace with pgvector for scale).
 * Keyword fallback: LIKE search when embeddings are unavailable.
 */
final class DocumentChunkStore
{
    private const TABLE = 'document_chunks';
    public function saveMany(array $chunks): void
    {
        if (empty($chunks)) return;
        $rows = array_map(fn (DocumentChunk $c) => [
            'document_id' => $c->documentId, 'chunk_index' => $c->index, 'text' => $c->text,
            'embedding'   => $c->embedding ? json_encode($c->embedding) : null,
            'meta'        => $c->meta ? json_encode($c->meta) : null,
            'created_at'  => now(), 'updated_at' => now(),
        ], $chunks);
        foreach (array_chunk($rows, 100) as $batch) { DB::table(self::TABLE)->insert($batch); }
    }
    public function findByEmbedding(array $queryEmbedding, array $entityClasses = [], int $topK = 5): array
    {
        $query = DB::table(self::TABLE)->whereNotNull('embedding')
            ->select(['document_id', 'chunk_index', 'text', 'embedding', 'meta']);
        $query = $this->applyEntityScope($query, $entityClasses);
        $rows  = $query->get();
        if ($rows->isEmpty()) return [];
        return $rows->map(fn ($r) => ['row' => $r, 'score' => $this->cosineSimilarity($queryEmbedding, json_decode($r->embedding, true) ?? [])])
            ->sortByDesc('score')->take($topK)
            ->map(fn ($i) => $this->rowToChunk($i['row']))->values()->all();
    }
    public function findByKeyword(string $query, array $entityClasses = [], int $topK = 5): array
    {
        $builder = DB::table(self::TABLE)->select(['document_id', 'chunk_index', 'text', 'embedding', 'meta'])->limit($topK);
        $builder = $this->applyEntityScope($builder, $entityClasses);
        foreach (array_filter(explode(' ', $query)) as $word) { $builder->where('text', 'like', "%{$word}%"); }
        return $builder->get()->map(fn ($r) => $this->rowToChunk($r))->all();
    }
    public function deleteByDocumentId(string $documentId): bool
    {
        return DB::table(self::TABLE)->where('document_id', $documentId)->delete() > 0;
    }
    private function applyEntityScope(object $query, array $entityClasses): object
    {
        if (empty($entityClasses)) return $query;
        return $query->where(fn ($q) => array_walk($entityClasses, fn ($c) => $q->orWhereJsonContains('meta->entity_class', $c)));
    }
    private function rowToChunk(object $row): DocumentChunk
    {
        return new DocumentChunk(
            text: $row->text, index: (int) $row->chunk_index, documentId: $row->document_id,
            meta: $row->meta ? json_decode($row->meta, true) : [],
            embedding: $row->embedding ? json_decode($row->embedding, true) : null,
        );
    }
    private function cosineSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b) || count($a) !== count($b)) return 0.0;
        $dot = $nA = $nB = 0.0;
        for ($i = 0, $n = count($a); $i < $n; $i++) { $dot += $a[$i]*$b[$i]; $nA += $a[$i]**2; $nB += $b[$i]**2; }
        $d = sqrt($nA) * sqrt($nB);
        return $d > 0.0 ? $dot / $d : 0.0;
    }
}
