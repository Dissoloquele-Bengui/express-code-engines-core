<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\WorkflowEngine\Guards;

use ExpressCodeEngines\Shared\Contracts\WorkflowGuardInterface;
use ExpressCodeEngines\Shared\DTOs\TransitionRequest;

/**
 * Delegates the guard check to an arbitrary PHP static method.
 *
 * Format: 'callable:App\Guards\OrderGuard::canApprove'
 *
 * The method receives (TransitionRequest $request): bool
 *
 * Design: only static methods (no closures) — keeps guard strings
 * serialisable, auditable, and referenceable from config files.
 */
final class CallableGuard implements WorkflowGuardInterface
{
    public function supports(string $guard): bool
    {
        return str_starts_with($guard, 'callable:');
    }

    public function passes(string $guard, TransitionRequest $request): bool
    {
        $callableStr = substr($guard, 9); // strip 'callable:'

        if (! str_contains($callableStr, '::')) {
            return false; // invalid format — deny safely
        }

        $callable = explode('::', $callableStr, 2);

        if (! is_callable($callable)) {
            return false;
        }

        return (bool) call_user_func($callable, $request);
    }
}
