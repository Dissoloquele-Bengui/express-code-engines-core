<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ExpressCodeEngines\Shared\Contracts\NotificationEngineInterface;
use ExpressCodeEngines\Shared\DTOs\NotificationRequest;

/**
 * Queued job that delivers a NotificationRequest via the NotificationEngine.
 *
 * Used by NotifyHandler::dispatchAsync() to offload notification delivery
 * from the main request lifecycle.
 *
 * The job is self-contained: it receives the fully built NotificationRequest
 * and resolves the NotificationEngine from the container on execution.
 * This means any config changes between dispatch and execution are picked up.
 *
 * Retry behaviour: 3 attempts with exponential backoff (5s, 25s, 125s).
 * On final failure, the job is moved to the failed jobs table.
 */
final class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(
        public readonly NotificationRequest $request,
    ) {}

    public function handle(NotificationEngineInterface $engine): void
    {
        $result = $engine->send($this->request);

        if ($result->failed()) {
            try { Log::error('[SendNotificationJob] Delivery failed after execution.', [
                'template'  => $this->request->templateKey,
                'channel'   => $result->channel,
                'error'     => $result->errorMessage,
                'attempt'   => $this->attempts(),
            ]); } catch (\Throwable $__e) {}

            // Re-throw so Laravel can retry the job
            throw new \RuntimeException($result->errorMessage ?? 'Notification delivery failed.');
        }

        try { Log::info('[SendNotificationJob] Delivered.', [
            'template'   => $this->request->templateKey,
            'channel'    => $result->channel,
            'message_id' => $result->messageId,
        ]); } catch (\Throwable $__e) {}
    }

    /**
     * Exponential backoff: 5s → 25s → 125s between retries.
     *
     * @return int[]
     */
    public function backoff(): array
    {
        return [5, 25, 125];
    }

    public function failed(\Throwable $exception): void
    {
        try { Log::critical('[SendNotificationJob] All retries exhausted.', [
            'template'  => $this->request->templateKey,
            'exception' => $exception->getMessage(),
        ]); } catch (\Throwable $__e) {}
    }
}
