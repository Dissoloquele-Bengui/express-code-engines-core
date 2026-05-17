<?php

declare(strict_types=1);

use ExpressCodeEngines\Core\BusinessRuleEngine\BusinessRuleEngine;
use ExpressCodeEngines\Core\BusinessRuleEngine\RuleEvaluatorRegistry;
use ExpressCodeEngines\Shared\DTOs\RuleContext;
use ExpressCodeEngines\Shared\DTOs\RuleDefinition;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function bre_engine(): BusinessRuleEngine
{
    return new BusinessRuleEngine(new RuleEvaluatorRegistry());
}

function bre_context(array $data = [], ?object $user = null): RuleContext
{
    return new RuleContext(data: $data, user: $user);
}

function bre_comparisonRule(
    string $id,
    string $field,
    string $operator,
    mixed $value,
    string $severity = 'deny',
    int $priority = 0,
    string $message = 'Rule triggered.',
    array $effects = [],
    ?string $onlyIf = null,
): RuleDefinition {
    return new RuleDefinition(
        id:       $id,
        ruleType: 'comparison',
        field:    $field,
        params:   ['operator' => $operator, 'value' => $value],
        severity: $severity,
        priority: $priority,
        message:  $message,
        effects:  $effects,
        onlyIf:   $onlyIf,
    );
}

// ──────────────────────────────────────────────────────────────
// Basic evaluation
// ──────────────────────────────────────────────────────────────

it('passes when no rules fire', function () {
    $engine   = bre_engine();
    $response = $engine->evaluate(
        bre_context(['order' => ['total' => 100]]),
        [bre_comparisonRule('r1', 'order.total', '<', 50)],
    );

    expect($response->passed())->toBeTrue()
        ->and($response->denials)->toBeEmpty()
        ->and($response->warnings)->toBeEmpty();
});

it('produces a denial when a deny-severity rule fires', function () {
    $engine   = bre_engine();
    $response = $engine->evaluate(
        bre_context(['order' => ['total' => 5]]),
        [bre_comparisonRule('min_value', 'order.total', '<', 10, 'deny', message: 'Minimum is €10.')],
    );

    expect($response->hasDenials())->toBeTrue()
        ->and($response->firstDenialMessage())->toBe('Minimum is €10.')
        ->and($response->denials[0]['rule_id'])->toBe('min_value');
});

it('produces a warning but does not deny', function () {
    $engine   = bre_engine();
    $response = $engine->evaluate(
        bre_context(['order' => ['total' => 4500]]),
        [bre_comparisonRule('large_order', 'order.total', '>', 4000, 'warn', message: 'Large order — review recommended.')],
    );

    expect($response->passed())->toBeTrue()
        ->and($response->hasWarnings())->toBeTrue()
        ->and($response->warnings[0]['rule_id'])->toBe('large_order');
});

it('returns approved effects for allow-severity rules', function () {
    $engine   = bre_engine();
    $response = $engine->evaluate(
        bre_context(['order' => ['total' => 6000]]),
        [bre_comparisonRule('notify_mgr', 'order.total', '>', 5000, 'allow', effects: ['notify_manager', 'flag_review'])],
    );

    expect($response->passed())->toBeTrue()
        ->and($response->approvedEffects)->toContain('notify_manager')
        ->and($response->approvedEffects)->toContain('flag_review');
});

it('collects multiple denials across all rules', function () {
    $engine   = bre_engine();
    $response = $engine->evaluate(
        bre_context(['order' => ['total' => 3, 'items' => 0]]),
        [
            bre_comparisonRule('r1', 'order.total', '<', 10, message: 'Min total.'),
            bre_comparisonRule('r2', 'order.items', '<',  1, message: 'No items.'),
        ],
    );

    expect($response->denials)->toHaveCount(2);
});

// ──────────────────────────────────────────────────────────────
// Priority ordering
// ──────────────────────────────────────────────────────────────

it('evaluates rules in priority DESC order', function () {
    // Instead of extending the final RuleEvaluatorRegistry, we test
    // priority ordering through the output order of denials.
    $engine = bre_engine();

    $response = $engine->evaluate(
        bre_context(['x' => 1]),
        [
            new RuleDefinition(id: 'low',  ruleType: 'comparison', field: 'x', params: ['operator' => '==', 'value' => 1], severity: 'deny', priority: 1,  message: 'Low.'),
            new RuleDefinition(id: 'high', ruleType: 'comparison', field: 'x', params: ['operator' => '==', 'value' => 1], severity: 'deny', priority: 10, message: 'High.'),
            new RuleDefinition(id: 'mid',  ruleType: 'comparison', field: 'x', params: ['operator' => '==', 'value' => 1], severity: 'deny', priority: 5,  message: 'Mid.'),
        ],
    );

    // All three fire, collected in priority DESC order
    $ids = array_column($response->denials, 'rule_id');
    expect($ids)->toBe(['high', 'mid', 'low']);
});

// ──────────────────────────────────────────────────────────────
// Fail-fast mode
// ──────────────────────────────────────────────────────────────

