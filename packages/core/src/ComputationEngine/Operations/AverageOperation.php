<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine\Operations;

use ExpressCodeEngines\Core\ComputationEngine\OperationInterface;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

final class AverageOperation extends BaseOperation
{
    public function execute(array $args, ?int $precision): ComputationResult
    {
        if (empty($args)) {
            return ComputationResult::error('INSUFFICIENT_ARGS', 'average requires at least 1 argument.');
        }

        $total = 0.0;

        foreach ($args as $arg) {
            $v = $this->toFloat($arg);
            if ($v instanceof ComputationResult) return $v;
            $total += $v;
        }

        return ComputationResult::ok($this->applyPrecision($total / count($args), $precision));
    }
}
