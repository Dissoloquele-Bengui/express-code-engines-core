# ExpressCodeEngine — Configuração de Regras: config/ vs Base de Dados

Guia completo para definir regras das engines via ficheiros de configuração PHP e via base de dados, com esquemas SQL, migrations, repositórios, e exemplos lado-a-lado.

---

## Índice

1. [Quando usar config/ vs BD](#1-quando-usar-config-vs-bd)
2. [Esquema completo da base de dados](#2-esquema-completo-da-base-de-dados)
3. [BusinessRuleEngine — regras](#3-businessruleengine--regras)
4. [WorkflowEngine — transições](#4-workflowengine--transições)
5. [DynamicPolicyEngine — políticas de acesso](#5-dynamicpolicyengine--políticas-de-acesso)
6. [ReactionEngine — definições de reacção](#6-reactionengine--definições-de-reacção)
7. [NotificationEngine — templates](#7-notificationengine--templates)
8. [IntegrationEngine — integrações externas](#8-integrationengine--integrações-externas)
9. [OrchestrationEngine — fluxos](#9-orchestrationengine--fluxos)
10. [Loader universal com cache](#10-loader-universal-com-cache)
11. [Modo híbrido — config/ com overrides em BD](#11-modo-híbrido--config-com-overrides-em-bd)
12. [Interface de administração — considerações](#12-interface-de-administração--considerações)

---

## 1. Quando usar config/ vs BD

| Critério | config/ | Base de dados |
|---|---|---|
| Quem edita | Developers | Utilizadores de negócio / administradores |
| Frequência de mudança | Raro (deploy) | Frequente (self-service) |
| Versionamento | Git nativo | Precisa de auditoria própria |
| Validação | PHP + PHPStan | À medida (validation layer) |
| Multi-tenant | Partilhado | Regras por tenant |
| Performance | Zero overhead | Requer cache |
| Rollback | `git revert` | Precisa de soft-delete + histórico |

**Regra prática:**

- **config/** para regras que definem o comportamento base do sistema e que só mudam com um deploy (workflows de negócio estruturais, políticas de acesso base, integrações externas)
- **BD** para regras que utilizadores de negócio precisam de ajustar sem deploy (regras de preços, limites por tenant, templates de notificação, reacções customizadas por cliente)
- **Híbrido** para a maioria dos sistemas reais: config/ define o esqueleto, BD sobrepõe por tenant

---

## 2. Esquema completo da base de dados

### Migration consolidada

```php
<?php
// database/migrations/xxxx_create_engine_rules_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────
        // BusinessRuleEngine
        // ──────────────────────────────────────────────

        Schema::create('engine_business_rules', function (Blueprint $table) {
            $table->id();

            // Agrupamento — a que entidade/contexto esta regra pertence
            // e.g. 'App\Models\Order', 'invoicing', 'checkout'
            $table->string('rule_set');

            // Escopo multi-tenant (null = global, aplica a todos os tenants)
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            // Campos do RuleDefinition DTO
            $table->string('rule_id');         // identificador único dentro do rule_set
            $table->string('rule_type');       // comparison | range | in_list | regex | custom_callable
            $table->string('field');           // dot-path: 'order.total', 'client.status'
            $table->json('params');            // parâmetros específicos do rule_type
            $table->string('severity')->default('deny'); // deny | warn | allow
            $table->integer('priority')->default(0);
            $table->string('message');
            $table->json('effects')->nullable(); // só para severity='allow'
            $table->string('only_if')->nullable(); // condição de activação: "field op value"

            // Ciclo de vida
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('active_from')->nullable();  // activar em data futura
            $table->timestamp('active_until')->nullable(); // expirar automaticamente

            // Auditoria
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->text('change_reason')->nullable(); // motivo da alteração
            $table->timestamps();
            $table->softDeletes();

            $table->index(['rule_set', 'tenant_id', 'is_active']);
            $table->unique(['rule_set', 'rule_id', 'tenant_id'], 'engine_rules_unique_id_per_tenant');
        });

        // Histórico de alterações às regras (auditoria)
        Schema::create('engine_business_rules_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->string('action'); // created | updated | deleted | activated | deactivated
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at');

            $table->index(['rule_id', 'changed_at']);
        });

        // ──────────────────────────────────────────────
        // WorkflowEngine
        // ──────────────────────────────────────────────

        Schema::create('engine_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('entity_class');           // 'App\Models\Order'
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->json('initial_states');           // ['draft']
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_class', 'tenant_id'], 'engine_workflows_entity_tenant');
        });

        Schema::create('engine_workflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('engine_workflows')->cascadeOnDelete();

            $table->string('from_state');             // estado de origem
            $table->string('to_state');               // estado de destino
            $table->json('guards')->nullable();        // ['role:manager', 'field:locked:false']
            $table->json('post_actions')->nullable();  // ['notify_reviewer', 'log_transition']
            $table->json('metadata')->nullable();      // {'label': 'Aprovar', 'icon': 'check'}

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0); // ordem de exibição na UI

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workflow_id', 'from_state', 'is_active']);
        });

        // ──────────────────────────────────────────────
        // DynamicPolicyEngine
        // ──────────────────────────────────────────────

        Schema::create('engine_policies', function (Blueprint $table) {
            $table->id();
            $table->string('entity_class');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('default_effect')->default('deny'); // allow | deny
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_class', 'tenant_id'], 'engine_policies_entity_tenant');
        });

        Schema::create('engine_policy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('engine_policies')->cascadeOnDelete();

            $table->string('rule_id');                // identificador único dentro da política
            $table->string('ability');                // view | update | delete | * (todos)
            $table->string('effect');                 // allow | deny
            $table->text('condition')->nullable();    // 'record.tenant_id == user.tenant_id'
            $table->json('bypass_roles')->nullable(); // ['admin', 'super_admin']
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();  // documentação da regra

            $table->timestamps();
            $table->softDeletes();

            $table->index(['policy_id', 'ability', 'is_active']);
        });

        // ──────────────────────────────────────────────
        // ReactionEngine
        // ──────────────────────────────────────────────

        Schema::create('engine_reactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            // Campos do ReactionDefinition DTO
            $table->string('event');              // 'order.submitted', 'order.*'
            $table->string('handler');            // 'notify', 'dispatch_job', 'update_field'
            $table->json('params');               // parâmetros específicos do handler
            $table->string('only_if')->nullable(); // condição: "order.total > 1000"
            $table->boolean('async')->default(true);
            $table->string('queue')->nullable();   // fila específica
            $table->integer('delay_seconds')->default(0);

            // Agrupamento (opcional — para organizar por módulo/entidade)
            $table->string('group')->nullable()->index(); // 'orders', 'invoices'

            // Ciclo de vida
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['event', 'tenant_id', 'is_active']);
        });

        // ──────────────────────────────────────────────
        // NotificationEngine — templates
        // ──────────────────────────────────────────────

        Schema::create('engine_notification_templates', function (Blueprint $table) {
            $table->id();

            // Chave do template — referenciada em NotificationRequest e ReactionDefinition
            $table->string('template_key');
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            // Campos do NotificationTemplate DTO
            $table->json('channels');             // ['mail', 'database', 'slack']
            $table->string('subject')->nullable(); // 'Encomenda #{{order.reference}} recebida'
            $table->longText('body')->nullable();  // corpo ou 'view:emails.order_submitted'
            $table->json('defaults')->nullable();  // dados padrão

            // Localização (opcional — override por locale)
            $table->string('locale')->nullable()->default(null); // null = default, 'pt', 'en'

            // Ciclo de vida
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['template_key', 'tenant_id', 'locale'], 'engine_notif_template_unique');
        });

        // ──────────────────────────────────────────────
        // IntegrationEngine — definições de integração
        // ──────────────────────────────────────────────

        Schema::create('engine_integrations', function (Blueprint $table) {
            $table->id();

            $table->string('integration_key');  // 'stripe_payment', 'erp_sync'
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            // Campos do IntegrationDefinition DTO
            $table->string('url');
            $table->string('method')->default('POST'); // GET | POST | PUT | PATCH | DELETE
            $table->json('headers')->nullable();        // headers estáticos
            $table->json('auth')->nullable();           // {type: bearer, token_env: STRIPE_KEY}
            $table->json('retry')->nullable();          // {attempts: 3, backoff: [1,5,15]}
            $table->integer('timeout')->default(30);
            $table->json('mapping')->nullable();        // {'order.total': 'amount'}
            $table->boolean('async')->default(true);

            // NUNCA guardar credenciais em texto simples
            // Usa sempre env vars ou um serviço de secrets
            $table->string('credentials_reference')->nullable(); // e.g. 'vault:stripe/production'

            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['integration_key', 'tenant_id'], 'engine_integrations_key_tenant');
        });

        // ──────────────────────────────────────────────
        // OrchestrationEngine — fluxos e steps
        // ──────────────────────────────────────────────

        Schema::create('engine_flows', function (Blueprint $table) {
            $table->id();

            $table->string('flow_key');           // 'process_checkout', 'onboard_client'
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->boolean('compensate_on_failure')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['flow_key', 'tenant_id'], 'engine_flows_key_tenant');
        });

        Schema::create('engine_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('engine_flows')->cascadeOnDelete();

            $table->string('step_id');               // identificador do step no fluxo
            $table->string('handler');               // FQCN do step handler
            $table->string('compensation')->nullable(); // FQCN do handler de compensação
            $table->json('input')->nullable();         // mapeamento de input: {key: 'payload.path'}
            $table->boolean('continue_on_failure')->default(false);
            $table->integer('retries')->default(0);
            $table->integer('sort_order')->default(0); // ordem de execução

            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['flow_id', 'sort_order', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_flow_steps');
        Schema::dropIfExists('engine_flows');
        Schema::dropIfExists('engine_integrations');
        Schema::dropIfExists('engine_notification_templates');
        Schema::dropIfExists('engine_reactions');
        Schema::dropIfExists('engine_policy_rules');
        Schema::dropIfExists('engine_policies');
        Schema::dropIfExists('engine_workflow_transitions');
        Schema::dropIfExists('engine_workflows');
        Schema::dropIfExists('engine_business_rules_history');
        Schema::dropIfExists('engine_business_rules');
    }
};
```

---

## 3. BusinessRuleEngine — regras

### Via config/engines.php

```php
// config/engines.php
return [
    'rule_sets' => [

        'App\Models\Order' => [
            [
                'id'       => 'min_order_value',
                'rule_type'=> 'comparison',
                'field'    => 'order.total',
                'params'   => ['operator' => '>=', 'value' => 10.00],
                'severity' => 'deny',
                'priority' => 100,
                'message'  => 'Valor mínimo de encomenda é €10,00.',
            ],
            [
                'id'       => 'blocked_client',
                'rule_type'=> 'in_list',
                'field'    => 'client.status',
                'params'   => ['values' => ['blocked', 'suspended']],
                'severity' => 'deny',
                'priority' => 200,
                'message'  => 'Cliente bloqueado. Contacte o suporte.',
            ],
            [
                'id'       => 'large_order_flag',
                'rule_type'=> 'comparison',
                'field'    => 'order.total',
                'params'   => ['operator' => '>', 'value' => 5000.00],
                'severity' => 'allow',
                'priority' => 50,
                'message'  => 'Encomenda grande — aprovação de gestor aprovada.',
                'effects'  => ['require_manager_approval'],
                'only_if'  => "order.type == 'b2b'",
            ],
        ],

    ],
];
```

### Via base de dados — dados de exemplo

```sql
-- Inserir as mesmas regras na BD
INSERT INTO engine_business_rules
    (rule_set, tenant_id, rule_id, rule_type, field, params, severity, priority, message, effects, only_if, is_active)
VALUES
    -- Regra global (tenant_id NULL = aplica a todos)
    (
        'App\Models\Order', NULL,
        'min_order_value', 'comparison', 'order.total',
        '{"operator":">=","value":10.00}',
        'deny', 100, 'Valor mínimo de encomenda é €10,00.',
        NULL, NULL, TRUE
    ),
    -- Override para tenant 5 (limite diferente)
    (
        'App\Models\Order', 5,
        'min_order_value', 'comparison', 'order.total',
        '{"operator":">=","value":50.00}',  -- tenant 5 tem mínimo de €50
        'deny', 100, 'Valor mínimo para este cliente é €50,00.',
        NULL, NULL, TRUE
    ),
    -- Regra de cliente bloqueado — global
    (
        'App\Models\Order', NULL,
        'blocked_client', 'in_list', 'client.status',
        '{"values":["blocked","suspended"]}',
        'deny', 200, 'Cliente bloqueado. Contacte o suporte.',
        NULL, NULL, TRUE
    ),
    -- Regra com efeito e onlyIf
    (
        'App\Models\Order', NULL,
        'large_order_flag', 'comparison', 'order.total',
        '{"operator":">","value":5000.00}',
        'allow', 50, 'Encomenda grande — aprovação de gestor aprovada.',
        '["require_manager_approval"]',
        'order.type == ''b2b''',
        TRUE
    );
```

### Loader — ler da BD e construir DTOs

```php
<?php

namespace App\Engine\Loaders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\DTOs\RuleDefinition;

final class BusinessRuleLoader
{
    public function __construct(
        private readonly int $cacheTtl = 300, // 5 minutos
    ) {}

    /**
     * Carrega regras para um rule_set e tenant.
     * Merge de regras globais (tenant_id NULL) com override do tenant (mais específico ganha).
     *
     * @return RuleDefinition[]
     */
    public function load(string $ruleSet, ?int $tenantId = null): array
    {
        $cacheKey = "engine.rules.{$ruleSet}." . ($tenantId ?? 'global');

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($ruleSet, $tenantId) {
            return $this->fetchAndMerge($ruleSet, $tenantId);
        });
    }

    /**
     * Invalida o cache para um rule_set e tenant específico.
     * Chamar após qualquer alteração às regras na BD.
     */
    public function invalidate(string $ruleSet, ?int $tenantId = null): void
    {
        Cache::forget("engine.rules.{$ruleSet}." . ($tenantId ?? 'global'));
        Cache::forget("engine.rules.{$ruleSet}.global");
    }

    private function fetchAndMerge(string $ruleSet, ?int $tenantId): array
    {
        $now = now();

        // 1. Regras globais (tenant_id NULL, activas, dentro do período de vigência)
        $globalRows = DB::table('engine_business_rules')
            ->where('rule_set', $ruleSet)
            ->whereNull('tenant_id')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('active_from')->orWhere('active_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('active_until')->orWhere('active_until', '>', $now))
            ->whereNull('deleted_at')
            ->orderByDesc('priority')
            ->get()
            ->keyBy('rule_id');

        // 2. Regras específicas do tenant (sobrepõem as globais pelo mesmo rule_id)
        $tenantRows = $tenantId
            ? DB::table('engine_business_rules')
                ->where('rule_set', $ruleSet)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('active_from')->orWhere('active_from', '<=', $now))
                ->where(fn ($q) => $q->whereNull('active_until')->orWhere('active_until', '>', $now))
                ->whereNull('deleted_at')
                ->orderByDesc('priority')
                ->get()
                ->keyBy('rule_id')
            : collect();

        // 3. Merge: tenant override ganha sobre global
        $merged = $globalRows->merge($tenantRows);

        return $merged
            ->values()
            ->map(fn (object $row) => new RuleDefinition(
                id:       $row->rule_id,
                ruleType: $row->rule_type,
                field:    $row->field,
                params:   json_decode($row->params, true),
                severity: $row->severity,
                priority: (int) $row->priority,
                message:  $row->message,
                effects:  json_decode($row->effects ?? '[]', true),
                onlyIf:   $row->only_if,
            ))
            ->all();
    }
}
```

### Uso na Action

```php
// Via config/ (estático):
$rules = array_map(
    fn ($r) => new RuleDefinition(...$r),
    config('engines.rule_sets.App\Models\Order', [])
);

// Via BD (dinâmico):
$rules = $this->ruleLoader->load('App\Models\Order', $user->tenant_id);

// A chamada ao engine é idêntica nos dois casos:
$response = $this->bre->evaluate($ruleContext, $rules);
```

---

## 4. WorkflowEngine — transições

### Via config/engines.php

```php
return [
    'workflows' => [
        'App\Models\Order' => [
            'initial_states' => ['draft'],
            'transitions'    => [
                ['from' => 'draft',     'to' => 'submitted',
                 'post_actions' => ['notify_reviewer']],
                ['from' => 'submitted', 'to' => 'approved',
                 'guards' => ['role:manager'], 'post_actions' => ['notify_client']],
                ['from' => 'submitted', 'to' => 'rejected',
                 'guards' => ['role:manager']],
            ],
        ],
    ],
];
```

### Via base de dados — dados de exemplo

```sql
-- Workflow global para Order
INSERT INTO engine_workflows (entity_class, tenant_id, initial_states)
VALUES ('App\Models\Order', NULL, '["draft"]');

-- Transições
INSERT INTO engine_workflow_transitions
    (workflow_id, from_state, to_state, guards, post_actions, metadata, sort_order)
VALUES
    (1, 'draft',     'submitted', NULL,                     '["notify_reviewer"]',  '{"label":"Submeter"}',  1),
    (1, 'submitted', 'approved',  '["role:manager"]',       '["notify_client"]',    '{"label":"Aprovar"}',   2),
    (1, 'submitted', 'rejected',  '["role:manager"]',       NULL,                   '{"label":"Rejeitar"}',  3),
    (1, 'approved',  'cancelled', NULL,                     '["refund_payment"]',   '{"label":"Cancelar"}',  4);

-- Override para tenant 7 — tem um step adicional de compliance
INSERT INTO engine_workflows (entity_class, tenant_id, initial_states)
VALUES ('App\Models\Order', 7, '["draft"]');

INSERT INTO engine_workflow_transitions
    (workflow_id, from_state, to_state, guards, post_actions, sort_order)
VALUES
    (2, 'draft',      'submitted',         NULL,                         '["notify_reviewer"]',     1),
    (2, 'submitted',  'compliance_review', '["role:manager"]',           '["notify_compliance"]',   2),
    (2, 'compliance_review', 'approved',   '["role:compliance_officer"]','["notify_client"]',       3),
    (2, 'compliance_review', 'rejected',   '["role:compliance_officer"]',NULL,                      4);
```

### Loader

```php
<?php

namespace App\Engine\Loaders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\DTOs\TransitionDefinition;
use ExpressCodeEngines\Shared\DTOs\WorkflowDefinition;

final class WorkflowLoader
{
    public function load(string $entityClass, ?int $tenantId = null): ?WorkflowDefinition
    {
        $cacheKey = "engine.workflow.{$entityClass}." . ($tenantId ?? 'global');

        return Cache::remember($cacheKey, 600, function () use ($entityClass, $tenantId) {
            return $this->fetch($entityClass, $tenantId);
        });
    }

    public function invalidate(string $entityClass, ?int $tenantId = null): void
    {
        Cache::forget("engine.workflow.{$entityClass}." . ($tenantId ?? 'global'));
    }

    private function fetch(string $entityClass, ?int $tenantId): ?WorkflowDefinition
    {
        // Prefere o workflow do tenant, recua para o global
        $workflow = DB::table('engine_workflows')
            ->where('entity_class', $entityClass)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->orderByRaw('tenant_id IS NULL ASC') // tenant-specific primeiro
            ->first();

        if (! $workflow) {
            return null;
        }

        $transitions = DB::table('engine_workflow_transitions')
            ->where('workflow_id', $workflow->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (object $row) => new TransitionDefinition(
                from:        $row->from_state,
                to:          $row->to_state,
                guards:      json_decode($row->guards       ?? '[]', true),
                postActions: json_decode($row->post_actions ?? '[]', true),
                metadata:    json_decode($row->metadata     ?? '{}', true),
            ))
            ->all();

        return new WorkflowDefinition(
            entityClass:   $entityClass,
            transitions:   $transitions,
            initialStates: json_decode($workflow->initial_states, true),
        );
    }
}
```

### Uso

```php
// Via config/ — construção directa:
$definition = WorkflowDefinition::fromArray(Order::class, config('engines.workflows.App\Models\Order'));
$engine     = new WorkflowEngine(definitions: [Order::class => $definition], guards: [...]);

// Via BD — o WorkflowEngine recebe a definition no momento da chamada:
$definition = $this->workflowLoader->load(Order::class, $user->tenant_id);
if (! $definition) throw new ConfigurationException('Workflow não configurado para Order.');

$engine = new WorkflowEngine(definitions: [Order::class => $definition], guards: [...]);
// OU — se a engine for singleton no container, injectar dinamicamente:
$result = $engine->transition(new TransitionRequest(
    entityClass:  Order::class,
    currentState: $order->status,
    transition:   'approved',
    data:         $order->toArray(),
    user:         $user,
));
```

---

## 5. DynamicPolicyEngine — políticas de acesso

### Via config/engines.php

```php
return [
    'policies' => [
        'App\Models\Order' => [
            'default_effect' => 'deny',
            'rules' => [
                ['id' => 'admin_bypass', 'ability' => '*',     'effect' => 'allow',
                 'condition' => null, 'bypass_roles' => ['admin'], 'priority' => 100],
                ['id' => 'tenant_view',  'ability' => 'view',  'effect' => 'allow',
                 'condition' => 'record.tenant_id == user.tenant_id', 'priority' => 50],
                ['id' => 'own_update',   'ability' => 'update','effect' => 'allow',
                 'condition' => 'record.created_by == user.id', 'priority' => 40],
            ],
        ],
    ],
];
```

### Via base de dados — dados de exemplo

```sql
INSERT INTO engine_policies (entity_class, tenant_id, default_effect)
VALUES ('App\Models\Order', NULL, 'deny');

-- id=1 para o policy global
INSERT INTO engine_policy_rules
    (policy_id, rule_id, ability, effect, condition, bypass_roles, priority, description)
VALUES
    (1, 'admin_bypass', '*',      'allow', NULL,
     '["admin","super_admin"]', 100, 'Admins têm acesso total'),
    (1, 'tenant_view',  'view',   'allow',
     'record.tenant_id == user.tenant_id', NULL, 50, 'Isolamento por tenant'),
    (1, 'own_update',   'update', 'allow',
     'record.created_by == user.id',       NULL, 40, 'Criador pode editar'),
    (1, 'no_deleted',   'update', 'deny',
     'record.status == deleted',            NULL, 90, 'Registos eliminados não editáveis');
```

### Loader

```php
<?php

namespace App\Engine\Loaders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\DTOs\PolicyDefinition;
use ExpressCodeEngines\Shared\DTOs\PolicyRule;

final class PolicyLoader
{
    public function load(string $entityClass, ?int $tenantId = null): ?PolicyDefinition
    {
        $cacheKey = "engine.policy.{$entityClass}." . ($tenantId ?? 'global');

        return Cache::remember($cacheKey, 300, function () use ($entityClass, $tenantId) {
            return $this->fetch($entityClass, $tenantId);
        });
    }

    public function invalidate(string $entityClass, ?int $tenantId = null): void
    {
        Cache::forget("engine.policy.{$entityClass}." . ($tenantId ?? 'global'));
        Cache::forget("engine.policy.{$entityClass}.global");
    }

    private function fetch(string $entityClass, ?int $tenantId): ?PolicyDefinition
    {
        $policy = DB::table('engine_policies')
            ->where('entity_class', $entityClass)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->orderByRaw('tenant_id IS NULL ASC')
            ->first();

        if (! $policy) return null;

        $rules = DB::table('engine_policy_rules')
            ->where('policy_id', $policy->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderByDesc('priority')
            ->get()
            ->map(fn (object $row) => new PolicyRule(
                id:          $row->rule_id,
                ability:     $row->ability,
                effect:      $row->effect,
                condition:   $row->condition,
                bypassRoles: json_decode($row->bypass_roles ?? '[]', true),
                priority:    (int) $row->priority,
            ))
            ->all();

        return new PolicyDefinition(
            entityClass:   $entityClass,
            rules:         $rules,
            defaultEffect: $policy->default_effect,
        );
    }
}
```

---

## 6. ReactionEngine — definições de reacção

### Via config/engines/extended.php

```php
'reactions' => [
    'definitions' => [
        [
            'event'   => 'order.submitted',
            'handler' => 'notify',
            'async'   => true,
            'params'  => ['template_key' => 'order_submitted', 'recipient_path' => 'customer.email'],
        ],
        [
            'event'   => 'order.*',
            'handler' => 'dispatch_job',
            'async'   => true,
            'params'  => ['job' => 'App\Jobs\AuditOrderJob'],
        ],
    ],
],
```

### Via base de dados — dados de exemplo

```sql
INSERT INTO engine_reactions
    (tenant_id, event, handler, params, only_if, async, queue, description, is_active)
VALUES
    -- Global: notificar sempre ao submeter
    (NULL, 'order.submitted', 'notify',
     '{"template_key":"order_submitted","recipient_path":"customer.email"}',
     NULL, TRUE, 'notifications', 'Notificação de submissão de encomenda', TRUE),

    -- Só para encomendas > €5000 (qualquer tenant)
    (NULL, 'order.submitted', 'notify',
     '{"template_key":"large_order_alert","recipient_path":"manager.email","channels":["slack","mail"]}',
     'order.total > 5000', TRUE, 'notifications', 'Alerta de encomenda grande', TRUE),

    -- Override do tenant 3: também notifica via Slack o seu canal específico
    (3, 'order.submitted', 'notify',
     '{"template_key":"order_submitted_slack","recipient_path":"tenant.slack_webhook"}',
     NULL, TRUE, 'notifications', 'Notificação Slack específica do tenant 3', TRUE),

    -- Auditoria global para todos os eventos de order
    (NULL, 'order.*', 'dispatch_job',
     '{"job":"App\\Jobs\\AuditOrderEventJob"}',
     NULL, TRUE, 'audit', 'Auditoria de eventos de encomenda', TRUE);
```

### Loader

```php
<?php

namespace App\Engine\Loaders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\DTOs\ReactionDefinition;

final class ReactionLoader
{
    /**
     * Carrega definições para um evento e tenant.
     * Combina definições globais + específicas do tenant.
     *
     * @return ReactionDefinition[]
     */
    public function load(string $event, ?int $tenantId = null): array
    {
        $cacheKey = "engine.reactions.{$event}." . ($tenantId ?? 'global');

        return Cache::remember($cacheKey, 120, function () use ($event, $tenantId) {
            return $this->fetch($event, $tenantId);
        });
    }

    /**
     * Carrega TODAS as definições para um tenant (para eventos com wildcard).
     * Mais eficiente do que carregar evento-a-evento.
     *
     * @return ReactionDefinition[]
     */
    public function loadAll(?int $tenantId = null): array
    {
        $cacheKey = "engine.reactions.all." . ($tenantId ?? 'global');

        return Cache::remember($cacheKey, 120, function () use ($tenantId) {
            return $this->fetchAll($tenantId);
        });
    }

    public function invalidate(?int $tenantId = null): void
    {
        Cache::forget("engine.reactions.all." . ($tenantId ?? 'global'));
        // Para invalidação por evento, precisaria de guardar os keys no cache
        // — para simplicidade, invalida tudo:
        Cache::flush(); // em produção, usar tags Redis
    }

    private function fetch(string $event, ?int $tenantId): array
    {
        $rows = DB::table('engine_reactions')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->where(function ($q) use ($event) {
                // Corresponde ao evento exacto ou ao wildcard
                $q->where('event', $event)
                  ->orWhere('event', 'like', explode('.', $event)[0] . '.*');
            })
            ->orderBy('sort_order')
            ->get();

        return $this->toDefinitions($rows);
    }

    private function fetchAll(?int $tenantId): array
    {
        $rows = DB::table('engine_reactions')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->orderBy('sort_order')
            ->get();

        return $this->toDefinitions($rows);
    }

    private function toDefinitions(\Illuminate\Support\Collection $rows): array
    {
        return $rows->map(fn (object $row) => new ReactionDefinition(
            event:        $row->event,
            handler:      $row->handler,
            params:       json_decode($row->params, true),
            onlyIf:       $row->only_if,
            async:        (bool) $row->async,
            queue:        $row->queue,
            delaySeconds: (int) ($row->delay_seconds ?? 0),
        ))->all();
    }
}
```

---

## 7. NotificationEngine — templates

### Via config/engines/extended.php

```php
'notifications' => [
    'templates' => [
        'order_submitted' => [
            'channels' => ['mail', 'database'],
            'subject'  => 'Encomenda #{{order.reference}} recebida',
            'body'     => "Olá {{customer.name}},\n\nA sua encomenda foi recebida.",
            'defaults' => [],
        ],
    ],
],
```

### Loader com locale e fallback

```php
<?php

namespace App\Engine\Loaders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\DTOs\NotificationTemplate;
use ExpressCodeEngines\Extended\NotificationEngine\TemplateRegistry;

final class NotificationTemplateLoader
{
    /**
     * Constrói um TemplateRegistry lendo da BD.
     * Merge: templates da BD sobrepõem os da config/
     */
    public function buildRegistry(?int $tenantId = null, ?string $locale = null): TemplateRegistry
    {
        $cacheKey = "engine.notif.templates.{$tenantId}.{$locale}";

        $templates = Cache::remember($cacheKey, 300, function () use ($tenantId, $locale) {
            return $this->loadAll($tenantId, $locale);
        });

        $registry = new TemplateRegistry(config('engines.extended.notifications.templates', []));

        foreach ($templates as $template) {
            $registry->register($template); // sobrepõe o da config se existir
        }

        return $registry;
    }

    public function invalidate(?int $tenantId = null, ?string $locale = null): void
    {
        Cache::forget("engine.notif.templates.{$tenantId}.{$locale}");
    }

    private function loadAll(?int $tenantId, ?string $locale): array
    {
        // Ordem de preferência: tenant+locale > tenant+null > global+locale > global+null
        $rows = DB::table('engine_notification_templates')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            })
            ->where(function ($q) use ($locale) {
                $q->whereNull('locale')->orWhere('locale', $locale);
            })
            ->orderByRaw('
                CASE
                    WHEN tenant_id IS NOT NULL AND locale IS NOT NULL THEN 1
                    WHEN tenant_id IS NOT NULL AND locale IS NULL THEN 2
                    WHEN tenant_id IS NULL AND locale IS NOT NULL THEN 3
                    ELSE 4
                END ASC
            ')
            ->get()
            ->keyBy('template_key') // mais específico ganha
            ->values();

        return $rows->map(fn (object $row) => new NotificationTemplate(
            key:      $row->template_key,
            channels: json_decode($row->channels, true),
            subject:  $row->subject,
            body:     $row->body,
            defaults: json_decode($row->defaults ?? '{}', true),
        ))->all();
    }
}
```

---

## 8. IntegrationEngine — integrações externas

### Loader com segurança de credenciais

```php
<?php

namespace App\Engine\Loaders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Extended\IntegrationEngine\IntegrationRegistry;

final class IntegrationLoader
{
    public function buildRegistry(?int $tenantId = null): IntegrationRegistry
    {
        $cacheKey = "engine.integrations." . ($tenantId ?? 'global');

        $rows = Cache::remember($cacheKey, 600, function () use ($tenantId) {
            return DB::table('engine_integrations')
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($tenantId) {
                    $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
                })
                ->orderByRaw('tenant_id IS NULL ASC')
                ->get()
                ->keyBy('integration_key');
        });

        $config = [];

        foreach ($rows as $key => $row) {
            $auth = json_decode($row->auth ?? '{}', true);

            // NUNCA armazenar credenciais em texto na BD
            // Resolver a credencial do ambiente usando o env var reference
            if (isset($auth['token_env'])) {
                $auth['token'] = env($auth['token_env']);
                unset($auth['token_env']); // não passar o nome do env para a engine
            }

            $config[$key] = [
                'url'     => $row->url,
                'method'  => $row->method,
                'headers' => json_decode($row->headers  ?? '{}', true),
                'auth'    => $auth,
                'retry'   => json_decode($row->retry    ?? '{}', true),
                'timeout' => $row->timeout,
                'mapping' => json_decode($row->mapping  ?? '{}', true),
                'async'   => (bool) $row->async,
            ];
        }

        // Merge com config/ (config/ tem precedência para integrações base)
        $baseConfig = config('engines.extended.integrations', []);

        return new IntegrationRegistry(array_merge($baseConfig, $config));
    }
}
```

---

## 9. OrchestrationEngine — fluxos

### Loader

```php
<?php

namespace App\Engine\Loaders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\DTOs\OrchestrationDefinition;
use ExpressCodeEngines\Shared\DTOs\OrchestrationStep;

final class OrchestrationLoader
{
    public function load(string $flowKey, ?int $tenantId = null): ?OrchestrationDefinition
    {
        $cacheKey = "engine.flow.{$flowKey}." . ($tenantId ?? 'global');

        return Cache::remember($cacheKey, 600, function () use ($flowKey, $tenantId) {
            return $this->fetch($flowKey, $tenantId);
        });
    }

    private function fetch(string $flowKey, ?int $tenantId): ?OrchestrationDefinition
    {
        $flow = DB::table('engine_flows')
            ->where('flow_key', $flowKey)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            })
            ->orderByRaw('tenant_id IS NULL ASC')
            ->first();

        if (! $flow) return null;

        $steps = DB::table('engine_flow_steps')
            ->where('flow_id', $flow->id)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (object $row) => new OrchestrationStep(
                id:                 $row->step_id,
                handler:            $row->handler,
                compensation:       $row->compensation,
                input:              json_decode($row->input ?? '{}', true),
                continueOnFailure:  (bool) $row->continue_on_failure,
                retries:            (int) $row->retries,
            ))
            ->all();

        return new OrchestrationDefinition(
            flowKey:            $flow->flow_key,
            steps:              $steps,
            compensateOnFailure: (bool) $flow->compensate_on_failure,
        );
    }
}
```

---

## 10. Loader universal com cache

Para simplificar o uso na aplicação, um facade que agrega todos os loaders:

```php
<?php

namespace App\Engine;

use App\Engine\Loaders\BusinessRuleLoader;
use App\Engine\Loaders\NotificationTemplateLoader;
use App\Engine\Loaders\OrchestrationLoader;
use App\Engine\Loaders\PolicyLoader;
use App\Engine\Loaders\ReactionLoader;
use App\Engine\Loaders\WorkflowLoader;

final class EngineDefinitions
{
    public function __construct(
        private readonly BusinessRuleLoader          $rules,
        private readonly WorkflowLoader              $workflows,
        private readonly PolicyLoader                $policies,
        private readonly ReactionLoader              $reactions,
        private readonly NotificationTemplateLoader  $templates,
        private readonly OrchestrationLoader         $flows,
    ) {}

    /** @return \ExpressCodeEngines\Shared\DTOs\RuleDefinition[] */
    public function rules(string $ruleSet, ?int $tenantId = null): array
    {
        return $this->rules->load($ruleSet, $tenantId);
    }

    public function workflow(string $entityClass, ?int $tenantId = null): ?\ExpressCodeEngines\Shared\DTOs\WorkflowDefinition
    {
        return $this->workflows->load($entityClass, $tenantId);
    }

    public function policy(string $entityClass, ?int $tenantId = null): ?\ExpressCodeEngines\Shared\DTOs\PolicyDefinition
    {
        return $this->policies->load($entityClass, $tenantId);
    }

    /** @return \ExpressCodeEngines\Shared\DTOs\ReactionDefinition[] */
    public function reactions(?int $tenantId = null): array
    {
        return $this->reactions->loadAll($tenantId);
    }

    public function templateRegistry(?int $tenantId = null, ?string $locale = null): \ExpressCodeEngines\Extended\NotificationEngine\TemplateRegistry
    {
        return $this->templates->buildRegistry($tenantId, $locale);
    }

    public function flow(string $flowKey, ?int $tenantId = null): ?\ExpressCodeEngines\Shared\DTOs\OrchestrationDefinition
    {
        return $this->flows->load($flowKey, $tenantId);
    }

    /** Invalida todo o cache de definições para um tenant */
    public function invalidateAll(?int $tenantId = null): void
    {
        $this->rules->invalidate('*', $tenantId);
        $this->workflows->invalidate('*', $tenantId);
        $this->policies->invalidate('*', $tenantId);
        $this->reactions->invalidate($tenantId);
        $this->templates->invalidate($tenantId);
    }
}
```

### Uso na Action com o loader unificado

```php
final class CreateOrderAction
{
    public function __construct(
        private readonly ConstraintEngineInterface   $constraints,
        private readonly BusinessRuleEngineInterface $bre,
        private readonly WorkflowEngineInterface     $workflow,
        private readonly ReactionEngineInterface     $reaction,
        private readonly EngineDefinitions           $definitions,
        private readonly OrderRepository             $orders,
    ) {}

    public function execute(CreateOrderDTO $dto, User $user): Order
    {
        $tenantId = $user->tenant_id;

        // Regras carregadas da BD (com merge global + tenant)
        $rules = $this->definitions->rules('App\Models\Order', $tenantId);

        // Workflow do tenant (ou global se não tiver específico)
        $workflowDef = $this->definitions->workflow(Order::class, $tenantId)
            ?? throw new ConfigurationException('Workflow não configurado.');

        // BRE
        $breResult = $this->bre->evaluate(
            new RuleContext(data: $dto->toArray()),
            $rules,
        );
        if ($breResult->hasDenials()) throw new BusinessRuleException($breResult->firstDenialMessage());

        // Persistir + transição
        return DB::transaction(function () use ($dto, $user, $workflowDef, $tenantId) {
            $order = $this->orders->create($dto->toArray());

            $engine = new WorkflowEngine(
                definitions: [Order::class => $workflowDef],
                guards:      [...], // guards injectados via container
            );

            $transition = $engine->transition(new TransitionRequest(
                entityClass: Order::class, currentState: 'draft',
                transition: 'submitted', data: $order->toArray(), user: $user,
            ));

            if ($transition->wasAllowed()) {
                $this->orders->updateState($order, $transition->newState);
            }

            DB::afterCommit(function () use ($order, $tenantId) {
                // Reacções da BD para este tenant
                $reactions = $this->definitions->reactions($tenantId);

                $this->reaction->react(
                    new ReactionContext(event: 'order.submitted', payload: $order->toArray()),
                    $reactions,
                );
            });

            return $order;
        });
    }
}
```

---

## 11. Modo híbrido — config/ com overrides em BD

O padrão mais comum em produção: config/ define o comportamento base, BD permite overrides por tenant sem deploy.

```php
// AppServiceProvider::boot()
$this->app->singleton(EngineDefinitions::class, function ($app) {

    // Regras base da config/ — sempre carregadas
    $baseRules = array_map(
        fn ($r) => new RuleDefinition(...$r),
        config('engines.rule_sets.App\Models\Order', [])
    );

    // Regras da BD — sobrepõem as da config pelo mesmo rule_id
    $dbLoader = $app->make(BusinessRuleLoader::class);

    return new class ($baseRules, $dbLoader) {
        public function __construct(
            private array $baseRules,
            private BusinessRuleLoader $dbLoader,
        ) {}

        public function rules(string $ruleSet, ?int $tenantId): array
        {
            $dbRules = $this->dbLoader->load($ruleSet, $tenantId);

            // Indexar por rule_id — DB ganha sobre config
            $base = collect($this->baseRules)->keyBy('id');
            $db   = collect($dbRules)->keyBy('id');

            return $base->merge($db)->values()->all();
        }
    };
});
```

---

## 12. Interface de administração — considerações

### O que invalidar quando uma regra muda

```php
// Model Observer — invalida o cache quando qualquer regra é alterada
class EngineBusinessRuleObserver
{
    public function __construct(private readonly BusinessRuleLoader $loader) {}

    public function saved(EngineBusinessRule $rule): void
    {
        $this->loader->invalidate($rule->rule_set, $rule->tenant_id);

        // Também invalida o global (que pode ter sido o fallback)
        $this->loader->invalidate($rule->rule_set, null);
    }

    public function deleted(EngineBusinessRule $rule): void
    {
        $this->saved($rule);
    }
}
```

### Auditoria de alterações

```php
// Registar no histórico antes de qualquer alteração
class EngineBusinessRule extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $rule) {
            DB::table('engine_business_rules_history')->insert([
                'rule_id'    => $rule->id,
                'action'     => 'updated',
                'before'     => json_encode($rule->getOriginal()),
                'after'      => json_encode($rule->getDirty()),
                'reason'     => request()->input('change_reason'),
                'changed_by' => auth()->id(),
                'changed_at' => now(),
            ]);
        });
    }
}
```

### Datas de vigência — activação agendada

```sql
-- Regra que só entra em vigor amanhã (ex: nova lei IVA)
UPDATE engine_business_rules
SET active_from = '2025-07-01 00:00:00',
    change_reason = 'Nova taxa IVA entra em vigor a 1 de Julho'
WHERE rule_id = 'vat_rate_check' AND tenant_id IS NULL;

-- Regra que expira no fim do mês (ex: promoção)
UPDATE engine_business_rules
SET active_until = '2025-06-30 23:59:59',
    change_reason = 'Promoção de Junho termina'
WHERE rule_id = 'promotional_discount' AND tenant_id = 12;
```

O `BusinessRuleLoader` já filtra automaticamente por `active_from` e `active_until` em cada query — não é necessário código adicional.
