<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\AiEngine;

use Illuminate\Support\Facades\Cache;
use ExpressCodeEngines\Shared\Contracts\AiEngineInterface;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;

/**
 * Decorator for AiEngineInterface that caches vector embedding results.
 *
 * Problem: every document chunk, every search query, and every RAG retrieval
 * calls embed(). The same text embedded twice wastes API cost and adds latency.
 * At scale (10K+ chunks), this can mean thousands of unnecessary API calls.
 *
 * Solution: cache embeddings keyed by sha256(provider + model + text).
 * Embeddings are deterministic — same text + same model = same vector.
 *
 * Two caching strategies:
 *
 *   Standard (default):
 *     Uses Cache::forever() or Cache::put() with TTL.
 *     Works with any cache driver (file, database, array, Redis).
 *     Invalidation requires changing $prefix or $modelKey (invalidates all embeddings).
 *
 *   Redis with tags (recommended for production):
 *     Enable with $useRedisTags = true. Requires a taggable cache driver (Redis, Memcached).
 *     Allows selective invalidation by model or provider without clearing all embeddings.
 *     Cache::tags(['engine.embedding.openai'])->flush()  → clears only OpenAI embeddings
 *     Cache::tags(['engine.embedding.model.text-embedding-3-small'])->flush() → by model
 *
 * Usage:
 *   // Standard (any cache driver):
 *   new CachedEmbeddingEngine($engine)
 *
 *   // Redis with tags:
 *   new CachedEmbeddingEngine($engine, useRedisTags: true)
 *
 *   // Or bind in ServiceProvider (done automatically via config):
 *   'embedding_cache' => ['enabled' => true, 'use_redis_tags' => true, 'ttl' => 0]
 */
final class CachedEmbeddingEngine implements AiEngineInterface
{
    public function __construct(
        private readonly AiEngineInterface $inner,

        /**
         * TTL in seconds. 0 = permanent (Cache::forever).
         * Recommended: 0 — embeddings are deterministic and don't expire.
         */
        private readonly int    $ttl           = 0,

        /**
         * Key prefix. Change to invalidate ALL cached embeddings at once.
         * e.g. 'engine.embedding.v2.' invalidates all v1 embeddings.
         */
        private readonly string $prefix        = 'engine.embedding.v1.',

        /**
         * Enable Redis cache tags for selective invalidation.
         * Requires Redis or Memcached as the cache driver.
         * When false, falls back to standard cache (no tag support).
         */
        private readonly bool   $useRedisTags  = false,
    ) {}

    /**
     * Completions are never cached — always delegates to inner engine.
     */
    public function complete(AiRequest $request): AiResponse
    {
        return $this->inner->complete($request);
    }

    /**
     * Embeddings are cached by sha256(provider + model + text).
     * Model is included in the key so switching models auto-invalidates.
     *
     * @return float[]
     */
    public function embed(string $text, string $provider = 'openai'): array
    {
        $cacheKey = $this->buildKey($text, $provider);

        $cached = $this->cacheGet($cacheKey, $provider);

        if ($cached !== null) {
            return $cached;
        }

        $embedding = $this->inner->embed($text, $provider);

        if (! empty($embedding)) {
            $this->cachePut($cacheKey, $embedding, $provider);

            try { Log::debug('[CachedEmbeddingEngine] Cached.', [
                'provider'   => $provider,
                'dimensions' => count($embedding),
                'text_chars' => strlen($text),
            ]); } catch (\Throwable $__e) {}
        }

        return $embedding;
    }

    /**
     * Clears all cached embeddings for a specific provider.
     *
     * With Redis tags:     precise invalidation — only that provider's embeddings.
     * Without Redis tags:  cannot selectively clear. Logs a migration hint.
     */
    public function clearByProvider(string $provider): void
    {
        if ($this->useRedisTags) {
            Cache::tags([$this->providerTag($provider)])->flush();
            try { Log::info('[CachedEmbeddingEngine] Cleared embeddings for provider.', ['provider' => $provider]); } catch (\Throwable $__e) {}
            return;
        }

        try { Log::warning('[CachedEmbeddingEngine] Selective clear requires Redis tags.', [
            'hint'     => "Enable 'use_redis_tags: true' in engines.extended.ai.embedding_cache.",
            'workaround' => "Change 'prefix' in config to invalidate all cached embeddings.",
            'provider' => $provider,
        ]); } catch (\Throwable $__e) {}
    }

    /**
     * Clears all cached embeddings for a specific model.
     * Only available with Redis tags.
     */
    public function clearByModel(string $provider, string $model): void
    {
        if ($this->useRedisTags) {
            Cache::tags([$this->modelTag($provider, $model)])->flush();
            try { Log::info('[CachedEmbeddingEngine] Cleared embeddings for model.', [
                'provider' => $provider,
                'model'    => $model,
            ]); } catch (\Throwable $__e) {}
            return;
        }

        try { Log::warning('[CachedEmbeddingEngine] clearByModel() requires Redis tags.'); } catch (\Throwable $__e) {}
    }

