<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\NotificationEngine\NotificationEngine;
use ExpressCodeEngines\Extended\NotificationEngine\Renderers\TemplateRenderer;
use ExpressCodeEngines\Extended\NotificationEngine\TemplateRegistry;
use ExpressCodeEngines\Extended\ReactionEngine\Conditions\ConditionEvaluator;
use ExpressCodeEngines\Extended\ReactionEngine\Handlers\NotifyHandler;
use ExpressCodeEngines\Extended\ReactionEngine\ReactionEngine;
use ExpressCodeEngines\Extended\ReactionEngine\ReactionHandlerRegistry;
use ExpressCodeEngines\Shared\Contracts\NotificationChannelInterface;
use ExpressCodeEngines\Shared\DTOs\NotificationTemplate;
use ExpressCodeEngines\Shared\DTOs\ReactionContext;
use ExpressCodeEngines\Shared\DTOs\ReactionDefinition;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

// ──────────────────────────────────────────────────────────────
// Integration wiring
// ──────────────────────────────────────────────────────────────

/**
 * Builds a complete wired stack: ReactionEngine → NotifyHandler → NotificationEngine → SpyChannel
 * Returns the engine and a reference to the spy's captured deliveries.
 */
function buildStack(array &$delivered = []): ReactionEngine
{
    // Spy channel — captures every delivery without sending anything real
    $spyChannel = new class ($delivered) implements NotificationChannelInterface {
        public function __construct(private array &$log) {}
        public function channel(): string { return 'mail'; }
        public function deliver(string|object $recipient, array $rendered): NotificationResult
        {
            $this->log[] = [
                'recipient' => is_string($recipient) ? $recipient : ($recipient->email ?? '?'),
                'subject'   => $rendered['subject'] ?? null,
                'body'      => $rendered['body']    ?? null,
            ];
            return NotificationResult::sent('mail', 'spy-' . count($this->log));
        }
    };

    $templates = new TemplateRegistry([
        'order_submitted' => [
            'channels' => ['mail'],
            'subject'  => 'Order #{{order.reference}} received',
            'body'     => 'Hi {{customer.name}}, your order total is {{order.total}}.',
        ],
        'large_order_alert' => [
            'channels' => ['mail'],
            'subject'  => 'Large order alert: #{{order.reference}}',
            'body'     => 'Order total {{order.total}} exceeds threshold.',
        ],
    ]);

    $notificationEngine = new NotificationEngine(
        templates: $templates,
        renderer:  new TemplateRenderer(),
        channels:  ['mail' => $spyChannel],
    );

    $registry = new ReactionHandlerRegistry([
        new NotifyHandler($notificationEngine),
    ]);

    return new ReactionEngine(
        registry:   $registry,
        conditions: new ConditionEvaluator(),
    );
}

// ──────────────────────────────────────────────────────────────
// Integration tests
// ──────────────────────────────────────────────────────────────

it('delivers a notification end-to-end when event fires and condition is met', function () {
    $delivered = [];
    $engine    = buildStack($delivered);

    $context = new ReactionContext(
        event:   'order.submitted',
        payload: [
            'order'    => ['reference' => 'ORD-042', 'total' => '€350.00'],
            'customer' => ['name' => 'Maria', 'email' => 'maria@example.com'],
        ],
    );

    $definitions = [
        ReactionDefinition::fromArray([
            'event'   => 'order.submitted',
            'handler' => 'notify',
            'async'   => false, // sync so we can assert immediately
            'params'  => [
                'template_key'   => 'order_submitted',
                'recipient_path' => 'customer.email',
            ],
        ]),
    ];

    $summary = $engine->react($context, $definitions);

    expect($summary->dispatched)->toBe(1)
        ->and($summary->failed)->toBe(0)
        ->and($delivered)->toHaveCount(1)
        ->and($delivered[0]['recipient'])->toBe('maria@example.com')
        ->and($delivered[0]['subject'])->toBe('Order #ORD-042 received')
        ->and($delivered[0]['body'])->toBe('Hi Maria, your order total is €350.00.');
});

