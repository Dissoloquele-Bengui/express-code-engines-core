<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A single integration call request — key + payload.
 */
final class IntegrationRequest
{
    public function __construct(
        /** Integration key registered in the IntegrationRegistry */
        public readonly string $integrationKey,

        /** The payload to send — mapped to the remote format before sending */
        public readonly array $payload,

        /** Optional: metadata for logging and tracing */
        public readonly array $meta = [],
    ) {}
}
