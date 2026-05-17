<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine\Operations;

use ExpressCodeEngines\Core\ComputationEngine\OperationInterface;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

/**
 * @internal
 */
abstract class BaseOperation implements OperationInterface
{
    protected function toFloat(mixed $value): float|ComputationResult
    {
        if (! is_numeric($value)) {
            return ComputationResult::error('INVALID_TYPE', "Expected numeric, got: " . gettype($value));
        }

        return (float) $value;
    }

    protected function applyPrecision(float $value, ?int $precision): int|float|string
    {
        if ($precision === null) {
            return $value;
        }

        // Round to the requested precision, return as string for financial consistency
        return number_format(round($value, $precision), $precision, '.', '');
    }
}