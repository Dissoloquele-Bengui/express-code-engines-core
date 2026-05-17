<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\AiEngine;

use ExpressCodeEngines\Shared\Contracts\AiProviderInterface;

/**
 * Registry of AI providers with fallback chain support.
 *
 * Providers are registered by name (e.g. 'openai', 'anthropic', 'ollama', 'null').
 * The fallback chain defines the order in which providers are tried when the
 * primary provider fails or is unavailable.
 *
 * The 'null' provider is always registered as the final fallback.
 */
final class AiProviderRegistry
{
    /** @var array<string, AiProviderInterface> */
    private array $providers = [];

    /** @var string[] provider names in fallback order */
    private array $fallbackChain = ['null'];

    /**
     * @param  AiProviderInterface[]  $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(AiProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function setFallbackChain(array $chain): void
    {
        $this->fallbackChain = $chain;
    }

    public function get(string $name): ?AiProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * Returns providers in fallback chain order, skipping unavailable ones.
     * The first available provider in the chain is used as the primary.
     *
     * @return AiProviderInterface[]
     */
    public function resolveChain(string $requestedProvider): array
    {
        $chain = [];

        // Start with the explicitly requested provider
        if (isset($this->providers[$requestedProvider]) &&
            $this->providers[$requestedProvider]->isAvailable()) {
            $chain[] = $this->providers[$requestedProvider];
        }

        // Add fallback chain, skipping already-added providers
        foreach ($this->fallbackChain as $name) {
            if ($name === $requestedProvider) continue;
            if (isset($this->providers[$name]) && $this->providers[$name]->isAvailable()) {
                $chain[] = $this->providers[$name];
            }
        }

        return $chain;
    }

    /** @return string[] */
    public function registeredNames(): array
    {
        return array_keys($this->providers);
    }
}
