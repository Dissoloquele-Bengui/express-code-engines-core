<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\SearchEngine\Drivers\NullDriver;
use ExpressCodeEngines\Extended\SearchEngine\SearchEngine;
use ExpressCodeEngines\Shared\Contracts\SearchDriverInterface;
use ExpressCodeEngines\Shared\DTOs\IndexRequest;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;
use ExpressCodeEngines\Shared\ValueObjects\SearchResult;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function se_spyDriver(string $name, ?SearchResult $result = null, bool $throws = false): SearchDriverInterface
{
    return new class ($name, $result, $throws) implements SearchDriverInterface {
        public array  $searchCalls = [];
        public array  $indexCalls  = [];

        public function __construct(
            private string        $n,
            private ?SearchResult $result,
            private bool          $throws,
        ) {}

        public function name(): string { return $this->n; }

        public function search(SearchRequest $req): SearchResult
        {
            $this->searchCalls[] = $req;
            if ($this->throws) throw new \RuntimeException("Driver {$this->n} failed.");
            return $this->result ?? new SearchResult(
                hits: [['id' => 1, 'name' => 'Result from ' . $this->n]],
                total: 1, page: 1, perPage: 15,
            );
        }

        public function index(IndexRequest $req): bool
        {
            $this->indexCalls[] = $req;
            return ! $this->throws;
        }

        public function indexMany(array $reqs): bool { return ! $this->throws; }
        public function delete(string $e, int|string $id): bool { return true; }
        public function flush(string $e): bool { return true; }
    };
}

function se_makeSearch(string $query = 'test', array $entities = []): SearchRequest
{
    return new SearchRequest(query: $query, entities: $entities);
}

// ──────────────────────────────────────────────────────────────
// Basic search
// ──────────────────────────────────────────────────────────────

it('delegates search to the default driver', function () {
    $driver = se_spyDriver('primary');
    $engine = new SearchEngine(defaultDriver: $driver);

    $result = $engine->search(se_makeSearch('hello'));

    expect($driver->searchCalls)->toHaveCount(1)
        ->and($result->hasResults())->toBeTrue()
        ->and($result->hits[0]['name'])->toBe('Result from primary');
});

it('returns empty result when default driver throws and no fallback', function () {
    $engine = new SearchEngine(defaultDriver: se_spyDriver('broken', throws: true));
    $result = $engine->search(se_makeSearch('hello'));

    expect($result->hasResults())->toBeFalse()
        ->and($result->total)->toBe(0);
});

it('falls back to secondary driver when primary throws', function () {
    $primary   = se_spyDriver('primary',   throws: true);
    $secondary = se_spyDriver('secondary', throws: false);

    $engine = new SearchEngine(
        defaultDriver:  $primary,
        fallbackDriver: $secondary,
    );

    $result = $engine->search(se_makeSearch('hello'));

    expect($result->hits[0]['name'])->toBe('Result from secondary');
});

it('returns empty when both primary and fallback fail', function () {
    $engine = new SearchEngine(
        defaultDriver:  se_spyDriver('primary',  throws: true),
        fallbackDriver: se_spyDriver('fallback', throws: true),
    );

    expect($engine->search(se_makeSearch())->hasResults())->toBeFalse();
});

it('uses entity-specific driver override when entity matches', function () {
    $default  = se_spyDriver('default');
    $specific = se_spyDriver('specific');

    $engine = new SearchEngine(
        defaultDriver: $default,
        entityDrivers: ['App\Models\Product' => $specific],
    );

    $engine->search(se_makeSearch('shirt', entities: ['App\Models\Product']));
    $engine->search(se_makeSearch('order', entities: ['App\Models\Order']));

    expect($specific->searchCalls)->toHaveCount(1)
        ->and($default->searchCalls)->toHaveCount(1);
});

// ──────────────────────────────────────────────────────────────
// Indexing
// ──────────────────────────────────────────────────────────────

it('delegates index() to the correct driver', function () {
    $driver = se_spyDriver('primary');
    $engine = new SearchEngine(defaultDriver: $driver);

    $result = $engine->index(new IndexRequest('App\Models\Order', 1, ['reference' => 'ORD-001']));

    expect($result)->toBeTrue()
        ->and($driver->indexCalls)->toHaveCount(1);
});

it('returns false and does not throw when indexing fails', function () {
    $engine = new SearchEngine(defaultDriver: se_spyDriver('broken', throws: true));

    expect($engine->index(new IndexRequest('App\Models\Order', 1, [])))->toBeFalse();
});

it('indexMany returns true when all succeed', function () {
    $engine = new SearchEngine(defaultDriver: se_spyDriver('primary'));

    $result = $engine->indexMany([
        new IndexRequest('App\Models\Order', 1, ['ref' => 'A']),
        new IndexRequest('App\Models\Order', 2, ['ref' => 'B']),
    ]);

    expect($result)->toBeTrue();
});

it('indexMany returns false when any driver fails', function () {
    $engine = new SearchEngine(defaultDriver: se_spyDriver('broken', throws: true));

    expect($engine->indexMany([new IndexRequest('App\Models\Order', 1, [])]))->toBeFalse();
});

// ──────────────────────────────────────────────────────────────
// SearchResult Value Object
// ──────────────────────────────────────────────────────────────

it('SearchResult::empty() returns correct defaults', function () {
    $result = SearchResult::empty();

    expect($result->hits)->toBe([])
        ->and($result->total)->toBe(0)
        ->and($result->hasResults())->toBeFalse();
});

it('totalPages() calculates correctly', function () {
    $result = new SearchResult(hits: [], total: 47, page: 1, perPage: 15);
    expect($result->totalPages())->toBe(4);

    $exact = new SearchResult(hits: [], total: 30, page: 1, perPage: 15);
    expect($exact->totalPages())->toBe(2);
});

it('ids() extracts id column from hits', function () {
    $result = new SearchResult(
        hits:    [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']],
        total:   2, page: 1, perPage: 15,
    );

    expect($result->ids())->toBe([1, 2]);
});

// ──────────────────────────────────────────────────────────────
// NullDriver
// ──────────────────────────────────────────────────────────────

it('NullDriver always returns empty results without throwing', function () {
    $driver = new NullDriver();

    expect($driver->search(se_makeSearch('anything'))->hasResults())->toBeFalse()
        ->and($driver->index(new IndexRequest('Entity', 1, [])))->toBeTrue()
        ->and($driver->delete('Entity', 1))->toBeTrue()
        ->and($driver->flush('Entity'))->toBeTrue();
});
