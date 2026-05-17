<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\AiEngine\Providers;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\AiProviderInterface;
use ExpressCodeEngines\Shared\DTOs\AiMessage;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;

/**
 * Groq provider — ultra-fast inference via Language Processing Units (LPUs).
 *
 * Groq is dramatically faster than GPU-based providers (typically 10-20x).
 * Ideal for: real-time chat, latency-sensitive applications, high-throughput pipelines.
 *
 * API is fully OpenAI-compatible — same request/response format.
 *
 * Supported models (as of 2025):
 *   'llama-3.3-70b-versatile'    — best quality, versatile
 *   'llama-3.1-8b-instant'       — fastest, lowest latency
 *   'mixtral-8x7b-32768'         — long context (32K)
 *   'gemma2-9b-it'               — Google Gemma 2
 *   'deepseek-r1-distill-llama-70b' — reasoning model
 *
 * Free tier: generous daily token limits. No credit card required.
 *
 * Limitations:
 *   - No image/vision support
 *   - No embeddings API (use openai or ollama for embeddings)
 *   - Rate limits per model per minute (varies by plan)
 *
 * Configuration:
 *   GROQ_API_KEY=gsk_...
 */
final class GroqProvider implements AiProviderInterface
{
    private const BASE_URL = 'https://api.groq.com/openai/v1';

    /** @var array<string, array{input: float, output: float}> cost per 1M tokens USD */
    private const PRICING = [
        'llama-3.3-70b-versatile'         => ['input' => 0.59, 'output' => 0.79],
        'llama-3.1-70b-versatile'         => ['input' => 0.59, 'output' => 0.79],
        'llama-3.1-8b-instant'            => ['input' => 0.05, 'output' => 0.08],
        'mixtral-8x7b-32768'              => ['input' => 0.24, 'output' => 0.24],
        'gemma2-9b-it'                    => ['input' => 0.20, 'output' => 0.20],
        'deepseek-r1-distill-llama-70b'   => ['input' => 0.75, 'output' => 0.99],
        'llama3-70b-8192'                 => ['input' => 0.59, 'output' => 0.79],
        'llama3-8b-8192'                  => ['input' => 0.05, 'output' => 0.08],
    ];

    public function __construct(
        private readonly string $apiKey,
    ) {}

    public function name(): string { return 'groq'; }

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

        // Groq supports JSON mode via response_format (same as OpenAI)
        if ($model->jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->baseUrl(self::BASE_URL)
                ->timeout(30) // Groq is fast — 30s is generous
                ->post('/chat/completions', $body);

            if (! $response->successful()) {
                // Groq returns 429 when rate limited — caller can retry
                $statusCode = $response->status();
                $errorMsg   = $response->json('error.message', $response->body());

                if ($statusCode === 429) {
                    try { Log::warning('[GroqProvider] Rate limited.', [
                        'model' => $model->model,
                        'retry_after' => $response->header('Retry-After'),
                    ]); } catch (\Throwable $__e) {}
                    return AiResponse::failed('RATE_LIMITED', "Groq rate limit: {$errorMsg}");
                }

                return AiResponse::failed('PROVIDER_ERROR', "Groq HTTP {$statusCode}: {$errorMsg}");
            }

            $data    = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            $usage   = $data['usage'] ?? [];
            $input   = $usage['prompt_tokens']     ?? 0;
            $output  = $usage['completion_tokens'] ?? 0;
            $cost    = $this->estimateCost($model->model, $input, $output);

            $structured = null;
            if ($model->jsonMode) {
                $decoded    = json_decode($content, true);
                $structured = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }

            return AiResponse::ok(
                content:      $content,
                provider:     'groq',
                model:        $model->model,
                inputTokens:  $input,
                outputTokens: $output,
                estimatedCost: $cost,
                data:         $structured,
            );

        } catch (\Throwable $e) {
            try { Log::error('[GroqProvider] Request failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return AiResponse::failed('CONNECTION_ERROR', $e->getMessage());
        }
    }

    /**
     * Groq does not provide an embeddings API.
     * Returns empty array — use openai or ollama for embeddings.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        return [];
    }

    private function estimateCost(string $model, int $input, int $output): float
    {
        foreach (self::PRICING as $key => $rates) {
            if (str_starts_with($model, $key)) {
                return (($input * $rates['input']) + ($output * $rates['output'])) / 1_000_000;
            }
        }

        return 0.0;
    }
}
