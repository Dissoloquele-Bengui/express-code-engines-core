<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\AiEngine\Providers;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\AiProviderInterface;
use ExpressCodeEngines\Shared\DTOs\AiMessage;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;

/**
 * Anthropic provider — supports Claude 3.x and Claude 4.x families.
 *
 * Differences from OpenAI format:
 *   - System message is sent as a top-level field, not in the messages array
 *   - Response is in content[0].text, not choices[0].message.content
 *   - Token counts use input_tokens / output_tokens
 */
final class AnthropicProvider implements AiProviderInterface
{
    private const BASE_URL    = 'https://api.anthropic.com/v1';
    private const API_VERSION = '2023-06-01';

    /** @var array<string, array{input: float, output: float}> cost per 1M tokens in USD */
    private const PRICING = [
        'claude-opus-4'     => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4'   => ['input' => 3.00,  'output' => 15.00],
        'claude-haiku-4'    => ['input' => 0.80,  'output' => 4.00],
        'claude-3-5-sonnet' => ['input' => 3.00,  'output' => 15.00],
        'claude-3-opus'     => ['input' => 15.00, 'output' => 75.00],
        'claude-3-haiku'    => ['input' => 0.25,  'output' => 1.25],
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly array  $extraPricing = [],
    ) {}

    public function name(): string { return 'anthropic'; }

    public function isAvailable(): bool { return $this->apiKey !== ''; }

    public function complete(AiRequest $request): AiResponse
    {
        $model    = $request->model;
        $messages = $request->messages;

        // Separate system message from conversation messages
        $systemContent = '';
        $chatMessages  = [];

        foreach ($messages as $msg) {
            if ($msg->role === 'system') {
                $systemContent .= ($systemContent ? "\n\n" : '') . $msg->content;
            } else {
                $chatMessages[] = $msg->toArray();
            }
        }

        $body = [
            'model'      => $model->model,
            'max_tokens' => $model->maxTokens,
            'messages'   => $chatMessages,
        ];

        if ($systemContent !== '') {
            $body['system'] = $systemContent;
        }

        // Anthropic enforces JSON via the system prompt — we add the instruction when jsonMode is set
        if ($model->jsonMode && $systemContent === '') {
            $body['system'] = 'You MUST respond with valid JSON only. No prose, no markdown fences.';
        } elseif ($model->jsonMode) {
            $body['system'] .= "\n\nYou MUST respond with valid JSON only. No prose, no markdown fences.";
        }

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'Content-Type'      => 'application/json',
            ])->timeout(60)->post(self::BASE_URL . '/messages', $body);

            if (! $response->successful()) {
                return AiResponse::failed(
                    'HTTP_ERROR',
                    "Anthropic returned HTTP {$response->status()}.",
                    'anthropic',
                    $model->model,
                );
            }

            $data    = $response->json();
            $content = $data['content'][0]['text'] ?? '';
            $usage   = $data['usage'] ?? [];
            $input   = $usage['input_tokens']  ?? 0;
            $output  = $usage['output_tokens'] ?? 0;
            $cost    = $this->estimateCost($model->model, $input, $output);

            $structured = null;
            if ($model->jsonMode) {
                $clean      = trim(preg_replace('/^```json\s*/i', '', preg_replace('/\s*```$/', '', trim($content))));
                $decoded    = json_decode($clean, true);
                $structured = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }

            return AiResponse::ok(
                content:          $content,
                provider:         'anthropic',
                model:            $model->model,
                inputTokens:  $input,
                outputTokens: $output,
                estimatedCost:    $cost,
                data:         $structured,
            );

        } catch (\Throwable $e) {
            try { Log::error('[AnthropicProvider] Request failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return AiResponse::failed('CONNECTION_ERROR', $e->getMessage(), 'anthropic', $model->model);
        }
    }

    /** @return float[] */
    public function embed(string $text): array
    {
        // Anthropic does not currently offer a public embeddings API.
        // Return empty — the engine will fall back to the OpenAI provider for embeddings.
        return [];
    }

    private function estimateCost(string $model, int $input, int $output): float
    {
        $pricing = array_merge(self::PRICING, $this->extraPricing);

        foreach ($pricing as $key => $rates) {
            if (str_contains($model, $key)) {
                return (($input * $rates['input']) + ($output * $rates['output'])) / 1_000_000;
            }
        }

        return 0.0;
    }
}
