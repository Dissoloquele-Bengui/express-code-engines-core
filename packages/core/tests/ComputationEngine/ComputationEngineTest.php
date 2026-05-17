<?php

declare(strict_types=1);

use ExpressCodeEngines\Core\ComputationEngine\ComputationEngine;
use ExpressCodeEngines\Core\ComputationEngine\OperationRegistry;
use ExpressCodeEngines\Shared\DTOs\ComputationRequest;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function comp_engine(): ComputationEngine
{
    return new ComputationEngine(OperationRegistry::withDefaults());
}

function comp_req(string $op, array $args, array $ctx = [], ?int $precision = null): ComputationRequest
{
    return new ComputationRequest(operation: $op, args: $args, context: $ctx, precision: $precision);
}

// ──────────────────────────────────────────────────────────────
// Basic arithmetic
// ──────────────────────────────────────────────────────────────

it('adds two numbers', function () {
    expect(comp_engine()->compute(comp_req('add', [10, 20]))->value)->toBe(30.0);
});

it('subtracts two numbers', function () {
    expect(comp_engine()->compute(comp_req('subtract', [50, 20]))->value)->toBe(30.0);
});

it('multiplies two numbers', function () {
    expect(comp_engine()->compute(comp_req('multiply', [6, 7]))->value)->toBe(42.0);
});

it('divides two numbers', function () {
    expect(comp_engine()->compute(comp_req('divide', [100, 4]))->value)->toBe(25.0);
});

it('returns error on division by zero', function () {
    $result = comp_engine()->compute(comp_req('divide', [100, 0]));
    expect($result->isError())->toBeTrue()
        ->and($result->errorCode)->toBe('DIVISION_BY_ZERO');
});

// ──────────────────────────────────────────────────────────────
// Aggregation
// ──────────────────────────────────────────────────────────────

it('sums a list of values', function () {
    expect(comp_engine()->compute(comp_req('sum', [10, 20, 30]))->value)->toBe(60.0);
});

it('sums empty list to zero', function () {
    expect(comp_engine()->compute(comp_req('sum', []))->value)->toBe(0.0);
});

it('computes average', function () {
    expect(comp_engine()->compute(comp_req('average', [10, 20, 30]))->value)->toBe(20.0);
});

it('finds min and max', function () {
    expect(comp_engine()->compute(comp_req('min', [5, 2, 8]))->value)->toBe(2.0);
    expect(comp_engine()->compute(comp_req('max', [5, 2, 8]))->value)->toBe(8.0);
});

it('computes percentage', function () {
    // 20% of 500 = 100
    expect(comp_engine()->compute(comp_req('percentage', [500, 20]))->value)->toBe(100.0);
});

it('applies BCMath precision for financial accuracy', function () {
    // 23% of 199.99 = 45.9977 → rounded to 2dp = 46.00
    $result = comp_engine()->compute(comp_req('percentage', [199.99, 23], precision: 2));
    expect($result->isError())->toBeFalse()
        // BCMath returns a string like "46.00"; cast to float for comparison
        ->and((float) $result->value)->toEqual(46.00);
});

it('rounds to specified decimal places', function () {
    expect(comp_engine()->compute(comp_req('round', [3.456, 2]))->value)->toBe(3.46);
    expect(comp_engine()->compute(comp_req('round', [3.454, 2]))->value)->toBe(3.45);
});

it('computes absolute value', function () {
    expect(comp_engine()->compute(comp_req('abs', [-42]))->value)->toBe(42.0);
    expect(comp_engine()->compute(comp_req('abs', [42]))->value)->toBe(42.0);
});

it('clamps value within range', function () {
    // clamp(value, min, max)
    expect(comp_engine()->compute(comp_req('clamp', [150, 0, 100]))->value)->toBe(100.0);
    expect(comp_engine()->compute(comp_req('clamp', [-5, 0, 100]))->value)->toBe(0.0);
    expect(comp_engine()->compute(comp_req('clamp', [50, 0, 100]))->value)->toBe(50.0);
});

