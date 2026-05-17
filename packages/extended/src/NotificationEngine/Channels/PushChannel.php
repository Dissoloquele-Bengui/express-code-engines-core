<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\NotificationEngine\Channels;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\NotificationChannelInterface;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

/**
 * Push notification channel via Firebase Cloud Messaging (FCM) v1 API.
 *
 * Recipient must expose a push token:
 *   - string: the FCM registration token directly
 *   - object with getFcmToken(): string method
 *   - object with ->fcm_token property
 *
 * Configuration:
 *   FIREBASE_PROJECT_ID=your-project-id
 *   FIREBASE_SERVICE_ACCOUNT_JSON=/path/to/service-account.json
 *
 * Template data keys used by this channel:
 *   title  → notification title
 *   body   → notification body (from template body field)
 *   image  → optional image URL
 *   data   → optional custom key-value pairs for the app
 */
final class PushChannel implements NotificationChannelInterface
{
    private const FCM_URL = 'https://fcm.googleapis.com/v1/projects/{projectId}/messages:send';

    public function __construct(
        private readonly string $projectId,
        private readonly string $serviceAccountPath,
    ) {}

    public function channel(): string { return 'push'; }

    public function deliver(string|object $recipient, array $rendered): NotificationResult
    {
        $token = $this->resolveToken($recipient);

        if ($token === null) {
            return NotificationResult::failed('push', 'NO_TOKEN', 'Could not resolve FCM token from recipient.');
        }

        try {
            $accessToken = $this->getAccessToken();
            $url         = str_replace('{projectId}', $this->projectId, self::FCM_URL);

            $message = [
                'message' => [
                    'token'        => $token,
                    'notification' => [
                        'title' => $rendered['subject'] ?? ($rendered['data']['title'] ?? ''),
                        'body'  => $rendered['body']    ?? '',
                    ],
                ],
            ];

            // Optional image
            if (! empty($rendered['data']['image'])) {
                $message['message']['notification']['image'] = $rendered['data']['image'];
            }

            // Custom data payload for the app
            if (! empty($rendered['data']['push_data'])) {
                $message['message']['data'] = array_map(
                    'strval',
                    $rendered['data']['push_data'],
                );
            }

            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->post($url, $message);

            if (! $response->successful()) {
                $error = $response->json('error.message', $response->body());
                try { Log::error('[PushChannel] FCM delivery failed.', [
                    'status' => $response->status(),
                    'error'  => $error,
                ]); } catch (\Throwable $__e) {}
                return NotificationResult::failed('push', 'FCM_ERROR', $error);
            }

            $messageId = $response->json('name', '');
            return NotificationResult::sent('push', $messageId);

        } catch (\Throwable $e) {
            try { Log::error('[PushChannel] Unexpected error.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return NotificationResult::failed('push', 'EXCEPTION', $e->getMessage());
        }
    }

    private function resolveToken(string|object $recipient): ?string
    {
        if (is_string($recipient)) {
            return $recipient;
        }

        if (method_exists($recipient, 'getFcmToken')) {
            return $recipient->getFcmToken();
        }

        return $recipient->fcm_token ?? null;
    }

    /**
     * Obtains a Google OAuth2 access token from the service account JSON file.
     * In production, cache this token for its lifetime (~1 hour).
     */
    private function getAccessToken(): string
    {
        if (! file_exists($this->serviceAccountPath)) {
            throw new \RuntimeException("Firebase service account not found: {$this->serviceAccountPath}");
        }

        $serviceAccount = json_decode(file_get_contents($this->serviceAccountPath), true);

        $now    = time();
        $expiry = $now + 3600;

        $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'iss'   => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $expiry,
            'iat'   => $now,
        ]));

        $signature = '';
        openssl_sign(
            "{$header}.{$payload}",
            $signature,
            openssl_pkey_get_private($serviceAccount['private_key']),
            OPENSSL_ALGO_SHA256,
        );

        $jwt = "{$header}.{$payload}." . base64_encode($signature);

        $tokenResponse = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        return $tokenResponse->json('access_token', '');
    }
}
