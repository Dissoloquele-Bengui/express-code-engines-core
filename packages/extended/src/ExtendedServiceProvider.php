<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended;

use Illuminate\Support\ServiceProvider;
use ExpressCodeEngines\Shared\Contracts\DynamicPolicyEngineInterface;
use ExpressCodeEngines\Shared\Contracts\IntegrationEngineInterface;
use ExpressCodeEngines\Shared\Contracts\NotificationEngineInterface;
use ExpressCodeEngines\Shared\Contracts\OrchestrationEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ReactionEngineInterface;
use ExpressCodeEngines\Shared\Contracts\SearchEngineInterface;
use ExpressCodeEngines\Extended\DynamicPolicyEngine\Conditions\PolicyConditionEvaluator;
use ExpressCodeEngines\Extended\DynamicPolicyEngine\DynamicPolicyEngine;
use ExpressCodeEngines\Extended\IntegrationEngine\Http\PayloadMapper;
use ExpressCodeEngines\Extended\IntegrationEngine\IntegrationEngine;
use ExpressCodeEngines\Extended\IntegrationEngine\IntegrationRegistry;
use ExpressCodeEngines\Extended\NotificationEngine\Channels\DatabaseChannel;
use ExpressCodeEngines\Extended\NotificationEngine\Channels\MailChannel;
use ExpressCodeEngines\Extended\NotificationEngine\NotificationEngine;
use ExpressCodeEngines\Extended\NotificationEngine\TemplateRegistry;
use ExpressCodeEngines\Extended\NotificationEngine\Renderers\TemplateRenderer;
use ExpressCodeEngines\Extended\OrchestrationEngine\OrchestrationEngine;
use ExpressCodeEngines\Extended\AiEngine\AiEngine;
use ExpressCodeEngines\Extended\AiEngine\AiProviderRegistry;
use ExpressCodeEngines\Extended\AiEngine\CachedEmbeddingEngine;
use ExpressCodeEngines\Extended\AiEngine\Providers\GroqProvider;
use ExpressCodeEngines\Extended\AiEngine\Providers\HuggingFaceProvider;
use ExpressCodeEngines\Extended\AiEngine\Providers\OpenRouterProvider;
use ExpressCodeEngines\Extended\AiEngine\Providers\NullAiProvider;
use ExpressCodeEngines\Extended\AiEngine\Providers\OpenAiProvider;
use ExpressCodeEngines\Extended\DocumentEngine\Chunkers\ParagraphChunker;
use ExpressCodeEngines\Extended\DocumentEngine\DocumentChunkStore;
use ExpressCodeEngines\Extended\DocumentEngine\DocumentEngine;
use ExpressCodeEngines\Extended\DocumentEngine\Processors\DocxProcessor;
use ExpressCodeEngines\Extended\DocumentEngine\Processors\PdfProcessor;
use ExpressCodeEngines\Extended\DocumentEngine\Processors\TextProcessor;
use ExpressCodeEngines\Extended\ChatEngine\ChatEngine;
use ExpressCodeEngines\Extended\ChatEngine\Memory\CacheMemory;
use ExpressCodeEngines\Extended\ChatEngine\PersonaRegistry;
use ExpressCodeEngines\Shared\Contracts\AiEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ChatEngineInterface;
use ExpressCodeEngines\Shared\Contracts\DocumentEngineInterface;
use ExpressCodeEngines\Extended\ReactionEngine\Conditions\ConditionEvaluator;
use ExpressCodeEngines\Extended\ReactionEngine\Handlers\DispatchJobHandler;
use ExpressCodeEngines\Extended\ReactionEngine\Handlers\NotifyHandler;
use ExpressCodeEngines\Extended\ReactionEngine\Handlers\UpdateFieldHandler;
use ExpressCodeEngines\Extended\ReactionEngine\ReactionEngine;
use ExpressCodeEngines\Extended\ReactionEngine\ReactionHandlerRegistry;
use ExpressCodeEngines\Extended\DocumentEngine\MySqlVectorChunkStore;
use ExpressCodeEngines\Extended\DocumentEngine\PgvectorChunkStore;
use ExpressCodeEngines\Extended\SearchEngine\Drivers\AlgoliaDriver;
use ExpressCodeEngines\Extended\SearchEngine\Drivers\MeilisearchDriver;
use ExpressCodeEngines\Extended\SearchEngine\Drivers\NullDriver;
use ExpressCodeEngines\Extended\SearchEngine\SearchEngine;

class ExtendedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/extended.php', 'engines.extended');

        $this->registerNotificationEngine();
        $this->registerReactionEngine();
        $this->registerDynamicPolicyEngine();
        $this->registerSearchEngine();
        $this->registerIntegrationEngine();
        $this->registerOrchestrationEngine();
        $this->registerAiEngine();
        $this->registerDocumentEngine();
        $this->registerChatEngine();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/extended.php' => config_path('engines/extended.php'),
            ], 'engines-extended-config');
        }
    }

    private function registerNotificationEngine(): void
    {
        $this->app->singleton(TemplateRegistry::class, function ($app) {
            return new TemplateRegistry(
                config('engines.extended.notifications.templates', []),
            );
        });

        $this->app->singleton(NotificationEngineInterface::class, function ($app) {
            return new NotificationEngine(
                templates: $app->make(TemplateRegistry::class),
                renderer:  new TemplateRenderer(),
                channels:  [
                    'mail'     => new MailChannel(),
                    'database' => new DatabaseChannel(),
                    ...$this->resolveTaggedChannels(),
                ],
            );
        });
    }

    private function registerReactionEngine(): void
    {
        $this->app->singleton(ReactionHandlerRegistry::class, function ($app) {
            return new ReactionHandlerRegistry([
                new NotifyHandler($app->make(NotificationEngineInterface::class)),
                new DispatchJobHandler(),
                new UpdateFieldHandler(),
                ...$app->tagged('engine.reaction.handlers'),
            ]);
        });

        $this->app->singleton(ReactionEngineInterface::class, function ($app) {
            return new ReactionEngine(
                registry:   $app->make(ReactionHandlerRegistry::class),
                conditions: new ConditionEvaluator(),
            );
        });
    }

    private function registerDynamicPolicyEngine(): void
    {
        $this->app->singleton(DynamicPolicyEngineInterface::class, function ($app) {
            return new DynamicPolicyEngine(
                evaluator:    new PolicyConditionEvaluator(),
                cacheTtl:     config('engines.extended.policy.cache_ttl', 300),
                cacheEnabled: config('engines.extended.policy.cache_enabled', true),
            );
        });
    }

    private function registerSearchEngine(): void
    {
        $this->app->singleton(SearchEngineInterface::class, function ($app) {
            $driver       = config('engines.extended.search.driver', 'null');
            $entityConfig = config('engines.extended.search.entities', []);

            $defaultDriver = match ($driver) {
                'database'    => new DatabaseDriver($entityConfig),
                'meilisearch' => new MeilisearchDriver(
                    host:         env('MEILISEARCH_HOST', 'http://localhost:7700'),
                    apiKey:       env('MEILISEARCH_KEY', ''),
                    entityConfig: $entityConfig,
                ),
                'algolia'     => new AlgoliaDriver(
                    appId:        env('ALGOLIA_APP_ID', ''),
                    adminApiKey:  env('ALGOLIA_API_KEY', ''),
                    entityConfig: $entityConfig,
                ),
                default       => new NullDriver(),
            };

            return new SearchEngine(
                defaultDriver:  $defaultDriver,
                fallbackDriver: new NullDriver(),
                entityDrivers:  [],
            );
        });
    }

    private function registerIntegrationEngine(): void
    {
        $this->app->singleton(IntegrationRegistry::class, function ($app) {
            return new IntegrationRegistry(
                config('engines.extended.integrations', []),
            );
        });

        $this->app->singleton(IntegrationEngineInterface::class, function ($app) {
            return new IntegrationEngine(
                registry: $app->make(IntegrationRegistry::class),
                mapper:   new PayloadMapper(),
            );
        });
    }

    private function registerOrchestrationEngine(): void
    {
        $this->app->singleton(OrchestrationEngineInterface::class, function ($app) {
            return new OrchestrationEngine(
                container: $app,
            );
        });
    }

    private function registerAiEngine(): void
    {
        $this->app->singleton(AiEngineInterface::class, function ($app) {
            $cfg     = config('engines.extended.ai', []);
            $pricing = $cfg['pricing'] ?? [];

            $providers = [];

            if ($key = env('OPENAI_API_KEY')) {
                $providers[] = new OpenAiProvider(
                    apiKey:     $key,
                    embedModel: $cfg['openai']['embed_model'] ?? 'text-embedding-3-small',
                );
            }

            if ($key = env('ANTHROPIC_API_KEY')) {
                $providers[] = new AnthropicProvider(apiKey: $key);
            }

            if ($key = env('GROQ_API_KEY')) {
                $providers[] = new GroqProvider(apiKey: $key);
            }

            if ($key = env('OPENROUTER_API_KEY')) {
                $providers[] = new OpenRouterProvider(
                    apiKey:         $key,
                    appUrl:         env('OPENROUTER_APP_URL', config('app.url', '')),
                    appName:        env('OPENROUTER_APP_NAME', config('app.name', 'laravel-engines')),
                    fallbackModels: $cfg['openrouter']['fallback_models'] ?? [],
                );
            }

            if ($key = env('HF_API_KEY')) {
                $providers[] = new HuggingFaceProvider(
                    apiKey:       $key,
                    embedModel:   env('HF_EMBED_MODEL', $cfg['huggingface']['embed_model'] ?? 'BAAI/bge-small-en-v1.5'),
                );
            }

            if (env('OLLAMA_BASE_URL') || $cfg['ollama']['enabled'] ?? false) {
                $providers[] = new OllamaProvider(
                    baseUrl: env('OLLAMA_BASE_URL', 'http://localhost:11434'),
                );
            }

            // Always register NullProvider as final fallback
            $providers[] = new NullAiProvider();

            // App-defined providers via tag
            foreach ($app->tagged('engine.ai.providers') as $provider) {
                $providers[] = $provider;
            }

            $registry = new AiProviderRegistry($providers);
            $registry->setFallbackChain($cfg['fallback_chain'] ?? ['null']);

            $engine = new AiEngine($registry, $pricing);

            // Wrap with embedding cache when enabled (recommended in production)
            if ($cfg['embedding_cache']['enabled'] ?? true) {
                return new CachedEmbeddingEngine(
                    inner:         $engine,
                    ttl:           $cfg['embedding_cache']['ttl']            ?? 0,
                    prefix:        $cfg['embedding_cache']['prefix']          ?? 'engine.embedding.v1.',
                    useRedisTags:  $cfg['embedding_cache']['use_redis_tags']  ?? false,
                );
            }

            return $engine;
        });
    }

    private function registerDocumentEngine(): void
    {
        $this->app->singleton(DocumentEngineInterface::class, function ($app) {
            $cfg = config('engines.extended.documents', []);

            $processors = [
                new TextProcessor(),
                new PdfProcessor(),
                new DocxProcessor(),
                ...$app->tagged('engine.document.processors'),
            ];

            $chunkerCfg = $cfg['chunker'] ?? [];
            $storeName  = $cfg['vector_store'] ?? 'default';

            $store = match ($storeName) {
                'pgvector' => new PgvectorChunkStore(
                    table:      $cfg['pgvector']['table']      ?? 'document_chunks',
                    dimensions: $cfg['pgvector']['dimensions'] ?? 1536,
                ),
                'mysql' => new MySqlVectorChunkStore(
                    table:      $cfg['mysql']['table']      ?? 'document_chunks',
                    dimensions: $cfg['mysql']['dimensions'] ?? 384,
                ),
                default => new DocumentChunkStore(),
            };

            return new DocumentEngine(
                processors:    $processors,
                chunker:       new ParagraphChunker(
                    targetSize: $chunkerCfg['target_size'] ?? 500,
                    maxSize:    $chunkerCfg['max_size']    ?? 1000,
                    overlap:    $chunkerCfg['overlap']     ?? 50,
                ),
                store:         $store,
                ai:            $app->make(AiEngineInterface::class),
                embedProvider: $cfg['embed_provider'] ?? 'openai',
            );
        });
    }

    private function registerChatEngine(): void
    {
        $this->app->singleton(PersonaRegistry::class, function ($app) {
            return new PersonaRegistry(
                config('engines.extended.chat.personas', []),
            );
        });

        $this->app->singleton(ChatEngineInterface::class, function ($app) {
            $cfg = config('engines.extended.chat', []);

            return new ChatEngine(
                ai:              $app->make(AiEngineInterface::class),
                documents:       $app->make(DocumentEngineInterface::class),
                memory:          new CacheMemory(
                    ttlSeconds:  $cfg['session_ttl']    ?? 3600,
                    maxMessages: $cfg['max_messages']   ?? 20,
                ),
                personas:        $app->make(PersonaRegistry::class),
                maxContextChars: $cfg['max_context_chars'] ?? 4000,
            );
        });
    }

    private function resolveTaggedChannels(): array
    {
        $channels = [];
        foreach ($this->app->tagged('engine.notification.channels') as $channel) {
            $channels[$channel->channel()] = $channel;
        }
        return $channels;
    }
}
