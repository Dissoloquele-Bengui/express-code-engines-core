<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ChatEngine\Memory;

use Illuminate\Support\Facades\Cache;
use ExpressCodeEngines\Shared\Contracts\SessionStoreInterface;

/**
 * Session store backed by Laravel Cache.
 *
 * Suitable for most applications. For long-lived sessions or
 * multi-tenant environments, replace with a database-backed store.
 *
 * Sessions expire after $ttl seconds of inactivity.
 * TTL is refreshed on every save() call.
 */
final class CacheSessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly int    $ttl    = 3600,  // 1 hour
        private readonly string $prefix = 'engine.chat.session.',
    ) {}

    public function load(string $sessionId): array
    {
        return Cache::get($this->key($sessionId), []);
    }

    public function save(string $sessionId, array $messages): void
    {
        Cache::put($this->key($sessionId), $messages, $this->ttl);
    }

    public function clear(string $sessionId): void
    {
        Cache::forget($this->key($sessionId));
    }

    private function key(string $sessionId): string
    {
        return $this->prefix . $sessionId;
    }
}
