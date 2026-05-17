<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\SearchEngine\Drivers;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\SearchDriverInterface;
use ExpressCodeEngines\Shared\DTOs\IndexRequest;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;
use ExpressCodeEngines\Shared\ValueObjects\SearchResult;

/**
 * Algolia driver for the SearchEngine.
 *
 * Uses the Algolia Search REST API directly — no Scout dependency.
 * Typo-tolerant, relevance-ranked, geo-aware full-text search.
 *
 * Algolia advantages over Meilisearch:
 *   - Globally distributed (lower latency worldwide)
 *   - Instant search / as-you-type results
 *   - Rules engine for merchandising
 *   - AI-powered ranking (NeuralSearch on Business plan)
 *   - Better SLA guarantees for enterprise
 *
 * Algolia advantages of Meilisearch:
 *   - Self-hostable (no vendor lock-in)
 *   - Open source
 *   - Cheaper at high volume
 *
 * Free tier: 10,000 search requests/month + 10,000 records.
 *
 * Configuration:
 *   ALGOLIA_APP_ID=XXXXXXXXXX
 *   ALGOLIA_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx   (Admin API key — keep secret)
 *   ALGOLIA_SEARCH_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx (Search-only key — safe for frontend)
 *
 * Index naming:
 *   Default: snake_case of class basename + 's'  (App\Models\Order → orders)
 *   Override per entity: 'index_name' in entity config
 */
final class AlgoliaDriver implements SearchDriverInterface
{
    private const WRITE_BASE = 'https://{appId}.algolia.net';
    private const READ_BASE  = 'https://{appId}-dsn.algolia.net'; // distributed search network

    public function __construct(
        private readonly string $appId,
        private readonly string $adminApiKey,
        private readonly array  $entityConfig = [],
    ) {}

