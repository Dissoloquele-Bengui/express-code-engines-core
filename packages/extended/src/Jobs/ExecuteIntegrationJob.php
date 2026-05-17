<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ExpressCodeEngines\Shared\Contracts\IntegrationEngineInterface;
use ExpressCodeEngines\Shared\DTOs\IntegrationRequest;

/**
 * Executes an integration call asynchronously.
 * Dispatched by IntegrationEngine::dispatch().
 */
final class ExecuteIntegrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly IntegrationRequest $request,
    ) {}

    public function handle(IntegrationEngineInterface $engine): void
    {
        $result = $engine->call($this->request);

        if ($result->failed()) {
            try { Log::error('[ExecuteIntegrationJob] Integration failed.', [
                'key'     => $this->request->integrationKey,
                'code'    => $result->errorCode,
                'message' => $result->errorMessage,
                'attempt' => $this->attempts(),
            ]); } catch (\Throwable $__e) {}

            throw new \RuntimeException($result->errorMessage ?? 'Integration failed.');
        }

        try { Log::info('[ExecuteIntegrationJob] Integration succeeded.', [
            'key'         => $this->request->integrationKey,
            'status'      => $result->statusCode,
            'duration_ms' => $result->durationMs,
        ]); } catch (\Throwable $__e) {}
    }

    public function backoff(): array { return [5, 25, 125]; }

    public function failed(\Throwable $e): void
    {
        try { Log::critical('[ExecuteIntegrationJob] All retries exhausted.', [
            'key'       => $this->request->integrationKey,
            'exception' => $e->getMessage(),
        ]); } catch (\Throwable $__e) {}
    }
}
