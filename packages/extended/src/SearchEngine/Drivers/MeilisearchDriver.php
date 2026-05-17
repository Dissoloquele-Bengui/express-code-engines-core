<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\SearchEngine\Drivers;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\SearchDriverInterface;
use ExpressCodeEngines\Shared\DTOs\IndexRequest;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;
use ExpressCodeEngines\Shared\ValueObjects\SearchResult;

/**
 * Meilisearch driver for the SearchEngine.
 *
 * Uses the Meilisearch HTTP API directly — no Scout dependency.
 * Compatible with Meilisearch Cloud and self-hosted instances.
 *
 * Features:
 *   - Typo-tolerant full-text search
 *   - Faceted search and filtering
 *   - Weighted field relevance
 *   - Async indexing (updates dispatched to Meilisearch queue)
 *   - Per-entity index configuration (searchable, filterable, sortable fields)
 *
 * Configuration (in engines.extended.search.entities):
 *   'App\Models\Order' => [
 *       'index_name'        => 'orders',
 *       'searchable_fields' => ['reference', 'customer_name'],
 *       'filterable_fields' => ['status', 'tenant_id'],
 *       'sortable_fields'   => ['created_at', 'total'],
 *       'ranking_rules'     => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
 *   ]
 */
final class MeilisearchDriver implements SearchDriverInterface
{
    public function __construct(
        private readonly string $host,
        private readonly string $apiKey,
        private readonly array  $entityConfig = [],
    ) {}

    public function search(SearchRequest $request): SearchResult
    {
        try {
            $results = [];
            $facets  = [];

            // Search across all requested entity indices
            $entities = ! empty($request->entities) ? $request->entities : array_keys($this->entityConfig);

            foreach ($entities as $entityClass) {
                $indexName = $this->indexName($entityClass);
                $config    = $this->entityConfig[$entityClass] ?? [];

                $body = [
                    'q'                => $request->query,
                    'limit'            => $request->perPage,
                    'offset'           => ($request->page - 1) * $request->perPage,
                    'attributesToRetrieve' => ['*'],
                ];

                // Scoping and filters
                $filterParts = [];

                foreach (array_merge($request->scope ?? [], $request->filters) as $field => $value) {
                    if (is_array($value)) {
                        $inList       = implode(', ', array_map(fn ($v) => "\"{$v}\"", $value));
                        $filterParts[] = "{$field} IN [{$inList}]";
                    } else {
                        $filterParts[] = "{$field} = \"{$value}\"";
                    }
                }

                if (! empty($filterParts)) {
                    $body['filter'] = implode(' AND ', $filterParts);
                }

                // Facets
                if (! empty($request->facets)) {
                    $body['facets'] = $request->facets;
                }

                // Sorting
                if (! empty($request->sort)) {
                    $body['sort'] = array_map(
                        fn ($s) => "{$s['field']}:{$s['direction']}",
                        $request->sort,
                    );
                }

                $response = Http::withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                    ->timeout(10)
                    ->post("{$this->host}/indexes/{$indexName}/search", $body);

                if (! $response->successful()) {
                    try { Log::warning('[MeilisearchDriver] Search failed.', [
                        'index'  => $indexName,
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]); } catch (\Throwable $__e) {}
                    continue;
                }

                $data = $response->json();

                foreach ($data['hits'] ?? [] as $hit) {
                    $results[] = array_merge($hit, ['entity' => $entityClass]);
                }

                // Merge facet distributions
                foreach ($data['facetDistribution'] ?? [] as $field => $distribution) {
                    $facets[$field] = ($facets[$field] ?? []) + $distribution;
                }
            }

            $total = count($results); // approximation when searching multiple indices

            return new SearchResult(
                hits:      $results,
                total:     $total,
                perPage:   $request->perPage,
                page:      $request->page,
                facets:    $facets,
            );

        } catch (\Throwable $e) {
            try { Log::error('[MeilisearchDriver] Unexpected error.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return SearchResult::empty();
        }
    }

    public function index(IndexRequest $request): bool
    {
        try {
            $indexName = $this->indexName($request->entityClass);

            // Ensure index is configured
            $this->ensureIndexConfig($indexName, $request->entityClass);

            $response = Http::withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                ->timeout(10)
                ->put("{$this->host}/indexes/{$indexName}/documents", [$request->document]);

            return $response->successful();

        } catch (\Throwable $e) {
            try { Log::error('[MeilisearchDriver] Index failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return false;
        }
    }

    public function indexMany(array $requests): bool
    {
        // Group by entity class for batch indexing
        $grouped = [];
        foreach ($requests as $req) {
            $grouped[$req->entityClass][] = $req->document;
        }

        $allOk = true;

        foreach ($grouped as $entityClass => $documents) {
            try {
                $indexName = $this->indexName($entityClass);
                $this->ensureIndexConfig($indexName, $entityClass);

                $response = Http::withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                    ->timeout(30)
                    ->put("{$this->host}/indexes/{$indexName}/documents", $documents);

                if (! $response->successful()) {
                    try { Log::warning('[MeilisearchDriver] Batch index failed.', [
                        'entity' => $entityClass,
                        'count'  => count($documents),
                    ]); } catch (\Throwable $__e) {}
                    $allOk = false;
                }

            } catch (\Throwable $e) {
                try { Log::error('[MeilisearchDriver] Batch index exception.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
                $allOk = false;
            }
        }

        return $allOk;
    }

    public function delete(string $entityClass, int|string $id): bool
    {
        try {
            $indexName = $this->indexName($entityClass);

            $response = Http::withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                ->timeout(10)
                ->delete("{$this->host}/indexes/{$indexName}/documents/{$id}");

            return $response->successful();

        } catch (\Throwable $e) {
            try { Log::error('[MeilisearchDriver] Delete failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return false;
        }
    }

    public function flush(string $entityClass): bool
    {
        try {
            $indexName = $this->indexName($entityClass);

            $response = Http::withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                ->timeout(10)
                ->delete("{$this->host}/indexes/{$indexName}/documents");

            return $response->successful();

        } catch (\Throwable $e) {
            try { Log::error('[MeilisearchDriver] Flush failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return false;
        }
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                ->timeout(3)
                ->get("{$this->host}/health");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    private function indexName(string $entityClass): string
    {
        return $this->entityConfig[$entityClass]['index_name']
            ?? strtolower(str_replace('\\', '_', class_basename($entityClass))) . 's';
    }

    private function ensureIndexConfig(string $indexName, string $entityClass): void
    {
        $config = $this->entityConfig[$entityClass] ?? [];

        if (empty($config)) {
            return;
        }

        $settings = [];

        if (! empty($config['searchable_fields'])) {
            $settings['searchableAttributes'] = $config['searchable_fields'];
        }

        if (! empty($config['filterable_fields'])) {
            $settings['filterableAttributes'] = $config['filterable_fields'];
        }

        if (! empty($config['sortable_fields'])) {
            $settings['sortableAttributes'] = $config['sortable_fields'];
        }

        if (! empty($config['ranking_rules'])) {
            $settings['rankingRules'] = $config['ranking_rules'];
        }

        if (! empty($settings)) {
            Http::withHeaders(['Authorization' => "Bearer {$this->apiKey}"])
                ->timeout(5)
                ->patch("{$this->host}/indexes/{$indexName}/settings", $settings);
        }
    }
}
