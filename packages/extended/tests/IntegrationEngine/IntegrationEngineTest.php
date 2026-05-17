<?php

declare(strict_types=1);

use ExpressCodeEngines\Extended\IntegrationEngine\Http\PayloadMapper;
use ExpressCodeEngines\Extended\IntegrationEngine\IntegrationRegistry;
use ExpressCodeEngines\Shared\DTOs\IntegrationDefinition;
use ExpressCodeEngines\Shared\DTOs\IntegrationRequest;
use ExpressCodeEngines\Shared\ValueObjects\IntegrationResult;

// ──────────────────────────────────────────────────────────────
// PayloadMapper
// ──────────────────────────────────────────────────────────────

it('returns payload as-is when mapping is empty', function () {
    $mapper  = new PayloadMapper();
    $payload = ['order' => ['reference' => 'ORD-001', 'total' => 250]];

    expect($mapper->map($payload, []))->toBe($payload);
});

it('maps flat local field to flat remote field', function () {
    $mapper = new PayloadMapper();
    $result = $mapper->map(
        payload: ['reference' => 'ORD-001'],
        mapping: ['reference' => 'externalRef'],
    );

    expect($result)->toBe(['externalRef' => 'ORD-001']);
});

it('maps nested local dot-path to flat remote field', function () {
    $mapper = new PayloadMapper();
    $result = $mapper->map(
        payload: ['order' => ['reference' => 'ORD-001']],
        mapping: ['order.reference' => 'externalRef'],
    );

    expect($result)->toBe(['externalRef' => 'ORD-001']);
});

it('maps flat local field to nested remote dot-path', function () {
    $mapper = new PayloadMapper();
    $result = $mapper->map(
        payload: ['email' => 'user@example.com'],
        mapping: ['email' => 'contact.email'],
    );

    expect($result)->toBe(['contact' => ['email' => 'user@example.com']]);
});

it('maps multiple fields including nested-to-nested', function () {
    $mapper = new PayloadMapper();
    $result = $mapper->map(
        payload: [
            'order'    => ['reference' => 'ORD-001', 'total' => 250.00],
            'customer' => ['email' => 'user@example.com', 'name' => 'João'],
        ],
        mapping: [
            'order.reference' => 'ref',
            'order.total'     => 'amount',
            'customer.email'  => 'contact.email',
            'customer.name'   => 'contact.name',
        ],
    );

    expect($result)->toBe([
        'ref'     => 'ORD-001',
        'amount'  => 250.00,
        'contact' => ['email' => 'user@example.com', 'name' => 'João'],
    ]);
});

it('silently skips unmapped paths that do not exist in payload', function () {
    $mapper = new PayloadMapper();
    $result = $mapper->map(
        payload: ['order' => ['reference' => 'ORD-001']],
        mapping: [
            'order.reference'  => 'ref',
            'order.nonexistent' => 'missing', // path does not exist
        ],
    );

    expect($result)->toBe(['ref' => 'ORD-001']);
    expect($result)->not->toHaveKey('missing');
});

// ──────────────────────────────────────────────────────────────
// IntegrationRegistry
// ──────────────────────────────────────────────────────────────

it('registers and finds definitions by key', function () {
    $registry = new IntegrationRegistry();
    $def      = new IntegrationDefinition(key: 'stripe_charge', url: 'https://api.stripe.com/v1/charges');

    $registry->register($def);

    expect($registry->has('stripe_charge'))->toBeTrue()
        ->and($registry->find('stripe_charge'))->toBe($def)
        ->and($registry->find('nonexistent'))->toBeNull();
});

it('builds definitions from config array in constructor', function () {
    $registry = new IntegrationRegistry([
        'send_sms' => [
            'url'    => 'https://api.twilio.com/messages',
            'method' => 'POST',
            'auth'   => ['type' => 'basic', 'user_env' => 'TWILIO_SID', 'pass_env' => 'TWILIO_TOKEN'],
        ],
    ]);

    expect($registry->has('send_sms'))->toBeTrue()
        ->and($registry->find('send_sms')->url)->toBe('https://api.twilio.com/messages')
        ->and($registry->find('send_sms')->method)->toBe('POST');
});

// ──────────────────────────────────────────────────────────────
// IntegrationResult Value Object
// ──────────────────────────────────────────────────────────────

it('IntegrationResult::ok() is not failed', function () {
    $result = IntegrationResult::ok(200, ['id' => 'ch_123'], attempts: 1, durationMs: 42);

    expect($result->isFailed())->toBeFalse()
        ->and($result->success)->toBeTrue()
        ->and($result->statusCode)->toBe(200)
        ->and($result->response)->toBe(['id' => 'ch_123'])
        ->and($result->durationMs)->toBe(42);
});

it('IntegrationResult::failed() is failed', function () {
    $result = IntegrationResult::failed(503, 'HTTP_ERROR', 'Service unavailable.', attempts: 3);

    expect($result->isFailed())->toBeTrue()
        ->and($result->isServerError())->toBeTrue()
        ->and($result->isClientError())->toBeFalse()
        ->and($result->attempts)->toBe(3);
});

it('classifies 4xx as client errors and 5xx as server errors', function () {
    expect(IntegrationResult::failed(422, 'VALIDATION', 'Invalid.')->isClientError())->toBeTrue()
        ->and(IntegrationResult::failed(429, 'RATE_LIMIT', 'Slow down.')->isClientError())->toBeTrue()
        ->and(IntegrationResult::failed(500, 'SERVER', 'Crash.')->isServerError())->toBeTrue()
        ->and(IntegrationResult::failed(502, 'GATEWAY', 'Bad gateway.')->isServerError())->toBeTrue();
});

// ──────────────────────────────────────────────────────────────
// IntegrationDefinition
// ──────────────────────────────────────────────────────────────

it('builds IntegrationDefinition from array with defaults', function () {
    $def = IntegrationDefinition::fromArray('my_api', [
        'url' => 'https://api.example.com/endpoint',
    ]);

    expect($def->key)->toBe('my_api')
        ->and($def->method)->toBe('POST')
        ->and($def->timeout)->toBe(30)
        ->and($def->async)->toBeTrue()
        ->and($def->retry['attempts'])->toBe(3);
});
