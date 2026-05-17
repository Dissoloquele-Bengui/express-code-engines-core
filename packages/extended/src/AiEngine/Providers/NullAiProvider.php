<?php
declare(strict_types=1);
namespace ExpressCodeEngines\Extended\AiEngine\Providers;
use ExpressCodeEngines\Shared\Contracts\AiProviderInterface;
use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\ValueObjects\AiResponse;
/** Null provider — for testing and environments without API keys. */
final class NullAiProvider implements AiProviderInterface
{
    public function name(): string { return 'null'; }
    public function isAvailable(): bool { return true; }
    public function complete(AiRequest $request): AiResponse
    {
        return AiResponse::ok('{}', 'null', $request->model->model, 0, 0);
    }
    public function embed(string $text): array
    {
        return array_fill(0, 1536, 0.0); // fixed zero vector for testing
    }
}
