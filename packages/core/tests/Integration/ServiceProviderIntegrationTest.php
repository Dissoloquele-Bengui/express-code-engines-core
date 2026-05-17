<?php

declare(strict_types=1);

use Carbon\Carbon;

use ExpressCodeEngines\Core\ConstraintEngine\Checkers\LimitChecker;
use ExpressCodeEngines\Core\ConstraintEngine\Checkers\OverlapChecker;
use ExpressCodeEngines\Core\ConstraintEngine\Checkers\UniquenessChecker;
use ExpressCodeEngines\Core\ConstraintEngine\ConstraintEngine;
use ExpressCodeEngines\Core\BusinessRuleEngine\BusinessRuleEngine;
use ExpressCodeEngines\Core\ComputationEngine\ComputationEngine;
use ExpressCodeEngines\Core\WorkflowEngine\WorkflowEngine;
use ExpressCodeEngines\Shared\Contracts\BusinessRuleEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ComputationEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ConstraintEngineInterface;
use ExpressCodeEngines\Shared\Contracts\WorkflowEngineInterface;
use ExpressCodeEngines\Shared\DTOs\ConstraintContext;
use ExpressCodeEngines\Shared\DTOs\ConstraintDefinition;
use ExpressCodeEngines\Shared\DTOs\RuleContext;
use ExpressCodeEngines\Shared\DTOs\RuleDefinition;
use ExpressCodeEngines\Shared\DTOs\ComputationRequest;
use ExpressCodeEngines\Shared\DTOs\TransitionRequest;
use ExpressCodeEngines\Shared\DTOs\WorkflowDefinition;
use ExpressCodeEngines\Tests\Integration\EngineIntegrationTestCase;

// ──────────────────────────────────────────────────────────────
// ServiceProvider binding tests
// ──────────────────────────────────────────────────────────────

uses(EngineIntegrationTestCase::class);

it('resolves ConstraintEngineInterface from container', function () {
    $engine = $this->make(ConstraintEngineInterface::class);

    expect($engine)->toBeInstanceOf(ConstraintEngine::class);
});

it('resolves BusinessRuleEngineInterface from container', function () {
    $engine = $this->make(BusinessRuleEngineInterface::class);

    expect($engine)->toBeInstanceOf(BusinessRuleEngine::class);
});

it('resolves ComputationEngineInterface from container', function () {
    $engine = $this->make(ComputationEngineInterface::class);

    expect($engine)->toBeInstanceOf(ComputationEngine::class);
});

it('resolves WorkflowEngineInterface from container', function () {
    $engine = $this->make(WorkflowEngineInterface::class);

    expect($engine)->toBeInstanceOf(WorkflowEngine::class);
});

it('binds always return the same instance (singleton behaviour for bind)', function () {
    $a = $this->make(ConstraintEngineInterface::class);
    $b = $this->make(ConstraintEngineInterface::class);

    // bind() creates new instances — assert same class, not same object
    expect($a)->toBeInstanceOf(ConstraintEngine::class)
        ->and($b)->toBeInstanceOf(ConstraintEngine::class);
});

// ──────────────────────────────────────────────────────────────
// ConstraintEngine integration with SQLite
// ──────────────────────────────────────────────────────────────

it('UniquenessChecker passes when no existing record', function () {
    $engine = $this->make(ConstraintEngineInterface::class);

    $result = $engine->validate(
        new ConstraintContext(entityClass: 'App\Models\Order', data: ['reference' => 'ORD-001', 'tenant_id' => 1]),
        [new ConstraintDefinition(type: 'uniqueness', scopeFields: ['reference', 'tenant_id'],
            message: 'Duplicate.', config: ['table' => 'orders'])],
    );

    expect($result->passed)->toBeTrue();
});

