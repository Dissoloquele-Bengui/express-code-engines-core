<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * A single chat message request.
 */
final class ChatMessage
{
    public function __construct(
        public readonly string  $content,

        /**
         * Session ID — used to retrieve and persist conversation history.
         * The engine itself does not store sessions; it delegates to the
         * SessionStoreInterface provided by the application.
         */
        public readonly string  $sessionId,

        /** The user sending the message */
        public readonly object  $user,

        /**
         * Persona key registered in the ChatEngine config.
         * Controls the system prompt, tone, and knowledge scope.
         * e.g. 'support', 'sales', 'internal_ops'
         */
        public readonly string  $persona = 'default',

        /**
         * Entity classes to restrict RAG retrieval to.
         * Empty = search all indexed entities the user can access.
         * @var string[]
         */
        public readonly array   $scopeEntities = [],

        /** Extra context injected directly into the prompt (structured data) */
        public readonly array   $context = [],
    ) {}
}
