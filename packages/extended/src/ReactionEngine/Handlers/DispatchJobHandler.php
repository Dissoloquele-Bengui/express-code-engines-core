<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ReactionEngine\Handlers;

use ExpressCodeEngines\Shared\Contracts\HandlerResult;
use ExpressCodeEngines\Shared\Contracts\ReactionHandlerInterface;
use ExpressCodeEngines\Shared\DTOs\ReactionContext;

final class DispatchJobHandler implements ReactionHandlerInterface
{
    public function key(): string { return 'dispatch_job'; }

    public function handle(ReactionContext $context, array $params): HandlerResult
    {
        return $this->doDispatch($context, $params, null, 0);
    }

    public function dispatchAsync(ReactionContext $context, array $params, ?string $queue, int $delaySeconds): void
    {
        $this->doDispatch($context, $params, $queue, $delaySeconds);
    }

    private function doDispatch(ReactionContext $context, array $params, ?string $queue, int $delay): HandlerResult
    {
        $jobClass = $params['job'] ?? null;

        if (! $jobClass || ! class_exists($jobClass)) {
            try { Log::warning("[DispatchJobHandler] Job '{$jobClass}' not found.", ['event' => $context->event]); } catch (\Throwable $__e) {}
            return HandlerResult::failed("Job class '{$jobClass}' not found.");
        }

        try {
            $job = new $jobClass($context->payload, $context->meta);
            $job = $queue ? $job->onQueue($queue) : $job;
            $delay > 0 ? dispatch($job)->delay(now()->addSeconds($delay)) : dispatch($job);
            return HandlerResult::ok(['job' => $jobClass]);
        } catch (\Throwable $e) {
            try { Log::error('[DispatchJobHandler] Dispatch failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return HandlerResult::failed($e->getMessage());
        }
    }
}
