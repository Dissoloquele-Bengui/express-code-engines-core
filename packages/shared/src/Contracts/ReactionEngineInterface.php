<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\ReactionContext;
use ExpressCodeEngines\Shared\DTOs\ReactionDefinition;
use ExpressCodeEngines\Shared\DTOs\NotificationRequest;
use ExpressCodeEngines\Shared\ValueObjects\ReactionSummary;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

/**
 * Orchestrates side-effects triggered by domain events.
 *
 * Called after DB::commit() — never inside a transaction.
 * Handler failures are isolated: one failing handler never
 * prevents the remaining handlers from running.
 */
interface ReactionEngineInterface
{
    /**
     * Process all reactions matching the given event.
     *
     * @param  ReactionDefinition[]  $definitions
     */
    public function react(ReactionContext $context, array $definitions): ReactionSummary;
}
