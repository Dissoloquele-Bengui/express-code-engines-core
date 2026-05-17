<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\AiEngine\Providers;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\AiProviderInterface;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;

/**
 * Hugging Face Inference API provider.
 *
 * Primary use: free embeddings via the public Inference API.
 * Also supports text generation via Hugging Face hosted models.
 *
 * Free tier:
 *   - Embeddings: free with rate limits (generous for development/small production)
 *   - Text generation: free tier available, paid Inference Endpoints for production
 *
 * Best embedding models (free):
 *   'sentence-transformers/all-MiniLM-L6-v2'      — 384 dims, general purpose, fast
 *   'sentence-transformers/all-mpnet-base-v2'     — 768 dims, higher quality
 *   'BAAI/bge-small-en-v1.5'                      — 384 dims, excellent retrieval
 *   'BAAI/bge-base-en-v1.5'                       — 768 dims, best free option
 *   'intfloat/multilingual-e5-large'              — multilingual, 1024 dims
 *   'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2' — multilingual, 384 dims
 *
 * Configuration:
 *   HF_API_KEY=hf_...           (free at huggingface.co/settings/tokens)
 *   HF_EMBED_MODEL=BAAI/bge-small-en-v1.5   (optional, overrides default)
 *
 * Important: dimensions vary by model — must match your vector store column size.
 * Default model (bge-small-en-v1.5) outputs 384 dimensions.
 * pgvector and MySQL Vector columns must be created with the correct dimension.
 */
final class HuggingFaceProvider implements AiProviderInterface
{
    private const BASE_URL = 'https://api-inference.huggingface.co';

    /** Default dimensions per model (to avoid runtime API calls to check) */
    private const MODEL_DIMENSIONS = [
        'sentence-transformers/all-MiniLM-L6-v2'                              => 384,
        'sentence-transformers/all-mpnet-base-v2'                             => 768,
        'BAAI/bge-small-en-v1.5'                                              => 384,
        'BAAI/bge-base-en-v1.5'                                               => 768,
        'BAAI/bge-large-en-v1.5'                                              => 1024,
        'intfloat/multilingual-e5-large'                                       => 1024,
        'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2'         => 384,
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $embedModel   = 'BAAI/bge-small-en-v1.5',
        private readonly string $generateModel = 'mistralai/Mixtral-8x7B-Instruct-v0.1',
    ) {}

    public function name(): string { return 'huggingface'; }

    public function isAvailable(): bool { return $this->apiKey !== ''; }

    /**
     * Text generation via HuggingFace hosted models.
     * Note: HF text generation API is not OpenAI-compatible.
     * For production LLM inference, prefer Groq/OpenRouter/Anthropic.
     */
    public function complete(AiRequest $request): AiResponse
    {
        $model    = $request->model;

        // Extract the user message content (last user message)
        $userContent = '';
        foreach (array_reverse($request->messages) as $msg) {
            if ($msg->role === 'user') {
                $userContent = $msg->content;
                break;
            }
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post(self::BASE_URL . "/models/{$model->model}", [
                    'inputs'     => $userContent,
                    'parameters' => [
                        'max_new_tokens' => $model->maxTokens,
                        'temperature'    => $model->temperature,
                        'return_full_text' => false,
                    ],
                ]);

            if (! $response->successful()) {
                return AiResponse::failed(
                    'PROVIDER_ERROR',
                    "HuggingFace HTTP {$response->status()}: {$response->body()}",
                );
            }

            $data    = $response->json();
            $content = $data[0]['generated_text'] ?? '';

            $structured = null;
            if ($model->jsonMode) {
                $decoded    = json_decode($content, true);
                $structured = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }

            return AiResponse::ok(
                content:       $content,
                provider:      'huggingface',
                model:         $model->model,
                inputTokens:   0, // HF doesn't return token counts on free tier
                outputTokens:  0,
                estimatedCost: 0.0, // free tier
                data:          $structured,
            );

        } catch (\Throwable $e) {
            try { Log::error('[HuggingFaceProvider] Completion failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return AiResponse::failed('CONNECTION_ERROR', $e->getMessage());
        }
    }

    /**
     * Generates embeddings via the Sentence Transformers pipeline.
     * This is the primary value of this provider — free, high-quality embeddings.
     *
     * The API may return a 503 on first call while the model loads (~20s cold start).
     * The engine's retry mechanism handles this automatically.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post(self::BASE_URL . "/pipeline/feature-extraction/{$this->embedModel}", [
                    'inputs'  => $text,
                    'options' => ['wait_for_model' => true], // waits for cold-start instead of 503
                ]);

            if (! $response->successful()) {
                try { Log::warning('[HuggingFaceProvider] Embedding failed.', [
                    'model'  => $this->embedModel,
                    'status' => $response->status(),
                ]); } catch (\Throwable $__e) {}
                return [];
            }

            $embedding = $response->json();

            // HF returns nested arrays for batch inputs — unwrap single input
            if (isset($embedding[0]) && is_array($embedding[0])) {
                $embedding = $embedding[0];
            }

            return is_array($embedding) ? array_map('floatval', $embedding) : [];

        } catch (\Throwable $e) {
            try { Log::error('[HuggingFaceProvider] Embedding exception.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return [];
        }
    }

    /**
     * Returns the embedding dimension for the configured model.
     * Use this to create vector store columns with the correct size.
     */
    public function embeddingDimensions(): int
    {
        return self::MODEL_DIMENSIONS[$this->embedModel] ?? 384;
    }
}
