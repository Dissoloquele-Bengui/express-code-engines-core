<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\IntegrationEngine\Webhooks\WebhookContracts;
use ExpressCodeEngines\Extended\IntegrationEngine\Webhooks\WebhookHandlerInterface;
use ExpressCodeEngines\Extended\IntegrationEngine\Webhooks\WebhookProcessor;
use ExpressCodeEngines\Extended\IntegrationEngine\Webhooks\WebhookResult;
use ExpressCodeEngines\Extended\NotificationEngine\RateLimiting\NotificationRateLimiter;
use ExpressCodeEngines\Extended\SearchEngine\Drivers\AlgoliaDriver;
use ExpressCodeEngines\Extended\SearchEngine\Drivers\MeilisearchDriver;
use ExpressCodeEngines\Shared\DTOs\SearchRequest;
use Illuminate\Http\Request;

// ══════════════════════════════════════════════════════════════
// MeilisearchDriver — unit tests (no HTTP calls)
// ══════════════════════════════════════════════════════════════

it('MeilisearchDriver returns SearchResult::empty() when HTTP fails', function () {
    // Without a real Meilisearch instance, search() should return empty gracefully
    $driver = new MeilisearchDriver(
        host:   'http://localhost:7700',
        apiKey: 'invalid-key',
        entityConfig: ['App\Models\Order' => ['index_name' => 'orders']],
    );

    // The driver catches Throwable and returns empty — no crash
    $result = $driver->search(new SearchRequest(
        query:    'test',
        entities: ['App\Models\Order'],
    ));

    expect($result->hits)->toBe([])
        ->and($result->total)->toBe(0);
});

it('MeilisearchDriver generates correct index name from entity class', function () {
    $driver = new MeilisearchDriver('http://localhost', 'key', [
        'App\Models\Invoice' => ['index_name' => 'invoices_custom'],
        'App\Models\Order'   => [], // no index_name → auto-generated
    ]);

    $refl = new ReflectionClass($driver);
    $method = $refl->getMethod('indexName');
    $method->setAccessible(true);

    expect($method->invoke($driver, 'App\Models\Invoice'))->toBe('invoices_custom')
        ->and($method->invoke($driver, 'App\Models\Order'))->toBe('orders')
        ->and($method->invoke($driver, 'App\Models\ProductCategory'))->toBe('productcategorys');
});

it('MeilisearchDriver isAvailable() returns false when server unreachable', function () {
    $driver = new MeilisearchDriver('http://localhost:19999', 'key');
    expect($driver->isAvailable())->toBeFalse();
});

// ══════════════════════════════════════════════════════════════
// AlgoliaDriver — unit tests
// ══════════════════════════════════════════════════════════════

it('AlgoliaDriver generates correct index name', function () {
    $driver = new AlgoliaDriver('APP_ID', 'API_KEY', [
        'App\Models\Order'   => ['index_name' => 'prod_orders'],
        'App\Models\Product' => [],
    ]);

    $refl   = new ReflectionClass($driver);
    $method = $refl->getMethod('indexName');
    $method->setAccessible(true);

    expect($method->invoke($driver, 'App\Models\Order'))->toBe('prod_orders')
        ->and($method->invoke($driver, 'App\Models\Product'))->toBe('products');
});

it('AlgoliaDriver buildSearchParams maps page correctly (0-indexed)', function () {
    $driver = new AlgoliaDriver('APP_ID', 'API_KEY');

    $refl   = new ReflectionClass($driver);
    $method = $refl->getMethod('buildSearchParams');
    $method->setAccessible(true);

    $params = $method->invoke($driver, new SearchRequest(query: 'test', page: 1, perPage: 20), 'App\Models\Order');

    expect($params['page'])->toBe(0)       // Algolia is 0-indexed
        ->and($params['hitsPerPage'])->toBe(20)
        ->and($params['query'])->toBe('test');
});

it('AlgoliaDriver buildSearchParams builds filter string from scope and filters', function () {
    $driver = new AlgoliaDriver('APP_ID', 'API_KEY');

    $refl   = new ReflectionClass($driver);
    $method = $refl->getMethod('buildSearchParams');
    $method->setAccessible(true);

    $request = new SearchRequest(
        query:   'test',
        filters: ['status' => 'active'],
        scope:   ['tenant_id' => '10'],
    );

    $params = $method->invoke($driver, $request, 'App\Models\Order');

    expect($params)->toHaveKey('filters')
        ->and($params['filters'])->toContain('status:"active"')
        ->and($params['filters'])->toContain('tenant_id:"10"');
});

it('AlgoliaDriver returns empty on HTTP failure', function () {
    $driver = new AlgoliaDriver('INVALID', 'INVALID');

    $result = $driver->search(new SearchRequest(query: 'test', entities: ['App\Models\Order']));

    expect($result->hits)->toBe([]);
});

// ══════════════════════════════════════════════════════════════
// WebhookProcessor — unit tests
// ══════════════════════════════════════════════════════════════

function makeWebhookRequest(
    string $body    = '{"event":"order.created","data":{}}',
    array  $headers = [],
): Request {
    $request = Request::create('/webhook', 'POST', [], [], [], [], $body);
    $request->headers->add($headers);
    return $request;
}

