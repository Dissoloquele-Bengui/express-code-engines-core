<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\NotificationEngine;

use ExpressCodeEngines\Shared\Contracts\NotificationChannelInterface;
use ExpressCodeEngines\Shared\Contracts\NotificationEngineInterface;
use ExpressCodeEngines\Shared\DTOs\NotificationRequest;
use ExpressCodeEngines\Shared\ValueObjects\NotificationResult;
use ExpressCodeEngines\Extended\NotificationEngine\Renderers\TemplateRenderer;
use ExpressCodeEngines\Extended\NotificationEngine\RateLimiting\NotificationRateLimiter;

/**
 * Sends notifications across multiple channels with template rendering,
 * rate limiting and isolated per-channel failure handling.
 *
 * Processing pipeline for send():
 *   1. Check rate limits — return RATE_LIMITED if exceeded
 *   2. Resolve the template from the registry
 *   3. Determine the channels (request override → template default)
 *   4. Render the template (subject + body substitution)
 *   5. Deliver on each channel independently
 *   6. On success: record the send for rate-limiting
 *   7. Failures on one channel never block the others
 *   8. Return the first successful result (or last failure)
 *
 * The engine never throws — all failures are logged and returned
 * as NotificationResult::failed('unknown', 'CHANNEL_ERROR', 'Channel delivery failed').
 */
final class NotificationEngine implements NotificationEngineInterface
{
    /**
     * @param  array<string, NotificationChannelInterface>  $channels  keyed by channel name
     */
    public function __construct(
        private readonly TemplateRegistry          $templates,
        private readonly TemplateRenderer          $renderer,
        private readonly array                     $channels,
        private readonly ?NotificationRateLimiter  $rateLimiter = null,
    ) {}

    public function send(NotificationRequest $request): NotificationResult
    {
        // Step 1: Rate limiting check
        if ($this->rateLimiter !== null &&
            ! $this->rateLimiter->allow($request->templateKey, $request->recipient)) {
            try { Log::info("[NotificationEngine] Rate limited.", [
                'template' => $request->templateKey,
            ]); } catch (\Throwable $__e) {}
            return NotificationResult::failed('unknown', 'RATE_LIMITED', 'Notification rate limit exceeded.');
        }

        // Step 2: Resolve template
        $template = $this->templates->find($request->templateKey);

        if ($template === null) {
            $msg = "Notification template '{$request->templateKey}' not registered.";
            try { Log::warning("[NotificationEngine] {$msg}"); } catch (\Throwable $__e) {}
            return NotificationResult::failed('unknown', 'TEMPLATE_NOT_FOUND', $msg);
        }

        // Step 2: Determine channels
        $channelKeys = $request->channels ?? $template->channels;

        // Step 3: Render
        $rendered = $this->renderer->render($template, $request->data);

        // Step 4 & 5: Deliver on each channel — isolated
        $lastResult = NotificationResult::failed('unknown', 'NO_CHANNEL', 'No channels configured.');

        foreach ($channelKeys as $channelKey) {
            $channel = $this->channels[$channelKey] ?? null;

            if ($channel === null) {
                try { Log::warning("[NotificationEngine] Unknown channel '{$channelKey}'.", [
                    'template' => $request->templateKey,
                ]); } catch (\Throwable $__e) {}
                continue;
            }

            $result = $channel->deliver($request->recipient, $rendered);

            if ($result->isFailed()) {
                try { Log::error("[NotificationEngine] Channel '{$channelKey}' failed.", [
                    'template' => $request->templateKey,
                    'error'    => $result->errorMessage,
                ]); } catch (\Throwable $__e) {}
            }

            $lastResult = $result;
        }

        // Record successful send for rate limiting
        if (! $lastResult->isFailed() && $this->rateLimiter !== null) {
            $this->rateLimiter->record($request->templateKey, $request->recipient);
        }

        return $lastResult;
    }

    /**
     * @param  NotificationRequest[]  $requests
     * @return NotificationResult[]
     */
    public function sendMany(array $requests): array
    {
        $results = [];

        foreach ($requests as $key => $request) {
            $results[$key] = $this->send($request);
        }

        return $results;
    }
}
