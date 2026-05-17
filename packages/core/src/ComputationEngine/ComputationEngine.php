<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine;

use ExpressCodeEngines\Shared\Contracts\ComputationEngineInterface;
use ExpressCodeEngines\Shared\DTOs\ComputationRequest;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

/**
 * Pure, deterministic computation engine with a whitelist operation registry.
 *
 * Execution model:
 *   - Each arg can be a scalar, a dot-path string, or a nested ComputationRequest array
 *   - Nested requests are resolved recursively before the parent operation runs
 *   - BCMath is used when precision is set, keeping financial accuracy exact
 *   - No eval(), no dynamic code — only registered operations can run
 *   - Errors are returned as ComputationResult::error(), never as exceptions
 *
 * Guard against infinite recursion: max depth of 10 nested operations.
 */
final class ComputationEngine implements ComputationEngineInterface
{
    private const MAX_DEPTH = 10;

    private OperationRegistry $registry;

    public function __construct(?OperationRegistry $registry = null)
    {
        $this->registry = $registry ?? OperationRegistry::withDefaults();
    }

    public function compute(ComputationRequest $request, int $depth = 0): ComputationResult
    {
        if ($depth > self::MAX_DEPTH) {
            return ComputationResult::error('MAX_DEPTH_EXCEEDED', 'Computation nesting limit reached.');
        }

        // Resolve args — each may be a scalar, dot-path, or nested ComputationRequest
        $resolvedArgs = [];

        foreach ($request->args as $arg) {
            $resolved = $this->resolveArg($arg, $request->context, $depth);

            if ($resolved instanceof ComputationResult && $resolved->isError()) {
                return $resolved; // bubble nested errors up immediately
            }

            $resolvedArgs[] = $resolved instanceof ComputationResult ? $resolved->value : $resolved;
        }

        $operation = $this->registry->get($request->operation);

        if ($operation === null) {
            return ComputationResult::error(
                'UNKNOWN_OPERATION',
                "Operation '{$request->operation}' is not registered.",
            );
        }

        return $operation->execute($resolvedArgs, $request->precision);
    }

    /**
     * @param  ComputationRequest[]  $requests  keyed by field name
     * @return ComputationResult[]              keyed by same field name
     */
    public function computeMany(array $requests): array
    {
        $results = [];

        foreach ($requests as $key => $request) {
            $results[$key] = $this->compute($request);
        }

        return $results;
    }

    private function resolveArg(mixed $arg, array $context, int $depth): mixed
    {
        // Nested ComputationRequest array — resolve recursively
        if (is_array($arg) && isset($arg['operation'])) {
            $nested = new ComputationRequest(
                operation: $arg['operation'],
                args:      $arg['args']      ?? [],
                context:   $arg['context']   ?? $context,
                precision: $arg['precision'] ?? null,
            );

            return $this->compute($nested, $depth + 1);
        }

        // Dot-path string — resolve against context
        if (is_string($arg) && str_starts_with($arg, '@')) {
            return $this->resolvePath(substr($arg, 1), $context);
        }

        // Scalar — return as-is
        return $arg;
    }

    private function resolvePath(string $path, array $context): mixed
    {
        $segments = explode('.', $path);
        $value    = $context;

        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }
}