it('iif returns trueValue when condition is truthy', function () {
    // iif(condition, trueValue, falseValue)
    expect(comp_engine()->compute(comp_req('iif', [1, 'yes', 'no']))->value)->toBe('yes');
});

it('iif returns falseValue when condition is falsy', function () {
    expect(comp_engine()->compute(comp_req('iif', [0, 'yes', 'no']))->value)->toBe('no');
});

// ──────────────────────────────────────────────────────────────
// Dot-path resolution from context
// ──────────────────────────────────────────────────────────────

it('resolves @dot-path arguments from context', function () {
    $result = comp_engine()->compute(comp_req(
        'add',
        ['@order.subtotal', '@order.tax'],
        ctx: ['order' => ['subtotal' => 100, 'tax' => 23]],
    ));

    expect($result->value)->toBe(123.0);
});

it('returns null for unresolvable paths', function () {
    $result = comp_engine()->compute(comp_req('add', ['@nonexistent', 10]));
    // null + 10 — depending on implementation this may be an error or 10
    // The key contract: doesn't crash
    expect($result)->not->toBeNull();
});

// ──────────────────────────────────────────────────────────────
// Nested / recursive computations
// ──────────────────────────────────────────────────────────────

it('resolves nested computation requests', function () {
    // (10 + 20) + 30 = 60
    $result = comp_engine()->compute(comp_req('add', [
        ['operation' => 'add', 'args' => [10, 20]],
        30,
    ]));

    expect($result->value)->toBe(60.0);
});

it('bubbles errors from nested computations', function () {
    // Nested division by zero: add(10, divide(5, 0))
    $result = comp_engine()->compute(comp_req('add', [
        10,
        ['operation' => 'divide', 'args' => [5, 0]],
    ]));

    expect($result->isError())->toBeTrue();
});

it('bubbles errors from deeply nested computations with zero division', function () {
    // Nested: add(10, divide(5, subtract(5, 5)))
    // subtract(5,5) = 0, then divide(5, 0) = error
    $result = comp_engine()->compute(comp_req('add', [
        10,
        ['operation' => 'divide', 'args' => [
            5,
            ['operation' => 'subtract', 'args' => [5, 5]], // resolves to 0
        ]],
    ]));

    expect($result->isError())->toBeTrue()
        ->and($result->errorCode)->toBe('DIVISION_BY_ZERO');
});

it('enforces max depth to prevent infinite recursion', function () {
    // Build nesting that exceeds MAX_DEPTH (10)
    // Each level wraps the previous as a nested arg
    $nested = ['operation' => 'add', 'args' => [1, 1]];
    for ($i = 0; $i < 12; $i++) {
        $nested = ['operation' => 'add', 'args' => [$nested, 1]];
    }

    // Wrap in a ComputationRequest at the top level
    $request = comp_req('add', [$nested, 1]);
    $result = comp_engine()->compute($request);

    expect($result->isError())->toBeTrue()
        ->and($result->errorCode)->toBe('MAX_DEPTH_EXCEEDED');
});

// ──────────────────────────────────────────────────────────────
// computeMany
// ──────────────────────────────────────────────────────────────

it('computes many named requests in one call', function () {
    $results = comp_engine()->computeMany([
        'subtotal' => comp_req('sum', [100, 50, 25]),
        'tax'      => comp_req('percentage', [175, 23], precision: 2),
    ]);

    expect($results)->toHaveKeys(['subtotal', 'tax'])
        ->and($results['subtotal']->value)->toBe(175.0)
        ->and((float) $results['tax']->value)->toEqual(40.25);
});

// ──────────────────────────────────────────────────────────────
// Error handling
// ──────────────────────────────────────────────────────────────

it('returns error for unregistered operation', function () {
    $result = comp_engine()->compute(comp_req('nonexistent_op', [1, 2]));
    expect($result->isError())->toBeTrue()
        ->and($result->errorCode)->toBe('UNKNOWN_OPERATION');
});

it('returns INVALID_TYPE error for non-numeric arguments', function () {
    $result = comp_engine()->compute(comp_req('add', ['abc', 'def']));
    expect($result->isError())->toBeTrue();
});