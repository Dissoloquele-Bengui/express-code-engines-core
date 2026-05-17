<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\AiEngine\Providers;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\AiProviderInterface;
use ExpressCodeEngines\Shared\DTOs\AiMessage;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;

/**
 * Ollama provider for local LLM inference.
 *
 * Runs models locally via Ollama (https://ollama.com).
 * No API key required. No per-token cost.
 * Suitable for development, privacy-sensitive workloads, and airgapped environments.
 *
 * Supported models (install via `ollama pull`):
 *   llama3.2, llama3.1, mistral, gemma2, phi4, qwen2.5, deepseek-r1, etc.
 *
 * Configuration:
 *   OLLAMA_BASE_URL=http://localhost:11434   (default)
 *
 * Differences from cloud providers:
 *   - No cost tracking (local inference is free)
 *   - Token counts estimated from response metadata (eval_count)
 *   - JSON mode uses Ollama's native `format: json` parameter
 *   - Timeout is longer (120s) — local models can be slow on CPU
 *   - Embeddings via /api/embeddings endpoint
 */
final class OllamaProvider implements AiProviderInterface
{
    private const DEFAULT_BASE_URL = 'http://localhost:11434';

    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {}

    public function name(): string { return 'ollama'; }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(3)->get("{$this->baseUrl}/api/tags");
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function complete(AiRequest $request): AiResponse
    {
        $model    = $request->model;
        $messages = $request->messages;

        // Separate system message (Ollama supports it in messages array natively)
        $body = [
            'model'    => $model->model,
            'messages' => array_map(fn (AiMessage $m) => $m->toArray(), $messages),
            'stream'   => false,
            'options'  => [
                'temperature' => $model->temperature,
                'num_predict' => $model->maxTokens,
            ],
        ];

        if ($model->jsonMode) {
            $body['format'] = 'json';
        }

        try {
            $response = Http::timeout(120) // local models can be slow on CPU
                ->post("{$this->baseUrl}/api/chat", $body);

            if (! $response->successful()) {
                return AiResponse::failed(
                    'PROVIDER_ERROR',
                    "Ollama HTTP {$response->status()}: {$response->body()}",
                );
            }

            $data    = $response->json();
            $content = $data['message']['content'] ?? '';

            $inputTokens  = (int) ($data['prompt_eval_count'] ?? 0);
            $outputTokens = (int) ($data['eval_count']        ?? 0);

            $structured = null;
            if ($model->jsonMode) {
                $decoded    = json_decode($content, true);
                $structured = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }

            return AiResponse::ok(
                content:      $content,
                provider:     'ollama',
                model:        $model->model,
                inputTokens:  $inputTokens,
                outputTokens: $outputTokens,
                estimatedCost: 0.0, // local inference — no cost
                data:         $structured,
            );

        } catch (\Throwable $e) {
            try { Log::error('[OllamaProvider] Request failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return AiResponse::failed('CONNECTION_ERROR', $e->getMessage());
        }
    }

    /**
     * Generates embeddings via Ollama's /api/embeddings endpoint.
     * Use `ollama pull nomic-embed-text` for a good embedding model.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        try {
            $response = Http::timeout(30)
                ->post("{$this->baseUrl}/api/embeddings", [
                    'model'  => 'nomic-embed-text', // recommended embedding model for Ollama
                    'prompt' => $text,
                ]);

            if (! $response->successful()) {
                return [];
            }

            return $response->json('embedding', []);

        } catch (\Throwable $e) {
            try { Log::warning('[OllamaProvider] Embedding failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return [];
        }
    }
}
