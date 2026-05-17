<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * Defines a single external integration endpoint.
 */
final class IntegrationDefinition
{
    public function __construct(
        /** Unique integration key — used to resolve from registry */
        public readonly string $key,

        /** Full URL of the external endpoint */
        public readonly string $url,

        /** HTTP method */
        public readonly string $method = 'POST',

        /**
         * Static headers merged with runtime headers.
         * e.g. ['Content-Type' => 'application/json', 'X-Api-Version' => '2']
         */
        public readonly array $headers = [],

        /**
         * Authentication config.
         * Types: 'bearer', 'basic', 'api_key', 'oauth2', 'none'
         * e.g. ['type' => 'bearer', 'token_env' => 'STRIPE_SECRET_KEY']
         */
        public readonly array $auth = [],

        /**
         * Retry policy.
         * e.g. ['attempts' => 3, 'backoff' => [1, 5, 15], 'on_status' => [429, 500, 503]]
         */
        public readonly array $retry = ['attempts' => 3, 'backoff' => [1, 5, 15]],

        /** Request timeout in seconds */
        public readonly int $timeout = 30,

        /**
         * Payload field mapping: local_field → remote_field.
         * Supports dot-paths on both sides.
         * e.g. ['order.reference' => 'externalRef', 'customer.email' => 'contact.email']
         */
        public readonly array $mapping = [],

        /**
         * Whether to execute asynchronously via Queue.
         * Webhooks and non-critical integrations should be async.
         */
        public readonly bool $async = true,
    ) {}

    public static function fromArray(string $key, array $config): self
    {
        return new self(
            key:     $key,
            url:     $config['url'],
            method:  $config['method']  ?? 'POST',
            headers: $config['headers'] ?? [],
            auth:    $config['auth']    ?? [],
            retry:   $config['retry']   ?? ['attempts' => 3, 'backoff' => [1, 5, 15]],
            timeout: $config['timeout'] ?? 30,
            mapping: $config['mapping'] ?? [],
            async:   $config['async']   ?? true,
        );
    }
}
