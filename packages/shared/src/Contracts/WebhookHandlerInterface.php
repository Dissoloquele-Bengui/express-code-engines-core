<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\IntegrationEngine\Webhooks;

use Illuminate\Http\Request;

/**
 * Contract for a webhook event handler.
 *
 * Each handler handles one or more (integration, event_type) combinations.
 * Register handlers in the ServiceProvider via:
 *   $this->app->tag(StripePaymentHandler::class, 'engine.webhook.handlers');
 */
interface WebhookHandlerInterface
{
    /**
     * Returns true when this handler can process the given integration + event combination.
     */
    public function supports(string $integrationKey, string $eventType): bool;

    /**
     * Handles the inbound webhook event.
     *
     * Must not throw — catch exceptions internally and log.
     * Return value is ignored; use side effects (dispatching jobs, etc.).
     */
    public function handle(string $integrationKey, string $eventType, array $payload, Request $request): void;
}
