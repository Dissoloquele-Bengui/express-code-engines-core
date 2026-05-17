<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\WorkflowEngine\Guards;

use ExpressCodeEngines\Shared\Contracts\WorkflowGuardInterface;
use ExpressCodeEngines\Shared\DTOs\TransitionRequest;

/**
 * Passes when the requesting user has the specified role.
 *
 * Format: 'role:admin' | 'role:manager' | 'role:editor'
 *
 * Relies on a hasRole(string $role): bool method on the user object,
 * which is the de-facto standard in Laravel (Spatie, Bouncer, etc.).
 * Falls back to checking a 'role' property directly if hasRole() is absent.
 */
final class RoleGuard implements WorkflowGuardInterface
{
    public function supports(string $guard): bool
    {
        return str_starts_with($guard, 'role:');
    }

    public function passes(string $guard, TransitionRequest $request): bool
    {
        $role = substr($guard, 5); // strip 'role:'

        if ($request->user === null) {
            return false;
        }

        // Spatie / Bouncer / custom hasRole()
        if (method_exists($request->user, 'hasRole')) {
            return $request->user->hasRole($role);
        }

        // Fallback: direct property
        return ($request->user->role ?? null) === $role;
    }
}
