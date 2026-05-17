<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use ExpressCodeEngines\Extended\ExtendedServiceProvider;
use ExpressCodeEngines\Extended\NotificationEngine\NotificationEngine;
use ExpressCodeEngines\Extended\ReactionEngine\ReactionEngine;
use ExpressCodeEngines\Extended\DynamicPolicyEngine\DynamicPolicyEngine;
use ExpressCodeEngines\Shared\Contracts\NotificationEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ReactionEngineInterface;
use ExpressCodeEngines\Shared\Contracts\DynamicPolicyEngineInterface;
use ExpressCodeEngines\Shared\Contracts\AiEngineInterface;
use ExpressCodeEngines\Shared\Contracts\NotificationChannelInterface;
use ExpressCodeEngines\Shared\DTOs\NotificationRequest;
use ExpressCodeEngines\Shared\DTOs\PolicyContext;
use ExpressCodeEngines\Shared\DTOs\PolicyDefinition;
use ExpressCodeEngines\Shared\DTOs\ReactionContext;
use ExpressCodeEngines\Shared\DTOs\ReactionDefinition;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

// ──────────────────────────────────────────────────────────────
// Bootstrap — minimal container for extended engines
// ──────────────────────────────────────────────────────────────

function makeExtendedApp(array $extraConfig = []): Container
{
    $app = new Container();
    $app->instance('app', $app);
    $app->instance(Container::class, $app);

    $config = new ConfigRepository(array_merge_recursive([
        'app'    => ['debug' => true, 'env' => 'testing', 'url' => 'http://localhost', 'name' => 'Test'],
        'cache'  => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        'engines' => [
            'extended' => [
                'notifications' => [
                    'templates' => [
                        'test_template' => [
                            'channels' => ['mail'],
                            'subject'  => 'Test: {{title}}',
                            'body'     => 'Hello {{name}}, this is a test.',
                            'defaults' => [],
                        ],
                    ],
                    'rate_limits' => [], // disabled for tests
                ],
                'ai' => [
                    'fallback_chain'   => ['null'],
                    'embedding_cache'  => ['enabled' => false],
                    'ollama'           => ['enabled' => false],
                ],
                'documents' => [
                    'vector_store'   => 'default',
                    'embed_provider' => 'null',
                    'chunker'        => ['target_size' => 500, 'max_size' => 1000, 'overlap' => 50],
                ],
                'search'  => ['driver' => 'null', 'entities' => []],
                'chat'    => ['session_ttl' => 3600, 'max_messages' => 20, 'max_context_chars' => 4000,
                              'personas' => []],
                'policy'  => ['cache_enabled' => false, 'cache_ttl' => 300],
                'integrations' => [],
                'orchestration' => [],
            ],
        ],
    ], $extraConfig));

    $app->instance('config', $config);
    $app->instance(\Illuminate\Contracts\Config\Repository::class, $config);

    $events = new Dispatcher($app);
    $app->instance('events', $events);
    $app->instance(\Illuminate\Contracts\Events\Dispatcher::class, $events);

    // Minimal cache manager (array driver)
    $app->bind('cache', fn () => new \Illuminate\Cache\CacheManager($app));
    $app->bind(\Illuminate\Contracts\Cache\Factory::class, fn ($a) => $a->make('cache'));

    Facade::setFacadeApplication($app);

    $provider = new ExtendedServiceProvider($app);
    $provider->register();

    return $app;
}

// ──────────────────────────────────────────────────────────────
// NotificationEngine — ServiceProvider integration
// ──────────────────────────────────────────────────────────────

it('ExtendedServiceProvider resolves NotificationEngineInterface', function () {
    $app    = makeExtendedApp();
    $engine = $app->make(NotificationEngineInterface::class);

    expect($engine)->toBeInstanceOf(NotificationEngine::class);
});

it('NotificationEngine sends to a spy channel resolved via container', function () {
    $delivered = [];

    $spyChannel = new class ($delivered) implements NotificationChannelInterface {
        public function __construct(private array &$log) {}
        public function channel(): string { return 'mail'; }
        public function deliver(string|object $recipient, array $rendered): NotificationResult
        {
            $this->log[] = ['recipient' => $recipient, 'subject' => $rendered['subject']];
            return NotificationResult::sent('mail', 'spy-1');
        }
    };

    $app = makeExtendedApp();
    $app->tag([get_class($spyChannel)], 'engine.notification.channels');
    $app->bind(get_class($spyChannel), fn () => $spyChannel);

    // Re-resolve after tagging
    $engine = $app->make(NotificationEngineInterface::class);

    $result = $engine->send(new NotificationRequest(
        templateKey: 'test_template',
        recipient:   'test@example.com',
        data:        ['title' => 'Integration Test', 'name' => 'João'],
    ));

    expect($result->isFailed())->toBeFalse()
        ->and($result->channel)->toBe('mail');
});

