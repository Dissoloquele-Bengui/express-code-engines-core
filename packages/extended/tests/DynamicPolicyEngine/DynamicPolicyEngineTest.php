<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\DynamicPolicyEngine\Conditions\PolicyConditionEvaluator;
use ExpressCodeEngines\Extended\DynamicPolicyEngine\DynamicPolicyEngine;
use ExpressCodeEngines\Shared\DTOs\PolicyContext;
use ExpressCodeEngines\Shared\DTOs\PolicyDefinition;
use ExpressCodeEngines\Shared\DTOs\PolicyRule;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function dp_makeEngine(): DynamicPolicyEngine
{
    return new DynamicPolicyEngine(
        evaluator:    new PolicyConditionEvaluator(),
        cacheEnabled: false, // disable cache in tests
    );
}

function dp_makeUser(int $id = 1, string $role = 'user', int $tenantId = 10): object
{
    return new class ($id, $role, $tenantId) {
        public function __construct(
            public int    $id,
            public string $role,
            public int    $tenant_id,
        ) {}

        public function hasRole(string $r): bool    { return $this->role === $r; }
        public function hasAnyRole(array $rs): bool { return in_array($this->role, $rs, true); }
    };
}

function dp_makeRecord(array $data = []): array
{
    return array_merge(['id' => 99, 'tenant_id' => 10, 'status' => 'draft'], $data);
}

function dp_makeDefinition(array $rules, string $default = 'deny'): PolicyDefinition
{
    return new PolicyDefinition(
        entityClass:   'App\Models\Order',
        rules:         array_map(fn ($r) => PolicyRule::fromArray($r), $rules),
        defaultEffect: $default,
    );
}

function dp_makeContext(
    object  $user,
    string  $ability  = 'view',
    ?array  $record   = null,
    array   $meta     = [],
): PolicyContext {
    return new PolicyContext(
        user:    $user,
        ability: $ability,
        record:  $record,
        meta:    $meta,
    );
}

// ──────────────────────────────────────────────────────────────
// Default effect
// ──────────────────────────────────────────────────────────────

it('denies by default when no rules match and defaultEffect is deny', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition(rules: [], default: 'deny');
    $ctx    = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord());

    expect($engine->can($ctx, $def))->toBeFalse();
});

it('allows by default when no rules match and defaultEffect is allow', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition(rules: [], default: 'allow');
    $ctx    = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord());

    expect($engine->can($ctx, $def))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// Simple allow/deny rules
// ──────────────────────────────────────────────────────────────

it('allows when allow rule condition is met', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'same_tenant', 'ability' => 'view', 'effect' => 'allow',
         'condition' => 'record.tenant_id == user.tenant_id'],
    ]);

    $user = dp_makeUser(tenantId: 10);
    $ctx  = dp_makeContext($user, 'view', dp_makeRecord(['tenant_id' => 10]));

    expect($engine->can($ctx, $def))->toBeTrue();
});

it('denies when allow rule condition is NOT met', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'same_tenant', 'ability' => 'view', 'effect' => 'allow',
         'condition' => 'record.tenant_id == user.tenant_id'],
    ]);

    $user = dp_makeUser(tenantId: 10);
    $ctx  = dp_makeContext($user, 'view', dp_makeRecord(['tenant_id' => 99])); // different tenant

    expect($engine->can($ctx, $def))->toBeFalse();
});

it('denies when deny rule condition is met', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'block_deleted', 'ability' => '*', 'effect' => 'deny',
         'condition' => 'record.status == deleted'],
    ], default: 'allow');

    $ctx = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord(['status' => 'deleted']));

    expect($engine->can($ctx, $def))->toBeFalse();
});

it('allows via wildcard ability when rule uses *', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'all_access', 'ability' => '*', 'effect' => 'allow', 'condition' => null],
    ]);

    foreach (['view', 'update', 'delete', 'approve'] as $ability) {
        $ctx = dp_makeContext(dp_makeUser(), $ability, dp_makeRecord());
        expect($engine->can($ctx, $def))->toBeTrue("Failed for ability: {$ability}");
    }
});

it('skips rules for other abilities', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'allow_view', 'ability' => 'view', 'effect' => 'allow', 'condition' => null],
    ], default: 'deny');

    $ctxView   = dp_makeContext(dp_makeUser(), 'view',   dp_makeRecord());
    $ctxUpdate = dp_makeContext(dp_makeUser(), 'update', dp_makeRecord());

    expect($engine->can($ctxView, $def))->toBeTrue()
        ->and($engine->can($ctxUpdate, $def))->toBeFalse(); // no rule → default deny
});

// ──────────────────────────────────────────────────────────────
// Priority ordering
// ──────────────────────────────────────────────────────────────

it('evaluates rules in priority DESC and stops at first match', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'low',  'ability' => 'view', 'effect' => 'allow', 'condition' => null, 'priority' => 1],
        ['id' => 'high', 'ability' => 'view', 'effect' => 'deny',  'condition' => null, 'priority' => 10],
    ]);

    $ctx = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord());

    // 'high' fires first (deny) — 'low' is never reached
    expect($engine->can($ctx, $def))->toBeFalse();
});

it('falls through to lower priority rule when higher priority condition not met', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'high', 'ability' => 'view', 'effect' => 'deny',
         'condition' => 'record.status == archived', 'priority' => 10],  // won't fire
        ['id' => 'low',  'ability' => 'view', 'effect' => 'allow',
         'condition' => null, 'priority' => 1],
    ]);

    $ctx = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord(['status' => 'active']));

    expect($engine->can($ctx, $def))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// Bypass roles
