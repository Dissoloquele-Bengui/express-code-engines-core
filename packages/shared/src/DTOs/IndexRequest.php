<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * An indexing request — adds or updates a document in the search index.
 */
final class IndexRequest
{
    public function __construct(
        /** Fully-qualified entity class */
        public readonly string $entityClass,

        /** The record ID */
        public readonly int|string $id,

        /** The data to index — flat or nested array */
        public readonly array $data,

        /**
         * Optional: specific fields to index.
         * If empty, the driver uses its configured searchable fields.
         * @var string[]
         */
        public readonly array $fields = [],
    ) {}
}
