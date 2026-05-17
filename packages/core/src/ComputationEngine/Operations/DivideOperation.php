<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine\Operations;

use ExpressCodeEngines\Core\ComputationEngine\OperationInterface;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

final class DivideOperation extends BaseOperation
{
    public function execute(array $args, ?int $precision): ComputationResult
    {
        if (count($args) < 2) {
            return ComputationResult::error('INSUFFICIENT_ARGS', 'divide requires 2 arguments.');
        }

        $a = $this->toFloat($args[0]);
        $b = $this->toFloat($args[1]);

        if ($a instanceof ComputationResult) return $a;
        if ($b instanceof ComputationResult) return $b;

        if ($b == 0.0) {
            return ComputationResult::error('DIVISION_BY_ZERO', 'Cannot divide by zero.');
        }

        return ComputationResult::ok($this->applyPrecision($a / $b, $precision));
    }
}
