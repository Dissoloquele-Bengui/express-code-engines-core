<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\AiEngine\AiEngine;
use ExpressCodeEngines\Extended\AiEngine\AiProviderRegistry;
use ExpressCodeEngines\Extended\AiEngine\Providers\NullAiProvider;
use ExpressCodeEngines\Shared\Contracts\AiProviderInterface;
use ExpressCodeEngines\Shared\DTOs\AiMessage;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ModelConfig;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function makeModel(
    string $provider = 'test',
    string $model    = 'test-model',
    bool   $jsonMode = false,
    ?array $schema   = null,
): ModelConfig {
    return new ModelConfig(
        provider:   $provider,
        model:      $model,
        maxTokens:  100,
        jsonMode:   $jsonMode,
        jsonSchema: $schema,
    );
}

function makeRequest(
    ModelConfig $model    = null,
    string      $user     = 'Hello',
    array       $vars     = [],
): AiRequest {
    return new AiRequest(
        model:     $model ?? makeModel(),
        messages:  [AiMessage::system('You are helpful.'), AiMessage::user($user)],
        variables: $vars,
    );
}

function makeProvider(
    string  $name,
    string  $content     = 'Test response',
    bool    $available   = true,
    bool    $throws      = false,
    int     $inputTokens = 10,
    int     $outputTokens = 5,
): AiProviderInterface {
    return new class ($name, $content, $available, $throws, $inputTokens, $outputTokens) implements AiProviderInterface {
        public function __construct(
            private string $n,
            private string $c,
            private bool   $avail,
            private bool   $throws,
            private int    $in,
            private int    $out,
        ) {}

        public function name(): string { return $this->n; }
        public function isAvailable(): bool { return $this->avail; }

        public function complete(AiRequest $req): AiResponse
        {
            if ($this->throws) throw new \RuntimeException("Provider {$this->n} exploded.");
            return AiResponse::ok($this->c, $this->n, 'model', $this->in, $this->out);
        }

        public function embed(string $text): array { return [0.1, 0.2, 0.3]; }
    };
}

function makeEngine(array $providers = [], array $pricing = []): AiEngine
{
    $registry = new AiProviderRegistry($providers);
    return new AiEngine($registry, $pricing);
}

// ──────────────────────────────────────────────────────────────
// Basic completion
// ──────────────────────────────────────────────────────────────

it('returns successful response when provider completes', function () {
    $engine   = makeEngine([makeProvider('test', 'Hello there!')]);
    $response = $engine->complete(makeRequest(makeModel('test')));

    expect($response->isFailed())->toBeFalse()
        ->and($response->content)->toBe('Hello there!')
        ->and($response->provider)->toBe('test')
        ->and($response->inputTokens)->toBe(10)
        ->and($response->outputTokens)->toBe(5)
        ->and($response->totalTokens())->toBe(15);
});

it('returns PROVIDER_NOT_FOUND when provider is not registered', function () {
    $engine   = makeEngine([]);
    $response = $engine->complete(makeRequest(makeModel('nonexistent')));

    expect($response->isFailed())->toBeTrue()
        ->and($response->errorCode)->toBe('PROVIDER_NOT_FOUND');
});

// ──────────────────────────────────────────────────────────────
// Fallback chain
// ──────────────────────────────────────────────────────────────

it('falls back to secondary provider when primary fails', function () {
    $primary   = makeProvider('openai', throws: true);
    $secondary = makeProvider('anthropic', 'Fallback response');

    $registry = new AiProviderRegistry([$primary, $secondary]);
    $registry->setFallbackChain(['anthropic']);
    $engine = new AiEngine($registry);

    $response = $engine->complete(makeRequest(makeModel('openai')));

    expect($response->isFailed())->toBeFalse()
        ->and($response->content)->toBe('Fallback response')
        ->and($response->provider)->toBe('anthropic');
});

it('skips unavailable providers in the chain', function () {
    $unavailable = makeProvider('openai', available: false);
    $available   = makeProvider('anthropic', 'Available response');

    $registry = new AiProviderRegistry([$unavailable, $available]);
    $registry->setFallbackChain(['anthropic']);
    $engine = new AiEngine($registry);

    $response = $engine->complete(makeRequest(makeModel('openai')));

    expect($response->isFailed())->toBeFalse()
        ->and($response->content)->toBe('Available response');
});

it('returns ALL_PROVIDERS_FAILED when entire chain fails', function () {
    $registry = new AiProviderRegistry([
        makeProvider('openai',    throws: true),
        makeProvider('anthropic', throws: true),
    ]);
    $registry->setFallbackChain(['anthropic']);
    $engine = new AiEngine($registry);

    $response = $engine->complete(makeRequest(makeModel('openai')));

    expect($response->isFailed())->toBeTrue()
        ->and($response->errorCode)->toBe('ALL_PROVIDERS_FAILED');
});

// ──────────────────────────────────────────────────────────────
// JSON mode
// ──────────────────────────────────────────────────────────────

it('parses JSON response when jsonMode is enabled', function () {
    $provider = makeProvider('test', '{"name": "Test", "value": 42}');
    $engine   = makeEngine([$provider]);

    $response = $engine->complete(makeRequest(makeModel('test', jsonMode: true)));

    expect($response->isFailed())->toBeFalse()
        ->and($response->data)->toHaveKey('name', 'Test')
        ->and($response->data)->toHaveKey('value', 42);
});