function makeWebhookHandler(string $integration, string $event, bool &$called): WebhookHandlerInterface
{
    return new class ($integration, $event, $called) implements WebhookHandlerInterface {
        public function __construct(
            private string $int,
            private string $evt,
            private bool   &$called,
        ) {}

        public function supports(string $integrationKey, string $eventType): bool
        {
            return $integrationKey === $this->int && $eventType === $this->evt;
        }

        public function handle(string $integrationKey, string $eventType, array $payload, Request $request): void
        {
            $this->called = true;
        }
    };
}

it('WebhookProcessor rejects unknown integration key', function () {
    $processor = new WebhookProcessor(config: [], handlers: []);
    $result    = $processor->process('nonexistent', makeWebhookRequest());

    expect($result->wasAccepted())->toBeFalse()
        ->and($result->status)->toBe('rejected');
});

it('WebhookProcessor accepts and processes known event', function () {
    $handlerCalled = false;

    $processor = new WebhookProcessor(
        config: [
            'stripe' => [
                'signature'     => ['type' => 'none'],
                'event_source'  => 'header',
                'event_key'     => 'X-Event-Type',
            ],
        ],
        handlers: [
            makeWebhookHandler('stripe', 'payment.succeeded', $handlerCalled),
        ],
    );

    $result = $processor->process('stripe', makeWebhookRequest(
        headers: ['X-Event-Type' => 'payment.succeeded'],
    ));

    expect($result->wasAccepted())->toBeTrue()
        ->and($result->handled)->toBeTrue()
        ->and($handlerCalled)->toBeTrue();
});

it('WebhookProcessor accepts but marks unhandled when no handler matches', function () {
    $processor = new WebhookProcessor(
        config: [
            'github' => [
                'signature'    => ['type' => 'none'],
                'event_source' => 'header',
                'event_key'    => 'X-GitHub-Event',
            ],
        ],
        handlers: [], // no handlers registered
    );

    $result = $processor->process('github', makeWebhookRequest(
        headers: ['X-GitHub-Event' => 'push'],
    ));

    expect($result->wasAccepted())->toBeTrue()
        ->and($result->handled)->toBeFalse()  // accepted but not handled
        ->and($result->eventType)->toBe('push');
});

it('WebhookProcessor rejects invalid HMAC signature', function () {
    $processor = new WebhookProcessor(
        config: [
            'stripe' => [
                'signature' => [
                    'type'       => 'hmac_sha256',
                    'secret_env' => 'TEST_WEBHOOK_SECRET',
                    'header'     => 'X-Signature',
                    'prefix'     => 'sha256=',
                ],
                'event_source' => 'header',
                'event_key'    => 'X-Event',
            ],
        ],
        handlers: [],
    );

    // Set a different secret than what the signature uses
    putenv('TEST_WEBHOOK_SECRET=correct-secret');

    $result = $processor->process('stripe', makeWebhookRequest(
        headers: ['X-Signature' => 'sha256=invalidsignature', 'X-Event' => 'test'],
    ));

    expect($result->status)->toBe('rejected');
});

it('WebhookProcessor extracts event from payload dot-path', function () {
    $handlerCalled = false;

    $processor = new WebhookProcessor(
        config: [
            'shopify' => [
                'signature'    => ['type' => 'none'],
                'event_source' => 'payload',
                'event_key'    => 'type',
            ],
        ],
        handlers: [
            makeWebhookHandler('shopify', 'order/created', $handlerCalled),
        ],
    );

    $result = $processor->process(
        'shopify',
        makeWebhookRequest('{"type":"order/created","order":{"id":1}}'),
    );

    expect($result->wasAccepted())->toBeTrue()
        ->and($result->eventType)->toBe('order/created')
        ->and($handlerCalled)->toBeTrue();
});

it('WebhookResult::toHttpStatus maps correctly', function () {
    expect(WebhookResult::accepted('test', true)->toHttpStatus())->toBe(200)
        ->and(WebhookResult::rejected('bad')->toHttpStatus())->toBe(401)
        ->and(WebhookResult::failed('test', 'error')->toHttpStatus())->toBe(500);
});

// ══════════════════════════════════════════════════════════════
// NotificationRateLimiter — unit tests
// ══════════════════════════════════════════════════════════════

it('NotificationRateLimiter allows when no limits configured', function () {
    $limiter = new NotificationRateLimiter(config: []);

    expect($limiter->allow('welcome', 'user@example.com'))->toBeTrue();
});

it('NotificationRateLimiter returns correct recipient ID for strings', function () {
    $limiter = new NotificationRateLimiter();

    $refl   = new ReflectionClass($limiter);
    $method = $refl->getMethod('resolveRecipientId');
    $method->setAccessible(true);

    $id1 = $method->invoke($limiter, 'user@example.com');
    $id2 = $method->invoke($limiter, 'user@example.com');
    $id3 = $method->invoke($limiter, 'other@example.com');

    expect($id1)->toBe($id2)   // same email → same ID
        ->and($id1)->not->toBe($id3); // different email → different ID
});

it('NotificationRateLimiter resolves recipient ID from object with id property', function () {
    $user = new class { public int $id = 42; };

    $limiter = new NotificationRateLimiter();
    $refl    = new ReflectionClass($limiter);
    $method  = $refl->getMethod('resolveRecipientId');
    $method->setAccessible(true);

    expect($method->invoke($limiter, $user))->toBe('42');
});
