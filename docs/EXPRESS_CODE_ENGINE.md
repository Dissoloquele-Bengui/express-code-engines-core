# ExpressCodeEngine

**Enterprise engine ecosystem for Laravel.**
13 composable, stateless, contract-first engines for complex business applications.

---

```
composer require express-code/engine
```

[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-blue)](https://php.net)
[![Laravel 10+](https://img.shields.io/badge/Laravel-10%2B-red)](https://laravel.com)
[![Tests](https://img.shields.io/badge/tests-190%2B-green)]()
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-blueviolet)]()
[![License](https://img.shields.io/badge/license-MIT-lightgrey)](LICENSE)

---

## O que é o ExpressCodeEngine?

O ExpressCodeEngine é um conjunto de **engines especializadas** para Laravel que resolve os problemas mais difíceis de aplicações enterprise: lógica de negócio espalhada, regras duplicadas, integrações frágeis, e IA difícil de controlar em contexto multi-tenant.

Cada engine tem uma responsabilidade única. Todas são stateless. Todas são testáveis sem framework. Todas comunicam por contratos PHP tipados.

```
A tua Action:

1. ConstraintEngine    ← integridade física (unicidade, overlaps, limites)
2. ComputationEngine   ← totais, impostos, campos derivados
3. BusinessRuleEngine  ← regras de negócio declarativas com prioridade
                 ↓
         DB::transaction()
4. Repository          ← persistência
5. WorkflowEngine      ← transição de estado em memória
                 ↓
         DB::afterCommit()
6. ReactionEngine      ← efeitos colaterais event-driven
   ├─ NotificationEngine  (mail, database, SMS, push, Slack)
   ├─ IntegrationEngine   (APIs externas, webhooks)
   └─ OrchestrationEngine (fluxos multi-passo, compensação)
                 ↓
   DynamicPolicyEngine    ← acesso granular e Row-Level Security
   SearchEngine           ← busca multi-driver
   AiEngine               ← LLMs com fallback e cost tracking
   DocumentEngine         ← RAG, chunking, embeddings
   ChatEngine             ← assistente contextual com RAG obrigatório
```

---

## Engines disponíveis

### Fase 1 — Core (`express-code/engine-core`)

| Engine | Resolve |
|---|---|
| [ConstraintEngine](#constraintengine) | Unicidade composta, conflitos de agenda, limites físicos |
| [BusinessRuleEngine](#businessruleengine) | Regras declarativas com prioridade, severidade e efeitos |
| [ComputationEngine](#computationengine) | Cálculos puros sem `eval()` — totais, impostos, fórmulas |
| [WorkflowEngine](#workflowengine) | Máquinas de estado, aprovações, transições com guards |

### Fase 2 — Extended (`express-code/engine`)

| Engine | Resolve |
|---|---|
| [ReactionEngine](#reactionengine) | Efeitos colaterais event-driven após commit |
| [NotificationEngine](#notificationengine) | Notificações multi-canal com templates e rate limiting |

### Fase 3 — Extended (cont.)

| Engine | Resolve |
|---|---|
| [DynamicPolicyEngine](#dynamicpolicyengine) | Controlo de acesso data-driven e Row-Level Security |
| [SearchEngine](#searchengine) | Busca unificada: database, Meilisearch, Algolia |
| [IntegrationEngine](#integrationengine) | APIs externas com circuit breaker, retry e webhooks |
| [OrchestrationEngine](#orchestrationengine) | Fluxos multi-passo com compensação Saga |

### Fase 4 — IA

| Engine | Resolve |
|---|---|
| [AiEngine](#aiengine) | LLMs unificados: OpenAI, Anthropic, Groq, OpenRouter, Ollama, HuggingFace |
| [DocumentEngine](#documentengine) | OCR, chunking, embeddings, RAG indexing |
| [ChatEngine](#chatengine) | Assistente contextual com RAG obrigatório e citações |

---

## Instalação

```bash
# Core (4 engines, zero dependências externas):
composer require express-code/engine-core

# Tudo (13 engines):
composer require express-code/engine

# Publicar configuração:
php artisan vendor:publish --tag=engines-config
php artisan vendor:publish --tag=engines-extended-config
```

### Variáveis de ambiente

```env
# IA — configura apenas as que precisas
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GROQ_API_KEY=gsk_...
OPENROUTER_API_KEY=sk-or-v1-...
OLLAMA_BASE_URL=http://localhost:11434
HF_API_KEY=hf_...

# Busca
SEARCH_DRIVER=meilisearch          # database | meilisearch | algolia | null
MEILISEARCH_HOST=http://localhost:7700
MEILISEARCH_KEY=masterKey
ALGOLIA_APP_ID=XXXXXXXXXX
ALGOLIA_API_KEY=your-admin-key

# RAG / Embeddings
EMBED_PROVIDER=openai              # openai | huggingface | ollama | null
VECTOR_STORE=pgvector              # default | pgvector | mysql
EMBEDDING_CACHE_REDIS_TAGS=false   # true requer Redis

# SMS / Push
TWILIO_ACCOUNT_SID=ACxxxx
TWILIO_AUTH_TOKEN=xxxx
TWILIO_FROM_NUMBER=+1234567890
FIREBASE_PROJECT_ID=your-project
FIREBASE_SERVICE_ACCOUNT_JSON=/path/to/sa.json
```

---

## Início rápido — 5 minutos

### 1. Uma Action com as 4 engines core

```php
use ExpressCodeEngines\Shared\Contracts\ConstraintEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ComputationEngineInterface;
use ExpressCodeEngines\Shared\Contracts\BusinessRuleEngineInterface;
use ExpressCodeEngines\Shared\Contracts\WorkflowEngineInterface;

final class CreateInvoiceAction
{
    public function __construct(
        private readonly ConstraintEngineInterface   $constraints,
        private readonly ComputationEngineInterface  $computation,
        private readonly BusinessRuleEngineInterface $bre,
        private readonly WorkflowEngineInterface     $workflow,
        private readonly InvoiceRepository           $invoices,
    ) {}

    public function execute(CreateInvoiceDTO $dto, User $user): Invoice
    {
        // 1. Integridade — fail fast antes de qualquer cálculo
        $check = $this->constraints->validate(
            new ConstraintContext(entityClass: Invoice::class, data: $dto->toArray()),
            [
                new ConstraintDefinition(type: 'uniqueness',
                    scopeFields: ['number', 'tenant_id'],
                    message: 'Número de factura já existe.',
                    config: ['table' => 'invoices']),
            ]
        );
        if (! $check->passed) throw new ConstraintException($check->firstMessage());

        // 2. Cálculos — antes das regras para ter dados completos
        $totals = $this->computation->computeMany([
            'subtotal' => new ComputationRequest('sum', array_column($dto->lines, 'amount')),
            'vat'      => new ComputationRequest('percentage', ['@subtotal', $dto->vatRate],
                             context: ['subtotal' => 0], precision: 2),
        ]);
        $subtotal = $totals['subtotal']->value;
        $vat      = $this->computation->compute(
            new ComputationRequest('percentage', [$subtotal, $dto->vatRate], precision: 2)
        )->value;

        // 3. Regras de negócio
        $rules = $this->bre->evaluate(
            new RuleContext(data: ['invoice' => ['total' => $subtotal + $vat, 'client' => $dto->client]]),
            [
                new RuleDefinition(id: 'min_amount', ruleType: 'comparison',
                    field: 'invoice.total', params: ['operator' => '>=', 'value' => 1.00],
                    severity: 'deny', message: 'Valor mínimo de €1,00.'),
                new RuleDefinition(id: 'large_invoice', ruleType: 'comparison',
                    field: 'invoice.total', params: ['operator' => '>', 'value' => 10000],
                    severity: 'allow', effects: ['require_approval']),
            ]
        );
        if ($rules->hasDenials()) throw new BusinessRuleException($rules->firstDenialMessage());

        // 4. Persistir + transição de estado
        return DB::transaction(function () use ($dto, $subtotal, $vat, $rules, $user) {
            $invoice = $this->invoices->create([...$dto->toArray(), 'subtotal' => $subtotal, 'vat' => $vat]);

            $transition = $this->workflow->transition(new TransitionRequest(
                entityClass: Invoice::class, currentState: 'draft',
                transition: 'submit', data: $invoice->toArray(), user: $user,
            ));

            if ($transition->wasAllowed()) {
                $this->invoices->updateState($invoice, $transition->newState);
            }

            // 5. Efeitos após commit
            DB::afterCommit(function () use ($invoice, $rules) {
                foreach ($rules->approvedEffects as $effect) {
                    event("engine.effect.{$effect}", ['invoice' => $invoice]);
                }
            });

            return $invoice;
        });
    }
}
```

### 2. Reacções a eventos

```php
// Após qualquer commit que dispare 'invoice.paid':
$reaction->react(
    new ReactionContext(event: 'invoice.paid', payload: $invoice->toArray()),
    [
        ReactionDefinition::fromArray([
            'event'   => 'invoice.paid',
            'handler' => 'notify',
            'async'   => true,
            'params'  => ['template_key' => 'invoice_paid', 'recipient_path' => 'client.email'],
        ]),
        ReactionDefinition::fromArray([
            'event'   => 'invoice.paid',
            'handler' => 'dispatch_job',
            'async'   => true,
            'only_if' => 'invoice.total > 5000',
            'params'  => ['job' => 'App\Jobs\NotifyAccountingJob'],
        ]),
    ]
);
```

### 3. Chat com RAG

```php
$response = $chat->chat(new ChatMessage(
    content:   'Qual é o prazo de entrega para encomendas internacionais?',
    sessionId: "user-{$user->id}-support",
    persona:   'support',
    user:      $user,
));

echo $response->answer;   // resposta com citações inline [Source id#0]
foreach ($response->sources as $source) {
    echo $source['excerpt']; // texto do chunk citado
}
```

---

## Princípios de design

### Contratos primeiro

A tua aplicação injeta sempre a interface, nunca a classe concreta:

```php
// ✅ Correcto
public function __construct(private readonly ConstraintEngineInterface $engine) {}

// ❌ Errado — acopla ao driver específico
public function __construct(private readonly ConstraintEngine $engine) {}
```

### Zero persistência nas engines

Nenhuma engine chama `Model::save()` ou `DB::table()->insert()`. As engines retornam dados; as tuas Actions persistem.

Excepções deliberadas e documentadas: `ConstraintCheckers` (queries de leitura), `UpdateFieldHandler` (campo denormalizado), `DocumentChunkStore` (vectores para RAG).

### Stateless e determinístico

Mesmo input → mesmo output. Sem estado interno entre chamadas. Escalável horizontalmente sem partilha de estado.

### Erros estruturados — nunca exceptions genéricas

```php
// As engines retornam Value Objects de erro:
$result->passed          // bool
$result->firstMessage()  // ?string
$result->hasDenials()    // bool

// A tua Action decide se lança exception:
if (! $result->passed) {
    throw new ConstraintException($result->firstMessage());
}
```

### Testabilidade sem framework

```php
// Qualquer engine testável em milissegundos, sem bootstrap:
$engine = new BusinessRuleEngine(new RuleEvaluatorRegistry());
$result = $engine->evaluate(new RuleContext(data: ['total' => 5]), $rules);
expect($result->hasDenials())->toBeTrue();
```

---

## Visualização de workflows

```bash
# Exportar todos os workflows como Mermaid:
php artisan engines:graph

# Exportar para ficheiro SVG via Graphviz:
php artisan engines:graph --format=dot --output=storage/diagrams/workflows.dot
dot -Tsvg storage/diagrams/workflows.dot -o storage/diagrams/workflows.svg

# Exportar só um workflow:
php artisan engines:graph --entity="App\\Models\\Order"
```

---

## PHPStan nível 8

```bash
composer stan           # análise completa
composer stan:baseline  # gerar baseline para erros existentes
composer ci             # format + stan + test
```

---

## Testes

```bash
composer test
./vendor/bin/pest packages/core/tests/
./vendor/bin/pest packages/extended/tests/
./vendor/bin/pest packages/core/tests/Integration/     # integração com SQLite
./vendor/bin/pest packages/extended/tests/Integration/ # integração ExtendedServiceProvider
```

**190+ testes** cobrindo todas as 13 engines, incluindo testes de integração com SQLite in-memory que validam os ServiceProviders sem uma aplicação Laravel completa.

---

## Comparação com alternativas

| Funcionalidade | ExpressCodeEngine | Laravel nativo | Spatie packages | Custom |
|---|---|---|---|---|
| Regras de negócio declarativas | ✅ BRE com prioridade e efeitos | ❌ | ❌ | ⚠️ Reinventado |
| Máquinas de estado | ✅ WorkflowEngine com guards | ❌ | ✅ model-states | ⚠️ Reinventado |
| Notificações multi-canal + templates | ✅ com rate limiting | ✅ básico | ✅ | ⚠️ Fragmentado |
| Busca multi-driver | ✅ DB / Meilisearch / Algolia | ✅ Scout | ❌ | ⚠️ Fragmentado |
| Integrações externas + circuit breaker | ✅ | ❌ | ❌ | ⚠️ Reinventado |
| IA com fallback chain | ✅ 6 providers | ❌ | ❌ | ⚠️ Reinventado |
| RAG com policy filtering | ✅ | ❌ | ❌ | ❌ |
| Tudo testável sem framework | ✅ | ❌ | ⚠️ Parcial | ⚠️ Depende |
| Configuração declarativa | ✅ arrays/DTOs | ❌ | ⚠️ Parcial | ❌ |

---

## Requisitos

- PHP 8.2+
- Laravel 10.x ou 11.x
- PostgreSQL 14+ ou MySQL 9.0+ (apenas para vector stores)
- Redis (apenas para embedding cache com tags)

---

## Licença

MIT License — ver [LICENSE](LICENSE).

---

## Guia de implementação

Para um guia completo engine-a-engine com exemplos reais, padrões de erro, e casos de uso por domínio, ver **[IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md)**.
