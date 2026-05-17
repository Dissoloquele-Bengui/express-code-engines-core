<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\OrchestrationEngine\OrchestrationEngine;
use ExpressCodeEngines\Shared\DTOs\OrchestrationFlow;
use ExpressCodeEngines\Shared\DTOs\OrchestrationStep;
use ExpressCodeEngines\Shared\ValueObjects\OrchestrationResult;

// ──────────────────────────────────────────────────────────────
// Fake container — resolves handlers from a local registry
// ──────────────────────────────────────────────────────────────

function orch_makeEngine(array $bindings = []): OrchestrationEngine
{
    $container = new class ($bindings) implements \Illuminate\Contracts\Container\Container {
        public function __construct(private array $bindings) {}

        public function make($abstract, array $parameters = [])
        {
            if (isset($this->bindings[$abstract])) {
                $factory = $this->bindings[$abstract];
                return $factory();
            }
            throw new \RuntimeException("Not bound: {$abstract}");
        }

        // Stub remaining interface methods
        public function bound($abstract) { return isset($this->bindings[$abstract]); }
        public function alias($abstract, $alias) {}
        public function tag($abstracts, ...$tags) {}
        public function tagged($tag) { return []; }
        public function bind($abstract, $concrete = null, $shared = false) {}
        public function bindIf($abstract, $concrete = null, $shared = false) {}
        public function singleton($abstract, $concrete = null) {}
        public function singletonIf($abstract, $concrete = null) {}
        public function scoped($abstract, $concrete = null) {}
        public function scopedIf($abstract, $concrete = null) {}
        public function extend($abstract, \Closure $closure) {}
        public function instance($abstract, $instance) {}
        public function addContextualBinding($concrete, $abstract, $implementation) {}
        public function when($concrete) { return new class { public function needs($a) { return $this; } public function give($i) {} }; }
        public function factory($abstract) { return fn () => $this->make($abstract); }
        public function flush() {}
        public function resolved($abstract) { return false; }
        public function beforeResolving($abstract, \Closure $callback = null) {}
        public function resolving($abstract, \Closure $callback = null) {}
        public function afterResolving($abstract, \Closure $callback = null) {}
        public function bindMethod($method, $callback) {}
        public function call($callback, array $parameters = [], $defaultMethod = null) { return null; }
        public function get(string $id) { return $this->make($id); }
        public function has(string $id): bool { return $this->bound($id); }
    };

    return new OrchestrationEngine($container);
}

/** Creates a handler stub that returns the given output array */
function orch_handler(array $output = [], bool $throws = false): object
{
    return new class ($output, $throws) {
        public array $calls = [];
        public function __construct(private array $output, private bool $throws) {}
        public function execute(array $input, array $payload): array
        {
            $this->calls[] = compact('input', 'payload');
            if ($this->throws) throw new \RuntimeException('Step handler failed.');
            return $this->output;
        }
    };
}

/** Creates a compensation stub */
function orch_compensation(bool $throws = false): object
{
    return new class ($throws) {
        public array $calls = [];
        public function __construct(private bool $throws) {}
        public function compensate(array $input, array $payload): void
        {
            $this->calls[] = compact('input', 'payload');
            if ($this->throws) throw new \RuntimeException('Compensation failed.');
        }
    };
}

function orch_makeFlow(array $steps, string $key = 'test_flow'): OrchestrationFlow
{
    return new OrchestrationFlow(key: $key, steps: $steps);
}

function orch_makeStep(string $id, string $handler, array $extra = []): OrchestrationStep
{
    return new OrchestrationStep(...array_merge(['id' => $id, 'handler' => $handler], $extra));
}

// ──────────────────────────────────────────────────────────────
// Basic execution
// ──────────────────────────────────────────────────────────────

