<?php

declare(strict_types=1);

use ExpressCodeEngines\Core\ConstraintEngine\ConstraintEngine;
use ExpressCodeEngines\Shared\Contracts\ConstraintCheckerInterface;
use ExpressCodeEngines\Shared\DTOs\ConstraintContext;
use ExpressCodeEngines\Shared\DTOs\ConstraintDefinition;

// ──────────────────────────────────────────────────────────────
// Helpers (prefixed to avoid collisions when all tests run together)
// ──────────────────────────────────────────────────────────────

function ce_makeChecker(string $type, bool $passes): ConstraintCheckerInterface
{
    return new class ($type, $passes) implements ConstraintCheckerInterface {
        public function __construct(
            private readonly string $type,
            private readonly bool $passes,
        ) {}

        public function supports(string $constraintType): bool
        {
            return $constraintType === $this->type;
        }

        public function check(ConstraintDefinition $definition, ConstraintContext $context): bool
        {
            return $this->passes;
        }
    };
}

function ce_makeContext(array $data = []): ConstraintContext
{
    return new ConstraintContext(entityClass: 'App\Models\Order', data: $data);
}

function ce_makeDefinition(string $type, string $message = 'Violation.'): ConstraintDefinition
{
    return new ConstraintDefinition(type: $type, message: $message);
}

// ──────────────────────────────────────────────────────────────
// Tests
// ──────────────────────────────────────────────────────────────

it('passes when all checkers pass', function () {
    $engine = new ConstraintEngine([
        ce_makeChecker('uniqueness', passes: true),
        ce_makeChecker('limit', passes: true),
    ]);

    $result = $engine->validate(ce_makeContext(), [
        ce_makeDefinition('uniqueness'),
        ce_makeDefinition('limit'),
    ]);

    expect($result->passed)->toBeTrue()
        ->and($result->violations)->toBeEmpty();
});

it('fails when any checker fails', function () {
    $engine = new ConstraintEngine([
        ce_makeChecker('uniqueness', passes: false),
    ]);

    $result = $engine->validate(ce_makeContext(), [
        ce_makeDefinition('uniqueness', 'Email already taken.'),
    ]);

    expect($result->passed)->toBeFalse()
        ->and($result->violations)->toHaveCount(1)
        ->and($result->firstMessage())->toBe('Email already taken.');
});

it('collects all violations instead of stopping at first', function () {
    $engine = new ConstraintEngine([
        ce_makeChecker('uniqueness', passes: false),
        ce_makeChecker('overlap', passes: false),
    ]);

    $result = $engine->validate(ce_makeContext(), [
        ce_makeDefinition('uniqueness', 'Duplicate email.'),
        ce_makeDefinition('overlap', 'Schedule conflict.'),
    ]);

    expect($result->violations)->toHaveCount(2);
});

it('treats unknown constraint types as violations', function () {
    $engine = new ConstraintEngine([]); // no checkers registered

    $result = $engine->validate(ce_makeContext(), [
        ce_makeDefinition('unknown_type'),
    ]);

    expect($result->passed)->toBeFalse();
});