<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine\Operations;

use ExpressCodeEngines\Core\ComputationEngine\OperationInterface;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

final class AbsOperation extends BaseOperation
{
    public function execute(array $args, ?int $precision): ComputationResult
    {
        if (empty($args)) {
            return ComputationResult::error('INSUFFICIENT_ARGS', 'abs requires 1 argument.');
        }

        $v = $this->toFloat($args[0]);
        if ($v instanceof ComputationResult) return $v;

        return ComputationResult::ok($this->applyPrecision(abs($v), $precision));
    }
}
