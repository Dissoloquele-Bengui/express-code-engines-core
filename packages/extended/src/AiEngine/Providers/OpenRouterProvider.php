<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\AiEngine\Providers;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\AiProviderInterface;
use ExpressCodeEngines\Shared\DTOs\AiMessage;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;

/**
 * OpenRouter provider — routes to 200+ models via a single OpenAI-compatible API.
 *
 * OpenRouter aggregates: OpenAI, Anthropic, Google, Meta, Mistral, Cohere,
 * Perplexity, DeepSeek, and many others — including free-tier models.
 *
 * Key advantages:
 *   - Single API key for all providers
 *   - Automatic fallback between providers (set via X-OR-Fallback-Models header)
 *   - Free models available (prefixed with ':free' suffix, e.g. 'meta-llama/llama-3-8b-instruct:free')
 *   - Per-request model routing (good for cost optimisation)
 *   - Usage and cost tracked at openrouter.ai/activity
 *
 * Configuration:
 *   OPENROUTER_API_KEY=sk-or-v1-...
 *   OPENROUTER_APP_URL=https://yourdomain.com   (required by OpenRouter for attribution)
 *   OPENROUTER_APP_NAME=YourAppName             (shown in openrouter.ai/activity)
 *
 * Model naming convention (must match OpenRouter's model IDs):
 *   'openai/gpt-4o'
 *   'anthropic/claude-3-5-sonnet'
 *   'meta-llama/llama-3.1-70b-instruct'
 *   'mistralai/mistral-7b-instruct:free'
 *   'google/gemma-2-9b-it:free'
 *
 * Embeddings:
 *   OpenRouter does NOT support embeddings — they are delegated to the OpenAI provider.
 *   Use embed_provider: 'openai' or 'ollama' in the DocumentEngine config.
 */
final class OpenRouterProvider implements AiProviderInterface
{
    private const BASE_URL = 'https://openrouter.ai/api/v1';

    /** @var array<string, array{prompt: float, completion: float}> cost per 1M tokens USD */
    private const KNOWN_FREE_MODELS = [
        'meta-llama/llama-3.1-8b-instruct:free',
        'meta-llama/llama-3.2-3b-instruct:free',
        'google/gemma-2-9b-it:free',
        'mistralai/mistral-7b-instruct:free',
        'microsoft/phi-3-mini-128k-instruct:free',
        'qwen/qwen-2-7b-instruct:free',
    ];

    public function __construct(
        private readonly string  $apiKey,
        private readonly string  $appUrl  = '',
        private readonly string  $appName = 'laravel-engines',

        /**
         * Fallback model IDs if the primary model is unavailable.
         * Sent as X-OR-Fallback-Models header.
         * e.g. ['openai/gpt-4o-mini', 'meta-llama/llama-3.1-8b-instruct:free']
         */
        private readonly array   $fallbackModels = [],
    ) {}

    public function name(): string { return 'openrouter'; }

    public function isAvailable(): bool { return $this->apiKey !== ''; }

    public function complete(AiRequest $request): AiResponse
    {
        $model    = $request->model;
        $messages = array_map(fn (AiMessage $m) => $m->toArray(), $request->messages);

        $body = [
            'model'       => $model->model,
            'messages'    => $messages,
            'max_tokens'  => $model->maxTokens,
            'temperature' => $model->temperature,
        ];

        if ($model->jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $headers = [
            'Authorization'  => "Bearer {$this->apiKey}",
            'Content-Type'   => 'application/json',
            'HTTP-Referer'   => $this->appUrl,
            'X-Title'        => $this->appName,
        ];

        if (! empty($this->fallbackModels)) {
            $headers['X-OR-Fallback-Models'] = implode(',', $this->fallbackModels);
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(90)
                ->post(self::BASE_URL . '/chat/completions', $body);

            if (! $response->successful()) {
                return AiResponse::failed(
                    'PROVIDER_ERROR',
                    "OpenRouter HTTP {$response->status()}: {$response->body()}",
                );
            }

            $data    = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            $usage   = $data['usage'] ?? [];
            $input   = $usage['prompt_tokens']     ?? 0;
            $output  = $usage['completion_tokens'] ?? 0;

            // OpenRouter returns cost in USD directly in the response
            $cost = (float) ($data['usage']['cost'] ?? $this->estimateCost($model->model, $input, $output));

            $structured = null;
            if ($model->jsonMode) {
                $decoded    = json_decode($content, true);
                $structured = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }

            return AiResponse::ok(
                content:      $content,
                provider:     'openrouter',
                model:        $model->model,
                inputTokens:  $input,
                outputTokens: $output,
                estimatedCost: $cost,
                data:         $structured,
            );

        } catch (\Throwable $e) {
            try { Log::error('[OpenRouterProvider] Request failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return AiResponse::failed('CONNECTION_ERROR', $e->getMessage());
        }
    }

    /**
     * OpenRouter does not provide an embeddings endpoint.
     * Returns empty array — use openai or ollama provider for embeddings.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        return [];
    }

    public function isFreeModel(string $modelId): bool
    {
        return in_array($modelId, self::KNOWN_FREE_MODELS, true)
            || str_ends_with($modelId, ':free');
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    private function estimateCost(string $model, int $input, int $output): float
    {
        // Free models have zero cost
        if ($this->isFreeModel($model)) {
            return 0.0;
        }

        // For paid models, OpenRouter returns the actual cost in the response.
        // This fallback is only used if 'cost' is missing from the response.
        return 0.0;
    }
}
