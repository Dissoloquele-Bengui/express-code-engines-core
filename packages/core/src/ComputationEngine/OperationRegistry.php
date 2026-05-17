<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine;

use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

/**
 * Contract for a single whitelisted computation operation.
 */
interface OperationInterface
{
    /**
     * @param  array     $args       Fully resolved arguments (scalars only at this point)
     * @param  int|null  $precision  BCMath decimal places, null = native PHP float
     */
    public function execute(array $args, ?int $precision): ComputationResult;
}

// ──────────────────────────────────────────────────────────────

/**
 * Holds the whitelist of registered operations.
 * Only operations in this registry can be executed — nothing else.
 */
final class OperationRegistry
{
    /** @var array<string, OperationInterface> */
    private array $operations = [];

    public function register(string $name, OperationInterface $operation): void
    {
        $this->operations[$name] = $operation;
    }

    public function get(string $name): ?OperationInterface
    {
        return $this->operations[$name] ?? null;
    }

    /**
     * Factory that returns a registry pre-loaded with all built-in operations.
     */
    public static function withDefaults(): self
    {
        $registry = new self();

        $registry->register('add',        new Operations\AddOperation());
        $registry->register('subtract',   new Operations\SubtractOperation());
        $registry->register('multiply',   new Operations\MultiplyOperation());
        $registry->register('divide',     new Operations\DivideOperation());
        $registry->register('sum',        new Operations\SumOperation());
        $registry->register('average',    new Operations\AverageOperation());
        $registry->register('min',        new Operations\MinOperation());
        $registry->register('max',        new Operations\MaxOperation());
        $registry->register('round',      new Operations\RoundOperation());
        $registry->register('percentage', new Operations\PercentageOperation());
        $registry->register('iif',        new Operations\IifOperation());
        $registry->register('abs',        new Operations\AbsOperation());
        $registry->register('clamp',      new Operations\ClampOperation());

        return $registry;
    }
}
