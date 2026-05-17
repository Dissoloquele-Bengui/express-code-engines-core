<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\ReactionContext;
use ExpressCodeEngines\Shared\DTOs\ReactionDefinition;
use ExpressCodeEngines\Shared\DTOs\NotificationRequest;
use ExpressCodeEngines\Shared\ValueObjects\ReactionSummary;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

/**
 * A single reaction handler.
 *
 * Each handler is responsible for one type of side-effect:
 * sending a notification, dispatching a job, updating a field, etc.
 *
 * Handlers must be registered in the ReactionHandlerRegistry
 * before the engine can dispatch to them.
 */
interface ReactionHandlerInterface
{
    /**
     * The handler key used in ReactionDefinition::handler.
     * e.g. 'notify', 'dispatch_job', 'update_field'
     */
    public function key(): string;

    /**
     * Execute the handler synchronously.
     *
     * Failures must be caught internally and returned as a failed HandlerResult.
     * Exceptions must never propagate out of this method.
     *
     * @param  array  $params  From ReactionDefinition::params
     */
    public function handle(ReactionContext $context, array $params): HandlerResult;

    /**
     * Dispatch the handler asynchronously via Laravel Queue.
     * The engine calls this instead of handle() when async: true.
     */
    public function dispatchAsync(ReactionContext $context, array $params, ?string $queue, int $delaySeconds): void;
}
