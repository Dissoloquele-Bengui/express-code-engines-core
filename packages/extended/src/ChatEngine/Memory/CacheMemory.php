<?php
declare(strict_types=1);
namespace ExpressCodeEngines\Extended\ChatEngine\Memory;
use Illuminate\Support\Facades\Cache;
use ExpressCodeEngines\Shared\Contracts\ChatMemoryInterface;
use ExpressCodeEngines\Shared\DTOs\AiMessage;
/**
 * Chat session memory backed by Laravel Cache.
 * TTL resets on every write. Sessions expire after inactivity.
 * maxMessages trims old messages FIFO, preserving the system message.
 */
final class CacheMemory implements ChatMemoryInterface
{
    public function __construct(
        private readonly int    $ttlSeconds  = 3600,
        private readonly int    $maxMessages = 20,
        private readonly string $prefix      = 'engine.chat.session.',
    ) {}
    public function load(string $sessionId): array
    {
        $raw = Cache::get($this->key($sessionId), []);
        return array_map(fn (array $m) => new AiMessage($m['role'], $m['content']), $raw);
    }
    public function save(string $sessionId, array $messages): void
    {
        if (count($messages) > $this->maxMessages) {
            $system  = isset($messages[0]) && $messages[0]->role === 'system' ? [$messages[0]] : [];
            $rest    = array_slice($messages, count($system));
            $trimmed = array_slice($rest, -($this->maxMessages - count($system)));
            $messages = array_merge($system, $trimmed);
        }
        Cache::put($this->key($sessionId), array_map(fn (AiMessage $m) => $m->toArray(), $messages), $this->ttlSeconds);
    }
    public function append(string $sessionId, AiMessage $message): void
    {
        $messages   = $this->load($sessionId);
        $messages[] = $message;
        $this->save($sessionId, $messages);
    }
    public function clear(string $sessionId): void { Cache::forget($this->key($sessionId)); }
    private function key(string $sessionId): string { return $this->prefix . $sessionId; }
}
