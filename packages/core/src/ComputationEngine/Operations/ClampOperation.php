<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine\Operations;

use ExpressCodeEngines\Core\ComputationEngine\OperationInterface;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

final class ClampOperation extends BaseOperation
{
    public function execute(array $args, ?int $precision): ComputationResult
    {
        if (count($args) < 3) {
            return ComputationResult::error('INSUFFICIENT_ARGS', 'clamp requires 3 arguments: value, min, max.');
        }

        $v   = $this->toFloat($args[0]);
        $min = $this->toFloat($args[1]);
        $max = $this->toFloat($args[2]);

        if ($v   instanceof ComputationResult) return $v;
        if ($min instanceof ComputationResult) return $min;
        if ($max instanceof ComputationResult) return $max;

        return ComputationResult::ok($this->applyPrecision(max($min, min($max, $v)), $precision));
    }
}
