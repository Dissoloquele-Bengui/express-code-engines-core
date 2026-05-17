<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\TransitionRequest;

/**
 * Evaluates a single guard expression for a workflow transition.
 *
 * A guard returns true when the transition IS allowed (passes the check).
 * The WorkflowEngine requires ALL guards to pass for a transition to proceed.
 *
 * Each guard handles a specific format of guard string:
 *   RoleGuard     → 'role:admin'
 *   FieldGuard    → 'field:approved_by:null'
 *   CallableGuard → 'callable:App\Guards\OrderGuard::canApprove'
 */
interface WorkflowGuardInterface
{
    public function supports(string $guard): bool;

    public function passes(string $guard, TransitionRequest $request): bool;
}
