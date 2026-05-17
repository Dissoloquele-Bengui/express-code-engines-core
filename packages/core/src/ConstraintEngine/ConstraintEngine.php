<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ConstraintEngine;

use ExpressCodeEngines\Shared\Contracts\ConstraintEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ConstraintCheckerInterface;
use ExpressCodeEngines\Shared\DTOs\ConstraintContext;
use ExpressCodeEngines\Shared\DTOs\ConstraintDefinition;
use ExpressCodeEngines\Shared\ValueObjects\ValidationResult;

/**
 * Concrete implementation of the ConstraintEngine.
 *
 * Delegates each constraint check to the registered ConstraintCheckerInterface
 * that declares support for that constraint type.
 *
 * The engine itself NEVER queries the database.
 */
final class ConstraintEngine implements ConstraintEngineInterface
{
    /**
     * @param  iterable<ConstraintCheckerInterface>  $checkers
     */
    public function __construct(
        private readonly iterable $checkers,
    ) {}

    /**
     * @param  ConstraintDefinition[]  $definitions
     */
    public function validate(ConstraintContext $context, array $definitions): ValidationResult
    {
        $violations = [];

        foreach ($definitions as $definition) {
            $checker = $this->resolveChecker($definition->type);

            if ($checker === null) {
                // Unknown constraint type — treat as a violation so nothing slips through silently
                $violations[] = [
                    'type'    => $definition->type,
                    'message' => "No checker registered for constraint type '{$definition->type}'.",
                ];
                continue;
            }

            if (! $checker->check($definition, $context)) {
                $violations[] = [
                    'type'    => $definition->type,
                    'message' => $definition->message,
                    'fields'  => $definition->scopeFields,
                ];
            }
        }

        return empty($violations)
            ? ValidationResult::pass()
            : ValidationResult::fail($violations);
    }

    private function resolveChecker(string $type): ?ConstraintCheckerInterface
    {
        foreach ($this->checkers as $checker) {
            if ($checker->supports($type)) {
                return $checker;
            }
        }

        return null;
    }
}
