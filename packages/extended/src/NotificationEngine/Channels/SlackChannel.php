<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\NotificationEngine\Channels;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\NotificationChannelInterface;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;

/**
 * Slack channel via Incoming Webhooks.
 *
 * Supports two payload formats:
 *   simple   — plain text message via 'text' field (always works)
 *   blocks   — Slack Block Kit (rich formatting, buttons, sections)
 *
 * Recipient resolution (in order):
 *   1. string starting with 'https://' → treated as a webhook URL directly
 *   2. object with getSlackWebhookUrl(): string
 *   3. object with ->slack_webhook_url property
 *   4. Falls back to the default webhook URL configured in the constructor
 *
 * Template data keys:
 *   subject  → used as the notification title / fallback text
 *   body     → used as the main message text
 *   blocks   → optional Slack Block Kit blocks array (overrides simple text)
 *   color    → optional sidebar colour for message attachments
 *               'good' (green), 'warning' (yellow), 'danger' (red), or hex '#3AA3E3'
 *   channel  → optional channel override (e.g. '#alerts') — only works with Bot tokens
 *
 * Configuration:
 *   SLACK_WEBHOOK_URL=https://hooks.slack.com/services/T.../B.../...
 *
 * For per-user webhooks: set recipient to the user's personal webhook URL.
 * For channel webhooks:  configure the default URL and leave recipient as null/email.
 */
final class SlackChannel implements NotificationChannelInterface
{
    public function __construct(
        /** Default webhook URL used when recipient doesn't expose its own */
        private readonly ?string $defaultWebhookUrl = null,

        /**
         * Username shown as the sender in Slack.
         * Null = use the webhook's configured name.
         */
        private readonly ?string $username = null,

        /**
         * Emoji or URL to use as the bot icon.
         * e.g. ':robot_face:' or 'https://example.com/icon.png'
         */
        private readonly ?string $icon = null,
    ) {}

    public function channel(): string { return 'slack'; }

    public function deliver(string|object $recipient, array $rendered): NotificationResult
    {
        $webhookUrl = $this->resolveWebhookUrl($recipient);

        if ($webhookUrl === null) {
            return NotificationResult::failed(
                'slack',
                'NO_WEBHOOK_URL',
                'No Slack webhook URL configured. Set SLACK_WEBHOOK_URL or provide a recipient with getSlackWebhookUrl().',
            );
        }

        $payload = $this->buildPayload($rendered);

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);

            if (! $response->successful()) {
                // Slack returns plain text errors like "invalid_payload" or "channel_not_found"
                $error = $response->body();
                try { Log::error('[SlackChannel] Delivery failed.', [
                    'status' => $response->status(),
                    'error'  => $error,
                ]); } catch (\Throwable $__e) {}
                return NotificationResult::failed('slack', 'SLACK_ERROR', $error);
            }

            return NotificationResult::sent('slack');

        } catch (\Throwable $e) {
            try { Log::error('[SlackChannel] Unexpected error.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return NotificationResult::failed('slack', 'EXCEPTION', $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────
    // Private
    // ──────────────────────────────────────────────────────────

    private function resolveWebhookUrl(string|object $recipient): ?string
    {
        // Direct webhook URL as string
        if (is_string($recipient) && str_starts_with($recipient, 'https://')) {
            return $recipient;
        }

        // Object with getSlackWebhookUrl()
        if (is_object($recipient) && method_exists($recipient, 'getSlackWebhookUrl')) {
            return $recipient->getSlackWebhookUrl();
        }

        // Object with property
        if (is_object($recipient) && isset($recipient->slack_webhook_url)) {
            return $recipient->slack_webhook_url;
        }

        // Fall back to configured default
        return $this->defaultWebhookUrl;
    }

    private function buildPayload(array $rendered): array
    {
        $payload = [];

        if ($this->username !== null) {
            $payload['username'] = $this->username;
        }

        if ($this->icon !== null) {
            // Emoji vs URL
            if (str_starts_with($this->icon, ':') && str_ends_with($this->icon, ':')) {
                $payload['icon_emoji'] = $this->icon;
            } else {
                $payload['icon_url'] = $this->icon;
            }
        }

        // Block Kit payload (rich formatting)
        if (! empty($rendered['data']['blocks'])) {
            $payload['blocks']   = $rendered['data']['blocks'];
            $payload['text']     = $rendered['subject'] ?? $rendered['body'] ?? ''; // fallback text
            return $payload;
        }

        // Attachment with optional colour sidebar (classic formatting)
        $color = $rendered['data']['color'] ?? null;

        if ($color !== null) {
            $payload['attachments'] = [[
                'fallback' => $rendered['subject'] ?? $rendered['body'] ?? '',
                'color'    => $color,
                'title'    => $rendered['subject'] ?? null,
                'text'     => $rendered['body']    ?? '',
                'mrkdwn_in' => ['text'],
            ]];
            return $payload;
        }

        // Simple text message
        $text = '';

        if (! empty($rendered['subject'])) {
            $text .= "*{$rendered['subject']}*\n";
        }

        if (! empty($rendered['body'])) {
            $text .= $rendered['body'];
        }

        $payload['text'] = $text;

        return $payload;
    }
}
