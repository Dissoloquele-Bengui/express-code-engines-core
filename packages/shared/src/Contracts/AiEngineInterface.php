<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\Contracts;

use ExpressCodeEngines\Shared\DTOs\AiRequest;
use ExpressCodeEngines\Shared\DTOs\ChatMessage;
use ExpressCodeEngines\Shared\DTOs\DocumentChunk;
use ExpressCodeEngines\Shared\DTOs\DocumentInput;

/**
 * Unified abstraction over LLMs and AI providers.
 *
 * Responsibilities:
 *   - Provider selection and normalisation
 *   - JSON mode enforcement and schema validation
 *   - Token and cost tracking
 *   - Retry and fallback between providers
 *   - Never exposes API keys — always reads from env
 *
 * This engine has NO business logic. It is pure infrastructure.
 * Business logic belongs in the Action or ChatEngine that calls it.
 */
interface AiEngineInterface
{
    /**
     * Execute a completion and return the result.
     * Retries on transient errors and validates JSON schema when set.
     */
    public function complete(AiRequest $request): AiResponse;

    /**
     * Convenience: complete and parse the response as structured data.
     * Equivalent to setting jsonSchema on the request and calling complete().
     */
    public function structured(AiRequest $request, array $schema): AiResponse;
}
