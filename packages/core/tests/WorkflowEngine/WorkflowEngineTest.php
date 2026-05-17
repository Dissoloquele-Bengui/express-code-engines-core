<?php

declare(strict_types=1);

use ExpressCodeEngines\Core\WorkflowEngine\WorkflowEngine;
use ExpressCodeEngines\Core\WorkflowEngine\Guards\RoleGuard;
use ExpressCodeEngines\Core\WorkflowEngine\Guards\FieldGuard;
use ExpressCodeEngines\Core\WorkflowEngine\Guards\CallableGuard;
use ExpressCodeEngines\Shared\Contracts\WorkflowGuardInterface;
use ExpressCodeEngines\Shared\DTOs\TransitionRequest;
use ExpressCodeEngines\Shared\DTOs\WorkflowDefinition;
use ExpressCodeEngines\Shared\DTOs\TransitionDefinition;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

const ENTITY = 'App\Models\Order';

function wf_engine(array $config, array $guards = []): WorkflowEngine
{
    $definition = WorkflowDefinition::fromArray(ENTITY, $config);

    return new WorkflowEngine(
        definitions: [ENTITY => $definition],
        guards:      $guards,
    );
}

function wf_request(
    string  $currentState,
    string  $transition,
    array   $data   = [],
    ?object $user   = null,
    ?string $reason = null,
): TransitionRequest {
    return new TransitionRequest(
        entityClass:  ENTITY,
        currentState: $currentState,
        transition:   $transition,
        data:         $data,
        user:         $user,
        reason:       $reason,
    );
}

function wf_fakeUser(string $role = 'editor'): object
{
    return new class ($role) {
        public int $id = 42;
        public function __construct(public string $role) {}
        public function hasRole(string $r): bool { return $this->role === $r; }
    };
}

/** Standard Order workflow used across most tests */
function wf_orderWorkflow(): array
{
    return [
        'initial_states' => ['draft'],
        'transitions'    => [
            ['from' => 'draft',     'to' => 'submitted'],
            ['from' => 'submitted', 'to' => 'approved',  'guards' => ['role:manager']],
            ['from' => 'submitted', 'to' => 'rejected',  'guards' => ['role:manager']],
            ['from' => 'approved',  'to' => 'cancelled', 'post_actions' => ['notify_customer']],
        ],
    ];
}

// ──────────────────────────────────────────────────────────────
// Basic transitions
// ──────────────────────────────────────────────────────────────

it('allows a valid transition with no guards', function () {
    $engine = wf_engine(wf_orderWorkflow());
    $result = $engine->transition(wf_request('draft', 'submitted'));

    expect($result->wasAllowed())->toBeTrue()
        ->and($result->newState)->toBe('submitted');
});

it('denies a transition that does not exist from the current state', function () {
    $engine = wf_engine(wf_orderWorkflow());
    $result = $engine->transition(wf_request('draft', 'approved'));

    expect($result->wasAllowed())->toBeFalse()
        ->and($result->denialReason)->toContain("'approved' is not allowed from state 'draft'");
});

it('denies when no workflow is defined for the entity', function () {
    $engine = new WorkflowEngine(definitions: [], guards: []);
    $result = $engine->transition(wf_request('draft', 'submitted'));

    expect($result->wasAllowed())->toBeFalse()
        ->and($result->denialReason)->toContain('No workflow defined');
});

// ──────────────────────────────────────────────────────────────
// Post-actions and metadata
// ──────────────────────────────────────────────────────────────

it('returns configured post_actions on success', function () {
    $engine = wf_engine(wf_orderWorkflow(), [new RoleGuard()]);
    $result = $engine->transition(wf_request('approved', 'cancelled'));

    expect($result->postActions)->toContain('notify_customer');
});

it('includes transition metadata with actor and timestamps', function () {
    $engine = wf_engine(wf_orderWorkflow());
    $result = $engine->transition(
        wf_request('draft', 'submitted', user: wf_fakeUser(), reason: 'Ready for review'),
    );

    expect($result->metadata)
        ->toHaveKey('from', 'draft')
        ->toHaveKey('to', 'submitted')
        ->toHaveKey('actor_id', 42)
        ->toHaveKey('reason', 'Ready for review')
        ->toHaveKey('transitioned_at');
});

// ──────────────────────────────────────────────────────────────
// RoleGuard
// ──────────────────────────────────────────────────────────────

it('allows transition when user has the required role', function () {
    $engine = wf_engine(wf_orderWorkflow(), [new RoleGuard()]);
    $result = $engine->transition(wf_request('submitted', 'approved', user: wf_fakeUser('manager')));

    expect($result->wasAllowed())->toBeTrue();
});

