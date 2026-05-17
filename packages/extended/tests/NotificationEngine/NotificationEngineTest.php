<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\NotificationEngine\NotificationEngine;
use ExpressCodeEngines\Extended\NotificationEngine\Renderers\TemplateRenderer;
use ExpressCodeEngines\Extended\NotificationEngine\TemplateRegistry;
use ExpressCodeEngines\Shared\Contracts\NotificationChannelInterface;
use ExpressCodeEngines\Shared\DTOs\NotificationRequest;
use ExpressCodeEngines\Shared\DTOs\NotificationTemplate;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function notif_makeChannel(string $name, bool $succeeds = true, ?string &$capturedRendered = null): NotificationChannelInterface
{
    return new class ($name, $succeeds, $capturedRendered) implements NotificationChannelInterface {
        public function __construct(
            private string  $name,
            private bool    $succeeds,
            private ?string &$captured,
        ) {}

        public function channel(): string { return $this->name; }

        public function deliver(string|object $recipient, array $rendered): NotificationResult
        {
            if (isset($this->captured)) {
                $this->captured = $rendered['body'] ?? '';
            }

            return $this->succeeds
                ? NotificationResult::sent($this->name, 'msg-123')
                : NotificationResult::failed($this->name, 'DELIVERY_ERROR', 'Simulated failure.');
        }
    };
}

function notif_makeTemplate(
    string $key,
    array  $channels = ['mail'],
    string $subject  = 'Hello {{name}}',
    string $body     = 'Dear {{name}}, your {{entity}} is ready.',
    array  $defaults = [],
): NotificationTemplate {
    return new NotificationTemplate(
        key:      $key,
        channels: $channels,
        subject:  $subject,
        body:     $body,
        defaults: $defaults,
    );
}

function notif_makeEngine(
    array $templates = [],
    array $channels  = [],
): NotificationEngine {
    $registry = new TemplateRegistry();

    foreach ($templates as $template) {
        $registry->register($template);
    }

    return new NotificationEngine(
        templates: $registry,
        renderer:  new TemplateRenderer(),
        channels:  $channels,
    );
}

function notif_makeRequest(
    string       $templateKey,
    string|object $recipient = 'user@example.com',
    array         $data      = [],
    ?array        $channels  = null,
): NotificationRequest {
    return new NotificationRequest(
        templateKey: $templateKey,
        recipient:   $recipient,
        data:        $data,
        channels:    $channels,
    );
}

// ──────────────────────────────────────────────────────────────
// Template resolution
// ──────────────────────────────────────────────────────────────

it('returns TEMPLATE_NOT_FOUND when template is not registered', function () {
    $engine = notif_makeEngine(templates: [], channels: ['mail' => notif_makeChannel('mail')]);
    $result = $engine->send(notif_makeRequest('unknown_template'));

    expect($result->isFailed())->toBeTrue()
        ->and($result->errorCode)->toBe('TEMPLATE_NOT_FOUND');
});

it('sends successfully when template and channel are registered', function () {
    $engine = notif_makeEngine(
        templates: [notif_makeTemplate('welcome')],
        channels:  ['mail' => notif_makeChannel('mail')],
    );

    $result = $engine->send(notif_makeRequest('welcome', data: ['name' => 'Ana']));

    expect($result->isFailed())->toBeFalse()
        ->and($result->channel)->toBe('mail')
        ->and($result->messageId)->toBe('msg-123');
});

// ──────────────────────────────────────────────────────────────
// Channel selection
// ──────────────────────────────────────────────────────────────

it('uses template default channels when request channels is null', function () {
    $deliveredTo = [];

    $trackingChannel = function (string $name) use (&$deliveredTo): NotificationChannelInterface {
        return new class ($name, $deliveredTo) implements NotificationChannelInterface {
            public function __construct(private string $n, private array &$log) {}
            public function channel(): string { return $this->n; }
            public function deliver(string|object $recipient, array $rendered): NotificationResult
            {
                $this->log[] = $this->n;
                return NotificationResult::sent($this->n);
            }
        };
    };

    $engine = notif_makeEngine(
        templates: [notif_makeTemplate('order_ready', channels: ['mail', 'database'])],
        channels:  [
            'mail'     => $trackingChannel('mail'),
            'database' => $trackingChannel('database'),
        ],
    );

    $engine->send(notif_makeRequest('order_ready', channels: null));

    expect($deliveredTo)->toBe(['mail', 'database']);
});

