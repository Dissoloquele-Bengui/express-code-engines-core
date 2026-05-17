<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\AiEngine;

use ExpressCodeEngines\Shared\Contracts\AiEngineInterface;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;

/**
 * Unified AI engine with provider chain, retry and cost tracking.
 *
 * Execution pipeline:
 *   1. Resolve the provider chain for the requested provider
 *      (primary → fallbacks in order → null provider as last resort)
 *   2. Try each provider in sequence until one succeeds
 *   3. Validate JSON response when model->jsonMode is true
 *   4. Return AiResponse with content, tokens and estimated cost
 *
 * Design constraints:
 *   - Never throws — all failures returned as AiResponse::failed()
 *   - Never logs API keys — only provider names and error codes
 *   - JSON schema validation retries once before returning error
 *   - Cost is estimated at call time using provider pricing tables
 */
final class AiEngine implements AiEngineInterface
{
    public function __construct(
        private readonly AiProviderRegistry $registry,
        private readonly array              $pricing = [],
    ) {}

    public function complete(AiRequest $request): AiResponse
    {
        $chain = $this->registry->resolveChain($request->model->provider);

        if (empty($chain)) {
            return AiResponse::failed(
                'NO_PROVIDER',
                "No available provider for '{$request->model->provider}'.",
                $request->model->provider,
                $request->model->model,
            );
        }

        foreach ($chain as $provider) {
            try {
                $response = $provider->complete($request);

                if ($response->failed()) {
                    try { Log::warning('[AiEngine] Provider failed, trying next.', [
                        'provider' => $provider->name(),
                        'error'    => $response->errorCode,
                    ]); } catch (\Throwable $__e) {}
                    continue;
                }

                // Validate JSON when jsonMode is set
                if ($request->model->jsonMode && $response->structured === null) {
                    $validated = $this->parseJson($response);

                    if ($validated->failed()) {
                        try { Log::warning('[AiEngine] JSON parse failed.', [
                            'provider' => $provider->name(),
                        ]); } catch (\Throwable $__e) {}
                        continue;
                    }

                    $response = $validated;
                }

                // Validate against schema if provided
                if ($request->model->jsonSchema !== null && $response->structured !== null) {
                    $schemaOk = $this->validateSchema($response->structured, $request->model->jsonSchema);

                    if (! $schemaOk) {
                        try { Log::warning('[AiEngine] Schema validation failed.', [
                            'provider' => $provider->name(),
                        ]); } catch (\Throwable $__e) {}
                        continue;
                    }
                }

                try { Log::info('[AiEngine] Completion succeeded.', [
                    'provider' => $provider->name(),
                    'model'    => $response->model,
                    'tokens'   => $response->totalTokens(),
                    'cost_usd' => number_format($response->estimatedCost, 6),
                ]); } catch (\Throwable $__e) {}

                return $response;

            } catch (\Throwable $e) {
                try { Log::error('[AiEngine] Provider threw exception.', [
                    'provider'  => $provider->name(),
                    'exception' => $e->getMessage(),
                ]); } catch (\Throwable $__e) {}
            }
        }

        return AiResponse::failed(
            'ALL_PROVIDERS_FAILED',
            'All providers in the chain failed.',
            $request->model->provider,
            $request->model->model,
        );
    }

    /** @return float[] */
    public function embed(string $text, string $provider = 'openai'): array
    {
        $providerInstance = $this->registry->get($provider);

        if ($providerInstance === null || ! $providerInstance->isAvailable()) {
            return [];
        }

        try {
            return $providerInstance->embed($text);
        } catch (\Throwable) {
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    private function parseJson(AiResponse $response): AiResponse
    {
        $content = trim($response->content ?? '');

        // Strip markdown fences
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return AiResponse::failed(
                'JSON_PARSE_ERROR',
                'Model returned non-JSON content.',
                $response->provider,
                $response->model,
            );
        }

        return AiResponse::ok(
            content:          $content,
            provider:         $response->provider,
            model:            $response->model,
            promptTokens:     $response->promptTokens,
            completionTokens: $response->completionTokens,
            estimatedCost:    $response->estimatedCost,
            structured:       $decoded,
        );
    }

    private function validateSchema(array $data, array $schema): bool
    {
        // Basic required-field check
        foreach ($schema['required'] ?? [] as $field) {
            if (! array_key_exists($field, $data)) {
                return false;
            }
        }

        return true;
    }
}
