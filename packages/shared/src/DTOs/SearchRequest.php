<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A search request — query + filters + pagination.
 */
final class SearchRequest
{
    public function __construct(
        /** The search query string */
        public readonly string $query,

        /**
         * Entity class(es) to search within.
         * If empty, searches all indexed entities.
         * @var string[]
         */
        public readonly array $entities = [],

        /**
         * Attribute filters — exact match or range.
         * e.g. ['status' => 'active', 'total' => ['gte' => 100, 'lte' => 500]]
         */
        public readonly array $filters = [],

        /**
         * Sort order — attribute and direction.
         * e.g. [['field' => 'created_at', 'direction' => 'desc']]
         * @var array<array{field: string, direction: string}>
         */
        public readonly array $sort = [],

        /** Number of results per page */
        public readonly int $perPage = 15,

        /** Current page (1-based) */
        public readonly int $page = 1,

        /**
         * Facets to compute alongside results.
         * e.g. ['status', 'category_id']
         * @var string[]
         */
        public readonly array $facets = [],

        /**
         * Tenant/scope isolation — applied as a mandatory filter.
         * e.g. ['tenant_id' => 42]
         */
        public readonly array $scope = [],
    ) {}
}
