<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\NotificationEngine\Channels;

use Illuminate\Support\Facades\Mail;
use ExpressCodeEngines\Shared\Contracts\NotificationChannelInterface;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

/**
 * Delivers notifications via Laravel Mail.
 *
 * Recipient resolution order:
 *   1. Object with getEmailForNotification() — Laravel Notifiable trait
 *   2. Object with ->email property
 *   3. Plain email string
 */
final class MailChannel implements NotificationChannelInterface
{
    public function channel(): string
    {
        return 'mail';
    }

    public function deliver(string|object $recipient, array $rendered): NotificationResult
    {
        try {
            $email = $this->resolveEmail($recipient);

            if ($email === null) {
                return NotificationResult::failed('mail', 'NO_EMAIL', 'Could not resolve recipient email.');
            }

            Mail::raw($rendered['body'] ?? '', function ($message) use ($email, $rendered) {
                $message->to($email)->subject($rendered['subject'] ?? '(no subject)');
            });

            return NotificationResult::sent('mail');

        } catch (\Throwable $e) {
            try { Log::error('[MailChannel] Delivery failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return NotificationResult::failed('mail', 'MAIL_ERROR', $e->getMessage());
        }
    }

    private function resolveEmail(string|object $recipient): ?string
    {
        if (is_string($recipient)) {
            return $recipient;
        }

        if (method_exists($recipient, 'getEmailForNotification')) {
            return $recipient->getEmailForNotification();
        }

        return $recipient->email ?? null;
    }
}
