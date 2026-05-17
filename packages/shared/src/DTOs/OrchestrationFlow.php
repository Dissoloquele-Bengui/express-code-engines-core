<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * Defines a complete orchestration flow — ordered steps with compensation support.
 */
final class OrchestrationFlow
{
    /**
     * @param  OrchestrationStep[]  $steps
     */
    public function __construct(
        public readonly string $key,
        public readonly array  $steps,
        public readonly bool   $transactional = false,
    ) {}

    public static function fromArray(string $key, array $config): self
    {
        $steps = array_map(
            fn (array $s) => OrchestrationStep::fromArray($s),
            $config['steps'] ?? [],
        );

        return new self(
            key:           $key,
            steps:         $steps,
            transactional: $config['transactional'] ?? false,
        );
    }
}