it('fires multiple reactions for a single event', function () {
    $delivered = [];
    $engine    = buildStack($delivered);

    $context = new ReactionContext(
        event:   'order.submitted',
        payload: [
            'order'    => ['reference' => 'ORD-100', 'total' => '€1200.00'],
            'customer' => ['name' => 'Pedro', 'email' => 'pedro@example.com'],
            'manager'  => ['email' => 'manager@company.com'],
        ],
    );

    $definitions = [
        ReactionDefinition::fromArray([
            'event'   => 'order.submitted',
            'handler' => 'notify',
            'async'   => false,
            'params'  => [
                'template_key'   => 'order_submitted',
                'recipient_path' => 'customer.email',
            ],
        ]),
        ReactionDefinition::fromArray([
            'event'   => 'order.submitted',
            'handler' => 'notify',
            'async'   => false,
            'only_if' => 'order.total > 500', // large order alert
            'params'  => [
                'template_key'   => 'large_order_alert',
                'recipient_path' => 'manager.email',
            ],
        ]),
    ];

    $summary = $engine->react($context, $definitions);

    expect($summary->dispatched)->toBe(2)
        ->and($delivered)->toHaveCount(2)
        ->and($delivered[0]['recipient'])->toBe('pedro@example.com')
        ->and($delivered[1]['recipient'])->toBe('manager@company.com')
        ->and($delivered[1]['subject'])->toContain('ORD-100');
});

it('does not deliver when onlyIf condition is not met', function () {
    $delivered = [];
    $engine    = buildStack($delivered);

    $context = new ReactionContext(
        event:   'order.submitted',
        payload: [
            'order'    => ['reference' => 'ORD-001', 'total' => '€80.00'], // below 500
            'customer' => ['name' => 'Rui', 'email' => 'rui@example.com'],
            'manager'  => ['email' => 'manager@company.com'],
        ],
    );

    $definitions = [
        ReactionDefinition::fromArray([
            'event'   => 'order.submitted',
            'handler' => 'notify',
            'async'   => false,
            'only_if' => 'order.total > 500',
            'params'  => [
                'template_key'   => 'large_order_alert',
                'recipient_path' => 'manager.email',
            ],
        ]),
    ];

    $summary = $engine->react($context, $definitions);

    expect($summary->matched)->toBe(1)
        ->and($summary->dispatched)->toBe(0)
        ->and($delivered)->toHaveCount(0);
});

it('handles wildcard events correctly end-to-end', function () {
    $delivered = [];
    $engine    = buildStack($delivered);

    $definitions = [
        ReactionDefinition::fromArray([
            'event'   => 'order.*', // matches all order events
            'handler' => 'notify',
            'async'   => false,
            'params'  => [
                'template_key'   => 'order_submitted',
                'recipient_path' => 'customer.email',
            ],
        ]),
    ];

    $basePayload = [
        'order'    => ['reference' => 'ORD-X', 'total' => '€100.00'],
        'customer' => ['name' => 'Test', 'email' => 'test@example.com'],
    ];

    $engine->react(new ReactionContext('order.submitted', $basePayload), $definitions);
    $engine->react(new ReactionContext('order.approved',  $basePayload), $definitions);
    $engine->react(new ReactionContext('invoice.paid',    $basePayload), $definitions); // should not match

    expect($delivered)->toHaveCount(2);
});

it('gracefully handles missing recipient path without crashing', function () {
    $delivered = [];
    $engine    = buildStack($delivered);

    $context = new ReactionContext(
        event:   'order.submitted',
        payload: ['order' => ['reference' => 'ORD-001']], // no customer key
    );

    $definitions = [
        ReactionDefinition::fromArray([
            'event'   => 'order.submitted',
            'handler' => 'notify',
            'async'   => false,
            'params'  => [
                'template_key'   => 'order_submitted',
                'recipient_path' => 'customer.email', // unresolvable
            ],
        ]),
    ];

    // Should not throw — engine isolates failures
    $summary = $engine->react($context, $definitions);

    expect($delivered)->toHaveCount(0)
        ->and($summary->failed)->toBe(1);
});