it('stops at first denial in fail_fast mode', function () {
    $engine   = bre_engine();
    $response = $engine->evaluate(
        bre_context(['a' => 1, 'b' => 1]),
        [
            bre_comparisonRule('r1', 'a', '==', 1, priority: 10, message: 'First.'),
            bre_comparisonRule('r2', 'b', '==', 1, priority: 5,  message: 'Second.'),
        ],
        failFast: true,
    );

    expect($response->denials)->toHaveCount(1)
        ->and($response->denials[0]['rule_id'])->toBe('r1');
});

// ──────────────────────────────────────────────────────────────
// onlyIf guard
// ──────────────────────────────────────────────────────────────

it('skips a rule when onlyIf guard is not met', function () {
    $engine   = bre_engine();
    $response = $engine->evaluate(
        bre_context(['order' => ['type' => 'domestic', 'total' => 1]]),
        [
            bre_comparisonRule(
                id:      'intl_minimum',
                field:   'order.total',
                operator: '<',
                value:   100,
                onlyIf:  "order.type == 'international'",
                message: 'International minimum not met.',
            ),
        ],
    );

    expect($response->passed())->toBeTrue();
});

it('applies a rule when onlyIf guard IS met', function () {
    $engine   = bre_engine();
    $response = $engine->evaluate(
        bre_context(['order' => ['type' => 'international', 'total' => 50]]),
        [
            bre_comparisonRule(
                id:      'intl_minimum',
                field:   'order.total',
                operator: '<',
                value:   100,
                onlyIf:  "order.type == 'international'",
                message: 'International minimum not met.',
            ),
        ],
    );

    expect($response->hasDenials())->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// Dot-path resolution
// ──────────────────────────────────────────────────────────────

it('resolves nested dot-paths safely', function () {
    $ctx = bre_context(['order' => ['items' => [['qty' => 3], ['qty' => 7]]]]);
    expect($ctx->resolve('order.items.1.qty'))->toBe(7)
        ->and($ctx->resolve('order.items.99.qty'))->toBeNull()
        ->and($ctx->resolve('nonexistent'))->toBeNull();
});

// ──────────────────────────────────────────────────────────────
// Evaluator types
// ──────────────────────────────────────────────────────────────

it('evaluates range rule — fires when value is out of range', function () {
    $engine = bre_engine();

    $rule = new RuleDefinition(
        id: 'qty_range', ruleType: 'range', field: 'qty',
        params: ['min' => 1, 'max' => 100],
        severity: 'deny', message: 'Qty must be 1–100.',
    );

    expect($engine->evaluate(bre_context(['qty' => 0]),   [$rule])->hasDenials())->toBeTrue();
    expect($engine->evaluate(bre_context(['qty' => 50]),  [$rule])->hasDenials())->toBeFalse();
    expect($engine->evaluate(bre_context(['qty' => 101]), [$rule])->hasDenials())->toBeTrue();
});

it('evaluates in_list rule', function () {
    $engine = bre_engine();

    $rule = new RuleDefinition(
        id: 'status_block', ruleType: 'in_list', field: 'status',
        params: ['values' => ['blocked', 'suspended']],
        severity: 'deny', message: 'Status not allowed.',
    );

    expect($engine->evaluate(bre_context(['status' => 'blocked']),  [$rule])->hasDenials())->toBeTrue();
    expect($engine->evaluate(bre_context(['status' => 'active']),   [$rule])->hasDenials())->toBeFalse();
});

it('evaluates regex rule', function () {
    $engine = bre_engine();

    $rule = new RuleDefinition(
        id: 'vat_format', ruleType: 'regex', field: 'vat',
        params: ['pattern' => '/^PT\d{9}$/'],
        severity: 'deny', message: 'Invalid VAT format.',
    );

    expect($engine->evaluate(bre_context(['vat' => 'PT123456789']), [$rule])->hasDenials())->toBeFalse();
    expect($engine->evaluate(bre_context(['vat' => 'INVALID']),     [$rule])->hasDenials())->toBeTrue();
});

it('evaluates cross-field comparison via @ prefix', function () {
    $engine = bre_engine();

    $rule = new RuleDefinition(
        id: 'discount_cap', ruleType: 'comparison', field: 'discount',
        params: ['operator' => '>', 'value' => '@credit_limit'],
        severity: 'deny', message: 'Discount exceeds credit limit.',
    );

    expect($engine->evaluate(bre_context(['discount' => 600, 'credit_limit' => 500]), [$rule])->hasDenials())->toBeTrue();
    expect($engine->evaluate(bre_context(['discount' => 400, 'credit_limit' => 500]), [$rule])->hasDenials())->toBeFalse();
});

it('deduplicates approved effects', function () {
    $engine = bre_engine();

    $response = $engine->evaluate(
        bre_context(['a' => 1, 'b' => 1]),
        [
            new RuleDefinition('r1', 'comparison', 'a', ['operator' => '==', 'value' => 1], 'allow', effects: ['notify']),
            new RuleDefinition('r2', 'comparison', 'b', ['operator' => '==', 'value' => 1], 'allow', effects: ['notify']),
        ],
    );

    expect($response->approvedEffects)->toHaveCount(1)
        ->and($response->approvedEffects)->toContain('notify');
});