<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\IndexRequest;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;
use ExpressCodeEngines\Shared\ValueObjects\SearchResult;

/**
 * A search driver — one implementation per backend (Meilisearch, Algolia, Scout, etc.).
 */
interface SearchDriverInterface
{
    public function name(): string;

    public function search(SearchRequest $request): SearchResult;

    public function index(IndexRequest $request): bool;

    public function indexMany(array $requests): bool;

    public function delete(string $entityClass, int|string $id): bool;

    public function flush(string $entityClass): bool;
}
