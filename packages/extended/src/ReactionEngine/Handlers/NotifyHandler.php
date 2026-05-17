<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ReactionEngine\Handlers;

use ExpressCodeEngines\Shared\Contracts\HandlerResult;
use ExpressCodeEngines\Shared\Contracts\NotificationEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ReactionHandlerInterface;
use ExpressCodeEngines\Shared\DTOs\NotificationRequest;
use ExpressCodeEngines\Shared\DTOs\ReactionContext;

/**
 * Built-in reaction handler: 'notify'
 *
 * Bridges the ReactionEngine to the NotificationEngine.
 * Never instantiates notifications directly — delegates fully.
 *
 * Required params:
 *   template_key    string   Template key registered in the NotificationEngine
 *   recipient_path  string   Dot-path into the ReactionContext payload to resolve the recipient
 *                            e.g. 'customer.email', 'user.id'
 *
 * Optional params:
 *   channels        array    Override the template's default channels
 *   locale          string   Override the locale for this notification
 *   data            array    Extra data merged with the context payload
 *
 * Example reaction definition:
 *   [
 *       'event'   => 'order.submitted',
 *       'handler' => 'notify',
 *       'async'   => true,
 *       'params'  => [
 *           'template_key'   => 'order_submitted',
 *           'recipient_path' => 'customer.email',
 *           'channels'       => ['mail', 'database'],
 *       ],
 *   ]
 */
final class NotifyHandler implements ReactionHandlerInterface
{
    public function __construct(
        private readonly NotificationEngineInterface $notifications,
    ) {}

    public function key(): string
    {
        return 'notify';
    }

    public function handle(ReactionContext $context, array $params): HandlerResult
    {
        try {
            $request = $this->buildRequest($context, $params);

            if ($request === null) {
                return HandlerResult::failed('Missing required param: template_key or recipient_path.');
            }

            $result = $this->notifications->send($request);

            return $result->failed()
                ? HandlerResult::failed($result->errorMessage ?? 'Notification failed.')
                : HandlerResult::ok(['message_id' => $result->messageId]);

        } catch (\Throwable $e) {
            try { Log::error('[NotifyHandler] Unexpected error.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return HandlerResult::failed($e->getMessage());
        }
    }

    public function dispatchAsync(ReactionContext $context, array $params, ?string $queue, int $delaySeconds): void
    {
        $request = $this->buildRequest($context, $params);

        if ($request === null) {
            try { Log::warning('[NotifyHandler] Cannot queue: missing required params.', [
                'event'  => $context->event,
                'params' => $params,
            ]); } catch (\Throwable $__e) {}
            return;
        }

        $job = new \ExpressCodeEngines\Extended\Jobs\SendNotificationJob($request);

        $job = $queue ? $job->onQueue($queue) : $job;

        $delaySeconds > 0
            ? dispatch($job)->delay(now()->addSeconds($delaySeconds))
            : dispatch($job);
    }

    private function buildRequest(ReactionContext $context, array $params): ?NotificationRequest
    {
        $templateKey   = $params['template_key']   ?? null;
        $recipientPath = $params['recipient_path'] ?? null;

        if (! $templateKey || ! $recipientPath) {
            return null;
        }

        $recipient = $context->resolve($recipientPath);

        if ($recipient === null) {
            return null;
        }

        return new NotificationRequest(
            templateKey: $templateKey,
            recipient:   $recipient,
            data:        array_merge($context->payload, $params['data'] ?? []),
            channels:    $params['channels'] ?? null,
            locale:      $params['locale']   ?? null,
        );
    }
}
