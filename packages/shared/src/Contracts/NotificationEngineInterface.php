<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\ReactionContext;
use ExpressCodeEngines\Shared\DTOs\ReactionDefinition;
use ExpressCodeEngines\Shared\DTOs\NotificationRequest;
use ExpressCodeEngines\Shared\ValueObjects\ReactionSummary;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

/**
 * Sends notifications across multiple channels with template rendering,
 * rate limiting and graceful failure isolation.
 *
 * Can be called directly by an Action or via the ReactionEngine's
 * built-in 'notify' handler.
 *
 * Never blocks the main flow — failures are logged, not thrown.
 */
interface NotificationEngineInterface
{
    public function send(NotificationRequest $request): NotificationResult;

    /**
     * Queue multiple notifications in a single call.
     *
     * @param  NotificationRequest[]  $requests
     * @return NotificationResult[]
     */
    public function sendMany(array $requests): array;
}
