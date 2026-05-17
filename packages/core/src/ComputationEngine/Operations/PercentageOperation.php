<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine\Operations;

use ExpressCodeEngines\Core\ComputationEngine\OperationInterface;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

final class PercentageOperation extends BaseOperation
{
    public function execute(array $args, ?int $precision): ComputationResult
    {
        if (count($args) < 2) {
            return ComputationResult::error('INSUFFICIENT_ARGS', 'percentage requires 2 arguments.');
        }

        $base = $this->toFloat($args[0]);
        $pct  = $this->toFloat($args[1]);

        if ($base instanceof ComputationResult) return $base;
        if ($pct  instanceof ComputationResult) return $pct;

        return ComputationResult::ok($this->applyPrecision($base * ($pct / 100), $precision));
    }
}
