<?php
declare(strict_types=1);
namespace ExpressCodeEngines\Extended\ChatEngine;
use ExpressCodeEngines\Shared\DTOs\ChatPersona;
use ExpressCodeEngines\Shared\DTOs\ModelConfig;
/** Registry of available chat personas. Loaded from config at boot. */
final class PersonaRegistry
{
    /** @var array<string, ChatPersona> */
    private array $personas = [];
    public function __construct(array $config = [])
    {
        foreach ($config as $key => $cfg) {
            $this->register(ChatPersona::fromArray($key, $cfg));
        }
    }
    public function register(ChatPersona $persona): void { $this->personas[$persona->key] = $persona; }
    public function find(string $key): ?ChatPersona { return $this->personas[$key] ?? null; }
    public function findOrDefault(string $key): ChatPersona
    {
        return $this->find($key) ?? $this->find('default') ?? $this->buildFallback();
    }
    public function keys(): array { return array_keys($this->personas); }
    private function buildFallback(): ChatPersona
    {
        return new ChatPersona(
            key: 'default', systemPrompt: 'You are a helpful assistant.',
            model: new ModelConfig(provider: 'openai', model: 'gpt-4o-mini'),
            ragEnabled: false,
        );
    }
}
