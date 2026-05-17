<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\AiEngine\AiEngine;
use ExpressCodeEngines\Extended\AiEngine\AiProviderRegistry;
use ExpressCodeEngines\Extended\AiEngine\CachedEmbeddingEngine;
use ExpressCodeEngines\Extended\AiEngine\Providers\GroqProvider;
use ExpressCodeEngines\Extended\AiEngine\Providers\HuggingFaceProvider;
use ExpressCodeEngines\Extended\AiEngine\Providers\NullAiProvider;
use ExpressCodeEngines\Extended\AiEngine\Providers\OpenRouterProvider;
use ExpressCodeEngines\Shared\Contracts\AiEngineInterface;
use ExpressCodeEngines\Shared\Contracts\AiProviderInterface;
use ExpressCodeEngines\Shared\DTOs\AiMessage;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ModelConfig;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function makeRequest(
    string $provider = 'openrouter',
    string $model    = 'meta-llama/llama-3.1-8b-instruct:free',
    bool   $jsonMode = false,
): AiRequest {
    return new AiRequest(
        model:    new ModelConfig(provider: $provider, model: $model, jsonMode: $jsonMode),
        messages: [AiMessage::user('Hello')],
    );
}

function makeProvider(string $name, bool $succeeds = true, array $embedding = []): AiProviderInterface
{
    return new class ($name, $succeeds, $embedding) implements AiProviderInterface {
        public function __construct(
            private string $n,
            private bool   $ok,
            private array  $emb,
        ) {}

        public function name(): string       { return $this->n; }
        public function isAvailable(): bool  { return true; }
        public function embed(string $text): array { return $this->emb; }

        public function complete(AiRequest $request): AiResponse
        {
            return $this->ok
                ? AiResponse::ok('response', $this->n, $request->model->model, 10, 20)
                : AiResponse::failed('FAIL', 'Simulated failure.');
        }
    };
}

// ──────────────────────────────────────────────────────────────
// GroqProvider — unit tests
// ──────────────────────────────────────────────────────────────

it('GroqProvider returns correct name', function () {
    $p = new GroqProvider('gsk_test');
    expect($p->name())->toBe('groq');
});

it('GroqProvider is available when API key set', function () {
    expect((new GroqProvider('gsk_test'))->isAvailable())->toBeTrue();
    expect((new GroqProvider(''))->isAvailable())->toBeFalse();
});

it('GroqProvider returns empty embedding (no embeddings API)', function () {
    $p = new GroqProvider('gsk_test');
    expect($p->embed('hello'))->toBe([]);
});

it('GroqProvider estimates cost correctly for known models', function () {
    // Access via reflection — private method
    $p     = new GroqProvider('gsk_test');
    $refl  = new ReflectionClass($p);
    $method = $refl->getMethod('estimateCost');
    $method->setAccessible(true);

    $cost = $method->invoke($p, 'llama-3.1-8b-instant', 1_000_000, 1_000_000);
    expect($cost)->toBe(0.05 + 0.08); // input + output per 1M tokens
});

it('GroqProvider returns zero cost for unknown models', function () {
    $p     = new GroqProvider('gsk_test');
    $refl  = new ReflectionClass($p);
    $method = $refl->getMethod('estimateCost');
    $method->setAccessible(true);

    expect($method->invoke($p, 'unknown-model', 1000, 1000))->toBe(0.0);
});

// ──────────────────────────────────────────────────────────────
// OpenRouterProvider — unit tests
// ──────────────────────────────────────────────────────────────

it('OpenRouterProvider returns correct name', function () {
    expect((new OpenRouterProvider('sk-or-test'))->name())->toBe('openrouter');
});

it('OpenRouterProvider is available when API key set', function () {
    expect((new OpenRouterProvider('sk-or-test'))->isAvailable())->toBeTrue();
    expect((new OpenRouterProvider(''))->isAvailable())->toBeFalse();
});

it('OpenRouterProvider correctly identifies free models', function () {
    $p = new OpenRouterProvider('sk-or-test');

    expect($p->isFreeModel('meta-llama/llama-3.1-8b-instruct:free'))->toBeTrue()
        ->and($p->isFreeModel('google/gemma-2-9b-it:free'))->toBeTrue()
        ->and($p->isFreeModel('custom/model:free'))->toBeTrue()    // any :free suffix
        ->and($p->isFreeModel('openai/gpt-4o'))->toBeFalse()
        ->and($p->isFreeModel('anthropic/claude-3-5-sonnet'))->toBeFalse();
});

it('OpenRouterProvider returns empty embedding', function () {
    expect((new OpenRouterProvider('sk-or-test'))->embed('test'))->toBe([]);
});

// ──────────────────────────────────────────────────────────────
// HuggingFaceProvider — unit tests
// ──────────────────────────────────────────────────────────────

it('HuggingFaceProvider returns correct name', function () {
    expect((new HuggingFaceProvider('hf_test'))->name())->toBe('huggingface');
});

it('HuggingFaceProvider returns correct dimensions per model', function () {
    $smallModel = new HuggingFaceProvider('hf_test', 'BAAI/bge-small-en-v1.5');
    $baseModel  = new HuggingFaceProvider('hf_test', 'BAAI/bge-base-en-v1.5');
    $mpnet      = new HuggingFaceProvider('hf_test', 'sentence-transformers/all-mpnet-base-v2');
    $unknown    = new HuggingFaceProvider('hf_test', 'unknown/model');

    expect($smallModel->embeddingDimensions())->toBe(384)
        ->and($baseModel->embeddingDimensions())->toBe(768)
        ->and($mpnet->embeddingDimensions())->toBe(768)
        ->and($unknown->embeddingDimensions())->toBe(384); // fallback
});

