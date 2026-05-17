<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ReactionEngine;

use ExpressCodeEngines\Shared\Contracts\ReactionHandlerInterface;

/**
 * Registry of all available reaction handlers.
 *
 * Only handlers registered here can be invoked by the ReactionEngine.
 * This acts as the whitelist — unknown handler keys are rejected.
 *
 * Handlers are registered by key (matching ReactionDefinition::handler).
 * The registry is populated by the ExtendedServiceProvider and can be
 * extended by the application via addHandler().
 */
final class ReactionHandlerRegistry
{
    /** @var array<string, ReactionHandlerInterface> */
    private array $handlers = [];

    /**
     * @param  ReactionHandlerInterface[]  $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->addHandler($handler);
        }
    }

    public function addHandler(ReactionHandlerInterface $handler): void
    {
        $this->handlers[$handler->key()] = $handler;
    }

    public function resolve(string $key): ?ReactionHandlerInterface
    {
        return $this->handlers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->handlers[$key]);
    }

    /** @return string[] */
    public function registeredKeys(): array
    {
        return array_keys($this->handlers);
    }
}
