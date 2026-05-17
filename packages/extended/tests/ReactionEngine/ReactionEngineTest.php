<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\ReactionEngine\Conditions\ConditionEvaluator;
use ExpressCodeEngines\Extended\ReactionEngine\ReactionEngine;
use ExpressCodeEngines\Extended\ReactionEngine\ReactionHandlerRegistry;
use ExpressCodeEngines\Shared\Contracts\HandlerResult;
use ExpressCodeEngines\Shared\Contracts\ReactionHandlerInterface;
use ExpressCodeEngines\Shared\DTOs\ReactionContext;
use ExpressCodeEngines\Shared\DTOs\ReactionDefinition;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function rx_makeReactionEngine(array $handlers = []): ReactionEngine
{
    return new ReactionEngine(
        registry:   new ReactionHandlerRegistry($handlers),
        conditions: new ConditionEvaluator(),
    );
}

function rx_makeHandler(string $key, bool $succeeds = true, bool $throws = false): ReactionHandlerInterface
{
    return new class ($key, $succeeds, $throws) implements ReactionHandlerInterface {
        public function __construct(
            private string $k,
            private bool $succeeds,
            private bool $throws,
        ) {}

        public function key(): string { return $this->k; }

        public function handle(ReactionContext $ctx, array $params): HandlerResult
        {
            if ($this->throws) throw new \RuntimeException('Handler exploded.');
            return $this->succeeds ? HandlerResult::ok() : HandlerResult::failed('Simulated failure.');
        }

        public function dispatchAsync(ReactionContext $ctx, array $params, ?string $queue, int $delay): void
        {
            // No-op in tests — async dispatch not testable without Laravel
        }
    };
}

function rx_makeContext(string $event, array $payload = []): ReactionContext
{
    return new ReactionContext(event: $event, payload: $payload);
}

function rx_makeDef(
    string  $event,
    string  $handler,
    array   $params   = [],
    ?string $onlyIf   = null,
    bool    $async    = false,
): ReactionDefinition {
    return new ReactionDefinition(
        event:   $event,
        handler: $handler,
        params:  $params,
        onlyIf:  $onlyIf,
        async:   $async,
    );
}

// ──────────────────────────────────────────────────────────────
// Event matching
// ──────────────────────────────────────────────────────────────

it('dispatches handler when event matches exactly', function () {
    $engine  = rx_makeReactionEngine([rx_makeHandler('notify')]);
    $summary = $engine->react(
        rx_makeContext('order.submitted'),
        [rx_makeDef('order.submitted', 'notify')],
    );

    expect($summary->matched)->toBe(1)
        ->and($summary->dispatched)->toBe(1)
        ->and($summary->failed)->toBe(0);
});

it('skips definitions that do not match the event', function () {
    $engine  = rx_makeReactionEngine([rx_makeHandler('notify')]);
    $summary = $engine->react(
        rx_makeContext('invoice.paid'),
        [rx_makeDef('order.submitted', 'notify')],
    );

    expect($summary->matched)->toBe(0)
        ->and($summary->dispatched)->toBe(0);
});

it('matches wildcard event patterns', function () {
    $engine  = rx_makeReactionEngine([rx_makeHandler('notify')]);
    $summary = $engine->react(
        rx_makeContext('order.approved'),
        [rx_makeDef('order.*', 'notify')],
    );

    expect($summary->matched)->toBe(1)
        ->and($summary->dispatched)->toBe(1);
});

it('wildcard does not match across multiple segments', function () {
    $evaluator = new ConditionEvaluator();

    expect($evaluator->eventMatches('order.*', 'order.items.updated'))->toBeFalse()
        ->and($evaluator->eventMatches('order.*', 'order.submitted'))->toBeTrue();
});

it('dispatches multiple matching definitions', function () {
    $engine  = rx_makeReactionEngine([rx_makeHandler('notify'), rx_makeHandler('audit')]);
    $summary = $engine->react(
        rx_makeContext('order.submitted'),
        [
            rx_makeDef('order.submitted', 'notify'),
            rx_makeDef('order.submitted', 'audit'),
            rx_makeDef('invoice.paid',    'notify'), // ← should not match
        ],
    );

    expect($summary->matched)->toBe(2)
        ->and($summary->dispatched)->toBe(2);
});

// ──────────────────────────────────────────────────────────────
// onlyIf conditions
// ──────────────────────────────────────────────────────────────

it('skips handler when onlyIf condition is not met', function () {
    $engine  = rx_makeReactionEngine([rx_makeHandler('notify')]);
    $summary = $engine->react(
        rx_makeContext('order.submitted', ['order' => ['total' => 50]]),
        [rx_makeDef('order.submitted', 'notify', onlyIf: 'order.total > 100')],
    );

    expect($summary->matched)->toBe(1)
        ->and($summary->dispatched)->toBe(0);
});

it('dispatches handler when onlyIf condition IS met', function () {
    $engine  = rx_makeReactionEngine([rx_makeHandler('notify')]);
    $summary = $engine->react(
        rx_makeContext('order.submitted', ['order' => ['total' => 250]]),
        [rx_makeDef('order.submitted', 'notify', onlyIf: 'order.total > 100')],
    );

    expect($summary->dispatched)->toBe(1);
});

it('passes when onlyIf is null — no condition required', function () {
    $engine  = rx_makeReactionEngine([rx_makeHandler('notify')]);
    $summary = $engine->react(
        rx_makeContext('order.submitted'),
        [rx_makeDef('order.submitted', 'notify', onlyIf: null)],
    );

    expect($summary->dispatched)->toBe(1);
});