it('HuggingFaceProvider is available when API key set', function () {
    expect((new HuggingFaceProvider('hf_test'))->isAvailable())->toBeTrue();
    expect((new HuggingFaceProvider(''))->isAvailable())->toBeFalse();
});

// ──────────────────────────────────────────────────────────────
// CachedEmbeddingEngine — unit tests (without real cache)
// ──────────────────────────────────────────────────────────────

it('CachedEmbeddingEngine delegates complete() to inner engine', function () {
    $callCount = 0;

    $inner = new class ($callCount) implements AiEngineInterface {
        public function __construct(private int &$calls) {}
        public function complete(AiRequest $r): AiResponse { $this->calls++; return AiResponse::ok('x', 'test', 'm', 1, 1); }
        public function embed(string $text, string $provider = 'openai'): array { return [0.1, 0.2]; }
    };

    $cached = new CachedEmbeddingEngine($inner, ttl: 60);

    $cached->complete(makeRequest());
    $cached->complete(makeRequest());

    expect($callCount)->toBe(2); // completions are never cached
});

it('CachedEmbeddingEngine caches embeddings on second call', function () {
    $embedCallCount = 0;

    $inner = new class ($embedCallCount) implements AiEngineInterface {
        public function __construct(private int &$calls) {}
        public function complete(AiRequest $r): AiResponse { return AiResponse::ok('x', 'test', 'm', 1, 1); }
        public function embed(string $text, string $provider = 'openai'): array
        {
            $this->calls++;
            return [0.1, 0.2, 0.3];
        }
    };

    // Use in-memory array cache mock
    $cacheStore = [];

    $cached = new class ($inner, $cacheStore) extends CachedEmbeddingEngine {
        public function __construct(AiEngineInterface $inner, private array &$store)
        {
            parent::__construct($inner, ttl: 60, prefix: 'test.');
        }

        public function embed(string $text, string $provider = 'openai'): array
        {
            $key = 'test.' . hash('sha256', "{$provider}:{$text}");

            if (isset($this->store[$key])) {
                return $this->store[$key];
            }

            $result = parent::embed($text, $provider);
            $this->store[$key] = $result;
            return $result;
        }
    };

    $first  = $cached->embed('hello', 'openai');
    $second = $cached->embed('hello', 'openai');

    expect($first)->toBe([0.1, 0.2, 0.3])
        ->and($second)->toBe([0.1, 0.2, 0.3])
        ->and($embedCallCount)->toBe(1); // inner engine only called once
});

// ──────────────────────────────────────────────────────────────
// AiProviderRegistry — fallback chain tests
// ──────────────────────────────────────────────────────────────

it('AiProviderRegistry resolveChain returns primary then fallbacks', function () {
    $registry = new AiProviderRegistry([
        makeProvider('openai'),
        makeProvider('groq'),
        makeProvider('null'),
    ]);
    $registry->setFallbackChain(['groq', 'null']);

    $chain = $registry->resolveChain('openai');

    expect($chain)->toHaveCount(3)
        ->and($chain[0]->name())->toBe('openai')
        ->and($chain[1]->name())->toBe('groq')
        ->and($chain[2]->name())->toBe('null');
});

it('AiProviderRegistry resolveChain skips unavailable providers', function () {
    $unavailable = new class implements AiProviderInterface {
        public function name(): string       { return 'broken'; }
        public function isAvailable(): bool  { return false; }
        public function embed(string $t): array { return []; }
        public function complete(AiRequest $r): AiResponse { return AiResponse::failed('X', 'X'); }
    };

    $registry = new AiProviderRegistry([
        $unavailable,
        makeProvider('null'),
    ]);
    $registry->setFallbackChain(['null']);

    $chain = $registry->resolveChain('broken');

    expect($chain)->toHaveCount(1)
        ->and($chain[0]->name())->toBe('null');
});

it('AiEngine tries next provider when primary fails', function () {
    $registry = new AiProviderRegistry([
        makeProvider('failing', succeeds: false),
        makeProvider('working', succeeds: true),
    ]);
    $registry->setFallbackChain(['working']);

    $engine   = new AiEngine($registry);
    $response = $engine->complete(makeRequest('failing'));

    expect($response->isFailed())->toBeFalse()
        ->and($response->provider)->toBe('working');
});

it('AiEngine returns failed when all providers fail', function () {
    $registry = new AiProviderRegistry([
        makeProvider('p1', succeeds: false),
        makeProvider('p2', succeeds: false),
    ]);
    $registry->setFallbackChain(['p2']);

    $engine   = new AiEngine($registry);
    $response = $engine->complete(makeRequest('p1'));

    expect($response->isFailed())->toBeTrue()
        ->and($response->errorCode)->toBe('ALL_PROVIDERS_FAILED');
});

it('AiEngine NullProvider is always available and returns empty content', function () {
    $null     = new NullAiProvider();
    $registry = new AiProviderRegistry([$null]);
    $registry->setFallbackChain(['null']);

    $engine   = new AiEngine($registry);
    $response = $engine->complete(makeRequest('null', 'null'));

    expect($response->isFailed())->toBeFalse()
        ->and($response->provider)->toBe('null')
        ->and($response->estimatedCost)->toBe(0.0);
});

it('NullAiProvider returns 1536-dimension zero vector for embeddings', function () {
    $null      = new NullAiProvider();
    $embedding = $null->embed('any text');

    expect($embedding)->toHaveCount(1536)
        ->and(array_sum($embedding))->toBe(0.0);
});
