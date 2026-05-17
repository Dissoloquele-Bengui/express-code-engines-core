<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\SearchEngine;

use ExpressCodeEngines\Shared\Contracts\SearchDriverInterface;
use ExpressCodeEngines\Shared\Contracts\SearchEngineInterface;
use ExpressCodeEngines\Shared\DTOs\IndexRequest;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;
use ExpressCodeEngines\Shared\ValueObjects\SearchResult;

/**
 * Unified search engine with driver abstraction and graceful fallback.
 *
 * The engine delegates all operations to the configured driver.
 * Driver failures return empty results and log the error —
 * a search failure must never crash the application.
 *
 * Driver resolution:
 *   - A default driver is always required.
 *   - An optional fallback driver is used when the default fails.
 *   - Additional entity-specific drivers can be registered.
 *
 * Indexing is fire-and-forget by design.
 * All index operations should be dispatched via Jobs in production.
 */
final class SearchEngine implements SearchEngineInterface
{
    /**
     * @param  array<string, SearchDriverInterface>  $entityDrivers  entity class → driver override
     */
    public function __construct(
        private readonly SearchDriverInterface  $defaultDriver,
        private readonly ?SearchDriverInterface $fallbackDriver  = null,
        private readonly array                  $entityDrivers   = [],
    ) {}

    public function search(SearchRequest $request): SearchResult
    {
        $driver = $this->resolveDriver($request->entities[0] ?? null);

        try {
            return $driver->search($request);
        } catch (\Throwable $e) {
            $this->logSafe('[SearchEngine] Search failed.', [
                'driver' => $driver->name(),
                'query'  => $request->query,
                'error'  => $e->getMessage(),
            ]);

            if ($this->fallbackDriver && $driver !== $this->fallbackDriver) {
                try {
                    $this->logSafe('[SearchEngine] Falling back to: ' . $this->fallbackDriver->name());
                    return $this->fallbackDriver->search($request);
                } catch (\Throwable $fe) {
                    $this->logSafe('[SearchEngine] Fallback also failed.', ['error' => $fe->getMessage()]);
                }
            }

            return SearchResult::empty();
        }
    }

    public function index(IndexRequest $request): bool
    {
        $driver = $this->resolveDriver($request->entityClass);

        try {
            return $driver->index($request);
        } catch (\Throwable $e) {
            $this->logSafe('[SearchEngine] Indexing failed.', [
                'driver' => $driver->name(),
                'entity' => $request->entityClass,
                'id'     => $request->id,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function indexMany(array $requests): bool
    {
        if (empty($requests)) return true;

        // Group by driver to batch efficiently
        $grouped = [];
        foreach ($requests as $request) {
            $driver = $this->resolveDriver($request->entityClass);
            $grouped[$driver->name()][] = [$driver, $request];
        }

        $allSucceeded = true;

        foreach ($grouped as $items) {
            $driver   = $items[0][0];
            $batch    = array_column($items, 1);

            try {
                if (! $driver->indexMany($batch)) {
                    $allSucceeded = false;
                }
            } catch (\Throwable $e) {
                $this->logSafe('[SearchEngine] Batch indexing failed.', [
                    'driver' => $driver->name(),
                    'count'  => count($batch),
                    'error'  => $e->getMessage(),
                ]);
                $allSucceeded = false;
            }
        }

        return $allSucceeded;
    }

    public function delete(string $entityClass, int|string $id): bool
    {
        try {
            return $this->resolveDriver($entityClass)->delete($entityClass, $id);
        } catch (\Throwable $e) {
            $this->logSafe('[SearchEngine] Delete failed.', ['entity' => $entityClass, 'id' => $id]);
            return false;
        }
    }

    public function flush(string $entityClass): bool
    {
        try {
            return $this->resolveDriver($entityClass)->flush($entityClass);
        } catch (\Throwable $e) {
            $this->logSafe('[SearchEngine] Flush failed.', ['entity' => $entityClass]);
            return false;
        }
    }

    private function resolveDriver(?string $entityClass): SearchDriverInterface
    {
        if ($entityClass && isset($this->entityDrivers[$entityClass])) {
            return $this->entityDrivers[$entityClass];
        }

        return $this->defaultDriver;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (function_exists('logger')) {
            logger()->{$level}($message, $context);
        }
    }


    private function logSafe(string $message, array $context = []): void
    {
        $msg = $message;
        if ($context) {
            $msg .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        if (function_exists('logger')) {
            logger()->error($msg);
        }
    }
}