it('denies transition when user does not have the required role', function () {
    $engine = wf_engine(wf_orderWorkflow(), [new RoleGuard()]);
    $result = $engine->transition(wf_request('submitted', 'approved', user: wf_fakeUser('editor')));

    expect($result->wasAllowed())->toBeFalse()
        ->and($result->denialReason)->toContain("Guard 'role:manager' denied");
});

it('denies transition when no user is provided and role guard is required', function () {
    $engine = wf_engine(wf_orderWorkflow(), [new RoleGuard()]);
    $result = $engine->transition(wf_request('submitted', 'approved', user: null));

    expect($result->wasAllowed())->toBeFalse();
});

// ──────────────────────────────────────────────────────────────
// FieldGuard
// ──────────────────────────────────────────────────────────────

it('allows transition when field matches expected value', function () {
    $config = [
        'transitions' => [
            ['from' => 'draft', 'to' => 'locked', 'guards' => ['field:approved_by:null']],
        ],
    ];

    $engine = wf_engine($config, [new FieldGuard()]);
    $result = $engine->transition(wf_request('draft', 'locked', data: ['approved_by' => null]));

    expect($result->wasAllowed())->toBeTrue();
});

it('denies transition when field does not match expected value', function () {
    $config = [
        'transitions' => [
            ['from' => 'draft', 'to' => 'locked', 'guards' => ['field:approved_by:null']],
        ],
    ];

    $engine = wf_engine($config, [new FieldGuard()]);
    $result = $engine->transition(wf_request('draft', 'locked', data: ['approved_by' => 7]));

    expect($result->wasAllowed())->toBeFalse();
});

it('casts field guard special values — null, true, false', function () {
    $guard = new FieldGuard();

    $passesNull  = $guard->passes('field:x:null',  wf_request('a', 'b', ['x' => null]));
    $passesTrue  = $guard->passes('field:x:true',  wf_request('a', 'b', ['x' => true]));
    $passesFalse = $guard->passes('field:x:false', wf_request('a', 'b', ['x' => false]));

    expect($passesNull)->toBeTrue()
        ->and($passesTrue)->toBeTrue()
        ->and($passesFalse)->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// CallableGuard — PHP 8.2 compatible
// ──────────────────────────────────────────────────────────────

// Define a named test guard class (PHP 8.2 doesn't support `new class {}::class`)
class TestCallableAlwaysAllow
{
    public static function check(TransitionRequest $request): bool
    {
        return true;
    }
}

it('delegates to a static callable and passes when it returns true', function () {
    $config = ['transitions' => [['from' => 'a', 'to' => 'b', 'guards' => ['callable:TestCallableAlwaysAllow::check']]]];
    $engine = wf_engine($config, [new CallableGuard()]);
    $result = $engine->transition(wf_request('a', 'b'));

    expect($result->wasAllowed())->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// availableTransitions
// ──────────────────────────────────────────────────────────────

it('returns all valid target states from the current state', function () {
    $engine      = wf_engine(wf_orderWorkflow(), [new RoleGuard()]);
    $transitions = $engine->availableTransitions(ENTITY, 'submitted');

    expect($transitions)->toContain('approved')
        ->toContain('rejected')
        ->not->toContain('submitted');
});

it('returns empty array when entity has no workflow', function () {
    $engine = new WorkflowEngine(definitions: [], guards: []);

    expect($engine->availableTransitions('App\Models\Unknown', 'draft'))->toBe([]);
});

it('returns empty array when no transitions exist from current state', function () {
    $engine = wf_engine(wf_orderWorkflow());

    expect($engine->availableTransitions(ENTITY, 'nonexistent_state'))->toBe([]);
});

// ──────────────────────────────────────────────────────────────
// Guard not registered
// ──────────────────────────────────────────────────────────────

it('denies when a guard expression has no registered handler', function () {
    $engine = wf_engine(wf_orderWorkflow(), []);
    $result = $engine->transition(wf_request('submitted', 'approved', user: wf_fakeUser('manager')));

    expect($result->wasAllowed())->toBeFalse()
        ->and($result->denialReason)->toContain('No guard handler registered');
});

// ──────────────────────────────────────────────────────────────
// WorkflowDefinition helpers
// ──────────────────────────────────────────────────────────────

it('finds transitions from a given state', function () {
    $def         = WorkflowDefinition::fromArray(ENTITY, wf_orderWorkflow());
    $transitions = $def->transitionsFrom('submitted');

    expect($transitions)->toHaveCount(2)
        ->and(array_column($transitions, 'to'))->toContain('approved')
        ->and(array_column($transitions, 'to'))->toContain('rejected');
});

it('returns null when transition does not exist', function () {
    $def = WorkflowDefinition::fromArray(ENTITY, wf_orderWorkflow());

    expect($def->find('draft', 'nonexistent'))->toBeNull();
});