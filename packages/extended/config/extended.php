<?php

declare(strict_types=1);

return [

    // ──────────────────────────────────────────────────────────────
    // NotificationEngine
    // ──────────────────────────────────────────────────────────────

    'notifications' => [

        /*
         | Template definitions.
         | Each key is the template_key used in NotificationRequest and ReactionDefinition params.
         |
         | channels: the default delivery channels for this template.
         |   Built-in: 'mail', 'database'
         |   Custom:   register your own via NotificationChannelInterface and tag 'engine.notification.channels'
         |
         | subject / body: inline strings with {{dot.path}} placeholders for variable substitution.
         |   The body can also be a Laravel view: 'view:emails.order_submitted'
         |
         | defaults: data merged into every request using this template.
         */
        'templates' => [

            'order_submitted' => [
                'channels' => ['mail', 'database'],
                'subject'  => 'Order #{{order.reference}} received',
                'body'     => "Hello {{customer.name}},\n\nYour order #{{order.reference}} has been received and is being processed.\n\nTotal: {{order.total}}\n\nThank you.",
                'defaults' => [],
            ],

            'order_approved' => [
                'channels' => ['mail', 'database'],
                'subject'  => 'Your order #{{order.reference}} has been approved',
                'body'     => "Hello {{customer.name}},\n\nGreat news — your order #{{order.reference}} has been approved.\n\nExpected delivery: {{order.delivery_date}}.",
                'defaults' => [],
            ],

            'order_rejected' => [
                'channels' => ['mail'],
                'subject'  => 'Update on your order #{{order.reference}}',
                'body'     => "Hello {{customer.name}},\n\nUnfortunately, your order #{{order.reference}} could not be processed at this time.\n\nReason: {{reason}}\n\nPlease contact support if you need assistance.",
                'defaults' => ['reason' => 'No reason provided.'],
            ],

            'welcome' => [
                'channels' => ['mail'],
                'subject'  => 'Welcome to {{app.name}}, {{user.name}}!',
                'body'     => "Hi {{user.name}},\n\nWelcome aboard! Your account has been created successfully.\n\nEmail: {{user.email}}",
                'defaults' => ['app' => ['name' => config('app.name', 'Our Platform')]],
            ],

        ],

        /*
         | Rate limiting — prevents notification flooding.
         | Set limit to null to disable a specific rule.
         |
         | per_recipient_template: max sends per recipient+template combination per window
         | per_recipient:          max total sends per recipient across all templates
         | per_template:           global max sends per template per window
         */
        'rate_limits' => [
            'per_recipient_template' => ['limit' => 1,    'window_seconds' => 86400],
            'per_recipient'          => ['limit' => 10,   'window_seconds' => 3600],
            'per_template'           => ['limit' => 5000, 'window_seconds' => 3600],
        ],

    ],


    // ──────────────────────────────────────────────────────────────
    // DynamicPolicyEngine
    // ──────────────────────────────────────────────────────────────

    'policy' => [
        'cache_enabled' => true,
        'cache_ttl'     => 300,
    ],

    // ──────────────────────────────────────────────────────────────
    // SearchEngine
    // ──────────────────────────────────────────────────────────────

    'search' => [
        'driver'   => env('SEARCH_DRIVER', 'null'),
        'entities' => [
            // 'App\Models\Order' => [
            //     'table'             => 'orders',
            //     'searchable_fields' => ['reference', 'notes'],
            //     'filterable_fields' => ['status', 'tenant_id'],
            //     'sortable_fields'   => ['created_at', 'total'],
            // ],
        ],
    ],

    // ──────────────────────────────────────────────────────────────
    // IntegrationEngine
    // ──────────────────────────────────────────────────────────────

    'integrations' => [
        // 'stripe_charge' => [
        //     'url'     => 'https://api.stripe.com/v1/charges',
        //     'method'  => 'POST',
        //     'auth'    => ['type' => 'bearer', 'token_env' => 'STRIPE_SECRET_KEY'],
        //     'retry'   => ['attempts' => 3, 'backoff' => [1, 5, 15]],
        //     'mapping' => ['order.total' => 'amount', 'customer.email' => 'receipt_email'],
        // ],
    ],

    // ──────────────────────────────────────────────────────────────
    // ReactionEngine
    // ──────────────────────────────────────────────────────────────

    'reactions' => [

        /*
         | Global reaction definitions loaded automatically by the ReactionEngine.
         |
         | Applications typically pass definitions per-call rather than globally,
         | but this section is useful for cross-cutting reactions that always apply
         | (e.g. audit logging on every entity event).
         |
         | Each entry maps to a ReactionDefinition::fromArray() call.
         |
         | handler:      Key of a registered ReactionHandlerInterface
         | params:       Handler-specific parameters
         | only_if:      Optional condition — "field operator value"
         | async:        true (default) = queued; false = sync
         | queue:        Queue name for async dispatch (null = default)
         | delay_seconds: Delay before async dispatch
         */
        'definitions' => [

            // Example: notify the reviewer when any order is submitted
            // [
            //     'event'   => 'order.submitted',
            //     'handler' => 'notify',
            //     'async'   => true,
            //     'params'  => [
            //         'template_key'   => 'order_submitted',
            //         'recipient_path' => 'customer.email',
            //     ],
            // ],

            // Example: dispatch a job when an invoice is paid
            // [
            //     'event'   => 'invoice.paid',
            //     'handler' => 'dispatch_job',
            //     'async'   => true,
            //     'params'  => ['job' => 'App\Jobs\ProcessInvoicePaymentJob'],
            // ],

            // Example: update a denormalised field synchronously
            // [
            //     'event'   => 'order.created',
            //     'handler' => 'update_field',
            //     'async'   => false,
            //     'params'  => [
            //         'model'      => 'App\Models\Customer',
            //         'id_path'    => 'customer.id',
            //         'field'      => 'orders_count',
            //         'value_path' => 'customer.new_orders_count',
            //     ],
            // ],

        ],

    ],

    // ──────────────────────────────────────────────────────────────
    // DynamicPolicyEngine
    // ──────────────────────────────────────────────────────────────

    'policy' => [
        /*
         | Results of can() are cached per entity+ability+user+record.
         | Set cache_enabled: false in testing environments.
         */
        'cache_enabled' => true,
        'cache_ttl'     => 300, // seconds
    ],

    // ──────────────────────────────────────────────────────────────
    // SearchEngine
    // ──────────────────────────────────────────────────────────────

    'search' => [
        /*
         | Default search driver.
         |
         | 'database'    — SQL LIKE queries (zero config, small datasets)
         | 'meilisearch' — Meilisearch (self-hosted or Cloud, typo-tolerant)
         | 'algolia'     — Algolia (cloud, globally distributed, free tier available)
         | 'null'        — always returns empty (testing)
         |
         | Environment variables:
         |   database:    no env vars needed
         |   meilisearch: MEILISEARCH_HOST, MEILISEARCH_KEY
         |   algolia:     ALGOLIA_APP_ID, ALGOLIA_API_KEY
         */
        'driver' => env('SEARCH_DRIVER', 'database'),

        /*
         | Per-entity search configuration.
         | Each key is a fully-qualified entity class.
         |
         | index_name:        Override the auto-generated index name (class_basename + 's')
         | searchable_fields: Fields included in full-text search
         | filterable_fields: Fields usable as exact-match filters (must be indexed in Meili/Algolia)
         | sortable_fields:   Fields usable for ordering results
         | ranking_rules:     Meilisearch ranking rules override (optional)
         */
        'entities' => [
            // 'App\Models\Order' => [
            //     'index_name'        => 'orders',
            //     'searchable_fields' => ['reference', 'customer_name', 'notes'],
            //     'filterable_fields' => ['status', 'tenant_id', 'created_at'],
            //     'sortable_fields'   => ['created_at', 'total'],
            // ],
        ],
    ],

    // ──────────────────────────────────────────────────────────────
    // IntegrationEngine
    // ──────────────────────────────────────────────────────────────

    'integrations' => [
        /*
         | Named integration definitions.
         | Each key is the integration_key used in IntegrationRequest.
         |
         | url:      Full endpoint URL
         | method:   HTTP method (GET, POST, PUT, PATCH, DELETE)
         | headers:  Static headers (dynamic headers via auth config)
         | auth:     Authentication: bearer, basic, api_key, none
         | retry:    Retry policy: attempts + backoff (seconds between retries)
         | timeout:  Request timeout in seconds
         | mapping:  Payload field mapping: 'local.path' => 'remote.path'
         | async:    true = dispatch to queue (preferred); false = call() synchronously
         */

        // 'stripe_payment' => [
        //     'url'     => 'https://api.stripe.com/v1/payment_intents',
        //     'method'  => 'POST',
        //     'auth'    => ['type' => 'bearer', 'token_env' => 'STRIPE_SECRET_KEY'],
        //     'retry'   => ['attempts' => 3, 'backoff' => [1, 5, 15], 'on_status' => [429, 500, 503]],
        //     'timeout' => 30,
        //     'mapping' => [
        //         'order.total'    => 'amount',
        //         'order.currency' => 'currency',
        //         'customer.email' => 'receipt_email',
        //     ],
        //     'async' => false, // synchronous — response needed immediately
        // ],

        // 'slack_webhook' => [
        //     'url'    => env('SLACK_WEBHOOK_URL'),
        //     'method' => 'POST',
        //     'async'  => true,
        //     'mapping'=> ['message' => 'text'],
        // ],
    ],

    // ──────────────────────────────────────────────────────────────
    // AiEngine
    // ──────────────────────────────────────────────────────────────

    'ai' => [
        /*
         | Fallback chain — provider names tried in order when the primary fails.
         | 'null' is always the final fallback (returns empty responses without error).
         | Providers are auto-registered when their env vars are present.
         |
         | Tip: put fastest/cheapest providers first, most capable last.
         | e.g. ['groq', 'openrouter', 'anthropic', 'openai', 'ollama', 'null']
         */
        'fallback_chain' => ['anthropic', 'openai', 'groq', 'openrouter', 'ollama', 'huggingface', 'null'],

        /*
         | OpenAI configuration.
         | API key: OPENAI_API_KEY env var.
         */
        'openai' => [
            'embed_model' => 'text-embedding-3-small', // 1536 dims — change pgvector/mysql dimensions accordingly
        ],

        /*
         | OpenRouter configuration — routes to 200+ models via single API key.
         | API key: OPENROUTER_API_KEY env var.
         |
         | Free models (append ':free' to model ID):
         |   'meta-llama/llama-3.1-8b-instruct:free'
         |   'google/gemma-2-9b-it:free'
         |   'mistralai/mistral-7b-instruct:free'
         |
         | fallback_models: sent as X-OR-Fallback-Models header for auto-routing
         */
        'openrouter' => [
            'fallback_models' => [
                'meta-llama/llama-3.1-8b-instruct:free',
                'mistralai/mistral-7b-instruct:free',
            ],
        ],

        /*
         | Ollama configuration — local inference, no API costs.
         | Requires Ollama running locally or via Docker.
         | Base URL: OLLAMA_BASE_URL env var (default: http://localhost:11434).
         */
        'ollama' => [
            'enabled' => false, // set true or set OLLAMA_BASE_URL to enable
        ],

        /*
         | Hugging Face configuration — free embeddings via Inference API.
         | API key: HF_API_KEY env var (free at huggingface.co/settings/tokens).
         |
         | Best free embedding models:
         |   'BAAI/bge-small-en-v1.5'    — 384 dims, fast, excellent retrieval
         |   'BAAI/bge-base-en-v1.5'     — 768 dims, higher quality
         |   'BAAI/bge-large-en-v1.5'    — 1024 dims, best quality free
         |   'sentence-transformers/all-MiniLM-L6-v2' — 384 dims, multilingual ok
         |   'intfloat/multilingual-e5-large' — 1024 dims, best multilingual
         |
         | Note: cold start on first request (~20s). Subsequent calls are fast.
         | Note: dimensions must match your vector_store column size.
         */
        'huggingface' => [
            'embed_model' => env('HF_EMBED_MODEL', 'BAAI/bge-small-en-v1.5'),
        ],

        /*
         | Embedding cache — caches vector embeddings by sha256(provider+text).
         | Strongly recommended in production to reduce API costs and latency.
         |
         | ttl: seconds to cache. 0 = permanent (recommended — embeddings don't change).
         | prefix: change to invalidate all cached embeddings when switching models.
         */
        'embedding_cache' => [
            'enabled'        => true,
            'ttl'            => 0,       // 0 = permanent (Cache::forever) — recommended
            'prefix'         => 'engine.embedding.v1.',  // change to invalidate all embeddings
            /*
             | use_redis_tags: enables selective invalidation by provider or model.
             | Requires Redis or Memcached as the cache driver.
             |
             | With Redis tags:
             |   Cache::tags(['engine.embedding'])->flush()         → clear ALL embeddings
             |   Cache::tags(['engine.embedding.openai'])->flush()  → clear OpenAI only
             |   Cache::tags(['engine.embedding.huggingface'])->flush() → clear HF only
             |
             | Without Redis tags (default):
             |   Change 'prefix' in config to invalidate all embeddings at once.
             */
            'use_redis_tags' => env('EMBEDDING_CACHE_REDIS_TAGS', false),
        ],

        'pricing' => [], // override built-in provider pricing tables here
    ],

    // ──────────────────────────────────────────────────────────────
    // DocumentEngine
    // ──────────────────────────────────────────────────────────────

    'documents' => [
        /*
         | Vector store backend for document chunk storage and similarity search.
         |
         | 'default'  — in-memory/PHP implementation (dev/testing only, no persistence)
         | 'pgvector' — PostgreSQL + pgvector extension (recommended for production)
         | 'mysql'    — MySQL 9.0+ native VECTOR type (good for MySQL-first teams)
         |
         | After changing vector_store, re-process all documents to re-generate embeddings.
         */
        'vector_store' => env('VECTOR_STORE', 'default'),

        /*
         | pgvector configuration.
         | Prerequisites: PostgreSQL >= 14 + pgvector extension + HNSW index.
         | CREATE EXTENSION IF NOT EXISTS vector;
         | dimensions must match your embedding model output size.
         */
        'pgvector' => [
            'table'      => 'document_chunks',
            'dimensions' => 1536, // OpenAI text-embedding-3-small → 1536
                                  // BAAI/bge-small-en-v1.5 → 384
                                  // BAAI/bge-base-en-v1.5 → 768
        ],

        /*
         | MySQL 9.0+ VECTOR configuration.
         | Prerequisites: MySQL >= 9.0.0
         | DB::statement('ALTER TABLE document_chunks ADD VECTOR INDEX idx_embedding (embedding)');
         */
        'mysql' => [
            'table'      => 'document_chunks',
            'dimensions' => 384, // HuggingFace bge-small → 384 (free), OpenAI → 1536
        ],

        /*
         | Provider used for generating chunk embeddings.
         | Options: 'openai', 'huggingface', 'ollama', 'null'
         |
         | Cost comparison:
         |   openai:       ~$0.02/1M tokens (text-embedding-3-small)
         |   huggingface:  free (rate limits apply)
         |   ollama:       free (local compute required)
         |   null:         no embeddings (keyword search only)
         */
        'embed_provider' => env('EMBED_PROVIDER', 'openai'),

        'chunker' => [
            'target_size' => 500,
            'max_size'    => 1000,
            'overlap'     => 50,
        ],
    ],

    // ──────────────────────────────────────────────────────────────
    // ChatEngine
    // ──────────────────────────────────────────────────────────────

    'chat' => [
        /*
         | Session TTL in seconds. Resets on every message.
         | After this period of inactivity the session history is cleared.
         */
        'session_ttl' => 3600,

        /*
         | Maximum messages kept in session history (user + assistant pairs).
         | Oldest non-system messages are dropped when the limit is reached.
         */
        'max_messages' => 20,

        /*
         | Maximum characters of RAG context injected into the prompt.
         | Prevents token overflow on long or many retrieved chunks.
         */
        'max_context_chars' => 4000,

        /*
         | Persona definitions.
         | Each key is the persona name used in ChatMessage::persona.
         |
         | system_prompt: base instruction for the assistant
         | model:         ModelConfig array — provider, model name, temperature, etc.
         | rag_enabled:   whether to retrieve document chunks for each message
         | rag_top_k:     maximum chunks to retrieve per message
         | rag_entities:  entity classes to restrict RAG to (empty = all)
         | cite_sources:  whether to include [Source id#index] citations in answers
         */
        'personas' => [
            'default' => [
                'system_prompt' => 'You are a helpful assistant. Answer based on the provided context when available.',
                'model'         => ['provider' => 'openai', 'model' => 'gpt-4o-mini', 'max_tokens' => 1000, 'temperature' => 0.3],
                'rag_enabled'   => false,
                'rag_top_k'     => 5,
                'rag_entities'  => [],
                'cite_sources'  => false,
            ],

            // 'support' => [
            //     'system_prompt' => 'You are a customer support agent. Only answer questions based on the provided context. If the answer is not in the context, say you do not have that information.',
            //     'model'         => ['provider' => 'anthropic', 'model' => 'claude-haiku-4', 'max_tokens' => 800, 'temperature' => 0.2],
            //     'rag_enabled'   => true,
            //     'rag_top_k'     => 5,
            //     'rag_entities'  => ['App\Models\Article', 'App\Models\FaqEntry'],
            //     'cite_sources'  => true,
            // ],
        ],
    ],

    // ──────────────────────────────────────────────────────────────
    // OrchestrationEngine
    // ──────────────────────────────────────────────────────────────

    'orchestration' => [
        /*
         | Named flow definitions.
         | Each key is the flow key passed to OrchestrationEngine::run().
         |
         | steps: ordered list of steps, each with:
         |   id:                  Unique step ID (used in logs and compensation references)
         |   handler:             FQCN of a class with execute(array $input, array $payload): array
         |   compensation:        FQCN of a class with compensate(array $input, array $payload): void
         |   input:               Input mapping — 'step_key' => 'payload.dot.path'
         |   continue_on_failure: If true, flow continues even if this step fails
         |   retries:             Number of retry attempts after first failure
         */

        // 'process_order' => [
        //     'steps' => [
        //         [
        //             'id'           => 'validate_stock',
        //             'handler'      => 'App\Orchestration\ValidateStockStep',
        //             'compensation' => 'App\Orchestration\ReleaseStockStep',
        //         ],
        //         [
        //             'id'      => 'charge_payment',
        //             'handler' => 'App\Orchestration\ChargePaymentStep',
        //             'compensation' => 'App\Orchestration\RefundPaymentStep',
        //             'retries' => 2,
        //             'input'   => ['order_id' => 'order.id', 'amount' => 'order.total'],
        //         ],
        //         [
        //             'id'      => 'send_confirmation',
        //             'handler' => 'App\Orchestration\SendConfirmationStep',
        //             'continue_on_failure' => true, // non-critical — continue if email fails
        //         ],
        //     ],
        // ],
    ],

    // ──────────────────────────────────────────────────────────────
    // AiEngine
    // ──────────────────────────────────────────────────────────────

    'ai' => [
        /*
         | Provider credentials are always loaded from environment variables.
         | OPENAI_API_KEY and ANTHROPIC_API_KEY are read automatically.
         |
         | fallback_chain: provider names tried in order when the primary fails.
         |   The 'null' provider is always added as final fallback.
         |
         | pricing: cost per 1000 tokens per provider+model (USD).
         |   Used only for cost tracking — does not affect behaviour.
         */
        'fallback_chain' => ['anthropic', 'null'],

        'openai' => [
            'embed_model' => env('OPENAI_EMBED_MODEL', 'text-embedding-3-small'),
        ],

        'pricing' => [
            'openai' => [
                'gpt-4o'       => ['input' => 0.005,  'output' => 0.015],
                'gpt-4o-mini'  => ['input' => 0.00015,'output' => 0.0006],
            ],
            'anthropic' => [
                'claude-sonnet-4-6' => ['input' => 0.003, 'output' => 0.015],
                'claude-haiku-4-5-20251001'  => ['input' => 0.00025, 'output' => 0.00125],
            ],
        ],
    ],

    // ──────────────────────────────────────────────────────────────
    // DocumentEngine
    // ──────────────────────────────────────────────────────────────

    'documents' => [
        /*
         | embed_provider: which AiEngine provider to use for generating embeddings.
         | Defaults to 'openai' (text-embedding-3-small).
         |
         | chunker: text chunking strategy configuration.
         |   target_size: target chunk size in characters
         |   max_size:    maximum before force-splitting at sentence boundaries
         |   overlap:     characters of overlap between consecutive chunks (for context continuity)
         |
         | Processors are registered automatically: txt, md, csv, pdf, docx.
         | Add custom processors via: $app->tag(MyProcessor::class, 'engine.document.processors')
         */
        'embed_provider' => env('DOCUMENT_EMBED_PROVIDER', 'openai'),

        'chunker' => [
            'target_size' => 500,
            'max_size'    => 1000,
            'overlap'     => 50,
        ],
    ],

    // ──────────────────────────────────────────────────────────────
    // ChatEngine
    // ──────────────────────────────────────────────────────────────

    'chat' => [
        /*
         | session_ttl:       Seconds of inactivity before a session expires (default: 1 hour)
         | max_messages:      Maximum messages kept in session history (FIFO trim, keeps system)
         | max_context_chars: Maximum RAG context characters injected into the prompt
         |
         | personas: each key defines a named assistant persona.
         |   system_prompt: the system message sent to the LLM
         |   model:         provider + model + generation params
         |   rag_enabled:   true = RAG is mandatory (business data personas)
         |   rag_top_k:     number of document chunks retrieved for context
         |   rag_entities:  limit RAG retrieval to these entity classes (empty = all)
         |   cite_sources:  include [Source docId#chunkIndex] citations in answers
         */
        'session_ttl'       => 3600,
        'max_messages'      => 20,
        'max_context_chars' => 4000,

        'personas' => [

            'default' => [
                'system_prompt' => 'You are a helpful assistant. Answer clearly and concisely.',
                'model'         => [
                    'provider'    => env('DEFAULT_AI_PROVIDER', 'openai'),
                    'model'       => env('DEFAULT_AI_MODEL', 'gpt-4o-mini'),
                    'max_tokens'  => 1000,
                    'temperature' => 0.3,
                ],
                'rag_enabled'  => false,
                'cite_sources' => false,
            ],

            // Business assistant with mandatory RAG
            // 'business' => [
            //     'system_prompt' => 'You are a business assistant. Answer only from the provided context. Always cite your sources.',
            //     'model' => [
            //         'provider'    => 'openai',
            //         'model'       => 'gpt-4o',
            //         'max_tokens'  => 2000,
            //         'temperature' => 0.1,
            //         'json_mode'   => false,
            //     ],
            //     'rag_enabled'  => true,
            //     'rag_top_k'    => 5,
            //     'rag_entities' => ['App\Models\Order', 'App\Models\Invoice'],
            //     'cite_sources' => true,
            // ],

        ],
    ],

];
// appended — the file above remains valid; new sections added below
