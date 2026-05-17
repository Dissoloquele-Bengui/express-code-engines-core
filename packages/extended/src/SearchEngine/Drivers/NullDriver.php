<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\SearchEngine\Drivers;

use ExpressCodeEngines\Shared\Contracts\SearchDriverInterface;
use ExpressCodeEngines\Shared\DTOs\IndexRequest;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;
use ExpressCodeEngines\Shared\ValueObjects\SearchResult;

/**
 * A no-op driver that always returns empty results.
 *
 * Used in:
 *   - Test suites (avoids real HTTP calls to Meilisearch/Algolia)
 *   - Environments where search is disabled
 *   - As the fallback driver when the primary driver fails
 */
final class NullDriver implements SearchDriverInterface
{
    public function name(): string { return 'null'; }

    public function search(SearchRequest $request): SearchResult
    {
        return SearchResult::empty();
    }

    public function index(IndexRequest $request): bool   { return true; }
    public function indexMany(array $requests): bool     { return true; }
    public function delete(string $entity, int|string $id): bool { return true; }
    public function flush(string $entity): bool          { return true; }
}
