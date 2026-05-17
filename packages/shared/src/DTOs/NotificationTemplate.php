<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * Defines a notification template.
 */
final class NotificationTemplate
{
    public function __construct(
        public readonly string $key,

        /**
         * Default channels for this template.
         * e.g. ['mail'], ['mail', 'database'], ['sms']
         */
        public readonly array $channels,

        /**
         * Template subject (for email/push).
         * Supports {{variable}} placeholders.
         */
        public readonly ?string $subject = null,

        /**
         * Template body.
         * Can be a plain string with {{variable}} placeholders,
         * or a view name prefixed with 'view:' (e.g. 'view:emails.order_submitted').
         */
        public readonly ?string $body = null,

        /** Default data merged with every request using this template */
        public readonly array $defaults = [],
    ) {}

    /**
     * Factory from a plain config array.
     */
    public static function fromArray(string $key, array $config): self
    {
        return new self(
            key:      $key,
            channels: $config['channels'] ?? ['mail'],
            subject:  $config['subject']  ?? null,
            body:     $config['body']     ?? null,
            defaults: $config['defaults'] ?? [],
        );
    }
}
