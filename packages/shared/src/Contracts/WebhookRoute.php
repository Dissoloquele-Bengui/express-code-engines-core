<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\IntegrationEngine\Webhooks;

use Illuminate\Http\Request;

/**
 * Convenience class for registering webhook routes in Laravel.
 *
 * Usage in routes/api.php:
 *   WebhookRoute::register('stripe', '/webhooks/stripe');
 *   WebhookRoute::register('github', '/webhooks/github');
 *
 * Or with middleware:
 *   WebhookRoute::register('stripe', '/webhooks/stripe', ['throttle:60,1']);
 */
final class WebhookRoute
{
    public static function register(
        string $integrationKey,
        string $path,
        array  $middleware = [],
    ): void {
        $route = app('router')->post($path, function (Request $request) use ($integrationKey) {
            /** @var WebhookProcessor $processor */
            $processor = app(WebhookProcessor::class);
            $result    = $processor->process($integrationKey, $request);

            return response()->json(
                ['status' => $result->status, 'event' => $result->eventType],
                $result->toHttpStatus(),
            );
        });

        if (! empty($middleware)) {
            $route->middleware($middleware);
        }
    }
}
