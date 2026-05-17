<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ComputationEngine\Operations;

use ExpressCodeEngines\Core\ComputationEngine\OperationInterface;
use ExpressCodeEngines\Shared\ValueObjects\ComputationResult;

final class IifOperation extends BaseOperation
{
    public function execute(array $args, ?int $precision): ComputationResult
    {
        if (count($args) < 3) {
            return ComputationResult::error('INSUFFICIENT_ARGS', 'iif requires 3 arguments: condition, trueValue, falseValue.');
        }

        $condition  = $args[0];
        $trueValue  = $args[1];
        $falseValue = $args[2];

        return ComputationResult::ok((bool) $condition ? $trueValue : $falseValue);
    }
}