it('executes all steps in order and returns success', function () {
    $h1 = orch_handler(['step1_result' => 'done']);
    $h2 = orch_handler(['step2_result' => 'done']);

    $engine = orch_makeEngine([
        'Step1' => fn () => $h1,
        'Step2' => fn () => $h2,
    ]);

    $result = $engine->run(orch_makeFlow([
        orch_makeStep('step1', 'Step1'),
        orch_makeStep('step2', 'Step2'),
    ]), ['initial' => 'data']);

    expect($result->success)->toBeTrue()
        ->and($result->completedSteps)->toBe(2)
        ->and($result->payload)->toHaveKey('step1_result')
        ->and($result->payload)->toHaveKey('step2_result')
        ->and($result->log)->toHaveCount(2);
});

it('accumulates payload across steps', function () {
    $h1 = orch_handler(['order_id' => 42]);
    $h2 = orch_handler(['invoice_id' => 99]);

    $engine = orch_makeEngine(['H1' => fn () => $h1, 'H2' => fn () => $h2]);

    $result = $engine->run(orch_makeFlow([orch_makeStep('s1', 'H1'), orch_makeStep('s2', 'H2')]));

    // Both outputs must be present in final payload
    expect($result->payload['order_id'])->toBe(42)
        ->and($result->payload['invoice_id'])->toBe(99);
});

it('passes full accumulated payload to each step when no input mapping', function () {
    $h1 = orch_handler(['added' => 'by_h1']);
    $h2 = orch_handler([]);

    $engine = orch_makeEngine(['H1' => fn () => $h1, 'H2' => fn () => $h2]);

    $engine->run(orch_makeFlow([orch_makeStep('s1', 'H1'), orch_makeStep('s2', 'H2')]), ['seed' => 'value']);

    // H2 receives the accumulated payload including H1's output
    expect($h2->calls[0]['payload'])->toHaveKey('seed')
        ->and($h2->calls[0]['payload'])->toHaveKey('added');
});

it('resolves input mapping to extract subset of payload for a step', function () {
    $h1 = orch_handler(['order' => ['id' => 7, 'ref' => 'ORD-007']]);
    $h2 = orch_handler([]);

    $engine = orch_makeEngine(['H1' => fn () => $h1, 'H2' => fn () => $h2]);

    $engine->run(orch_makeFlow([
        orch_makeStep('s1', 'H1'),
        orch_makeStep('s2', 'H2', ['input' => ['order_id' => 'order.id', 'order_ref' => 'order.ref']]),
    ]));

    expect($h2->calls[0]['input'])->toBe(['order_id' => 7, 'order_ref' => 'ORD-007']);
});

// ──────────────────────────────────────────────────────────────
// Failure and compensation
// ──────────────────────────────────────────────────────────────

it('stops at the failing step and returns failed result', function () {
    $h1 = orch_handler(['step1' => 'done']);
    $h2 = orch_handler(throws: true);
    $h3 = orch_handler(['step3' => 'done']);

    $engine = orch_makeEngine([
        'H1' => fn () => $h1,
        'H2' => fn () => $h2,
        'H3' => fn () => $h3,
    ]);

    $result = $engine->run(orch_makeFlow([
        orch_makeStep('s1', 'H1'),
        orch_makeStep('s2', 'H2'),
        orch_makeStep('s3', 'H3'),
    ]));

    expect($result->success)->toBeFalse()
        ->and($result->failedAt)->toBe('s2')
        ->and($result->completedSteps)->toBe(1);

    // H3 must never have been called
    expect($h3->calls)->toHaveCount(0);
});

it('compensates completed steps in LIFO order on failure', function () {
    $comp1  = orch_compensation();
    $comp2  = orch_compensation();
    $broken = orch_handler(throws: true);

    $engine = orch_makeEngine([
        'H1'    => fn () => orch_handler(['r' => 1]),
        'H2'    => fn () => orch_handler(['r' => 2]),
        'H3'    => fn () => $broken,
        'Comp1' => fn () => $comp1,
        'Comp2' => fn () => $comp2,
    ]);

    $result = $engine->run(orch_makeFlow([
        orch_makeStep('s1', 'H1', ['compensation' => 'Comp1']),
        orch_makeStep('s2', 'H2', ['compensation' => 'Comp2']),
        orch_makeStep('s3', 'H3'),  // no compensation
    ]));

    expect($result->compensatedSteps)->toBe(2);

    // Compensations run LIFO: Comp2 first, then Comp1
    expect($comp2->calls)->toHaveCount(1); // ran
    expect($comp1->calls)->toHaveCount(1); // ran
});

