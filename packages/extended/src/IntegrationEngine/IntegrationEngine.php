<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\IntegrationEngine;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\IntegrationEngineInterface;
use ExpressCodeEngines\Shared\DTOs\IntegrationDefinition;
use ExpressCodeEngines\Shared\DTOs\IntegrationRequest;
use ExpressCodeEngines\Shared\ValueObjects\IntegrationResult;
use ExpressCodeEngines\Extended\IntegrationEngine\Http\PayloadMapper;

/**
 * Executes HTTP calls to external systems with retry, circuit breaker,
 * payload mapping and structured logging.
 *
 * Circuit breaker state is tracked per integration key in the cache.
 * When an integration exceeds the failure threshold, it enters the OPEN
 * state and calls are rejected immediately (without hitting the remote).
 * After the recovery window, it moves to HALF-OPEN to probe recovery.
 */
final class IntegrationEngine implements IntegrationEngineInterface
{
    private const CIRCUIT_OPEN_TTL     = 60;  // seconds open before half-open probe
    private const CIRCUIT_FAIL_THRESHOLD = 5; // failures before opening circuit

    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly PayloadMapper       $mapper,
    ) {}

    public function call(IntegrationRequest $request): IntegrationResult
    {
        $definition = $this->registry->find($request->integrationKey);

        if ($definition === null) {
            return IntegrationResult::failed(0, 'INTEGRATION_NOT_FOUND',
                "No integration registered for key '{$request->integrationKey}'.");
        }

        // Circuit breaker check
        if ($this->isCircuitOpen($definition->key)) {
            return IntegrationResult::failed(0, 'CIRCUIT_OPEN',
                "Circuit breaker open for integration '{$definition->key}'. Try again later.");
        }

        $payload    = $this->mapper->map($request->payload, $definition->mapping);
        $headers    = $this->resolveHeaders($definition);
        $maxAttempts = $definition->retry['attempts'] ?? 3;
        $backoff     = $definition->retry['backoff']   ?? [1, 5, 15];
        $retryOn     = $definition->retry['on_status'] ?? [429, 500, 502, 503, 504];

        $attempts  = 0;
        $startTime = microtime(true);

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                $response = Http::timeout($definition->timeout)
                    ->withHeaders($headers)
                    ->{strtolower($definition->method)}($definition->url, $payload);

                $durationMs = (int) ((microtime(true) - $startTime) * 1000);

                // Log structured request/response
                try { Log::info('[IntegrationEngine] Request completed.', [
                    'integration' => $definition->key,
                    'status'      => $response->status(),
                    'attempt'     => $attempts,
                    'duration_ms' => $durationMs,
                ]); } catch (\Throwable $__e) {}

                if ($response->successful()) {
                    $this->recordSuccess($definition->key);

                    return IntegrationResult::ok(
                        statusCode: $response->status(),
                        response:   $response->json() ?? [],
                        attempts:   $attempts,
                        durationMs: $durationMs,
                    );
                }

                // Retry on configured status codes
                if (in_array($response->status(), $retryOn, true) && $attempts < $maxAttempts) {
                    $delay = $backoff[$attempts - 1] ?? end($backoff);
                    sleep((int) $delay);
                    continue;
                }

                $this->recordFailure($definition->key);

                return IntegrationResult::failed(
                    statusCode: $response->status(),
                    code:       'HTTP_ERROR',
                    message:    "HTTP {$response->status()}: {$response->body()}",
                    attempts:   $attempts,
                    durationMs: $durationMs,
                );

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                try { Log::warning('[IntegrationEngine] Connection failed.', [
                    'integration' => $definition->key,
                    'attempt'     => $attempts,
                    'error'       => $e->getMessage(),
                ]); } catch (\Throwable $__e) {}

                if ($attempts < $maxAttempts) {
                    $delay = $backoff[$attempts - 1] ?? end($backoff);
                    sleep((int) $delay);
                    continue;
                }

                $this->recordFailure($definition->key);
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);

                return IntegrationResult::failed(
                    statusCode: 0,
                    code:       'CONNECTION_FAILED',
                    message:    $e->getMessage(),
                    attempts:   $attempts,
                    durationMs: $durationMs,
                );
            }
        }

        // Should not reach here, but satisfies PHP return type
        return IntegrationResult::failed(0, 'MAX_RETRIES', 'All retry attempts exhausted.');
    }

    public function dispatch(IntegrationRequest $request, ?string $queue = null): void
    {
        $job = new \ExpressCodeEngines\Extended\Jobs\ExecuteIntegrationJob($request);
        $job = $queue ? $job->onQueue($queue) : $job;
        dispatch($job);
    }

    // ──────────────────────────────────────────────────────────
    // Circuit breaker helpers
    // ──────────────────────────────────────────────────────────

    private function circuitKey(string $integrationKey): string
    {
        return "engine.integration.circuit.{$integrationKey}";
    }

    private function isCircuitOpen(string $key): bool
    {
        $state = cache()->get($this->circuitKey($key), ['status' => 'closed', 'failures' => 0]);
        return ($state['status'] ?? 'closed') === 'open';
    }

    private function recordSuccess(string $key): void
    {
        cache()->put($this->circuitKey($key), ['status' => 'closed', 'failures' => 0], 300);
    }

    private function recordFailure(string $key): void
    {
        $state    = cache()->get($this->circuitKey($key), ['status' => 'closed', 'failures' => 0]);
        $failures = ($state['failures'] ?? 0) + 1;

        if ($failures >= self::CIRCUIT_FAIL_THRESHOLD) {
            cache()->put($this->circuitKey($key), ['status' => 'open', 'failures' => $failures], self::CIRCUIT_OPEN_TTL);
            try { Log::warning("[IntegrationEngine] Circuit OPEN for '{$key}' after {$failures} failures."); } catch (\Throwable $__e) {}
        } else {
            cache()->put($this->circuitKey($key), ['status' => 'closed', 'failures' => $failures], 300);
        }
    }

    private function resolveHeaders(IntegrationDefinition $definition): array
    {
        $headers = array_merge(['Content-Type' => 'application/json', 'Accept' => 'application/json'], $definition->headers);
        $auth    = $definition->auth;

        return match ($auth['type'] ?? 'none') {
            'bearer'  => array_merge($headers, ['Authorization' => 'Bearer ' . env($auth['token_env'] ?? '')]),
            'api_key' => array_merge($headers, [$auth['header'] ?? 'X-Api-Key' => env($auth['key_env'] ?? '')]),
            'basic'   => array_merge($headers, ['Authorization' => 'Basic ' . base64_encode(env($auth['user_env'] ?? '') . ':' . env($auth['pass_env'] ?? ''))]),
            default   => $headers,
        };
    }
}