it('uses request channels when explicitly set, overriding template defaults', function () {
    $deliveredTo = [];

    $trackingChannel = function (string $name) use (&$deliveredTo): NotificationChannelInterface {
        return new class ($name, $deliveredTo) implements NotificationChannelInterface {
            public function __construct(private string $n, private array &$log) {}
            public function channel(): string { return $this->n; }
            public function deliver(string|object $recipient, array $rendered): NotificationResult
            {
                $this->log[] = $this->n;
                return NotificationResult::sent($this->n);
            }
        };
    };

    $engine = notif_makeEngine(
        templates: [notif_makeTemplate('order_ready', channels: ['mail', 'database'])],
        channels:  [
            'mail'     => $trackingChannel('mail'),
            'database' => $trackingChannel('database'),
        ],
    );

    // Override: only database
    $engine->send(notif_makeRequest('order_ready', channels: ['database']));

    expect($deliveredTo)->toBe(['database']);
});

it('skips unknown channels silently and continues with known ones', function () {
    $engine = notif_makeEngine(
        templates: [notif_makeTemplate('t', channels: ['nonexistent', 'mail'])],
        channels:  ['mail' => notif_makeChannel('mail')],
    );

    $result = $engine->send(notif_makeRequest('t'));

    // 'nonexistent' skipped, 'mail' succeeded
    expect($result->isFailed())->toBeFalse()
        ->and($result->channel)->toBe('mail');
});

// ──────────────────────────────────────────────────────────────
// Channel failure isolation
// ──────────────────────────────────────────────────────────────

it('continues to next channel when one channel fails', function () {
    $deliveredTo = [];

    $tracking = function (string $n, bool $ok) use (&$deliveredTo) { return new class ($n, $ok, $deliveredTo) implements NotificationChannelInterface {
        public function __construct(private string $n, private bool $ok, private array &$log) {}
        public function channel(): string { return $this->n; }
        public function deliver(string|object $r, array $rendered): NotificationResult
        {
            $this->log[] = $this->n;
            return $this->ok
                ? NotificationResult::sent($this->n)
                : NotificationResult::failed($this->n, 'ERR', 'failed');
        }
    }; };

    $engine = notif_makeEngine(
        templates: [notif_makeTemplate('t', channels: ['mail', 'database'])],
        channels:  [
            'mail'     => $tracking('mail',     false), // fails
            'database' => $tracking('database', true),  // succeeds
        ],
    );

    $result = $engine->send(notif_makeRequest('t'));

    expect($deliveredTo)->toBe(['mail', 'database']) // both attempted
        ->and($result->isFailed())->toBeFalse()         // last result succeeded
        ->and($result->channel)->toBe('database');
});

// ──────────────────────────────────────────────────────────────
// sendMany
// ──────────────────────────────────────────────────────────────