it('continues the flow when continueOnFailure is true', function () {
    $h1     = orch_handler(['ok' => true]);
    $broken = orch_handler(throws: true);
    $h3     = orch_handler(['final' => true]);

    $engine = orch_makeEngine([
        'H1'     => fn () => $h1,
        'Broken' => fn () => $broken,
        'H3'     => fn () => $h3,
    ]);

    $result = $engine->run(orch_makeFlow([
        orch_makeStep('s1', 'H1'),
        orch_makeStep('s2', 'Broken', ['continueOnFailure' => true]),
        orch_makeStep('s3', 'H3'),
    ]));

    // Overall success (continue_on_failure means s2 failure is tolerated)
    expect($result->success)->toBeTrue()
        ->and($h3->calls)->toHaveCount(1)
        ->and($result->log[1]['success'])->toBeFalse()   // s2 logged as failed
        ->and($result->log[2]['success'])->toBeTrue();   // s3 logged as succeeded
});

it('does not throw when a compensation handler fails', function () {
    $brokenComp = orch_compensation(throws: true);

    $engine = orch_makeEngine([
        'H1'      => fn () => orch_handler([]),
        'BrokenH' => fn () => orch_handler(throws: true),
        'BC'      => fn () => $brokenComp,
    ]);

    // Should not throw — compensation failures are logged, not re-thrown
    $result = $engine->run(orch_makeFlow([
        orch_makeStep('s1', 'H1', ['compensation' => 'BC']),
        orch_makeStep('s2', 'BrokenH'),
    ]));

    expect($result->isFailed())->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// Retry
// ──────────────────────────────────────────────────────────────

it('retries a failing step the configured number of times', function () {
    $attempt = 0;
    $handler = new class ($attempt) {
        public function __construct(private int &$count) {}
        public function execute(array $input, array $payload): array
        {
            $this->count++;
            if ($this->count < 3) throw new \RuntimeException('Not yet.');
            return ['retried' => true];
        }
    };

    $engine = orch_makeEngine(['H' => fn () => $handler]);

    $result = $engine->run(orch_makeFlow([
        orch_makeStep('s1', 'H', ['retries' => 2]), // 3 total attempts
    ]));

    expect($result->success)->toBeTrue()
        ->and($attempt)->toBe(3)
        ->and($result->log[0]['attempts'])->toBe(3);
});

it('fails after exhausting all retry attempts', function () {
    $engine = orch_makeEngine(['H' => fn () => orch_handler(throws: true)]);

    $result = $engine->run(orch_makeFlow([
        orch_makeStep('s1', 'H', ['retries' => 2]),
    ]));

    expect($result->isFailed())->toBeTrue()
        ->and($result->log[0]['attempts'])->toBe(3);
});

// ──────────────────────────────────────────────────────────────
// Empty flow
// ──────────────────────────────────────────────────────────────

it('succeeds immediately with no steps', function () {
    $engine = orch_makeEngine();
    $result = $engine->run(orch_makeFlow([]), ['seed' => 'data']);

    expect($result->success)->toBeTrue()
        ->and($result->completedSteps)->toBe(0)
        ->and($result->payload['seed'])->toBe('data');
});

// ──────────────────────────────────────────────────────────────
// OrchestrationResult Value Object
// ──────────────────────────────────────────────────────────────

it('OrchestrationResult::success() is not failed', function () {
    $result = OrchestrationResult::success(['key' => 'val'], completedSteps: 3, log: []);
    expect($result->isFailed())->toBeFalse()
        ->and($result->completedSteps)->toBe(3);
});

it('OrchestrationResult::failed() exposes failure details', function () {
    $result = OrchestrationResult::failed('step2', 'Oops.', [], 1, 1, []);
    expect($result->isFailed())->toBeTrue()
        ->and($result->failedAt)->toBe('step2')
        ->and($result->errorMessage)->toBe('Oops.')
        ->and($result->compensatedSteps)->toBe(1);
});
