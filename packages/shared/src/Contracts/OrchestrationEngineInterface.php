<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\OrchestrationFlow;
use ExpressCodeEngines\Shared\ValueObjects\OrchestrationResult;

/**
 * Coordinates multi-step flows with compensation (Saga Lite pattern).
 *
 * Key design decisions:
 *   - The engine contains NO business logic — only flow coordination
 *   - Each step's handler is resolved from the container — never instantiated directly
 *   - On failure, completed steps are compensated in REVERSE order (LIFO)
 *   - The engine does NOT manage DB transactions — that belongs to individual steps/Actions
 *   - Step results accumulate into a shared payload available to subsequent steps
 *
 * Usage: called by "master Actions" that orchestrate multi-step processes.
 * Replaces monolithic Actions that do too many things in sequence.
 */
interface OrchestrationEngineInterface
{
    /**
     * Execute an orchestration flow.
     *
     * @param  array  $initialPayload  Seed data for the flow
     */
    public function run(OrchestrationFlow $flow, array $initialPayload = []): OrchestrationResult;
}
