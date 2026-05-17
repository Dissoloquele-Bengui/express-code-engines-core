<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\IntegrationEngine\Webhooks;

use Illuminate\Http\Request;

/**
 * Handles inbound webhooks from external systems.
 *
 * Responsibilities:
 *   - Signature verification (HMAC-SHA256, plain token, or none)
 *   - Payload parsing and normalisation
 *   - Event type extraction from headers or payload
 *   - Dispatching to registered WebhookHandlerInterface implementations
 *
 * Integration with the IntegrationEngine:
 *   The WebhookProcessor is the inbound counterpart to the IntegrationEngine's
 *   outbound HTTP calls. Together they form a complete bidirectional integration layer.
 *
 * Usage in a controller:
 *   public function handle(Request $request, string $integration): Response
 *   {
 *       $result = $this->webhookProcessor->process($integration, $request);
 *       return response()->json(['status' => $result->status]);
 *   }
 */
final class WebhookProcessor
{
    /**
     * @param  array<string, array>              $config    Webhook definitions keyed by integration key
     * @param  WebhookHandlerInterface[]         $handlers  Registered handlers
     */
    public function __construct(
        private readonly array $config   = [],
        private readonly array $handlers = [],
    ) {}

    public function process(string $integrationKey, Request $request): WebhookResult
    {
        $config = $this->config[$integrationKey] ?? null;

        if ($config === null) {
            return WebhookResult::rejected("No webhook configuration for '{$integrationKey}'.");
        }

        // Step 1: Verify signature
        if (! $this->verifySignature($request, $config)) {
            try { Log::warning('[WebhookProcessor] Signature verification failed.', [
                'integration' => $integrationKey,
                'ip'          => $request->ip(),
            ]); } catch (\Throwable $__e) {}
            return WebhookResult::rejected('Signature verification failed.');
        }

        // Step 2: Parse payload
        $payload = $this->parsePayload($request, $config);

        if ($payload === null) {
            return WebhookResult::rejected('Could not parse webhook payload.');
        }

        // Step 3: Extract event type
        $eventType = $this->extractEventType($request, $payload, $config);

        try { Log::info('[WebhookProcessor] Webhook received.', [
            'integration' => $integrationKey,
            'event'       => $eventType,
        ]); } catch (\Throwable $__e) {}

        // Step 4: Dispatch to handlers
        $handler = $this->resolveHandler($integrationKey, $eventType);

        if ($handler === null) {
            // No handler — accepted but not processed (common for unrecognised events)
            return WebhookResult::accepted($eventType, handled: false);
        }

        try {
            $handler->handle($integrationKey, $eventType, $payload, $request);
            return WebhookResult::accepted($eventType, handled: true);
        } catch (\Throwable $e) {
            try { Log::error('[WebhookProcessor] Handler threw exception.', [
                'integration' => $integrationKey,
                'event'       => $eventType,
                'exception'   => $e->getMessage(),
            ]); } catch (\Throwable $__e) {}
            return WebhookResult::failed($eventType, $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────
    // Signature verification strategies
    // ──────────────────────────────────────────────────────────

    private function verifySignature(Request $request, array $config): bool
    {
        $strategy = $config['signature'] ?? ['type' => 'none'];

        return match ($strategy['type'] ?? 'none') {
            'hmac_sha256' => $this->verifyHmac($request, $strategy),
            'token'       => $this->verifyToken($request, $strategy),
            'none'        => true,
            default       => false,
        };
    }

    private function verifyHmac(Request $request, array $strategy): bool
    {
        $secret    = env($strategy['secret_env'] ?? '');
        $header    = $strategy['header']         ?? 'X-Hub-Signature-256';
        $prefix    = $strategy['prefix']         ?? 'sha256=';
        $body      = $request->getContent();
        $expected  = $prefix . hash_hmac('sha256', $body, $secret);
        $received  = $request->header($header, '');

        // Constant-time comparison prevents timing attacks
        return hash_equals($expected, $received);
    }

    private function verifyToken(Request $request, array $strategy): bool
    {
        $expected = env($strategy['token_env'] ?? '');
        $header   = $strategy['header'] ?? 'X-Webhook-Token';
        $received = $request->header($header, '');

        return hash_equals($expected, $received);
    }

    // ──────────────────────────────────────────────────────────
    // Payload parsing
    // ──────────────────────────────────────────────────────────

    private function parsePayload(Request $request, array $config): ?array
    {
        $format = $config['payload_format'] ?? 'json';

        return match ($format) {
            'json'      => $request->json()->all() ?: null,
            'form'      => $request->all() ?: null,
            'xml'       => $this->parseXml($request->getContent()),
            default     => $request->json()->all() ?: null,
        };
    }

    private function parseXml(string $content): ?array
    {
        try {
            $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA);
            return $xml !== false ? json_decode(json_encode($xml), true) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────
    // Event type extraction
    // ──────────────────────────────────────────────────────────

    private function extractEventType(Request $request, array $payload, array $config): string
    {
        $source = $config['event_source'] ?? 'header';
        $key    = $config['event_key']    ?? 'X-Event-Type';

        if ($source === 'header') {
            return $request->header($key, 'unknown');
        }

        if ($source === 'payload') {
            return $this->resolveDotPath($key, $payload) ?? 'unknown';
        }

        return 'unknown';
    }

    // ──────────────────────────────────────────────────────────
    // Handler resolution
    // ──────────────────────────────────────────────────────────

    private function resolveHandler(string $integrationKey, string $eventType): ?WebhookHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($integrationKey, $eventType)) {
                return $handler;
            }
        }

        return null;
    }

    private function resolveDotPath(string $path, array $data): mixed
    {
        $segments = explode('.', $path);
        $value    = $data;

        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }
}
