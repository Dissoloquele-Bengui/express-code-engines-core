<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\IntegrationEngine;

use ExpressCodeEngines\Shared\DTOs\IntegrationDefinition;

/**
 * Holds all registered integration definitions.
 * Populated at boot from config/engines/extended.php.
 */
final class IntegrationRegistry
{
    /** @var array<string, IntegrationDefinition> */
    private array $definitions = [];

    /**
     * @param  array<string, array>  $config  ['stripe_charge' => ['url' => ..., 'method' => ...]]
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $key => $definition) {
            $this->register(IntegrationDefinition::fromArray($key, $definition));
        }
    }

    public function register(IntegrationDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    public function find(string $key): ?IntegrationDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }
}