it('sends multiple notifications and returns keyed results', function () {
    $engine = notif_makeEngine(
        templates: [notif_makeTemplate('welcome'), notif_makeTemplate('order_ready')],
        channels:  ['mail' => notif_makeChannel('mail')],
    );

    $results = $engine->sendMany([
        'a' => notif_makeRequest('welcome'),
        'b' => notif_makeRequest('order_ready'),
        'c' => notif_makeRequest('nonexistent'),
    ]);

    expect($results)->toHaveKey('a')
        ->and($results)->toHaveKey('b')
        ->and($results)->toHaveKey('c')
        ->and($results['a']->isFailed())->toBeFalse()
        ->and($results['b']->isFailed())->toBeFalse()
        ->and($results['c']->isFailed())->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// TemplateRenderer — inline substitution
// ──────────────────────────────────────────────────────────────

it('substitutes {{placeholder}} variables in subject and body', function () {
    $renderer = new TemplateRenderer();
    $template = notif_makeTemplate(
        key:     'test',
        subject: 'Order #{{order.reference}} received',
        body:    'Hello {{customer.name}}, total: {{order.total}}',
    );

    $rendered = $renderer->render($template, [
        'order'    => ['reference' => 'ORD-001', 'total' => '€250.00'],
        'customer' => ['name' => 'João'],
    ]);

    expect($rendered['subject'])->toBe('Order #ORD-001 received')
        ->and($rendered['body'])->toBe('Hello João, total: €250.00');
});

it('leaves unresolved placeholders intact', function () {
    $renderer = new TemplateRenderer();
    $template = notif_makeTemplate(key: 'test', body: 'Hello {{missing.field}}!');

    $rendered = $renderer->render($template, []);

    expect($rendered['body'])->toBe('Hello {{missing.field}}!');
});

it('merges template defaults with request data, request data wins', function () {
    $renderer = new TemplateRenderer();
    $template = new NotificationTemplate(
        key:      'test',
        channels: ['mail'],
        body:     'App: {{app.name}}, User: {{user.name}}',
        defaults: ['app' => ['name' => 'DefaultApp'], 'user' => ['name' => 'Default User']],
    );

    $rendered = $renderer->render($template, ['user' => ['name' => 'Ana']]);

    expect($rendered['body'])->toBe('App: DefaultApp, User: Ana');
});

it('resolves nested dot-path placeholders correctly', function () {
    $renderer = new TemplateRenderer();
    $template = notif_makeTemplate(key: 't', body: 'Item: {{order.items.0.name}}');

    $rendered = $renderer->render($template, [
        'order' => ['items' => [['name' => 'Laptop']]],
    ]);

    expect($rendered['body'])->toBe('Item: Laptop');
});

it('exposes merged data in rendered array for channel drivers', function () {
    $renderer = new TemplateRenderer();
    $template = new NotificationTemplate(
        key:      'test',
        channels: ['mail'],
        body:     'Hi',
        defaults: ['app' => 'MyApp'],
    );

    $rendered = $renderer->render($template, ['user' => 'Ana']);

    expect($rendered['data'])->toHaveKey('app')
        ->and($rendered['data'])->toHaveKey('user');
});

// ──────────────────────────────────────────────────────────────
// TemplateRegistry
// ──────────────────────────────────────────────────────────────

it('registers and finds templates by key', function () {
    $registry = new TemplateRegistry();
    $template = notif_makeTemplate('welcome');

    $registry->register($template);

    expect($registry->has('welcome'))->toBeTrue()
        ->and($registry->find('welcome'))->toBe($template)
        ->and($registry->find('nonexistent'))->toBeNull();
});

it('builds templates from config array in constructor', function () {
    $registry = new TemplateRegistry([
        'order_submitted' => [
            'channels' => ['mail'],
            'subject'  => 'Order received',
            'body'     => 'Your order is confirmed.',
        ],
    ]);

    expect($registry->has('order_submitted'))->toBeTrue()
        ->and($registry->find('order_submitted')->channels)->toBe(['mail']);
});

it('lists all registered template keys', function () {
    $registry = new TemplateRegistry();
    $registry->register(notif_makeTemplate('a'));
    $registry->register(notif_makeTemplate('b'));
    $registry->register(notif_makeTemplate('c'));

    expect($registry->keys())->toBe(['a', 'b', 'c']);
});

// ──────────────────────────────────────────────────────────────
// NotificationResult Value Object
// ──────────────────────────────────────────────────────────────

it('NotificationResult::sent() is not failed', function () {
    $result = NotificationResult::sent('mail', 'msg-abc');

    expect($result->isFailed())->toBeFalse()
        ->and($result->success)->toBeTrue()
        ->and($result->channel)->toBe('mail')
        ->and($result->messageId)->toBe('msg-abc');
});

it('NotificationResult::failed() is failed', function () {
    $result = NotificationResult::failed('sms', 'TIMEOUT', 'Gateway timed out.');

    expect($result->isFailed())->toBeTrue()
        ->and($result->errorCode)->toBe('TIMEOUT')
        ->and($result->errorMessage)->toBe('Gateway timed out.');
});