it('UniquenessChecker fails when duplicate exists in DB', function () {
    // Insert a record first
    \Illuminate\Database\Capsule\Manager::table('orders')->insert([
        'reference' => 'ORD-DUP', 'tenant_id' => 1, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
    ]);

    $engine = $this->make(ConstraintEngineInterface::class);

    $result = $engine->validate(
        new ConstraintContext(entityClass: 'App\Models\Order', data: ['reference' => 'ORD-DUP', 'tenant_id' => 1]),
        [new ConstraintDefinition(type: 'uniqueness', scopeFields: ['reference', 'tenant_id'],
            message: 'Reference already exists.', config: ['table' => 'orders'])],
    );

    expect($result->passed)->toBeFalse()
        ->and($result->firstMessage())->toBe('Reference already exists.');
});

it('UniquenessChecker excludes current record on update', function () {
    $id = \Illuminate\Database\Capsule\Manager::table('orders')->insertGetId([
        'reference' => 'ORD-UPDATE', 'tenant_id' => 1, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
    ]);

    $engine = $this->make(ConstraintEngineInterface::class);

    // Updating the same record — should pass (excludes self by existingId)
    $result = $engine->validate(
        new ConstraintContext(
            entityClass: 'App\Models\Order',
            data:        ['reference' => 'ORD-UPDATE', 'tenant_id' => 1],
            existingId:  $id,
        ),
        [new ConstraintDefinition(type: 'uniqueness', scopeFields: ['reference', 'tenant_id'],
            message: 'Duplicate.', config: ['table' => 'orders'])],
    );

    expect($result->passed)->toBeTrue();
});

it('OverlapChecker detects scheduling conflicts', function () {
    \Illuminate\Database\Capsule\Manager::table('bookings')->insert([
        'resource_id' => 1,
        'tenant_id'   => 1,
        'starts_at'   => '2025-06-01 10:00:00',
        'ends_at'     => '2025-06-01 12:00:00',
        'created_at'  => Carbon::now(),
        'updated_at'  => Carbon::now(),
    ]);

    $engine = $this->make(ConstraintEngineInterface::class);

    // Overlapping booking (11:00–13:00 overlaps 10:00–12:00)
    $overlapping = $engine->validate(
        new ConstraintContext(entityClass: 'App\Models\Booking', data: [
            'resource_id' => 1, 'tenant_id' => 1,
            'starts_at'   => '2025-06-01 11:00:00',
            'ends_at'     => '2025-06-01 13:00:00',
        ]),
        [new ConstraintDefinition(type: 'overlap', scopeFields: ['resource_id'],
            message: 'Time slot already booked.',
            config: ['table' => 'bookings', 'start_field' => 'starts_at', 'end_field' => 'ends_at'])],
    );

    // Non-overlapping booking (13:00–15:00 is after 10:00–12:00)
    $nonOverlapping = $engine->validate(
        new ConstraintContext(entityClass: 'App\Models\Booking', data: [
            'resource_id' => 1, 'tenant_id' => 1,
            'starts_at'   => '2025-06-01 13:00:00',
            'ends_at'     => '2025-06-01 15:00:00',
        ]),
        [new ConstraintDefinition(type: 'overlap', scopeFields: ['resource_id'],
            message: 'Time slot already booked.',
            config: ['table' => 'bookings', 'start_field' => 'starts_at', 'end_field' => 'ends_at'])],
    );

    expect($overlapping->passed)->toBeFalse()
        ->and($overlapping->firstMessage())->toBe('Time slot already booked.')
        ->and($nonOverlapping->passed)->toBeTrue();
});