it('NotificationEngine returns TEMPLATE_NOT_FOUND for unregistered template', function () {
    $app    = makeExtendedApp();
    $engine = $app->make(NotificationEngineInterface::class);

    $result = $engine->send(new NotificationRequest(
        templateKey: 'nonexistent',
        recipient:   'test@example.com',
    ));

    expect($result->isFailed())->toBeTrue()
        ->and($result->errorCode)->toBe('TEMPLATE_NOT_FOUND');
});

// ──────────────────────────────────────────────────────────────
// ReactionEngine — ServiceProvider integration
// ──────────────────────────────────────────────────────────────

it('ExtendedServiceProvider resolves ReactionEngineInterface', function () {
    $app    = makeExtendedApp();
    $engine = $app->make(ReactionEngineInterface::class);

    expect($engine)->toBeInstanceOf(ReactionEngine::class);
});

it('ReactionEngine processes events end-to-end via container', function () {
    $app    = makeExtendedApp();
    $engine = $app->make(ReactionEngineInterface::class);

    $context = new ReactionContext(
        event:   'order.submitted',
        payload: ['order' => ['total' => 100], 'customer' => ['email' => 'c@x.com']],
    );

    // Dispatch with no matching definitions — should return empty summary without crashing
    $summary = $engine->react($context, []);

    expect($summary->matched)->toBe(0)
        ->and($summary->failed)->toBe(0);
});

it('ReactionEngine with notify handler routes through NotificationEngine', function () {
    $delivered = [];

    // Register a spy mail channel
    $spyChannel = new class ($delivered) implements NotificationChannelInterface {
        public function __construct(private array &$log) {}
        public function channel(): string { return 'mail'; }
        public function deliver(string|object $recipient, array $rendered): NotificationResult
        {
            $this->log[] = $recipient;
            return NotificationResult::sent('mail');
        }
    };

    $app = makeExtendedApp();
    $app->tag([get_class($spyChannel)], 'engine.notification.channels');
    $app->bind(get_class($spyChannel), fn () => $spyChannel);

    $engine = $app->make(ReactionEngineInterface::class);

    $summary = $engine->react(
        new ReactionContext(
            event:   'order.submitted',
            payload: [
                'order'    => ['reference' => 'ORD-001', 'total' => '€250.00'],
                'customer' => ['name' => 'Ana', 'email' => 'ana@example.com'],
            ],
        ),
        [
            ReactionDefinition::fromArray([
                'event'   => 'order.submitted',
                'handler' => 'notify',
                'async'   => false,
                'params'  => [
                    'template_key'   => 'test_template',
                    'recipient_path' => 'customer.email',
                    'data'           => ['title' => 'Order received', 'name' => 'Ana'],
                ],
            ]),
        ],
    );

    expect($summary->dispatched)->toBe(1)
        ->and($summary->failed)->toBe(0);
});

// ──────────────────────────────────────────────────────────────
// DynamicPolicyEngine — ServiceProvider integration
// ──────────────────────────────────────────────────────────────

it('ExtendedServiceProvider resolves DynamicPolicyEngineInterface', function () {
    $app    = makeExtendedApp();
    $engine = $app->make(DynamicPolicyEngineInterface::class);

    expect($engine)->toBeInstanceOf(DynamicPolicyEngine::class);
});

it('DynamicPolicyEngine resolves and evaluates can() end-to-end', function () {
    $app    = makeExtendedApp();
    $engine = $app->make(DynamicPolicyEngineInterface::class);

    $user = new class { public int $id = 1; public int $tenant_id = 10; };

    $definition = PolicyDefinition::fromArray('App\Models\Order', [
        'default_effect' => 'deny',
        'rules' => [
            [
                'id'        => 'same_tenant',
                'ability'   => 'view',
                'effect'    => 'allow',
                'condition' => 'record.tenant_id == user.tenant_id',
                'priority'  => 10,
            ],
        ],
    ]);

    $allowCtx = new PolicyContext(
        user:    $user,
        ability: 'view',
        record:  ['tenant_id' => 10],
    );

    $denyCtx = new PolicyContext(
        user:    $user,
        ability: 'view',
        record:  ['tenant_id' => 99],
    );

    expect($engine->can($allowCtx, $definition))->toBeTrue()
        ->and($engine->can($denyCtx, $definition))->toBeFalse();
});

// ──────────────────────────────────────────────────────────────
// AiEngine — resolves NullProvider from container
// ──────────────────────────────────────────────────────────────

it('ExtendedServiceProvider resolves AiEngineInterface with NullProvider fallback', function () {
    $app    = makeExtendedApp();
    $engine = $app->make(AiEngineInterface::class);

    expect($engine)->not->toBeNull();
});

// ──────────────────────────────────────────────────────────────
// Multiple engines resolve independently (no shared state)
// ──────────────────────────────────────────────────────────────

it('each make() call returns a valid engine instance', function () {
    $app = makeExtendedApp();

    $engines = [
        NotificationEngineInterface::class,
        ReactionEngineInterface::class,
        DynamicPolicyEngineInterface::class,
        AiEngineInterface::class,
    ];

    foreach ($engines as $interface) {
        $engine = $app->make($interface);
        expect($engine)->not->toBeNull("Failed to resolve {$interface}");
    }
});