// ──────────────────────────────────────────────────────────────

it('bypasses all rules and allows when user has a bypass role', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'deny_all', 'ability' => '*', 'effect' => 'deny',
         'condition' => null, 'bypass_roles' => ['admin'], 'priority' => 100],
    ]);

    $admin = dp_makeUser(role: 'admin');
    $ctx   = dp_makeContext($admin, 'delete', dp_makeRecord());

    expect($engine->can($ctx, $def))->toBeTrue();
});

it('does not bypass for users without bypass role', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'deny_all', 'ability' => '*', 'effect' => 'deny',
         'condition' => null, 'bypass_roles' => ['admin']],
    ], default: 'allow');

    $editor = dp_makeUser(role: 'editor');
    $ctx    = dp_makeContext($editor, 'delete', dp_makeRecord());

    // deny_all fires for editor (no bypass) → denied
    expect($engine->can($ctx, $def))->toBeFalse();
});

// ──────────────────────────────────────────────────────────────
// Cross-reference conditions (record.x == user.y)
// ──────────────────────────────────────────────────────────────

it('evaluates cross-reference conditions correctly', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'owner', 'ability' => 'update', 'effect' => 'allow',
         'condition' => 'record.created_by == user.id'],
    ]);

    $user      = dp_makeUser(id: 42);
    $ownRecord = dp_makeContext($user, 'update', dp_makeRecord(['created_by' => 42]));
    $otherRec  = dp_makeContext($user, 'update', dp_makeRecord(['created_by' => 99]));

    expect($engine->can($ownRecord, $def))->toBeTrue()
        ->and($engine->can($otherRec,  $def))->toBeFalse();
});

it('resolves meta values in conditions', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'region_match', 'ability' => 'view', 'effect' => 'allow',
         'condition' => 'user.region == meta.allowed_region'],
    ]);

    $user = new class { public int $id = 1; public string $region = 'EU'; };
    $ctx  = dp_makeContext($user, 'view', dp_makeRecord(), meta: ['allowed_region' => 'EU']);

    expect($engine->can($ctx, $def))->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// scope() — query builder scoping (RLS)
// ──────────────────────────────────────────────────────────────

it('applies WHERE clauses from allow rules to the query builder', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'tenant_scope', 'ability' => 'view', 'effect' => 'allow',
         'condition' => 'record.tenant_id == user.tenant_id'],
    ]);

    $user     = dp_makeUser(tenantId: 10);
    $ctx      = dp_makeContext($user, 'view');
    $captured = [];

    // Spy query builder
    $query = new class ($captured) {
        public function __construct(private array &$log) {}
        public function where(string $col, string $op, mixed $val): static
        {
            $this->log[] = compact('col', 'op', 'val');
            return $this;
        }
    };

    $engine->scope($query, $ctx, $def);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['col'])->toBe('tenant_id')
        ->and($captured[0]['op'])->toBe('=')
        ->and($captured[0]['val'])->toBe(10);
});

it('skips scoping for bypass roles in scope()', function () {
    $engine = dp_makeEngine();
    $def    = dp_makeDefinition([
        ['id' => 'tenant_scope', 'ability' => 'view', 'effect' => 'allow',
         'condition' => 'record.tenant_id == user.tenant_id',
         'bypass_roles' => ['admin']],
    ]);

    $admin    = dp_makeUser(role: 'admin');
    $ctx      = dp_makeContext($admin, 'view');
    $captured = [];

    $query = new class ($captured) {
        public function __construct(private array &$log) {}
        public function where(string $col, string $op, mixed $val): static
        {
            $this->log[] = compact('col', 'op', 'val');
            return $this;
        }
    };

    $engine->scope($query, $ctx, $def);

    expect($captured)->toHaveCount(0); // admin bypasses — no WHERE added
});

// ──────────────────────────────────────────────────────────────
// PolicyConditionEvaluator — unit tests
// ──────────────────────────────────────────────────────────────

it('evaluates literal string conditions', function () {
    $eval = new PolicyConditionEvaluator();
    $ctx  = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord(['status' => 'published']));

    expect($eval->evaluate('record.status == published', $ctx))->toBeTrue()
        ->and($eval->evaluate('record.status == draft',     $ctx))->toBeFalse();
});

it('evaluates numeric comparisons', function () {
    $eval = new PolicyConditionEvaluator();
    $ctx  = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord(['total' => 500]));

    expect($eval->evaluate('record.total > 100',  $ctx))->toBeTrue()
        ->and($eval->evaluate('record.total > 500',  $ctx))->toBeFalse()
        ->and($eval->evaluate('record.total >= 500', $ctx))->toBeTrue()
        ->and($eval->evaluate('record.total < 1000', $ctx))->toBeTrue();
});

it('passes null/true/false literals correctly', function () {
    $eval = new PolicyConditionEvaluator();

    $ctxNull  = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord(['deleted_at' => null]));
    $ctxTrue  = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord(['is_active' => true]));
    $ctxFalse = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord(['is_locked' => false]));

    expect($eval->evaluate('record.deleted_at == null',  $ctxNull))->toBeTrue()
        ->and($eval->evaluate('record.is_active == true',  $ctxTrue))->toBeTrue()
        ->and($eval->evaluate('record.is_locked == false', $ctxFalse))->toBeTrue();
});

it('passes on malformed condition to avoid locking out users', function () {
    $eval = new PolicyConditionEvaluator();
    $ctx  = dp_makeContext(dp_makeUser(), 'view', dp_makeRecord());

    expect($eval->evaluate('this is not valid', $ctx))->toBeTrue();
});