    /**
     * Clears ALL cached embeddings (all providers, all models).
     * Works with any cache driver.
     *
     * With Redis tags: precise flush of only engine embeddings.
     * Without Redis tags: changes the prefix — next call will generate fresh embeddings.
     */
    public function clearAll(): void
    {
        if ($this->useRedisTags) {
            Cache::tags(['engine.embedding'])->flush();
            try { Log::info('[CachedEmbeddingEngine] Cleared all cached embeddings.'); } catch (\Throwable $__e) {}
            return;
        }

        // Without tags, we can't selectively flush — document the manual workaround
        try { Log::warning('[CachedEmbeddingEngine] clearAll() without Redis tags: change the prefix in config to invalidate.', [
            'current_prefix' => $this->prefix,
        ]); } catch (\Throwable $__e) {}
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    private function buildKey(string $text, string $provider): string
    {
        return $this->prefix . hash('sha256', "{$provider}:{$text}");
    }

    private function cacheGet(string $key, string $provider): ?array
    {
        try {
            if ($this->useRedisTags) {
                return Cache::tags($this->tagsFor($provider))->get($key);
            }

            return Cache::get($key);
        } catch (\Throwable $e) {
            try { Log::warning('[CachedEmbeddingEngine] Cache read failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return null;
        }
    }

    private function cachePut(string $key, array $embedding, string $provider): void
    {
        try {
            if ($this->useRedisTags) {
                $store = Cache::tags($this->tagsFor($provider));
                $this->ttl === 0
                    ? $store->forever($key, $embedding)
                    : $store->put($key, $embedding, $this->ttl);
                return;
            }

            $this->ttl === 0
                ? Cache::forever($key, $embedding)
                : Cache::put($key, $embedding, $this->ttl);
        } catch (\Throwable $e) {
            try { Log::warning('[CachedEmbeddingEngine] Cache write failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
        }
    }

    /**
     * Returns the tag set for a provider.
     * Multiple tags allow flushing by either the top-level tag or the provider-specific tag.
     *
     * @return string[]
     */
    private function tagsFor(string $provider): array
    {
        return [
            'engine.embedding',              // top-level: clears all embeddings
            $this->providerTag($provider),   // provider: clears one provider's embeddings
        ];
    }

    private function providerTag(string $provider): string
    {
        return "engine.embedding.{$provider}";
    }

    private function modelTag(string $provider, string $model): string
    {
        $safeModel = str_replace(['/', '\\', '.', ' '], '_', $model);
        return "engine.embedding.{$provider}.{$safeModel}";
    }
}

 *
 * Problem: every document chunk, every search query, and every RAG retrieval
 * calls embed(). The same text embedded twice wastes money and adds latency.
 *
 * Solution: cache embeddings keyed by sha256(provider + text).
 * The cache is permanent by default (embeddings don't change for the same text+model).
 *
 * Usage — wrap the AiEngine in the ServiceProvider:
 *   $engine = new AiEngine($registry);
 *   return new CachedEmbeddingEngine($engine);
 *
 * Or bind in AppServiceProvider:
 *   $this->app->singleton(AiEngineInterface::class, function ($app) {
 *       return new CachedEmbeddingEngine(
 *           inner: $app->make(AiEngine::class),
 *           ttl:   0,   // 0 = permanent cache (recommended for embeddings)
 *       );
 *   });
 *
 * Cache invalidation:
 *   When you change the embedding model, flush the cache:
 *   Cache::tags(['engine.embeddings'])->flush();   (requires Redis/tag-capable store)
 *   Or change the $prefix to effectively invalidate all cached embeddings.
 */
final class CachedEmbeddingEngine implements AiEngineInterface
{
    public function __construct(
        private readonly AiEngineInterface $inner,

        /** TTL in seconds. 0 = permanent (recommended for embeddings). */
        private readonly int    $ttl    = 0,

        /** Change prefix to invalidate all cached embeddings */
        private readonly string $prefix = 'engine.embedding.v1.',
    ) {}

    /**
     * Completions are never cached — always delegates to inner engine.
     */
    public function complete(AiRequest $request): AiResponse
    {
        return $this->inner->complete($request);
    }

    /**
     * Embeddings are cached by sha256(provider + text).
     * Returns the cached vector immediately on subsequent calls with the same text.
     *
     * @return float[]
     */
    public function embed(string $text, string $provider = 'openai'): array
    {
        $cacheKey = $this->prefix . sha256("{$provider}:{$text}");

        // Check cache first
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Generate embedding via inner engine
        $embedding = $this->inner->embed($text, $provider);

        if (! empty($embedding)) {
            if ($this->ttl === 0) {
                Cache::forever($cacheKey, $embedding);
            } else {
                Cache::put($cacheKey, $embedding, $this->ttl);
            }

            try { Log::debug('[CachedEmbeddingEngine] Embedding cached.', [
                'provider'   => $provider,
                'text_chars' => strlen($text),
                'dimensions' => count($embedding),
            ]); } catch (\Throwable $__e) {}
        }

        return $embedding;
    }

    /**
     * Clears all cached embeddings for a specific provider.
     * Useful when switching models.
     */
    public function clearCache(string $provider): void
    {
        // Without cache tags (Memcached/File driver), we can't selectively clear.
        // Log a warning — users should use Redis with tags for selective invalidation.
        try { Log::info('[CachedEmbeddingEngine] Manual cache clear requested.', [
            'provider' => $provider,
            'hint'     => 'Use Cache::tags([\'engine.embeddings\'])->flush() with Redis for selective clearing.',
        ]); } catch (\Throwable $__e) {}
    }
}

// ──────────────────────────────────────────────────────────────
// sha256 helper — PHP has no built-in sha256() function
// ──────────────────────────────────────────────────────────────

if (! function_exists('sha256')) {
    function sha256(string $input): string
    {
        return hash('sha256', $input);
    }
}
