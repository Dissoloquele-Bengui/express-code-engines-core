<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine\Operations;

use ExpressCodeEngines\Core\ComputationEngine\OperationInterface;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

final class MinOperation extends BaseOperation
{
    public function execute(array $args, ?int $precision): ComputationResult
    {
        if (empty($args)) {
            return ComputationResult::error('INSUFFICIENT_ARGS', 'min requires at least 1 argument.');
        }

        $floats = [];

        foreach ($args as $arg) {
            $v = $this->toFloat($arg);
            if ($v instanceof ComputationResult) return $v;
            $floats[] = $v;
        }

        return ComputationResult::ok($this->applyPrecision(min($floats), $precision));
    }
}
