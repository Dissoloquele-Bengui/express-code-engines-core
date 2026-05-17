<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\NotificationEngine\Channels;

use Illuminate\Support\Facades\Mail;
use ExpressCodeEngines\Shared\Contracts\NotificationChannelInterface;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

/**
 * Persists notifications to the 'notifications' table via Laravel's
 * DatabaseNotification system (Notifiable trait required on recipient).
 */
final class DatabaseChannel implements NotificationChannelInterface
{
    public function channel(): string
    {
        return 'database';
    }

    public function deliver(string|object $recipient, array $rendered): NotificationResult
    {
        if (is_string($recipient) || ! method_exists($recipient, 'notify')) {
            return NotificationResult::failed(
                'database',
                'NOT_NOTIFIABLE',
                'Recipient must use the Laravel Notifiable trait for database channel.',
            );
        }

        try {
            $recipient->notify(
                new \ExpressCodeEngines\Extended\Notifications\GenericDatabaseNotification($rendered),
            );

            return NotificationResult::sent('database');

        } catch (\Throwable $e) {
            try { Log::error('[DatabaseChannel] Delivery failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return NotificationResult::failed('database', 'DB_ERROR', $e->getMessage());
        }
    }
}
