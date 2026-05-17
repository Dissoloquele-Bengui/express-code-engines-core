<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\WorkflowEngine\Guards;

use ExpressCodeEngines\Shared\Contracts\WorkflowGuardInterface;
use ExpressCodeEngines\Shared\DTOs\TransitionRequest;

/**
 * Passes when a field in the entity data equals the expected value.
 *
 * Format: 'field:field_name:expected_value'
 *
 * Special expected values:
 *   'null'  → field must be null
 *   'true'  → field must be truthy
 *   'false' → field must be falsy
 *
 * Examples:
 *   'field:approved_by:null'   — must not yet be approved
 *   'field:is_locked:false'    — entity must not be locked
 *   'field:status:submitted'   — status must equal 'submitted'
 */
final class FieldGuard implements WorkflowGuardInterface
{
    public function supports(string $guard): bool
    {
        return str_starts_with($guard, 'field:');
    }

    public function passes(string $guard, TransitionRequest $request): bool
    {
        // Format: 'field:field_name:expected_value'
        $parts = explode(':', $guard, 3);

        if (count($parts) < 3) {
            return false; // malformed guard — deny
        }

        [, $field, $rawExpected] = $parts;

        $actual   = $request->data[$field] ?? null;
        $expected = $this->cast($rawExpected);

        return $actual === $expected;
    }

    private function cast(string $raw): mixed
    {
        return match ($raw) {
            'null'  => null,
            'true'  => true,
            'false' => false,
            default => $raw,
        };
    }
}