// ──────────────────────────────────────────────────────────────
// Failure isolation
// ──────────────────────────────────────────────────────────────

it('continues processing when a sync handler returns failure', function () {
    $engine  = rx_makeReactionEngine([
        rx_makeHandler('failing_handler', succeeds: false),
        rx_makeHandler('passing_handler', succeeds: true),
    ]);

    $summary = $engine->react(
        rx_makeContext('order.submitted'),
        [
            rx_makeDef('order.submitted', 'failing_handler', async: false),
            rx_makeDef('order.submitted', 'passing_handler', async: false),
        ],
    );

    expect($summary->dispatched)->toBe(2)
        ->and($summary->failed)->toBe(1);
});

it('continues processing when a sync handler throws an exception', function () {
    $engine  = rx_makeReactionEngine([
        rx_makeHandler('explosive', succeeds: true, throws: true),
        rx_makeHandler('stable',    succeeds: true, throws: false),
    ]);

    $summary = $engine->react(
        rx_makeContext('order.submitted'),
        [
            rx_makeDef('order.submitted', 'explosive', async: false),
            rx_makeDef('order.submitted', 'stable',    async: false),
        ],
    );

    expect($summary->dispatched)->toBe(2)
        ->and($summary->failed)->toBe(1)
        ->and($summary->results[0]['error'])->toBe('Handler exploded.');
});

it('records a failure when handler key is not registered', function () {
    $engine  = rx_makeReactionEngine([]); // no handlers
    $summary = $engine->react(
        rx_makeContext('order.submitted'),
        [rx_makeDef('order.submitted', 'nonexistent')],
    );

    expect($summary->matched)->toBe(1)
        ->and($summary->dispatched)->toBe(0)
        ->and($summary->failed)->toBe(1)
        ->and($summary->results[0]['error'])->toContain("No handler registered for key 'nonexistent'");
});

// ──────────────────────────────────────────────────────────────
// Async vs sync dispatch
// ──────────────────────────────────────────────────────────────

it('marks async dispatches in results without calling handle()', function () {
    $called  = false;

    $asyncHandler = new class ($called) implements ReactionHandlerInterface {
        public function __construct(private bool &$called) {}
        public function key(): string { return 'async_handler'; }
        public function handle(ReactionContext $ctx, array $params): HandlerResult
        {
            $this->called = true; // should NOT be called
            return HandlerResult::ok();
        }
        public function dispatchAsync(ReactionContext $ctx, array $params, ?string $queue, int $delay): void
        {
            // queued — no-op in test
        }
    };

    $engine  = rx_makeReactionEngine([$asyncHandler]);
    $summary = $engine->react(
        rx_makeContext('order.submitted'),
        [rx_makeDef('order.submitted', 'async_handler', async: true)],
    );

    expect($called)->toBeFalse()
        ->and($summary->dispatched)->toBe(1)
        ->and($summary->results[0]['async'])->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// Empty / edge cases
// ──────────────────────────────────────────────────────────────

it('returns empty summary when no definitions are provided', function () {
    $engine  = rx_makeReactionEngine([rx_makeHandler('notify')]);
    $summary = $engine->react(rx_makeContext('order.submitted'), []);

    expect($summary->matched)->toBe(0)
        ->and($summary->dispatched)->toBe(0)
        ->and($summary->failed)->toBe(0);
});

it('returns summary with hasFailures() helper', function () {
    $engine  = rx_makeReactionEngine([rx_makeHandler('h', succeeds: false)]);
    $summary = $engine->react(
        rx_makeContext('order.submitted'),
        [rx_makeDef('order.submitted', 'h', async: false)],
    );

    expect($summary->hasFailures())->toBeTrue()
        ->and($summary->allSucceeded())->toBeFalse();
});

// ──────────────────────────────────────────────────────────────
// ConditionEvaluator — unit tests
// ──────────────────────────────────────────────────────────────

it('evaluates all comparison operators correctly', function () {
    $eval = new ConditionEvaluator();
    $ctx  = rx_makeContext('e', ['val' => 50]);

    expect($eval->passes('val == 50',  $ctx))->toBeTrue()
        ->and($eval->passes('val != 50',  $ctx))->toBeFalse()
        ->and($eval->passes('val > 40',   $ctx))->toBeTrue()
        ->and($eval->passes('val >= 50',  $ctx))->toBeTrue()
        ->and($eval->passes('val < 60',   $ctx))->toBeTrue()
        ->and($eval->passes('val <= 50',  $ctx))->toBeTrue();
});

it('casts special values in conditions', function () {
    $eval = new ConditionEvaluator();

    expect($eval->passes('x == null',  rx_makeContext('e', ['x' => null])))->toBeTrue()
        ->and($eval->passes('x == true',  rx_makeContext('e', ['x' => true])))->toBeTrue()
        ->and($eval->passes('x == false', rx_makeContext('e', ['x' => false])))->toBeTrue()
        ->and($eval->passes("x == 'hello'", rx_makeContext('e', ['x' => 'hello'])))->toBeTrue();
});

it('passes malformed conditions to avoid silently blocking reactions', function () {
    $eval = new ConditionEvaluator();
    $ctx  = rx_makeContext('e', []);

    expect($eval->passes('this is not valid', $ctx))->toBeTrue();
});