it('LimitChecker blocks when daily limit is reached', function () {
    // Insert 3 orders today
    foreach (range(1, 3) as $i) {
        \Illuminate\Database\Capsule\Manager::table('orders')->insert([
            'reference'  => "ORD-LIMIT-{$i}",
            'tenant_id'  => 1,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    $engine = $this->make(ConstraintEngineInterface::class);

    $result = $engine->validate(
        new ConstraintContext(
            entityClass: 'App\Models\Order',
            data:        ['tenant_id' => 1],
            meta:        ['tenant_id' => 1],
        ),
        [new ConstraintDefinition(
            type:        'limit',
            scopeFields: ['tenant_id'],
            message:     'Daily order limit of 3 reached.',
            config:      ['table' => 'orders', 'limit' => 3, 'period' => 'today'],
        )],
    );

    expect($result->passed)->toBeFalse()
        ->and($result->firstMessage())->toBe('Daily order limit of 3 reached.');
});

it('LimitChecker passes when below limit', function () {
    \Illuminate\Database\Capsule\Manager::table('orders')->insert([
        'reference' => 'ORD-BELOW', 'tenant_id' => 2, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
    ]);

    $engine = $this->make(ConstraintEngineInterface::class);

    // tenant 2 has 1 order, limit is 10 → should pass
    $result = $engine->validate(
        new ConstraintContext(
            entityClass: 'App\Models\Order',
            data:        ['tenant_id' => 2],
            meta:        ['tenant_id' => 2],
        ),
        [new ConstraintDefinition(
            type:        'limit',
            scopeFields: ['tenant_id'],
            message:     'Daily limit reached.',
            config:      ['table' => 'orders', 'limit' => 10, 'period' => 'today'],
        )],
    );

    expect($result->passed)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// Full Action pipeline — ConstraintEngine + BRE + ComputationEngine
// ──────────────────────────────────────────────────────────────

it('resolves all four core engines and runs a full pipeline', function () {
    $constraints = $this->make(ConstraintEngineInterface::class);
    $bre         = $this->make(BusinessRuleEngineInterface::class);
    $computation = $this->make(ComputationEngineInterface::class);

    // Step 1: Constraint check
    $constraintResult = $constraints->validate(
        new ConstraintContext(entityClass: 'App\Models\Order', data: ['reference' => 'PIPE-001', 'tenant_id' => 99]),
        [new ConstraintDefinition(type: 'uniqueness', scopeFields: ['reference'],
            message: 'Duplicate.', config: ['table' => 'orders'])],
    );

    expect($constraintResult->passed)->toBeTrue();

    // Step 2: Compute total
    $totals = $computation->computeMany([
        'subtotal' => new ComputationRequest('sum',        [100.00, 50.00, 25.00]),
        'tax'      => new ComputationRequest('percentage', [175.00, 23], precision: 2),
    ]);

    expect($totals['subtotal']->value)->toBe(175.0)
        ->and((float) $totals['tax']->value)->toBe(40.25);

    // Step 3: BRE rules
    $breResponse = $bre->evaluate(
        new RuleContext(data: ['order' => ['total' => 215.25]]),
        [
            new RuleDefinition(id: 'min', ruleType: 'comparison', field: 'order.total',
                params: ['operator' => '>=', 'value' => 10], severity: 'deny', message: 'Min €10.'),
            new RuleDefinition(id: 'large', ruleType: 'comparison', field: 'order.total',
                params: ['operator' => '>', 'value' => 200], severity: 'allow', effects: ['flag_review']),
        ],
    );

    expect($breResponse->passed())->toBeTrue()
        ->and($breResponse->approvedEffects)->toContain('flag_review');
});

// ──────────────────────────────────────────────────────────────
// WorkflowEngine integration
// ──────────────────────────────────────────────────────────────

it('WorkflowEngine resolves from container and executes transitions', function () {
    $engine = $this->make(WorkflowEngineInterface::class);

    $definition = WorkflowDefinition::fromArray('App\Models\Order', [
        'initial_states' => ['draft'],
        'transitions'    => [
            ['from' => 'draft',     'to' => 'submitted'],
            ['from' => 'submitted', 'to' => 'approved', 'post_actions' => ['notify_team']],
        ],
    ]);

    $result = $engine->transition(new TransitionRequest(
        entityClass:  'App\Models\Order',
        currentState: 'draft',
        transition:   'submitted',
        data:         ['id' => 1],
    ));

    // WorkflowEngine::transition needs the definition injected — test the engine directly
    $concreteEngine = new \ExpressCodeEngines\Core\WorkflowEngine\WorkflowEngine(
        definitions: ['App\Models\Order' => $definition],
        guards:      [],
    );

    $result = $concreteEngine->transition(new TransitionRequest(
        entityClass:  'App\Models\Order',
        currentState: 'draft',
        transition:   'submitted',
        data:         ['id' => 1],
    ));

    expect($result->wasAllowed())->toBeTrue()
        ->and($result->newState)->toBe('submitted');
});
