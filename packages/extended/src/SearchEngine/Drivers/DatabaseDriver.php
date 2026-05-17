<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\SearchEngine\Drivers;

use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\Contracts\SearchDriverInterface;
use ExpressCodeEngines\Shared\DTOs\IndexRequest;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;
use ExpressCodeEngines\Shared\ValueObjects\SearchResult;

/**
 * Search driver backed by the application database.
 *
 * Uses LIKE queries across searchable fields. Suitable for small-to-medium
 * datasets or as a reliable fallback when Meilisearch/Algolia is unavailable.
 *
 * config keys per entity class:
 *   table              string    DB table to search
 *   searchable_fields  string[]  Fields included in LIKE queries
 *   filterable_fields  string[]  Fields allowed as filters
 *   sortable_fields    string[]  Fields allowed for sorting
 */
final class DatabaseDriver implements SearchDriverInterface
{
    public function __construct(
        private readonly array $entityConfig = [],
    ) {}

    public function name(): string { return 'database'; }

    public function search(SearchRequest $request): SearchResult
    {
        $entityClass = $request->entities[0] ?? null;
        $config      = $this->resolveConfig($entityClass);

        if (! $config) {
            return SearchResult::empty();
        }

        $table            = $config['table'];
        $searchableFields = $config['searchable_fields'] ?? [];
        $filterableFields = $config['filterable_fields'] ?? [];
        $sortableFields   = $config['sortable_fields']   ?? [];

        $query = DB::table($table);

        // Mandatory scope (tenant isolation)
        foreach ($request->scope as $field => $value) {
            $query->where($field, $value);
        }

        // Full-text LIKE across searchable fields
        if ($request->query !== '' && ! empty($searchableFields)) {
            $query->where(function ($q) use ($request, $searchableFields) {
                foreach ($searchableFields as $field) {
                    $q->orWhere($field, 'LIKE', '%' . $request->query . '%');
                }
            });
        }

        // Filters (only allowed fields)
        foreach ($request->filters as $field => $value) {
            if (! in_array($field, $filterableFields, true)) continue;

            if (is_array($value)) {
                if (isset($value['gte'])) $query->where($field, '>=', $value['gte']);
                if (isset($value['lte'])) $query->where($field, '<=', $value['lte']);
                if (isset($value['in']))  $query->whereIn($field, $value['in']);
            } else {
                $query->where($field, $value);
            }
        }

        $total = $query->count();

        // Sort (only allowed fields)
        foreach ($request->sort as $sortItem) {
            $field     = $sortItem['field']     ?? null;
            $direction = $sortItem['direction'] ?? 'asc';
            if ($field && in_array($field, $sortableFields, true)) {
                $query->orderBy($field, $direction);
            }
        }

        $offset = ($request->page - 1) * $request->perPage;
        $hits   = $query->offset($offset)->limit($request->perPage)->get()->toArray();

        // Facets
        $facets = [];
        foreach ($request->facets as $facetField) {
            if (in_array($facetField, $filterableFields, true)) {
                $facets[$facetField] = DB::table($table)
                    ->selectRaw("{$facetField}, COUNT(*) as count")
                    ->groupBy($facetField)
                    ->pluck('count', $facetField)
                    ->toArray();
            }
        }

        return new SearchResult(
            hits:    array_map(fn ($row) => (array) $row, $hits),
            total:   $total,
            page:    $request->page,
            perPage: $request->perPage,
            facets:  $facets,
        );
    }

    public function index(IndexRequest $request): bool   { return true; } // reads directly
    public function indexMany(array $requests): bool     { return true; }
    public function delete(string $entity, int|string $id): bool { return true; }
    public function flush(string $entity): bool          { return true; }

    private function resolveConfig(?string $entityClass): ?array
    {
        if (! $entityClass) return null;
        return $this->entityConfig[$entityClass] ?? null;
    }
}
