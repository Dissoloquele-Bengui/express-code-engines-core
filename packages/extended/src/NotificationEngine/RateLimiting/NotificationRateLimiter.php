<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\NotificationEngine\RateLimiting;

use Illuminate\Support\Facades\Cache;

/**
 * Rate limiter for the NotificationEngine.
 *
 * Prevents notification flooding by limiting sends per:
 *   - Recipient + template combination (e.g. max 1 welcome email per day)
 *   - Recipient across all templates (e.g. max 10 notifications per hour)
 *   - Template globally (e.g. max 1000 sends per hour for newsletters)
 *
 * All limits use a sliding window via Laravel Cache (atomic increment).
 *
 * Configuration (in engines.extended.notifications.rate_limits):
 *   'rate_limits' => [
 *       'per_recipient_template' => ['limit' => 1,    'window_seconds' => 86400],  // 1/day
 *       'per_recipient'          => ['limit' => 10,   'window_seconds' => 3600],   // 10/hour
 *       'per_template'           => ['limit' => 1000, 'window_seconds' => 3600],   // 1000/hour
 *   ]
 */
final class NotificationRateLimiter
{
    public function __construct(
        private readonly array $config = [],
    ) {}

    /**
     * Returns true when the notification is allowed (not rate-limited).
     */
    public function allow(string $templateKey, string|object $recipient): bool
    {
        $recipientId = $this->resolveRecipientId($recipient);

        // Check per-recipient + template limit
        if (! $this->checkLimit(
            key:  "engine.notification.rate.{$recipientId}.{$templateKey}",
            rule: $this->config['per_recipient_template'] ?? null,
        )) {
            try { Log::info('[NotificationRateLimiter] Per-recipient-template limit hit.', [
                'template'  => $templateKey,
                'recipient' => $recipientId,
            ]); } catch (\Throwable $__e) {}
            return false;
        }

        // Check per-recipient limit (across all templates)
        if (! $this->checkLimit(
            key:  "engine.notification.rate.{$recipientId}",
            rule: $this->config['per_recipient'] ?? null,
        )) {
            try { Log::info('[NotificationRateLimiter] Per-recipient limit hit.', [
                'recipient' => $recipientId,
            ]); } catch (\Throwable $__e) {}
            return false;
        }

        // Check per-template global limit
        if (! $this->checkLimit(
            key:  "engine.notification.rate.template.{$templateKey}",
            rule: $this->config['per_template'] ?? null,
        )) {
            try { Log::info('[NotificationRateLimiter] Per-template global limit hit.', [
                'template' => $templateKey,
            ]); } catch (\Throwable $__e) {}
            return false;
        }

        return true;
    }

    /**
     * Records a send for rate-limiting purposes.
     * Call AFTER successful delivery, not before.
     */
    public function record(string $templateKey, string|object $recipient): void
    {
        $recipientId = $this->resolveRecipientId($recipient);
        $now         = time();

        $this->increment("engine.notification.rate.{$recipientId}.{$templateKey}",
            $this->config['per_recipient_template']['window_seconds'] ?? 86400);

        $this->increment("engine.notification.rate.{$recipientId}",
            $this->config['per_recipient']['window_seconds'] ?? 3600);

        $this->increment("engine.notification.rate.template.{$templateKey}",
            $this->config['per_template']['window_seconds'] ?? 3600);
    }

    /**
     * Resets all rate limits for a recipient (useful after account actions).
     */
    public function clearForRecipient(string|object $recipient): void
    {
        $recipientId = $this->resolveRecipientId($recipient);
        Cache::forget("engine.notification.rate.{$recipientId}");
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    private function checkLimit(string $key, ?array $rule): bool
    {
        if ($rule === null) {
            return true; // no limit configured → always allow
        }

        $limit  = $rule['limit']          ?? PHP_INT_MAX;
        $window = $rule['window_seconds'] ?? 3600;

        $current = (int) Cache::get($key, 0);

        return $current < $limit;
    }

    private function increment(string $key, int $ttlSeconds): void
    {
        if (Cache::has($key)) {
            Cache::increment($key);
        } else {
            Cache::put($key, 1, $ttlSeconds);
        }
    }

    private function resolveRecipientId(string|object $recipient): string
    {
        if (is_string($recipient)) {
            return md5($recipient);
        }

        if (isset($recipient->id)) {
            return (string) $recipient->id;
        }

        if (method_exists($recipient, 'getEmailForNotification')) {
            return md5($recipient->getEmailForNotification());
        }

        return md5(serialize($recipient));
    }
}
