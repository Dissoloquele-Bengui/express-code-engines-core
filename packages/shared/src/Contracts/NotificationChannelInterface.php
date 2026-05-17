<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\ReactionContext;
use ExpressCodeEngines\Shared\DTOs\ReactionDefinition;
use ExpressCodeEngines\Shared\DTOs\NotificationRequest;
use ExpressCodeEngines\Shared\ValueObjects\ReactionSummary;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

/**
 * Resolves a notification channel driver (mail, sms, push, database).
 * Each driver knows how to deliver a rendered notification to a recipient.
 */
interface NotificationChannelInterface
{
    public function channel(): string;

    /**
     * @param  string|object  $recipient
     * @param  array          $rendered   ['subject' => ..., 'body' => ..., 'data' => ...]
     */
    public function deliver(string|object $recipient, array $rendered): NotificationResult;
}
