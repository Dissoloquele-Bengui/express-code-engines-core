<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\ValueObjects;

use ExpressCodeEngines\Shared\DTOs\IndexRequest;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;
use ExpressCodeEngines\Shared\ValueObjects\SearchResult;

/**
 * Paginated search results with optional facets.
 */
final class SearchResult
{
    public function __construct(
        /**
         * The result hits — each is an associative array of indexed fields.
         * @var array<array<string, mixed>>
         */
        public readonly array $hits,

        /** Total number of matching documents (before pagination) */
        public readonly int $total,

        public readonly int $page,
        public readonly int $perPage,

        /**
         * Computed facets (if requested).
         * e.g. ['status' => ['active' => 42, 'draft' => 8]]
         * @var array<string, array<string, int>>
         */
        public readonly array $facets = [],

        /** Query processing time in milliseconds */
        public readonly ?int $processingTimeMs = null,
    ) {}

    public static function empty(): self
    {
        return new self(hits: [], total: 0, page: 1, perPage: 15);
    }

    public function totalPages(): int
    {
        if ($this->perPage === 0) return 0;
        return (int) ceil($this->total / $this->perPage);
    }

    public function hasResults(): bool
    {
        return ! empty($this->hits);
    }

    public function ids(): array
    {
        return array_column($this->hits, 'id');
    }
}