it('strips markdown code fences before parsing JSON', function () {
    $provider = makeProvider('test', "```json\n{\"status\": \"ok\"}\n```");
    $engine   = makeEngine([$provider]);

    $response = $engine->complete(makeRequest(makeModel('test', jsonMode: true)));

    expect($response->isFailed())->toBeFalse()
        ->and($response->data)->toHaveKey('status', 'ok');
});

it('returns JSON_PARSE_ERROR when response is not valid JSON', function () {
    $provider = makeProvider('test', 'This is not JSON at all.');
    $engine   = makeEngine([$provider]);

    $response = $engine->complete(makeRequest(makeModel('test', jsonMode: true)));

    expect($response->isFailed())->toBeTrue()
        ->and($response->errorCode)->toBe('JSON_PARSE_ERROR');
});

it('validates JSON response against schema required fields', function () {
    $schema   = ['required' => ['name', 'email']];
    $provider = makeProvider('test', '{"name": "Test"}'); // missing 'email'
    $engine   = makeEngine([$provider]);

    $response = $engine->complete(makeRequest(makeModel('test', jsonMode: true, schema: $schema)));

    expect($response->isFailed())->toBeTrue()
        ->and($response->errorCode)->toBe('JSON_SCHEMA_ERROR')
        ->and($response->errorMessage)->toContain('email');
});

// ──────────────────────────────────────────────────────────────
// Variable substitution
// ──────────────────────────────────────────────────────────────

it('substitutes {{variables}} into message content before sending', function () {
    $capturedMessages = [];

    $provider = new class ($capturedMessages) implements AiProviderInterface {
        public function __construct(private array &$log) {}
        public function name(): string { return 'test'; }
        public function isAvailable(): bool { return true; }
        public function embed(string $text): array { return []; }
        public function complete(AiRequest $req): AiResponse
        {
            $this->log = array_map(fn ($m) => $m->content, $req->messages);
            return AiResponse::ok('ok', 'test', 'model', 5, 5);
        }
    };

    $engine  = makeEngine([$provider]);
    $request = new AiRequest(
        model:     makeModel('test'),
        messages:  [
            AiMessage::system('Hello {{name}}, you work at {{company}}.'),
            AiMessage::user('My name is {{name}}.'),
        ],
        variables: ['name' => 'Ana', 'company' => 'Acme'],
    );

    $engine->complete($request);

    expect($capturedMessages[0])->toBe('Hello Ana, you work at Acme.')
        ->and($capturedMessages[1])->toBe('My name is Ana.');
});

// ──────────────────────────────────────────────────────────────
// Cost tracking
// ──────────────────────────────────────────────────────────────

it('calculates estimated cost from pricing config', function () {
    $pricing = ['test' => ['test-model' => ['input' => 0.01, 'output' => 0.03]]];
    $engine  = makeEngine([makeProvider('test', inputTokens: 1000, outputTokens: 500)], $pricing);

    $response = $engine->complete(makeRequest(makeModel('test')));

    // 1000 * 0.01/1000 + 500 * 0.03/1000 = 0.01 + 0.015 = 0.025
    expect($response->estimatedCost)->toBe(0.025);
});

it('returns zero cost when pricing is not configured', function () {
    $engine   = makeEngine([makeProvider('test', inputTokens: 1000, outputTokens: 500)], []);
    $response = $engine->complete(makeRequest(makeModel('test')));

    expect($response->estimatedCost)->toBe(0.0);
});

// ──────────────────────────────────────────────────────────────
// Embeddings
// ──────────────────────────────────────────────────────────────

it('returns embedding from provider', function () {
    $engine    = makeEngine([makeProvider('openai')]);
    $embedding = $engine->embed('test text', 'openai');

    expect($embedding)->toBe([0.1, 0.2, 0.3]);
});

it('returns empty array when embed provider not found', function () {
    $engine    = makeEngine([]);
    $embedding = $engine->embed('test', 'nonexistent');

    expect($embedding)->toBe([]);
});

// ──────────────────────────────────────────────────────────────
// NullAiProvider
// ──────────────────────────────────────────────────────────────

it('NullAiProvider always succeeds with empty JSON', function () {
    $provider = new NullAiProvider();
    $request  = makeRequest(makeModel('null'));

    $response = $provider->complete($request);

    expect($response->isFailed())->toBeFalse()
        ->and($response->content)->toBe('{}')
        ->and($provider->isAvailable())->toBeTrue();
});

it('NullAiProvider embed returns a 1536-element zero vector', function () {
    $provider  = new NullAiProvider();
    $embedding = $provider->embed('anything');

    expect($embedding)->toHaveCount(1536)
        ->and(array_sum($embedding))->toBe(0.0);
});

// ──────────────────────────────────────────────────────────────
// AiMessage helpers
// ──────────────────────────────────────────────────────────────

it('AiMessage factories produce correct role and serialise to array', function () {
    $system    = AiMessage::system('Be helpful.');
    $user      = AiMessage::user('Hello');
    $assistant = AiMessage::assistant('Hi!');

    expect($system->role)->toBe('system')
        ->and($user->role)->toBe('user')
        ->and($assistant->role)->toBe('assistant')
        ->and($system->toArray())->toBe(['role' => 'system', 'content' => 'Be helpful.']);
});
