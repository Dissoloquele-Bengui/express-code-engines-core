<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine\Operations;

use ExpressCodeEngines\Core\ComputationEngine\OperationInterface;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

final class RoundOperation extends BaseOperation
{
    public function execute(array $args, ?int $precision): ComputationResult
    {
        if (empty($args)) {
            return ComputationResult::error('INSUFFICIENT_ARGS', 'round requires at least 1 argument.');
        }

        $v = $this->toFloat($args[0]);
        if ($v instanceof ComputationResult) return $v;

        $decimals = isset($args[1]) ? (int) $args[1] : ($precision ?? 0);

        return ComputationResult::ok(round($v, $decimals));
    }
}