    public function search(SearchRequest $request): SearchResult
    {
        try {
            $entities  = ! empty($request->entities) ? $request->entities : array_keys($this->entityConfig);
            $allHits   = [];
            $allFacets = [];
            $total     = 0;

            // If multiple entities, use Algolia's multi-index search (single HTTP call)
            if (count($entities) > 1) {
                return $this->multiIndexSearch($request, $entities);
            }

            // Single index search
            $entityClass = $entities[0] ?? null;
            if ($entityClass === null) {
                return SearchResult::empty();
            }

            $indexName = $this->indexName($entityClass);
            $params    = $this->buildSearchParams($request, $entityClass);

            $response = $this->readRequest("indexes/{$indexName}/query", $params);

            if ($response === null) {
                return SearchResult::empty();
            }

            foreach ($response['hits'] ?? [] as $hit) {
                $allHits[] = array_merge($hit, ['entity' => $entityClass]);
            }

            $total     = $response['nbHits'] ?? count($allHits);
            $allFacets = $response['facets'] ?? [];

            return new SearchResult(
                hits:    $allHits,
                total:   $total,
                perPage: $request->perPage,
                page:    $request->page,
                facets:  $allFacets,
            );

        } catch (\Throwable $e) {
            try { Log::error('[AlgoliaDriver] Search failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return SearchResult::empty();
        }
    }

    public function index(IndexRequest $request): bool
    {
        try {
            $indexName = $this->indexName($request->entityClass);
            $doc       = array_merge($request->document, ['objectID' => $request->document['id'] ?? null]);

            $response = $this->writeRequest("indexes/{$indexName}/objects", [$doc]);

            return $response !== null;

        } catch (\Throwable $e) {
            try { Log::error('[AlgoliaDriver] Index failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return false;
        }
    }

    public function indexMany(array $requests): bool
    {
        $grouped = [];
        foreach ($requests as $req) {
            $grouped[$req->entityClass][] = array_merge(
                $req->document,
                ['objectID' => $req->document['id'] ?? null],
            );
        }

        $allOk = true;

        foreach ($grouped as $entityClass => $documents) {
            try {
                $indexName = $this->indexName($entityClass);
                $this->ensureIndexSettings($indexName, $entityClass);

                // Batch save (Algolia allows up to 1000 objects per batch)
                foreach (array_chunk($documents, 1000) as $batch) {
                    $operations = array_map(
                        fn ($doc) => ['action' => 'addObject', 'body' => $doc],
                        $batch,
                    );

                    $response = $this->writeRequest(
                        "indexes/{$indexName}/batch",
                        ['requests' => $operations],
                    );

                    if ($response === null) {
                        $allOk = false;
                    }
                }

            } catch (\Throwable $e) {
                try { Log::error('[AlgoliaDriver] Batch index failed.', [
                    'entity' => $entityClass,
                    'error'  => $e->getMessage(),
                ]); } catch (\Throwable $__e) {}
                $allOk = false;
            }
        }

        return $allOk;
    }

    public function delete(string $entityClass, int|string $id): bool
    {
        try {
            $indexName = $this->indexName($entityClass);
            $response  = Http::withHeaders($this->writeHeaders())
                ->timeout(10)
                ->delete($this->writeUrl("indexes/{$indexName}/objects/{$id}"));

            return $response->successful();
        } catch (\Throwable $e) {
            try { Log::error('[AlgoliaDriver] Delete failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return false;
        }
    }

    public function flush(string $entityClass): bool
    {
        try {
            $indexName = $this->indexName($entityClass);
            $response  = Http::withHeaders($this->writeHeaders())
                ->timeout(10)
                ->post($this->writeUrl("indexes/{$indexName}/clear"), []);

            return $response->successful();
        } catch (\Throwable $e) {
            try { Log::error('[AlgoliaDriver] Flush failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return false;
        }
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::withHeaders($this->writeHeaders())
                ->timeout(3)
                ->get($this->writeUrl('indexes'));
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    private function multiIndexSearch(SearchRequest $request, array $entities): SearchResult
    {
        $queries = array_map(function (string $entityClass) use ($request): array {
            return array_merge(
                $this->buildSearchParams($request, $entityClass),
                ['indexName' => $this->indexName($entityClass)],
            );
        }, $entities);

        $response = Http::withHeaders([
            'X-Algolia-Application-Id' => $this->appId,
            'X-Algolia-API-Key'        => $this->adminApiKey,
        ])->timeout(10)
          ->post($this->readUrl('indexes/*/queries'), ['requests' => $queries]);

        if (! $response->successful()) {
            return SearchResult::empty();
        }

        $allHits   = [];
        $allFacets = [];
        $total     = 0;

        foreach ($response->json('results', []) as $i => $result) {
            $entityClass = $entities[$i] ?? 'unknown';
            foreach ($result['hits'] ?? [] as $hit) {
                $allHits[] = array_merge($hit, ['entity' => $entityClass]);
            }
            $total     += $result['nbHits'] ?? 0;
            $allFacets  = array_merge_recursive($allFacets, $result['facets'] ?? []);
        }

        return new SearchResult(
            hits:    $allHits,
            total:   $total,
            perPage: $request->perPage,
            page:    $request->page,
            facets:  $allFacets,
        );
    }

    private function buildSearchParams(SearchRequest $request, string $entityClass): array
    {
        $config = $this->entityConfig[$entityClass] ?? [];
        $params = [
            'query'                => $request->query,
            'hitsPerPage'          => $request->perPage,
            'page'                 => $request->page - 1, // Algolia is 0-indexed
            'attributesToRetrieve' => ['*'],
        ];

        // Filters
        $filterParts = [];
        foreach (array_merge($request->scope ?? [], $request->filters) as $field => $value) {
            if (is_array($value)) {
                $filterParts[] = implode(' OR ', array_map(fn ($v) => "{$field}:\"{$v}\"", $value));
            } else {
                $filterParts[] = "{$field}:\"{$value}\"";
            }
        }

        if (! empty($filterParts)) {
            $params['filters'] = implode(' AND ', $filterParts);
        }

        // Facets
        if (! empty($request->facets)) {
            $params['facets'] = $request->facets;
        }

        // Custom ranking
        if (! empty($request->sort)) {
            // Algolia sorting requires replica indices — log a hint
            try { Log::debug('[AlgoliaDriver] Sorting requires replica indices.', [
                'entity' => $entityClass,
                'sort'   => $request->sort,
            ]); } catch (\Throwable $__e) {}
        }

        return $params;
    }

    private function ensureIndexSettings(string $indexName, string $entityClass): void
    {
        $config = $this->entityConfig[$entityClass] ?? [];
        if (empty($config)) return;

        $settings = [];

        if (! empty($config['searchable_fields'])) {
            $settings['searchableAttributes'] = $config['searchable_fields'];
        }

        if (! empty($config['filterable_fields'])) {
            $settings['attributesForFaceting'] = array_map(
                fn ($f) => "filterOnly({$f})",
                $config['filterable_fields'],
            );
        }

        if (! empty($settings)) {
            Http::withHeaders($this->writeHeaders())
                ->timeout(10)
                ->put($this->writeUrl("indexes/{$indexName}/settings"), $settings);
        }
    }

    private function indexName(string $entityClass): string
    {
        return $this->entityConfig[$entityClass]['index_name']
            ?? strtolower(str_replace('\\', '_', class_basename($entityClass))) . 's';
    }

    private function readRequest(string $path, array $params): ?array
    {
        $response = Http::withHeaders([
            'X-Algolia-Application-Id' => $this->appId,
            'X-Algolia-API-Key'        => $this->adminApiKey,
        ])->timeout(10)->post($this->readUrl($path), $params);

        if (! $response->successful()) {
            try { Log::warning('[AlgoliaDriver] Read request failed.', [
                'path'   => $path,
                'status' => $response->status(),
            ]); } catch (\Throwable $__e) {}
            return null;
        }

        return $response->json();
    }

    private function writeRequest(string $path, array $data): ?array
    {
        $response = Http::withHeaders($this->writeHeaders())
            ->timeout(15)
            ->post($this->writeUrl($path), $data);

        return $response->successful() ? $response->json() : null;
    }

    private function writeHeaders(): array
    {
        return [
            'X-Algolia-Application-Id' => $this->appId,
            'X-Algolia-API-Key'        => $this->adminApiKey,
        ];
    }

    private function writeUrl(string $path): string
    {
        return str_replace('{appId}', $this->appId, self::WRITE_BASE) . '/1/' . $path;
    }

    private function readUrl(string $path): string
    {
        return str_replace('{appId}', $this->appId, self::READ_BASE) . '/1/' . $path;
    }
}
