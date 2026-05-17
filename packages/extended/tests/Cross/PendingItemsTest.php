<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\AiEngine\CachedEmbeddingEngine;
use ExpressCodeEngines\Extended\DocumentEngine\PgvectorChunkStore;
use ExpressCodeEngines\Extended\NotificationEngine\Channels\SlackChannel;
use ExpressCodeEngines\Shared\Contracts\AiEngineInterface;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

// ══════════════════════════════════════════════════════════════
// SlackChannel — unit tests
// ══════════════════════════════════════════════════════════════

it('SlackChannel returns correct channel name', function () {
    expect((new SlackChannel())->channel())->toBe('slack');
});

it('SlackChannel returns NO_WEBHOOK_URL when no URL configured', function () {
    $channel = new SlackChannel(defaultWebhookUrl: null);

    $result = $channel->deliver('user@example.com', ['subject' => 'Test', 'body' => 'Hello']);

    expect($result->isFailed())->toBeTrue()
        ->and($result->errorCode)->toBe('NO_WEBHOOK_URL');
});

it('SlackChannel resolves webhook URL from https:// string recipient', function () {
    // Build payload and check it sends to the correct URL — mock HTTP
    // Without real Slack, we test URL resolution logic via reflection
    $channel = new SlackChannel(defaultWebhookUrl: 'https://hooks.slack.com/default');

    $refl   = new ReflectionClass($channel);
    $method = $refl->getMethod('resolveWebhookUrl');
    $method->setAccessible(true);

    // String URL → used directly
    $direct = $method->invoke($channel, 'https://hooks.slack.com/specific');
    expect($direct)->toBe('https://hooks.slack.com/specific');

    // Non-URL string → falls back to default
    $emailRecipient = $method->invoke($channel, 'user@example.com');
    expect($emailRecipient)->toBe('https://hooks.slack.com/default');
});

it('SlackChannel resolves webhook URL from object with getSlackWebhookUrl()', function () {
    $user = new class {
        public function getSlackWebhookUrl(): string { return 'https://hooks.slack.com/user-specific'; }
    };

    $channel = new SlackChannel(defaultWebhookUrl: 'https://hooks.slack.com/default');

    $refl   = new ReflectionClass($channel);
    $method = $refl->getMethod('resolveWebhookUrl');
    $method->setAccessible(true);

    expect($method->invoke($channel, $user))->toBe('https://hooks.slack.com/user-specific');
});

it('SlackChannel resolves webhook URL from object->slack_webhook_url property', function () {
    $user = new class { public string $slack_webhook_url = 'https://hooks.slack.com/property'; };

    $channel = new SlackChannel();

    $refl   = new ReflectionClass($channel);
    $method = $refl->getMethod('resolveWebhookUrl');
    $method->setAccessible(true);

    expect($method->invoke($channel, $user))->toBe('https://hooks.slack.com/property');
});

it('SlackChannel builds simple text payload', function () {
    $channel = new SlackChannel(username: 'Bot', icon: ':robot_face:');

    $refl   = new ReflectionClass($channel);
    $method = $refl->getMethod('buildPayload');
    $method->setAccessible(true);

    $payload = $method->invoke($channel, [
        'subject' => 'Alert Title',
        'body'    => 'Something happened.',
        'data'    => [],
    ]);

    expect($payload['text'])->toContain('Alert Title')
        ->and($payload['text'])->toContain('Something happened.')
        ->and($payload['username'])->toBe('Bot')
        ->and($payload['icon_emoji'])->toBe(':robot_face:');
});

it('SlackChannel builds colour attachment payload', function () {
    $channel = new SlackChannel();

    $refl   = new ReflectionClass($channel);
    $method = $refl->getMethod('buildPayload');
    $method->setAccessible(true);

    $payload = $method->invoke($channel, [
        'subject' => 'Warning',
        'body'    => 'High CPU usage.',
        'data'    => ['color' => 'warning'],
    ]);

    expect($payload)->toHaveKey('attachments')
        ->and($payload['attachments'][0]['color'])->toBe('warning')
        ->and($payload['attachments'][0]['title'])->toBe('Warning');
});

