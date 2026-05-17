# laravel-engines

Ecossistema enterprise de engines para Laravel — stateless, declarativas, testáveis sem framework.

---

## Índice

1. [Visão Geral](#1-visão-geral)
2. [Estrutura do Monorepo](#2-estrutura-do-monorepo)
3. [Regras Transversais](#3-regras-transversais)
4. [Fluxo de Orquestração numa Action](#4-fluxo-de-orquestração-numa-action)
5. [Fase 1 — Core](#5-fase-1--core)
6. [Fase 2 — Extended](#6-fase-2--extended)
7. [Fase 3 — Extended (cont.)](#7-fase-3--extended-cont)
8. [Fase 4 — IA](#8-fase-4--ia)
9. [Shared — Contracts, DTOs, Value Objects](#9-shared--contracts-dtos-value-objects)
10. [Instalação e Configuração](#10-instalação-e-configuração)
11. [Testes](#11-testes)
12. [Roadmap](#12-roadmap)

---

## 1. Visão Geral

| Pacote | Instalação | Engines |
|---|---|---|
| `laravel-engines/shared` | automática | Contratos, DTOs, Value Objects |
| `laravel-engines/core` | `composer require laravel-engines/core` | ConstraintEngine, BusinessRuleEngine, ComputationEngine, WorkflowEngine |
| `laravel-engines/extended` | `composer require laravel-engines/extended` | ReactionEngine, NotificationEngine, DynamicPolicyEngine, SearchEngine, IntegrationEngine, OrchestrationEngine, **AiEngine, DocumentEngine, ChatEngine** |

Todos os pacotes seguem os mesmos princípios: **contratos primeiro, configuração declarativa, zero persistência, erros estruturados, testabilidade máxima**.

---

## 2. Estrutura do Monorepo

```
laravel-engines/
├── composer.json
└── packages/
    ├── shared/src/
    │   ├── Contracts/          ← interfaces de todas as engines
    │   ├── DTOs/               ← dados de entrada (input)
    │   └── ValueObjects/       ← dados de saída imutáveis (output)
    │
    ├── core/src/
    │   ├── EngineServiceProvider.php
    │   ├── EngineConfigValidator.php
    │   ├── ConstraintEngine/Checkers/      ← Uniqueness, Overlap, Limit
    │   ├── BusinessRuleEngine/Evaluators/  ← Comparison, Range, InList, Regex, CustomCallable
    │   ├── ComputationEngine/Operations/   ← 13 operações built-in
    │   └── WorkflowEngine/Guards/          ← Role, Field, Callable
    │
    └── extended/src/
        ├── ExtendedServiceProvider.php
        ├── ReactionEngine/Handlers/        ← Notify, DispatchJob, UpdateField
        ├── NotificationEngine/Channels/    ← Mail, Database
        ├── DynamicPolicyEngine/Conditions/
        ├── SearchEngine/Drivers/           ← Database, Null
        ├── IntegrationEngine/Http/
        ├── OrchestrationEngine/Steps/
        ├── AiEngine/
        │   └── Providers/                  ← OpenAi, Anthropic, Null
        ├── DocumentEngine/
        │   ├── Chunkers/ParagraphChunker.php
        │   ├── Processors/                 ← Pdf, Docx, Text, Html, Markdown
        │   └── DocumentChunkStore.php
        ├── ChatEngine/
        │   ├── ChatEngine.php
        │   ├── PersonaRegistry.php
        │   └── Memory/CacheMemory.php
        └── Jobs/                           ← SendNotificationJob, ExecuteIntegrationJob
```

---

## 3. Regras Transversais

**Contratos primeiro.** A aplicação injeta sempre a interface, nunca a classe concreta.

**Zero persistência.** As engines retornam dados para a Action persistir. Excepções deliberadas e explícitas: `ConstraintCheckers` (queries de leitura), `UpdateFieldHandler` (escreve um campo), `DocumentChunkStore` (persiste chunks para RAG).

**Stateless e determinístico.** Mesmo input → mesmo output. Zero estado interno.

**Erros estruturados.** Value Objects de erro, nunca exceptions genéricas.

**Testabilidade máxima.** Cada engine é testável com arrays e DTOs simples, sem Laravel nem base de dados.

---

## 4. Fluxo de Orquestração numa Action

```
Action::execute()
│
├─ 1. ConstraintEngine::validate()       ← fail-fast antes de qualquer cálculo
├─ 2. ComputationEngine::computeMany()   ← campos derivados
├─ 3. BusinessRuleEngine::evaluate()     ← regras sobre dados completos
│
└─ DB::transaction(function() {
       ├─ 4. Repository::create/update()
       ├─ 5. WorkflowEngine::transition() → Repository::updateState()
       └─ DB::afterCommit(function() {
               └─ 6. ReactionEngine::react()
                       ├─ NotifyHandler → NotificationEngine → Queue
                       ├─ DispatchJobHandler → Queue
                       └─ UpdateFieldHandler → DB (campo denormalizado)
          })
   })
```

`DB::transaction()` fica **sempre** na Action. `ReactionEngine` é **sempre** chamada via `DB::afterCommit()`.

---

## 5. Fase 1 — Core

### ConstraintEngine

Primeira chamada em qualquer Action. Valida integridade "hard". Nunca faz queries directamente — delega em `ConstraintCheckerInterface[]` via `$app->tagged('engine.constraint.checkers')`.

| Checker | Tipo | Uso |
|---|---|---|
| `UniquenessChecker` | `uniqueness` | Unicidade composta por tabela |
| `OverlapChecker` | `overlap` | Conflitos de datas/horários. SQL: `start < proposed.end AND end > proposed.start` |
| `LimitChecker` | `limit` | Máximo de N registos por período: `today`, `this_week`, `this_month`, `P30D` |

### BusinessRuleEngine (BRE)

Regras declarativas com `priority DESC`, severidades `deny`/`warn`/`allow` e efeitos aprovados pós-commit.

Evaluators: `comparison` (suporta `@cross.field`), `range`, `in_list`, `regex`, `custom_callable`.

Guard `onlyIf`: `"order.type == 'international'"` — aplica a regra só quando satisfeito.

### ComputationEngine

Cálculos puros. Zero `eval()`. Whitelist: `add`, `subtract`, `multiply`, `divide`, `sum`, `average`, `min`, `max`, `round`, `percentage`, `abs`, `clamp`, `iif`. Args `@dot.path` resolvem no contexto. Nesting recursivo (max 10). `precision: 2` activa BCMath.

### WorkflowEngine

Máquinas de estado. Nunca persiste. Guards: `role:manager`, `field:approved_by:null`, `callable:App\Guards\X::check`.

---

## 6. Fase 2 — Extended

### ReactionEngine

Efeitos colaterais event-driven. Sempre via `DB::afterCommit()`. Wildcards `order.*`. Isolamento total de falhas.

Handlers: `notify`, `dispatch_job`, `update_field`. Adicionar: tag `engine.reaction.handlers`.

### NotificationEngine

Multi-canal com templates e isolamento por canal. Placeholders `{{dot.path}}` ou `view:emails.x`. Canais: `mail`, `database`. Adicionar: tag `engine.notification.channels`. Async via `SendNotificationJob` (retry 5s → 25s → 125s).

---

## 7. Fase 3 — Extended (cont.)

### DynamicPolicyEngine

Controlo de acesso declarativo. `can()` em memória, `scope()` para Row-Level Security no query builder.

Condições: `record.tenant_id == user.tenant_id`, `record.status == published`, `user.region == meta.region`. Bypass por role. Cache por `entity + ability + user + record` com TTL configurável.

### SearchEngine

Multi-driver com fallback graceful. Falhas retornam `SearchResult::empty()` sem crashar. Drivers: `database` (LIKE queries), `null`. Suporta facets, filtros e driver por entidade.

### IntegrationEngine

Comunicação com APIs externas. Circuit breaker (5 falhas → open 60s), retry exponencial, payload mapping com dot-paths, autenticação `bearer`/`basic`/`api_key`. Async via `ExecuteIntegrationJob`.

### OrchestrationEngine

Saga Lite multi-passo. Compensação LIFO em falha. Input mapping por step. Retry configurável por step. `continue_on_failure` para steps não críticos. Logging detalhado por step.

---

## 8. Fase 4 — IA

### AiEngine

Abstracção unificada de LLMs. Nunca throws — sempre retorna `AiResponse`. Provider chain com fallback automático.

**Interface:**
```php
interface AiEngineInterface
{
    public function complete(AiRequest $request): AiResponse;
    public function embed(string $text, string $provider = 'openai'): array;
}
```

**Providers built-in:**

| Provider | Nome | Modelos | Embeddings |
|---|---|---|---|
| `OpenAiProvider` | `openai` | GPT-4o, GPT-4o-mini, GPT-3.5-turbo | `text-embedding-3-small` |
| `AnthropicProvider` | `anthropic` | Claude Opus 4, Sonnet 4, Haiku 4 | ❌ (delegado ao OpenAI) |
| `NullAiProvider` | `null` | — | zero vector 1536D |

**Fallback chain:** providers tentados em ordem até um sucesso. Configurado em `engines.extended.ai.fallback_chain`.

**JSON mode:** `ModelConfig(jsonMode: true)` força resposta JSON. Markdown fences são stripped automaticamente. Validação de schema via `jsonSchema`.

**Cost tracking:** estimativa em USD por chamada, baseada em pricing tables internas. Actualizar em `engines.extended.ai.pricing`.

```php
$response = $ai->complete(AiRequest::simple(
    model:        new ModelConfig(provider: 'anthropic', model: 'claude-haiku-4', jsonMode: true),
    systemPrompt: 'Extract the order data as JSON.',
    userMessage:  $rawText,
));

if ($response->failed()) { /* ... */ }

$data   = $response->data;          // parsed array (jsonMode)
$tokens = $response->totalTokens(); // input + output
$cost   = $response->estimatedCost; // USD float
```

**Adicionar provider:**
```php
// Implementar AiProviderInterface:
class OllamaProvider implements AiProviderInterface
{
    public function name(): string { return 'ollama'; }
    public function isAvailable(): bool { return /* ping local server */; }
    public function complete(AiRequest $request): AiResponse { ... }
    public function embed(string $text): array { ... }
}

// Registar:
$this->app->tag(OllamaProvider::class, 'engine.ai.providers');
```

---

### DocumentEngine

Processa documentos em chunks de texto para RAG. Async por design — nunca chamado inline em uploads.

**Interface:**
```php
interface DocumentEngineInterface
{
    public function process(DocumentInput $input): DocumentResult;
    public function retrieve(string $query, array $entityClasses = [], int $topK = 5): array;
    public function delete(string $documentId): bool;
}
```

**Pipeline de `process()`:**
1. Seleccionar processor por extensão do ficheiro
2. Extrair texto (null em falha — não bloqueia)
3. Chunking via `ParagraphChunker` (target 500 chars, max 1000, overlap 50)
4. Gerar embeddings via `AiEngine::embed()` (opcional — skipped se AI indisponível)
5. Persistir chunks no `DocumentChunkStore`

**Processors built-in:**

| Processor | Extensões | Método |
|---|---|---|
| `TextProcessor` | `txt`, `csv`, `log` | `file_get_contents()` |
| `PdfProcessor` | `pdf` | `pdftotext` CLI (poppler-utils) |
| `DocxProcessor` | `docx` | ZipArchive + XML parsing (sem dependências) |
| `HtmlProcessor` | `html`, `xhtml` | `strip_tags()` + entity decode |
| `MarkdownProcessor` | `md` | strip Markdown syntax |

**Recuperação RAG (`retrieve()`):**
- Com embeddings: cosine similarity no `DocumentChunkStore`
- Sem embeddings: keyword LIKE search como fallback

**DocumentChunkStore:** persiste chunks na tabela `document_chunks` (`document_id`, `chunk_index`, `text`, `embedding` JSON, `meta` JSON). Cosine similarity em PHP (substituir por pgvector para escala).

```php
// Processar um documento (normalmente via Job após upload):
$result = $engine->process(new DocumentInput(
    source:      storage_path("uploads/{$filename}"),
    entityClass: Article::class,
    entityId:    $article->id,
    pipeline:    'default',
    meta:        ['uploaded_by' => $user->id],
));

// Recuperar chunks relevantes para RAG:
$chunks = $engine->retrieve(
    query:         'Como faço a devolução?',
    entityClasses: [Article::class, FaqEntry::class],
    topK:          5,
);
```

**Adicionar processor:**
```php
$this->app->tag(ImageOcrProcessor::class, 'engine.document.processors');
```

---

### ChatEngine

Assistente contextual com RAG obrigatório para dados empresariais.

**Interface:**
```php
interface ChatEngineInterface
{
    public function chat(ChatMessage $message): ChatResponse;
    public function clearSession(string $sessionId): void;
    public function history(string $sessionId): array; // AiMessage[]
}
```

**Pipeline por `chat()`:**
1. Resolver persona (sistema prompt, modelo, config RAG)
2. Carregar histórico de sessão via `CacheMemory`
3. Recuperar chunks via `DocumentEngine::retrieve()` (quando `ragEnabled: true`)
4. Compor mensagens: system (+ contexto RAG) + histórico + mensagem do utilizador
5. Chamar `AiEngine::complete()`
6. Extrair citações do texto de resposta (`[Source docId#chunkIndex]`)
7. Persistir user + assistant no histórico
8. Retornar `ChatResponse` com resposta, fontes e usage

**RAG é obrigatório** quando `ragEnabled: true` na persona. As respostas incluem sempre citações `[Source id#index]` para rastreabilidade.

**Personas:** definem o comportamento completo do assistente. Configuradas em `engines.extended.chat.personas`.

```php
// Numa Action ou Controller:
$response = $chat->chat(new ChatMessage(
    content:   'Qual é a política de devoluções?',
    sessionId: "user-{$user->id}-support",
    persona:   'support',
    user:      $user,
    scope:     ['tenant_id' => $user->tenant_id],
));

$response->answer;    // string — resposta com citações inline
$response->sources;   // array<{document_id, chunk_index, excerpt}>
$response->hasSources(); // bool
$response->inputTokens + $response->outputTokens; // usage
$response->estimatedCost; // USD
```

**Configuração de persona:**
```php
'personas' => [
    'support' => [
        'system_prompt' => 'Você é um agente de suporte. Responda apenas com base no contexto fornecido.',
        'model'         => ['provider' => 'anthropic', 'model' => 'claude-haiku-4',
                            'max_tokens' => 800, 'temperature' => 0.2],
        'rag_enabled'   => true,
        'rag_top_k'     => 5,
        'rag_entities'  => ['App\Models\Article', 'App\Models\FaqEntry'],
        'cite_sources'  => true,
    ],
],
```

**Sessões:** `CacheMemory` persiste no Laravel Cache com TTL de 1h. Substituir por implementação de `ChatMemoryInterface` para sessões persistentes na BD.

---

## 9. Shared — Contracts, DTOs, Value Objects

### Contracts completos

**Core:** `ConstraintEngineInterface`, `ConstraintCheckerInterface`, `BusinessRuleEngineInterface`, `ComputationEngineInterface`, `WorkflowEngineInterface`, `WorkflowGuardInterface`

**Extended:** `ReactionEngineInterface`, `ReactionHandlerInterface`, `NotificationEngineInterface`, `NotificationChannelInterface`, `DynamicPolicyEngineInterface`, `SearchEngineInterface`, `SearchDriverInterface`, `IntegrationEngineInterface`, `OrchestrationEngineInterface`

**AI:** `AiEngineInterface`, `AiProviderInterface`, `DocumentEngineInterface`, `DocumentProcessorInterface`, `DocumentChunkerInterface`, `ChatEngineInterface`, `ChatMemoryInterface`

### Value Objects

| Value Object | Named constructors |
|---|---|
| `ValidationResult` | `::pass()`, `::fail($violations)` |
| `BREResponse` | construtor público |
| `ComputationResult` | `::ok($value)`, `::error($code, $msg)` |
| `TransitionResult` | `::allow($state, $actions, $meta)`, `::deny($reason)` |
| `ReactionSummary` | construtor público, `::empty()` |
| `NotificationResult` | `::sent($channel, $id)`, `::failed($channel, $code, $msg)` |
| `SearchResult` | construtor público, `::empty()` |
| `IntegrationResult` | `::ok($status, $response)`, `::failed($status, $code, $msg)` |
| `OrchestrationResult` | `::success($payload, $steps, $log)`, `::failed($stepId, $msg, ...)` |
| `AiResponse` | `::ok($content, $provider, $model, ...)`, `::failed($code, $msg)` |
| `DocumentResult` | `::ok($documentId, $text, $chunkCount, ...)`, `::failed($code, $msg)` |
| `ChatResponse` | `::ok($answer, $sessionId, $persona, ...)`, `::failed($sessionId, $code, $msg)` |

---

## 10. Instalação e Configuração

```bash
composer require laravel-engines/core      # Fase 1
composer require laravel-engines/extended  # Fases 1+2+3+4

php artisan vendor:publish --tag=engines-config
php artisan vendor:publish --tag=engines-extended-config
```

**Variáveis de ambiente (Fase 4):**
```env
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
EMBED_PROVIDER=openai          # provider para embeddings RAG
```

**Extensões via tag:**
```php
$this->app->tag(MyChecker::class,     'engine.constraint.checkers');
$this->app->tag(AuditHandler::class,  'engine.reaction.handlers');
$this->app->tag(SmsChannel::class,    'engine.notification.channels');
$this->app->tag(OllamaProvider::class,'engine.ai.providers');
$this->app->tag(ImageOcrProcessor::class, 'engine.document.processors');
```

**Migração para DocumentChunkStore:**
```bash
# Criar a tabela document_chunks manualmente ou via migration:
Schema::create('document_chunks', function (Blueprint $table) {
    $table->id();
    $table->string('document_id')->index();
    $table->unsignedInteger('chunk_index');
    $table->longText('text');
    $table->json('embedding')->nullable();
    $table->json('meta')->nullable();
    $table->timestamps();
    $table->index(['document_id', 'chunk_index']);
});
```

O `EngineConfigValidator` corre no boot quando `app.debug = true`. Detecta transições circulares, severidades inválidas e templates mal configurados.

---

## 11. Testes

```bash
composer test
./vendor/bin/pest packages/core/tests/
./vendor/bin/pest packages/extended/tests/
```

| Ficheiro | Engine | Testes |
|---|---|---|
| `core/ConstraintEngine/` | ConstraintEngine | 5 |
| `core/BusinessRuleEngine/` | BRE | 15 |
| `core/ComputationEngine/` | ComputationEngine | 18 |
| `core/WorkflowEngine/` | WorkflowEngine | 14 |
| `extended/ReactionEngine/` | ReactionEngine | 18 |
| `extended/NotificationEngine/` | NotificationEngine | 14 |
| `extended/NotificationEngine/IntegrationTest` | Reaction+Notification | 5 |
| `extended/DynamicPolicyEngine/` | DynamicPolicyEngine | 16 |
| `extended/SearchEngine/` | SearchEngine | ~12 |
| `extended/IntegrationEngine/` | IntegrationEngine | ~12 |
| `extended/OrchestrationEngine/` | OrchestrationEngine | 12 |
| `extended/AiEngine/` | AiEngine | 17 |
| `extended/DocumentEngine/` | DocumentEngine | 14 |
| `extended/ChatEngine/` | ChatEngine | 18 |

**Total: ~190 testes** cobrindo todas as 13 engines.

---

## 12. Roadmap

### Concluído ✅
- **Fase 1:** ConstraintEngine, BusinessRuleEngine, ComputationEngine, WorkflowEngine
- **Fase 2:** ReactionEngine, NotificationEngine
- **Fase 3:** DynamicPolicyEngine, SearchEngine, IntegrationEngine, OrchestrationEngine
- **Fase 4:** AiEngine, DocumentEngine, ChatEngine

### Infraestrutura pendente
- [ ] Comando `engines:graph` — exporta workflows em Mermaid/Graphviz para auditoria
- [ ] Testes de integração com SQLite in-memory para ServiceProviders
- [ ] PHPStan nível 8 em CI/CD
- [ ] Rate limiting na NotificationEngine (por utilizador/template/período)
- [ ] Canais `push` e `sms` na NotificationEngine
- [ ] Driver Meilisearch/Algolia na SearchEngine
- [ ] pgvector support no DocumentChunkStore para RAG em escala
- [ ] OllamaProvider para inferência local sem custos de API
- [ ] Webhook support na IntegrationEngine (recepção, não só envio)

---

## 12. Roadmap — Estado Actual

### Concluído ✅

| Item | Ficheiro |
|---|---|
| Fase 1: ConstraintEngine + BRE + ComputationEngine + WorkflowEngine | `packages/core/src/` |
| Fase 2: ReactionEngine + NotificationEngine | `packages/extended/src/` |
| Fase 3: DynamicPolicyEngine + SearchEngine + IntegrationEngine + OrchestrationEngine | `packages/extended/src/` |
| Fase 4: AiEngine + DocumentEngine + ChatEngine | `packages/extended/src/` |
| **Comando `engines:graph`** | `core/src/Console/EnginesGraphCommand.php` |
| **PHPStan nível 8** | `phpstan.neon` + `phpstan.neon.dist` |
| **Driver Meilisearch** | `SearchEngine/Drivers/MeilisearchDriver.php` |
| **OllamaProvider** | `AiEngine/Providers/OllamaProvider.php` |
| **Webhook support** | `IntegrationEngine/Webhooks/WebhookProcessor.php` |
| **pgvector** | `DocumentEngine/PgvectorChunkStore.php` |
| **Rate limiting** | `NotificationEngine/RateLimiting/NotificationRateLimiter.php` |
| **Canal push (FCM)** | `NotificationEngine/Channels/PushChannel.php` |
| **Canal SMS (Twilio)** | `NotificationEngine/Channels/SmsChannel.php` |

### Pendente

- [ ] Testes de integração com SQLite in-memory para ServiceProviders e bindings
- [ ] Testes para os itens do roadmap (MeilisearchDriver, WebhookProcessor, PgvectorChunkStore, canais)
- [ ] Canal Slack na NotificationEngine
- [ ] Driver Algolia na SearchEngine
- [ ] IVFFlat index option no PgvectorChunkStore (para datasets > 1M vectores)
- [ ] Dashboard de observabilidade (tokens, custos, latências por engine)

---

## 13. Vantagens e Desvantagens

### Vantagens

**Separação de responsabilidades real.** Cada engine tem uma única responsabilidade atómica, definida por contrato. Uma equipa pode mudar o driver de busca de `database` para `meilisearch` sem tocar em nenhuma Action, Controller ou Policy. O mesmo vale para trocar o provider de IA de OpenAI para Anthropic: uma linha na config.

**Testabilidade sem framework.** Todas as engines são testáveis com arrays e DTOs simples, sem bootstrapping do Laravel, sem base de dados. Um teste de BusinessRuleEngine corre em milissegundos porque não precisa de container, migrations, nem HTTP. Isto é a diferença entre uma suite de testes que corre em 3s e uma que corre em 45s.

**Configuração declarativa como documentação viva.** Um workflow configurado em `config/engines.php` é simultaneamente a implementação e a documentação. O comando `engines:graph` transforma essa config num diagrama Mermaid. Um auditor ou novo elemento da equipa consegue perceber o fluxo de aprovação de uma encomenda sem ler código.

**Extensibilidade por contrato.** Adicionar um checker, um handler de reacção, um canal de notificação, um provider de IA, ou um processor de documentos é implementar uma interface e fazer um `tag()` no ServiceProvider. Nunca se toca no código interno das engines. Open-Closed Principle aplicado de forma consistente.

**Preparação real para IA.** A separação `AiEngine → DocumentEngine → ChatEngine` permite usar IA de forma segura em contexto empresarial. O RAG é obrigatório para dados de negócio — a ChatEngine nunca responde puramente do modelo. A `DynamicPolicyEngine` filtra o contexto RAG por permissões antes de injectar no prompt, garantindo que os dados do tenant A nunca aparecem nas respostas do tenant B.

**Escalabilidade horizontal natural.** Engines stateless por design. Podem correr em múltiplas instâncias sem estado partilhado. As operações com estado (sessions, cache de rate limiting) são delegadas ao Laravel Cache, que pode ser Redis em produção.

**Fallback chain na IA.** Se o OpenAI falhar, a engine tenta o Anthropic. Se ambos falharem, cai no `NullProvider`. A aplicação nunca crasha por uma API externa indisponível.

---

### Desvantagens

**Curva de aprendizagem alta.** São 13 engines, ~30 contratos, ~20 DTOs e ~12 Value Objects. Um developer junior que entra no projecto tem muito para aprender antes de conseguir contribuir com confiança. O padrão de "contratos primeiro" é poderoso mas requer disciplina para ser mantido.

**Overhead em projectos simples.** Um CRUD básico não precisa de BRE, WorkflowEngine, nem OrchestrationEngine. Usar este pacote num projecto pequeno é sobrecarga arquitectural. O pacote é justificável em projectos com lógica de negócio complexa, multi-tenant, ou com requisitos de compliance e auditoria.

**Configuração declarativa tem limites.** Condições complexas no BRE (e.g. lógica que depende de joins em múltiplas tabelas) não se expressam bem em arrays declarativos. A saída é usar `custom_callable`, que quebra a pureza declarativa e reabre a porta à lógica espalhada.

**pgvector e cosine similarity em PHP.** O `DocumentChunkStore` base faz a comparação cosine em PHP, o que não escala além de poucos milhares de chunks. O `PgvectorChunkStore` resolve isto para produção, mas requer PostgreSQL e a extensão `vector` instalada. Equipas que usam MySQL ficam sem opção nativa de vector search.

**Custo de chamadas HTTP na IA.** Cada mensagem no ChatEngine pode gerar 2-3 chamadas HTTP: uma para embeddings RAG, outra para o completion. Em alta concorrência, isto pode aumentar a latência e os custos. O caching de embeddings (por query hash) reduziria significativamente o custo mas não está implementado ainda.

**Dependência do Laravel.** O pacote usa `Illuminate\Support\Facades`, `Http`, `Cache`, `Queue`, e `Log` ao longo de todo o código. Não é portável para Symfony ou PHP puro sem trabalho significativo. Esta é uma decisão consciente — o pacote é para Laravel enterprise, não para framework-agnostic.

**Sem UI de gestão.** A configuração de regras BRE, workflows, personas de chat e integrações é feita em código (arrays em config files). Não existe uma interface de administração para os utilizadores de negócio definirem regras. Para projectos onde os utilizadores não-técnicos precisam de gerir regras, é necessário construir essa camada por cima.

**Preços de IA hardcoded.** As tabelas de pricing nos providers (OpenAI, Anthropic) são actualizadas manualmente em código. Numa mudança de preços da API (comum), o valor estimado fica incorrecto até ao próximo deployment.

---

## 14. Providers de IA, Vector Stores e Drivers de Busca

### Providers de IA — comparação completa

| Provider | Nome | Melhor para | Embeddings | Custo |
|---|---|---|---|---|
| `OpenAiProvider` | `openai` | Qualidade geral, JSON mode robusto | ✅ `text-embedding-3-small` (1536D) | Pago |
| `AnthropicProvider` | `anthropic` | Contexto longo, raciocínio, seguir instruções | ❌ (delega ao OpenAI) | Pago |
| `GroqProvider` | `groq` | Latência ultra-baixa, real-time chat | ❌ | Pago (free tier generoso) |
| `OpenRouterProvider` | `openrouter` | Acesso unificado a 200+ modelos, modelos gratuitos | ❌ | Pago + modelos `:free` |
| `OllamaProvider` | `ollama` | Inferência local, privacy-first, sem custos | ✅ `nomic-embed-text` (768D) | Gratuito |
| `HuggingFaceProvider` | `huggingface` | Embeddings gratuitos de alta qualidade | ✅ `BAAI/bge-small-en-v1.5` (384D) | Gratuito (rate limits) |
| `NullAiProvider` | `null` | Testes, fallback final | ✅ zero vector 1536D | Gratuito |

#### OpenRouter — modelos gratuitos disponíveis

```php
// Usar um modelo gratuito via OpenRouter:
new ModelConfig(
    provider: 'openrouter',
    model:    'meta-llama/llama-3.1-8b-instruct:free',
)

// Outros modelos gratuitos:
// 'google/gemma-2-9b-it:free'
// 'mistralai/mistral-7b-instruct:free'
// 'microsoft/phi-3-mini-128k-instruct:free'
// 'qwen/qwen-2-7b-instruct:free'
```

#### Groq — modelos disponíveis

```php
// Ultra-rápido para chat em tempo real:
new ModelConfig(provider: 'groq', model: 'llama-3.1-8b-instant')      // mais rápido
new ModelConfig(provider: 'groq', model: 'llama-3.3-70b-versatile')   // melhor qualidade
new ModelConfig(provider: 'groq', model: 'deepseek-r1-distill-llama-70b') // reasoning
```

#### Embeddings gratuitos — HuggingFace

```php
// Em .env:
HF_API_KEY=hf_...
HF_EMBED_MODEL=BAAI/bge-small-en-v1.5   # 384 dims — default
# ou:
HF_EMBED_MODEL=BAAI/bge-base-en-v1.5    # 768 dims — melhor qualidade
# ou (multilingual):
HF_EMBED_MODEL=intfloat/multilingual-e5-large  # 1024 dims

EMBED_PROVIDER=huggingface
VECTOR_STORE=mysql   # ou pgvector — ajustar dimensions conforme modelo
```

#### Embeddings gratuitos — Ollama

```bash
# Instalar modelo de embeddings:
ollama pull nomic-embed-text   # 768 dims

# Em .env:
OLLAMA_BASE_URL=http://localhost:11434
EMBED_PROVIDER=ollama
VECTOR_STORE=mysql
```

#### Caching de embeddings

O `CachedEmbeddingEngine` envolve o `AiEngine` e cacheia todos os vectores por `sha256(provider + text)`. Activado por defeito. Para invalidar ao mudar de modelo:

```php
// Mudar o prefix na config invalida todos os embeddings cached:
'embedding_cache' => ['prefix' => 'engine.embedding.v2.']

// Ou com Redis e cache tags:
Cache::tags(['engine.embeddings'])->flush();
```

---

### Vector Stores — comparação

| Store | Classe | BD | Similarity | Requisitos |
|---|---|---|---|---|
| `DocumentChunkStore` | default | qualquer | PHP (lento) | nenhum |
| `PgvectorChunkStore` | pgvector | PostgreSQL 14+ | `<=>` COSINE nativo | pgvector + HNSW index |
| `MySqlVectorChunkStore` | mysql | MySQL 9.0+ | `VECTOR_DISTANCE()` nativo | MySQL 9.0 + VECTOR INDEX |

#### MySQL 9.0 — migration completa

```php
Schema::create('document_chunks', function (Blueprint $table) {
    $table->id();
    $table->string('document_id')->index();
    $table->unsignedInteger('chunk_index');
    $table->longText('text');
    $table->fullText('text');                    // para keyword fallback
    $table->vector('embedding', 384)->nullable(); // 384 para HF bge-small, 1536 para OpenAI
    $table->json('meta')->nullable();
    $table->timestamps();
    $table->unique(['document_id', 'chunk_index']);
});

// VECTOR INDEX para pesquisa eficiente (MySQL 9.0):
DB::statement('ALTER TABLE document_chunks ADD VECTOR INDEX idx_embedding (embedding)');
```

#### pgvector — migration completa

```php
// Activar extensão (uma vez):
DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

Schema::create('document_chunks', function (Blueprint $table) {
    $table->id();
    $table->string('document_id')->index();
    $table->unsignedInteger('chunk_index');
    $table->longText('text');
    $table->vector('embedding', 1536)->nullable(); // 1536 para OpenAI
    $table->json('meta')->nullable();
    $table->timestamps();
    $table->unique(['document_id', 'chunk_index']);
});

// HNSW index para ANN (approximate nearest neighbour):
DB::statement('CREATE INDEX ON document_chunks USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64)');
```

---

### Drivers de Busca — comparação

| Driver | Classe | Melhor para | Custo |
|---|---|---|---|
| `DatabaseDriver` | `database` | Datasets pequenos/médios, sem infra extra | Gratuito |
| `MeilisearchDriver` | `meilisearch` | Self-hosted, typo-tolerant, open-source | Gratuito (self-hosted) |
| `AlgoliaDriver` | `algolia` | SaaS gerido, globally distributed, free tier | Pago (free tier 10K req/mês) |
| `NullDriver` | `null` | Testes | Gratuito |

#### Configuração por driver

```env
# Meilisearch
SEARCH_DRIVER=meilisearch
MEILISEARCH_HOST=http://localhost:7700
MEILISEARCH_KEY=masterKey

# Algolia
SEARCH_DRIVER=algolia
ALGOLIA_APP_ID=XXXXXXXXXX
ALGOLIA_API_KEY=your-admin-key
```

---

### Webhooks — recepção de eventos externos

```php
// routes/api.php:
use ExpressCodeEngines\Extended\IntegrationEngine\Webhooks\WebhookRoute;

WebhookRoute::register('stripe', '/webhooks/stripe');
WebhookRoute::register('github', '/webhooks/github', ['throttle:100,1']);

// Handler personalizado:
class StripePaymentHandler implements WebhookHandlerInterface
{
    public function supports(string $integrationKey, string $eventType): bool
    {
        return $integrationKey === 'stripe' && $eventType === 'payment_intent.succeeded';
    }

    public function handle(string $key, string $event, array $payload, Request $request): void
    {
        ProcessPaymentJob::dispatch($payload);
    }
}

// Registar no ServiceProvider:
$this->app->tag(StripePaymentHandler::class, 'engine.webhook.handlers');
```

Configuração de assinatura (HMAC-SHA256 para Stripe, token simples, ou nenhuma):

```php
'integrations' => [
    'stripe' => [
        'signature' => [
            'type'       => 'hmac_sha256',
            'secret_env' => 'STRIPE_WEBHOOK_SECRET',
            'header'     => 'Stripe-Signature',
            'prefix'     => 'sha256=',
        ],
        'event_source' => 'header',
        'event_key'    => 'Stripe-Event',
    ],
],
```

---

## 15. Roadmap — Estado Final

### Completo ✅

- Fase 1: ConstraintEngine, BusinessRuleEngine, ComputationEngine, WorkflowEngine
- Fase 2: ReactionEngine, NotificationEngine (Mail, Database, **SMS, Push**)
- Fase 3: DynamicPolicyEngine, SearchEngine (**Meilisearch, Algolia**), IntegrationEngine (**webhooks**), OrchestrationEngine
- Fase 4: AiEngine (**OpenAI, Anthropic, Groq, OpenRouter, Ollama, HuggingFace**), DocumentEngine, ChatEngine
- Infraestrutura: `engines:graph` (Mermaid + Graphviz DOT), PHPStan nível 8, rate limiting, **embedding cache com Redis tags**, **pgvector (HNSW + IVFFlat)**, **MySQL 9.0 Vector**, **testes de integração com SQLite in-memory**

### Pendente

- [x] Testes de integração com SQLite in-memory para ServiceProviders e ExtendedServiceProvider
- [x] Canal Slack na NotificationEngine (Incoming Webhooks + Block Kit)
- [x] IVFFlat index no PgvectorChunkStore (HNSW/IVFFlat/exact configurável, ef_search e probes)
- [x] Embedding cache com Redis tags para invalidação selectiva por provider e modelo
- [ ] Dashboard de observabilidade (tokens, custos, latências por engine)

---

## 16. Funcionalidades implementadas nesta iteração

### Testes de integração com SQLite in-memory

O `EngineIntegrationTestCase` faz bootstrap mínimo do Laravel — container IoC, config, dispatcher de eventos e SQLite in-memory via Eloquent Capsule — sem HTTP kernel, sessões, ou artisan. Cada test class obtém um schema limpo com as tabelas necessárias (`orders`, `bookings`).

Os testes do `ServiceProviderIntegrationTest` validam:
- Resolução de todos os 4 contratos core a partir do container (`ConstraintEngineInterface`, `BusinessRuleEngineInterface`, `ComputationEngineInterface`, `WorkflowEngineInterface`)
- `UniquenessChecker` com queries reais ao SQLite (inserção, detecção de duplicado, exclusão do registo actual em update)
- `OverlapChecker` com datas reais (overlap detectado, não-overlap passa)
- `LimitChecker` com contagem real de registos por período
- Pipeline completo: Constraint → Computation → BRE numa única sequência

O `ExtendedServiceProviderTest` valida o `ExtendedServiceProvider` com um container mínimo (sem artisan), incluindo o fluxo `ReactionEngine → NotifyHandler → NotificationEngine → SpyChannel` end-to-end.

### Canal Slack

`SlackChannel` suporta três formatos de payload:
- **Texto simples**: `subject` em bold + `body` como texto Slack markdown
- **Attachment com cor**: quando `data.color` está definido (`'good'`, `'warning'`, `'danger'`, ou hex)
- **Block Kit**: quando `data.blocks` está definido — substitui o texto simples por blocos ricos

Resolução de URL por prioridade: string `https://...` → `getSlackWebhookUrl()` → `->slack_webhook_url` → URL padrão configurada. O ícone aceita emoji (`:robot_face:`) ou URL (`https://...`), distinguidos automaticamente.

```php
// Registar:
$this->app->tag(SlackChannel::class, 'engine.notification.channels');

// Template com cor:
'templates' => [
    'deploy_alert' => [
        'channels' => ['slack'],
        'subject'  => 'Deploy concluído: {{version}}',
        'body'     => '{{app}} foi deployed para {{environment}} com sucesso.',
        'defaults' => ['data' => ['color' => 'good']],
    ],
],

// Enviar para um webhook específico:
new NotificationRequest(
    templateKey: 'deploy_alert',
    recipient:   'https://hooks.slack.com/services/T.../B.../..',
    data:        ['version' => 'v2.3.1', 'app' => 'API', 'environment' => 'production'],
)
```

### PgvectorChunkStore — HNSW e IVFFlat configuráveis

O store agora aceita `indexType` no construtor com três modos:

**HNSW** (padrão, até ~10M vectores): recall alto, latência baixa, memória maior. Runtime: `SET LOCAL hnsw.ef_search = 100` dentro de uma transacção antes da query.

**IVFFlat** (> 10M vectores): menor memória, recall configurável via `probes`. O número de `lists` no index é calculado como `sqrt(total_rows)` — regra standard da comunidade pgvector. **Requer que a tabela esteja populada antes de criar o index** (ao contrário do HNSW).

**Exact** (< 100K vectores): sem index, brute-force, recall perfeito. Mais rápido do que index para volumes pequenos.

O `SET LOCAL` aplica-se apenas à transacção corrente, tornando o ajuste de parâmetros seguro em ambiente concorrente.

```php
// HNSW (default, até 10M vectores):
new PgvectorChunkStore(indexType: 'hnsw', efSearch: 100)

// IVFFlat (> 10M vectores):
new PgvectorChunkStore(indexType: 'ivfflat', probes: 10)

// Criar index após popular dados:
$store->createIndex(totalRows: 500_000); // lists = sqrt(500000) ≈ 707

// Recriar index (e.g. após mudar estratégia):
$store->rebuildIndex(totalRows: 500_000);
```

### Embedding cache com Redis tags

O `CachedEmbeddingEngine` suporta agora dois modos:

**Standard** (qualquer driver de cache): invalida mudando o `prefix` na config. Simples mas invalida tudo de uma vez.

**Redis tags** (`use_redis_tags: true`): invalida selectivamente sem afectar outros providers ou modelos. Cada embedding é tagged com `['engine.embedding', 'engine.embedding.{provider}']`.

```php
// Configurar em .env:
EMBEDDING_CACHE_REDIS_TAGS=true  # requer Redis como cache driver

# Invalidação selectiva em código:
Cache::tags(['engine.embedding'])->flush()              // limpa TUDO
Cache::tags(['engine.embedding.openai'])->flush()       // só OpenAI
Cache::tags(['engine.embedding.huggingface'])->flush()  // só HuggingFace

# Ou via engine:
$engine->clearByProvider('openai')
$engine->clearAll()
```

Vectores vazios (`[]`) nunca são cacheados — se um provider falha, a próxima chamada tentará novamente em vez de retornar um vector nulo.
