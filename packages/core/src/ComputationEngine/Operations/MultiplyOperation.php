<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine\Operations;

use ExpressCodeEngines\Core\ComputationEngine\OperationInterface;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

final class MultiplyOperation extends BaseOperation
{
    public function execute(array $args, ?int $precision): ComputationResult
    {
        if (count($args) < 2) {
            return ComputationResult::error('INSUFFICIENT_ARGS', 'multiply requires 2 arguments.');
        }

        $a = $this->toFloat($args[0]);
        $b = $this->toFloat($args[1]);

        if ($a instanceof ComputationResult) return $a;
        if ($b instanceof ComputationResult) return $b;

        return ComputationResult::ok($this->applyPrecision($a * $b, $precision));
    }
}
