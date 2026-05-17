<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\AiEngine\Providers;

use Illuminate\Support\Facades\Http;
use ExpressCodeEngines\Shared\Contracts\AiProviderInterface;
use ExpressCodeEngines\Shared\DTOs\AiMessage;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;

/**
 * OpenAI provider — supports GPT-4o, GPT-4-turbo, GPT-3.5-turbo.
 * Also compatible with Azure OpenAI (set baseUrl) and any OpenAI-compatible API.
 *
 * JSON mode is enforced when $request->model->jsonMode is true.
 * Cost estimation uses public pricing — update as rates change.
 */
final class OpenAiProvider implements AiProviderInterface
{
    private const BASE_URL = 'https://api.openai.com/v1';

    /** @var array<string, array{input: float, output: float}> cost per 1M tokens in USD */
    private const PRICING = [
        'gpt-4o'        => ['input' => 2.50,  'output' => 10.00],
        'gpt-4o-mini'   => ['input' => 0.15,  'output' => 0.60],
        'gpt-4-turbo'   => ['input' => 10.00, 'output' => 30.00],
        'gpt-3.5-turbo' => ['input' => 0.50,  'output' => 1.50],
    ];

    public function __construct(
        private readonly string  $apiKey,
        private readonly string  $baseUrl     = self::BASE_URL,
        private readonly string  $embedModel  = 'text-embedding-3-small',
        private readonly array   $extraPricing = [],
    ) {}

    public function name(): string { return 'openai'; }

    public function isAvailable(): bool { return $this->apiKey !== ''; }

    public function complete(AiRequest $request): AiResponse
    {
        $model    = $request->model;
        $messages = $this->substituteVariables($request->messages, $request->variables);

        $body = [
            'model'       => $model->model,
            'messages'    => array_map(fn (AiMessage $m) => $m->toArray(), $messages),
            'max_tokens'  => $model->maxTokens,
            'temperature' => $model->temperature,
        ];

        if ($model->jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->baseUrl($this->baseUrl)
                ->timeout(60)
                ->post('/chat/completions', $body);

            if (! $response->successful()) {
                return AiResponse::failed(
                    'HTTP_ERROR',
                    "OpenAI returned HTTP {$response->status()}.",
                    'openai',
                    $model->model,
                );
            }

            $data    = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            $usage   = $data['usage'] ?? [];
            $prompt  = $usage['prompt_tokens']     ?? 0;
            $compl   = $usage['completion_tokens'] ?? 0;
            $cost    = $this->estimateCost($model->model, $prompt, $compl);

            $structured = null;
            if ($model->jsonMode) {
                $decoded    = json_decode($content, true);
                $structured = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }

            return AiResponse::ok(
                content:          $content,
                provider:         'openai',
                model:            $model->model,
                inputTokens:  $prompt,
                outputTokens: $compl,
                estimatedCost:    $cost,
                data:         $structured,
            );

        } catch (\Throwable $e) {
            try { Log::error('[OpenAiProvider] Request failed.', ['error' => $e->getMessage()]); } catch (\Throwable $__e) {}
            return AiResponse::failed('CONNECTION_ERROR', $e->getMessage(), 'openai', $model->model);
        }
    }

    /** @return float[] */
    public function embed(string $text): array
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->baseUrl($this->baseUrl)
                ->timeout(30)
                ->post('/embeddings', ['model' => $this->embedModel, 'input' => $text]);

            if (! $response->successful()) {
                return [];
            }

            return $response->json('data.0.embedding', []);

        } catch (\Throwable) {
            return [];
        }
    }

    private function estimateCost(string $model, int $input, int $output): float
    {
        $pricing = array_merge(self::PRICING, $this->extraPricing);

        foreach ($pricing as $key => $rates) {
            if (str_starts_with($model, $key)) {
                return (($input * $rates['input']) + ($output * $rates['output'])) / 1_000_000;
            }
        }

        return 0.0;
    }

    /**
     * @param  AiMessage[]  $messages
     * @return AiMessage[]
     */
    private function substituteVariables(array $messages, array $variables): array
    {
        if (empty($variables)) return $messages;

        return array_map(function (AiMessage $msg) use ($variables): AiMessage {
            $content = preg_replace_callback('/\{\{([\w.]+)\}\}/', function (array $m) use ($variables) {
                return $variables[$m[1]] ?? $m[0];
            }, $msg->content);

            return new AiMessage($msg->role, $content);
        }, $messages);
    }
}
