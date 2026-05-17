<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\IntegrationRequest;
use ExpressCodeEngines\Shared\ValueObjects\IntegrationResult;

/**
 * Communicates with external APIs, webhooks and third-party systems.
 *
 * Features:
 *   - Retry with exponential backoff
 *   - Circuit breaker pattern
 *   - Payload mapping (local field names → remote field names)
 *   - Structured request/response logging
 *   - Async by default (Jobs) — never blocks the main request
 */
interface IntegrationEngineInterface
{
    /**
     * Execute an integration call synchronously.
     * Use for critical integrations where the response is needed immediately.
     */
    public function call(IntegrationRequest $request): IntegrationResult;

    /**
     * Dispatch an integration call asynchronously via Laravel Queue.
     * Preferred for webhooks, notifications to external systems, etc.
     */
    public function dispatch(IntegrationRequest $request, ?string $queue = null): void;
}
