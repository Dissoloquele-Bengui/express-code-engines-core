<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\IndexRequest;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;
use ExpressCodeEngines\Shared\ValueObjects\SearchResult;

/**
 * Unified search abstraction over multiple drivers.
 *
 * The engine normalises input/output regardless of the underlying driver
 * (Meilisearch, Algolia, Laravel Scout full-text, etc.).
 *
 * Search is stateless per request. Indexing may be async.
 */
interface SearchEngineInterface
{
    /**
     * Execute a search query and return paginated results.
     */
    public function search(SearchRequest $request): SearchResult;

    /**
     * Index a single document synchronously.
     * For bulk operations, use indexMany() or dispatch an async job.
     */
    public function index(IndexRequest $request): bool;

    /**
     * @param  IndexRequest[]  $requests
     */
    public function indexMany(array $requests): bool;

    /**
     * Remove a document from the index.
     */
    public function delete(string $entityClass, int|string $id): bool;

    /**
     * Flush the entire index for an entity class.
     * Use with caution — requires full re-indexing afterwards.
     */
    public function flush(string $entityClass): bool;
}