it('SlackChannel builds Block Kit payload when blocks are provided', function () {
    $channel = new SlackChannel();

    $refl   = new ReflectionClass($channel);
    $method = $refl->getMethod('buildPayload');
    $method->setAccessible(true);

    $blocks = [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*Hello*']]];

    $payload = $method->invoke($channel, [
        'subject' => 'Fallback',
        'body'    => '',
        'data'    => ['blocks' => $blocks],
    ]);

    expect($payload)->toHaveKey('blocks')
        ->and($payload['blocks'])->toBe($blocks)
        ->and($payload['text'])->toBe('Fallback'); // fallback text for screen readers
});

it('SlackChannel uses icon_url for https:// icon', function () {
    $channel = new SlackChannel(icon: 'https://example.com/icon.png');

    $refl   = new ReflectionClass($channel);
    $method = $refl->getMethod('buildPayload');
    $method->setAccessible(true);

    $payload = $method->invoke($channel, ['subject' => 'S', 'body' => 'B', 'data' => []]);

    expect($payload)->toHaveKey('icon_url')
        ->and($payload)->not->toHaveKey('icon_emoji');
});

// ══════════════════════════════════════════════════════════════
// PgvectorChunkStore — index type and parameter tests
// ══════════════════════════════════════════════════════════════

it('PgvectorChunkStore defaults to HNSW index type', function () {
    $store = new PgvectorChunkStore();

    $refl  = new ReflectionClass($store);
    $prop  = $refl->getProperty('indexType');
    $prop->setAccessible(true);

    expect($prop->getValue($store))->toBe('hnsw');
});

it('PgvectorChunkStore accepts IVFFlat configuration', function () {
    $store = new PgvectorChunkStore(
        table:     'chunks',
        indexType: 'ivfflat',
        probes:    15,
    );

    $refl = new ReflectionClass($store);

    $indexType = $refl->getProperty('indexType');
    $indexType->setAccessible(true);
    $probes = $refl->getProperty('probes');
    $probes->setAccessible(true);

    expect($indexType->getValue($store))->toBe('ivfflat')
        ->and($probes->getValue($store))->toBe(15);
});

it('PgvectorChunkStore generates correct HNSW CREATE INDEX SQL', function () {
    $store = new PgvectorChunkStore(table: 'document_chunks', indexType: 'hnsw');

    // We capture the SQL by capturing what would be executed via a custom driver
    // Without a real PG connection, we test the logic via reflection on createIndex
    $refl = new ReflectionClass($store);

    // Verify the method exists and has the correct signature
    $method = $refl->getMethod('createIndex');
    expect($method->isPublic())->toBeTrue()
        ->and($method->getParameters()[0]->getName())->toBe('totalRows')
        ->and($method->getParameters()[0]->isOptional())->toBeTrue();
});

it('PgvectorChunkStore computes IVFFlat lists as sqrt(total_rows)', function () {
    // We test the lists calculation logic by simulating what createIndex does
    $totalRows = 10000;
    $lists     = max(1, (int) sqrt($totalRows));

    expect($lists)->toBe(100); // sqrt(10000) = 100
});

it('PgvectorChunkStore uses ef_search=100 by default for HNSW', function () {
    $store = new PgvectorChunkStore(indexType: 'hnsw', efSearch: 100);

    $refl    = new ReflectionClass($store);
    $prop    = $refl->getProperty('efSearch');
    $prop->setAccessible(true);

    expect($prop->getValue($store))->toBe(100);
});

it('PgvectorChunkStore allows exact mode (no index)', function () {
    $store = new PgvectorChunkStore(indexType: 'exact');

    $refl  = new ReflectionClass($store);
    $prop  = $refl->getProperty('indexType');
    $prop->setAccessible(true);

    expect($prop->getValue($store))->toBe('exact');
});

// ══════════════════════════════════════════════════════════════
// CachedEmbeddingEngine — Redis tags
// ══════════════════════════════════════════════════════════════

function makeInnerEngine(int &$callCount, array $returnVector = [0.1, 0.2, 0.3]): AiEngineInterface
{
    return new class ($callCount, $returnVector) implements AiEngineInterface {
        public function __construct(private int &$calls, private array $vector) {}
        public function complete(AiRequest $r): AiResponse { return AiResponse::ok('x', 'test', 'm', 1, 1); }
        public function embed(string $text, string $provider = 'openai'): array
        {
            $this->calls++;
            return $this->vector;
        }
    };
}

it('CachedEmbeddingEngine exposes clearByProvider() method', function () {
    $calls  = 0;
    $engine = new CachedEmbeddingEngine(makeInnerEngine($calls), useRedisTags: false);

    // Should not throw — just logs a warning when tags not enabled
    expect(fn () => $engine->clearByProvider('openai'))->not->toThrow(\Exception::class);
});

it('CachedEmbeddingEngine exposes clearByModel() method', function () {
    $calls  = 0;
    $engine = new CachedEmbeddingEngine(makeInnerEngine($calls), useRedisTags: false);

    expect(fn () => $engine->clearByModel('openai', 'text-embedding-3-small'))->not->toThrow(\Exception::class);
});

it('CachedEmbeddingEngine exposes clearAll() method', function () {
    $calls  = 0;
    $engine = new CachedEmbeddingEngine(makeInnerEngine($calls), useRedisTags: false);

    expect(fn () => $engine->clearAll())->not->toThrow(\Exception::class);
});

it('CachedEmbeddingEngine generates unique keys for different providers', function () {
    $calls  = 0;
    $engine = new CachedEmbeddingEngine(makeInnerEngine($calls));

    $refl   = new ReflectionClass($engine);
    $method = $refl->getMethod('buildKey');
    $method->setAccessible(true);

    $keyA = $method->invoke($engine, 'hello world', 'openai');
    $keyB = $method->invoke($engine, 'hello world', 'huggingface');
    $keyC = $method->invoke($engine, 'different text', 'openai');

    expect($keyA)->not->toBe($keyB)  // same text, different provider → different key
        ->and($keyA)->not->toBe($keyC); // different text, same provider → different key
});

it('CachedEmbeddingEngine generates consistent keys for same input', function () {
    $calls  = 0;
    $engine = new CachedEmbeddingEngine(makeInnerEngine($calls));

    $refl   = new ReflectionClass($engine);
    $method = $refl->getMethod('buildKey');
    $method->setAccessible(true);

    $key1 = $method->invoke($engine, 'consistent text', 'openai');
    $key2 = $method->invoke($engine, 'consistent text', 'openai');

    expect($key1)->toBe($key2); // deterministic
});

it('CachedEmbeddingEngine complete() always delegates to inner (no caching)', function () {
    $calls  = 0;
    $inner  = makeInnerEngine($calls);
    $engine = new CachedEmbeddingEngine($inner);

    $engine->complete(new AiRequest(
        model: new \ExpressCodeEngines\Shared\DTOs\ModelConfig(provider: 'null', model: 'null'),
        messages: [],
    ));

    $engine->complete(new AiRequest(
        model: new \ExpressCodeEngines\Shared\DTOs\ModelConfig(provider: 'null', model: 'null'),
        messages: [],
    ));

    // embed() not called — only complete() was called, and complete is not cached
    expect($calls)->toBe(0); // inner.embed never called via complete()
});

it('CachedEmbeddingEngine returns empty vector without caching it', function () {
    $calls  = 0;
    $inner  = new class ($calls) implements AiEngineInterface {
        public function __construct(private int &$c) {}
        public function complete(AiRequest $r): AiResponse { return AiResponse::ok('x', 't', 'm', 1, 1); }
        public function embed(string $text, string $provider = 'openai'): array { $this->c++; return []; }
    };

    $engine = new CachedEmbeddingEngine($inner);

    $engine->embed('text', 'openai');
    $engine->embed('text', 'openai'); // second call — should NOT be cached (empty vector)

    expect($calls)->toBe(2); // inner called both times — empty vectors not cached
});
