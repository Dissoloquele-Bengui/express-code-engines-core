<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;

/**
 * A single AI provider driver.
 * Each driver normalises its API to the same AiResponse format.
 */
interface AiProviderInterface
{
    public function name(): string;

    public function complete(AiRequest $request, \ExpressCodeEngines\Shared\DTOs\AiModelConfig $config): AiResponse;

    public function supportsJsonMode(): bool;
}
