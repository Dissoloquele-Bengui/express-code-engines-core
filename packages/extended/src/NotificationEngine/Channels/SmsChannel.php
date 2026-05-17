<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\NotificationEngine\Channels;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\NotificationChannelInterface;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

/**
 * SMS channel via Twilio REST API.
 *
 * Recipient resolution:
 *   - string: phone number directly (E.164 format: +351912345678)
 *   - object with getPhoneForSms(): string method
 *   - object with ->phone or ->phone_number property
 *
 * Configuration:
 *   TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxx
 *   TWILIO_AUTH_TOKEN=xxxxxxxxxxxxxxx
 *   TWILIO_FROM_NUMBER=+1234567890    (or alphanumeric sender ID where supported)
 *
 * The SMS body is taken from the template's 'body' field.
 * Subject is ignored (SMS has no subject).
 * Keep bodies under 160 characters to avoid multi-part SMS costs.
 */
final class SmsChannel implements NotificationChannelInterface
{
    private const TWILIO_BASE = 'https://api.twilio.com/2010-04-01';

    public function __construct(
        private readonly string $accountSid,
        private readonly string $authToken,
        private readonly string $fromNumber,
    ) {}

    public function channel(): string { return 'sms'; }

    public function deliver(string|object $recipient, array $rendered): NotificationResult
    {
        $phone = $this->resolvePhone($recipient);

        if ($phone === null) {
            return NotificationResult::failed('sms', 'NO_PHONE', 'Could not resolve phone number from recipient.');
        }

        $body = $rendered['body'] ?? '';

        if (trim($body) === '') {
            return NotificationResult::failed('sms', 'EMPTY_BODY', 'SMS body is empty.');
        }

        try {
            $url = self::TWILIO_BASE . "/Accounts/{$this->accountSid}/Messages.json";

            $response = Http::withBasicAuth($this->accountSid, $this->authToken)
                ->asForm()
                ->timeout(15)
                ->post($url, [
                    'From' => $this->fromNumber,
                    'To'   => $phone,
                    'Body' => $body,
                ]);

            if (! $response->successful()) {
                $error = $response->json('message', $response->body());
                try { Log::error('[SmsChannel] Twilio delivery failed.', [
                    'status' => $response->status(),
                    'error'  => $error,
                    'to'     => $this->maskPhone($phone),
                ]); } catch (\Throwable $__e) {}
                return NotificationResult::failed('sms', 'TWILIO_ERROR', $error);
            }

            $sid = $response->json('sid', '');
            return NotificationResult::sent('sms', $sid);

        } catch (\Throwable $e) {
            try { Log::error('[SmsChannel] Unexpected error.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return NotificationResult::failed('sms', 'EXCEPTION', $e->getMessage());
        }
    }

    private function resolvePhone(string|object $recipient): ?string
    {
        if (is_string($recipient)) {
            return $recipient;
        }

        if (method_exists($recipient, 'getPhoneForSms')) {
            return $recipient->getPhoneForSms();
        }

        return $recipient->phone ?? $recipient->phone_number ?? null;
    }

    /** Masks phone for safe logging: +351912***678 */
    private function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 6) return str_repeat('*', $len);
        return substr($phone, 0, 4) . str_repeat('*', $len - 7) . substr($phone, -3);
    }
}
