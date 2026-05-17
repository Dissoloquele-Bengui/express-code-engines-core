<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * Request to send a notification via the NotificationEngine.
 */
final class NotificationRequest
{
    public function __construct(
        /** Template key registered in the TemplateRegistry */
        public readonly string $templateKey,

        /** Recipient identifier (email, user_id, phone, etc.) */
        public readonly mixed $recipient,

        /** Data for placeholder substitution in templates */
        public readonly array $data = [],

        /** Override channels (null = use template defaults) */
        public readonly ?array $channels = null,

        /** Unique key for deduplication / idempotency */
        public readonly ?string $idempotencyKey = null,
    ) {}
}
