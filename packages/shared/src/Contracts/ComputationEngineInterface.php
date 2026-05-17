<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\ComputationRequest;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

/**
 * Executes pure, deterministic mathematical and logical computations.
 *
 * Rules:
 *  - Zero eval() or dynamic code execution
 *  - Operations resolved via a whitelist registry
 *  - Supports recursive/nested operations
 *  - Errors returned as ComputationResult::error(), never as exceptions
 *  - Optional BCMath precision for financial calculations
 */
interface ComputationEngineInterface
{
    public function compute(ComputationRequest $request): ComputationResult;

    /**
     * Resolve multiple named computations in one pass (useful for forms with many computed fields).
     *
     * @param  ComputationRequest[]  $requests  keyed by field name
     * @return ComputationResult[]              keyed by same field name
     */
    public function computeMany(array $requests): array;
}
