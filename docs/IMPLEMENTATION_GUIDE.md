# ExpressCodeEngine — Guia de Implementação

Guia completo engine-a-engine. Para cada engine: quando usar, como configurar, exemplos reais, erros comuns e como testá-la.

---

## Índice

1. [Antes de começar — regras de ouro](#1-antes-de-começar--regras-de-ouro)
2. [ConstraintEngine](#2-constraintengine)
3. [BusinessRuleEngine](#3-businessruleengine)
4. [ComputationEngine](#4-computationengine)
5. [WorkflowEngine](#5-workflowengine)
6. [ReactionEngine](#6-reactionengine)
7. [NotificationEngine](#7-notificationengine)
8. [DynamicPolicyEngine](#8-dynamicpolicyengine)
9. [SearchEngine](#9-searchengine)
10. [IntegrationEngine](#10-integrationengine)
11. [OrchestrationEngine](#11-orchestrationengine)
12. [AiEngine](#12-aiengine)
13. [DocumentEngine](#13-documentengine)
14. [ChatEngine](#14-chatengine)
15. [Padrões transversais](#15-padrões-transversais)

---

## 1. Antes de começar — regras de ouro

### A ordem na Action é imutável

```
ConstraintEngine → ComputationEngine → BusinessRuleEngine
       → DB::transaction() → Repository → WorkflowEngine
              → DB::afterCommit() → ReactionEngine
```

**Porquê?** A `ConstraintEngine` é fail-fast — não faz sentido calcular totais e avaliar regras se a referência já existe. A `ComputationEngine` corre antes do BRE porque as regras precisam dos dados completos (total com IVA, não só o subtotal). A `ReactionEngine` corre em `afterCommit()` porque os efeitos só devem acontecer se a transacção confirmar.

### Injecta sempre a interface

```php
// ✅ Correcto — desacoplado
public function __construct(private readonly ConstraintEngineInterface $engine) {}

// ❌ Errado — acopla à implementação
public function __construct(private readonly ConstraintEngine $engine) {}
```

### As engines não lançam exceptions

Todas as engines retornam Value Objects. A tua Action decide se lança exception ou não:

```php
$result = $engine->validate($ctx, $defs);

// Opção A — falha rápida
if (! $result->passed) throw new ConstraintException($result->firstMessage());

// Opção B — recolhe todos os erros e retorna ao utilizador
if (! $result->passed) return back()->withErrors($result->violations);
```

---

## 2. ConstraintEngine

### Quando usar

Usa a `ConstraintEngine` para validações que precisam de consultar a base de dados para verificar integridade **antes de qualquer lógica de negócio**. Se a validação não precisa de BD (tipos, formatos, campos obrigatórios), usa Form Requests do Laravel.

Exemplos certos: unicidade composta, conflitos de agenda, limites diários, dependências entre registos.
Exemplos errados: formato de email (Form Request), valor mínimo (BRE), permissões (Policy).

### Instalação e configuração

```php
// O ServiceProvider regista automaticamente os 3 checkers built-in.
// Para adicionar checkers customizados:

// AppServiceProvider::register()
$this->app->tag([
    UniquenessChecker::class,
    OverlapChecker::class,
    LimitChecker::class,
    MyCustomChecker::class, // ← adicionar aqui
], 'engine.constraint.checkers');
```

### Checkers built-in

#### UniquenessChecker — unicidade composta

```php
new ConstraintDefinition(
    type:        'uniqueness',
    scopeFields: ['reference', 'tenant_id'], // campos que formam a chave única
    message:     'Esta referência já existe para este tenant.',
    config:      ['table' => 'orders'],
)
```

**Actualização:** passa `existingId` no contexto para excluir o registo actual:

```php
new ConstraintContext(
    entityClass: Order::class,
    data:        $dto->toArray(),
    existingId:  $order->id, // ← exclui este ID do check de unicidade
)
```

#### OverlapChecker — conflitos de agenda

```php
// Detecta: existing.start < proposed.end AND existing.end > proposed.start
new ConstraintDefinition(
    type:        'overlap',
    scopeFields: ['resource_id', 'tenant_id'], // escopo do check
    message:     'Este slot já está ocupado.',
    config:      [
        'table'       => 'bookings',
        'start_field' => 'starts_at', // default
        'end_field'   => 'ends_at',   // default
    ],
)
```

#### LimitChecker — limites físicos/temporais

```php
new ConstraintDefinition(
    type:        'limit',
    scopeFields: ['tenant_id'], // escopo — os campos são resolvidos de data + meta
    message:     'Limite de 100 encomendas por dia atingido.',
    config:      [
        'table'      => 'orders',
        'limit'      => 100,
        'period'     => 'today', // today | this_week | this_month | this_year | P30D (ISO 8601)
        'date_field' => 'created_at', // default
    ],
)
```

**Atenção:** os campos de escopo são procurados em `$context->data` **e** `$context->meta`. Para `tenant_id` que vive normalmente no `$user`, passa-o em `meta`:

```php
new ConstraintContext(
    entityClass: Order::class,
    data:        $dto->toArray(),
    meta:        ['tenant_id' => $user->tenant_id], // ← aqui
)
```

### Criar um checker customizado

```php
final class CreditLimitChecker implements ConstraintCheckerInterface
{
    public function supports(string $constraintType): bool
    {
        return $constraintType === 'credit_limit';
    }

    public function check(ConstraintDefinition $definition, ConstraintContext $context): bool
    {
        $customerId     = $context->data['customer_id'] ?? null;
        $orderTotal     = $context->data['total']       ?? 0;
        $creditLimit    = $definition->config['credit_limit_field'] ?? 'credit_limit';

        $customer = DB::table('customers')->where('id', $customerId)->first();

        if (! $customer) return true; // sem cliente, deixa passar (Form Request validou antes)

        $used = DB::table('orders')
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'paid')
            ->sum('total');

        return ($used + $orderTotal) <= $customer->{$creditLimit};
    }
}
```

### Teste sem base de dados

```php
$checker = new class implements ConstraintCheckerInterface {
    public function supports(string $t): bool { return $t === 'uniqueness'; }
    public function check(ConstraintDefinition $d, ConstraintContext $c): bool { return true; }
};

$engine = new ConstraintEngine(checkers: [$checker]);
$result = $engine->validate($ctx, $defs);
expect($result->passed)->toBeTrue();
```

---

## 3. BusinessRuleEngine

### Quando usar

Para regras de negócio que dependem dos valores dos dados (não de estrutura). São regras que o negócio define, que mudam com o tempo, e que precisam de feedback claro ao utilizador.

Exemplos certos: valor mínimo de encomenda, limite de desconto por tipo de cliente, regras de aprovação por montante, bloqueio de clientes em dívida.
Exemplos errados: formato de campo (Form Request), permissões (Policy), integridade referencial (ConstraintEngine).

### Severidades

| Severidade | Comportamento | Uso |
|---|---|---|
| `deny` | Bloqueia a acção. Adicionado a `denials`. | Regras que proíbem a operação |
| `warn` | Alerta mas não bloqueia. Adicionado a `warnings`. | Avisos ao utilizador |
| `allow` | Aprova efeitos pós-commit. Adicionado a `approvedEffects`. | Acções condicionais após commit |

### Prioridade

Regras são avaliadas por `priority DESC`. A primeira regra `deny` que dispara em modo `failFast: true` para a avaliação.

```php
$response = $bre->evaluate($ctx, [
    // Alta prioridade — avaliada primeiro
    new RuleDefinition(id: 'client_blocked', priority: 100, ruleType: 'in_list',
        field: 'client.status', params: ['values' => ['blocked', 'suspended']],
        severity: 'deny', message: 'Cliente bloqueado.'),

    // Média prioridade
    new RuleDefinition(id: 'min_value', priority: 50, ruleType: 'comparison',
        field: 'order.total', params: ['operator' => '>=', 'value' => 10],
        severity: 'deny', message: 'Valor mínimo €10.'),

    // Baixa prioridade — só chega aqui se as anteriores não bloquearam
    new RuleDefinition(id: 'large_order', priority: 10, ruleType: 'comparison',
        field: 'order.total', params: ['operator' => '>', 'value' => 5000],
        severity: 'allow', effects: ['notify_manager']),
], failFast: false); // false = recolhe todos os erros (melhor UX)
```

### Evaluators e casos de uso reais

#### comparison — o mais versátil

```php
// Desconto não pode exceder o limite de crédito do cliente (referência cruzada)
new RuleDefinition(id: 'discount_cap', ruleType: 'comparison',
    field:  'order.discount',
    params: ['operator' => '<=', 'value' => '@client.credit_limit'], // @ = dot-path no contexto
    severity: 'deny', message: 'Desconto excede o limite de crédito.')

// Estado inválido para esta operação
new RuleDefinition(id: 'status_check', ruleType: 'comparison',
    field:  'order.status',
    params: ['operator' => '==', 'value' => 'draft'],
    severity: 'deny', message: 'Só é possível editar encomendas em rascunho.')
```

#### range — intervalo de valores

```php
// Quantidade tem de estar entre 1 e 1000 (dispara quando FORA do intervalo)
new RuleDefinition(id: 'qty_range', ruleType: 'range',
    field:  'line.quantity',
    params: ['min' => 1, 'max' => 1000, 'inclusive' => true],
    severity: 'deny', message: 'Quantidade deve ser entre 1 e 1000.')
```

#### in_list — membros de um conjunto

```php
// País tem de estar na lista de países suportados
new RuleDefinition(id: 'country_allowed', ruleType: 'in_list',
    field:  'shipping.country_code',
    params: ['values' => ['PT', 'ES', 'FR', 'DE'], 'negate' => true], // negate: não permitido
    severity: 'deny', message: 'País não suportado para envio.')
```

#### onlyIf — condição de activação

```php
// Esta regra só se aplica a encomendas internacionais
new RuleDefinition(id: 'intl_minimum', ruleType: 'comparison',
    field:  'order.total',
    params: ['operator' => '>=', 'value' => 100],
    onlyIf: "order.type == 'international'",  // só avalia se esta condição for verdade
    severity: 'deny', message: 'Mínimo €100 para encomendas internacionais.')
```

### Aprovação de efeitos

Os `approvedEffects` são chaves que a tua Action dispara após o commit:

```php
$response = $bre->evaluate($ctx, $rules);

DB::afterCommit(function () use ($order, $response) {
    foreach ($response->approvedEffects as $effect) {
        match ($effect) {
            'notify_manager'   => NotifyManagerJob::dispatch($order),
            'flag_compliance'  => FlagComplianceJob::dispatch($order),
            'require_approval' => $order->update(['requires_approval' => true]),
            default            => null,
        };
    }
});
```

### Adicionar um evaluator customizado

```php
final class ExpressionEvaluator implements RuleEvaluatorInterface
{
    public function evaluate(mixed $value, array $params, RuleContext $context): bool
    {
        // Avaliador de expressões próprio (e.g. parser de fórmulas de negócio)
        $formula = $params['formula'] ?? '';
        return (new FormulaParser($formula, $context->data))->evaluate();
    }
}

// Registar:
$registry = new RuleEvaluatorRegistry();
$registry->addEvaluator('formula', new ExpressionEvaluator());
$engine   = new BusinessRuleEngine($registry);
```

---

## 4. ComputationEngine

### Quando usar

Para qualquer cálculo que precise de ser determinístico, auditável, e testável sem contexto. O valor desta engine está em tornar os cálculos declarativos — um array de operações em vez de código PHP espalhado.

Usa sempre antes do BRE para que as regras avaliem dados completos (total com IVA, não só linhas individuais).

### Operações e casos de uso

```php
// Factura multi-linha
$results = $computation->computeMany([
    'subtotal'  => new ComputationRequest('sum', array_column($lines, 'amount')),
    'discount'  => new ComputationRequest('percentage', ['@subtotal', $dto->discountPct],
                       context: ['subtotal' => $subtotal], precision: 2),
    'after_disc'=> new ComputationRequest('subtract', ['@subtotal', '@discount'],
                       context: ['subtotal' => $subtotal, 'discount' => $discount], precision: 2),
    'vat'       => new ComputationRequest('percentage', ['@after_disc', 23], precision: 2),
    'total'     => new ComputationRequest('add', ['@after_disc', '@vat'], precision: 2),
]);
```

### Aninhamento recursivo

```php
// Cálculo complexo numa única request
// total_with_rounding = round(subtotal * (1 + vat_rate / 100), 2)
$result = $computation->compute(new ComputationRequest(
    operation: 'round',
    args: [
        [
            'operation' => 'multiply',
            'args' => [
                '@subtotal',
                ['operation' => 'add', 'args' => [1, ['operation' => 'divide', 'args' => ['@vat_rate', 100]]]],
            ],
        ],
        2, // decimal places
    ],
    context: ['subtotal' => 500.00, 'vat_rate' => 23],
));
```

### Precisão financeira — regra obrigatória

Qualquer cálculo monetário **deve** usar `precision`:

```php
// ❌ Errado — float arithmetic errors
new ComputationRequest('percentage', [199.99, 23])
// Resultado: 45.9977000000001...

// ✅ Correcto — BCMath garante precisão decimal exacta
new ComputationRequest('percentage', [199.99, 23], precision: 2)
// Resultado: '46.00'
```

### Tratamento de erros

```php
$result = $computation->compute($request);

if ($result->isError()) {
    match ($result->errorCode) {
        'DIVISION_BY_ZERO'  => throw new InvalidDataException('Divisão por zero.'),
        'INVALID_TYPE'      => throw new InvalidDataException('Valor não numérico.'),
        'UNKNOWN_OPERATION' => throw new ConfigurationException("Operação '{$result->errorCode}' não registada."),
        'MAX_DEPTH_EXCEEDED'=> throw new ConfigurationException('Aninhamento máximo excedido.'),
        default             => throw new ComputationException($result->errorMessage),
    };
}

$total = (float) $result->value; // cast explícito — com precision, value é string BCMath
```

### Adicionar operação customizada

```php
final class VatPortugalOperation implements OperationInterface
{
    public function execute(array $args, ?int $precision): ComputationResult
    {
        if (empty($args)) return ComputationResult::error('INSUFFICIENT_ARGS', 'Requer o subtotal.');

        $subtotal = (float) $args[0];
        $rate     = 0.23; // IVA padrão PT

        $vat = $subtotal * $rate;

        return ComputationResult::ok(
            $precision !== null ? bcadd(number_format($vat, $precision + 2, '.', ''), '0', $precision) : $vat
        );
    }
}

// Registar:
$registry = OperationRegistry::withDefaults();
$registry->register('vat_pt', new VatPortugalOperation());
$engine   = new ComputationEngine($registry);
```

---

## 5. WorkflowEngine

### Quando usar

Para entidades que têm um ciclo de vida com estados discretos e transições controladas. A engine garante que as transições são válidas, que as permissões são verificadas, e que os efeitos pós-transição são executados de forma controlada.

Exemplos certos: encomendas (draft → submitted → approved → shipped), facturas (draft → sent → paid → cancelled), aprovações, tickets de suporte.
Exemplos errados: campos booleanos simples (usa um campo comum), lógica condicional sem estados (usa BRE).

### Configuração de um workflow

```php
// config/engines.php:
'workflows' => [
    'App\Models\Order' => [
        'initial_states' => ['draft'],
        'transitions'    => [
            // Transição simples — sem guards
            ['from' => 'draft', 'to' => 'submitted',
             'post_actions' => ['notify_reviewer', 'log_submission']],

            // Transição com guard de role
            ['from' => 'submitted', 'to' => 'approved',
             'guards'       => ['role:manager'],
             'post_actions' => ['notify_client', 'trigger_fulfillment']],

            // Transição com múltiplos guards (todos têm de passar)
            ['from' => 'submitted', 'to' => 'rejected',
             'guards'   => ['role:manager', 'field:review_complete:true'],
             'metadata' => ['label' => 'Rejeitar pedido']],

            // Qualquer role pode cancelar uma encomenda aprovada
            ['from' => 'approved', 'to' => 'cancelled',
             'post_actions' => ['refund_payment', 'notify_client']],
        ],
    ],
],
```

### Executar uma transição

```php
$result = $workflow->transition(new TransitionRequest(
    entityClass:  Order::class,
    currentState: $order->status,
    transition:   'approved',              // estado destino
    data:         $order->toArray(),       // dados para os guards
    user:         $user,                   // user para role guards
    reason:       'Aprovado por director', // para o log de auditoria
));

if (! $result->wasAllowed()) {
    throw new WorkflowException($result->denialReason);
}

// Persistir DENTRO da transacção
DB::transaction(function () use ($order, $result) {
    $order->update([
        'status'         => $result->newState,
        'approved_at'    => $result->metadata['transitioned_at'],
        'approved_by_id' => $result->metadata['actor_id'],
    ]);
});

// Despachar post_actions APÓS commit
DB::afterCommit(function () use ($order, $result) {
    foreach ($result->postActions as $action) {
        event("workflow.{$action}", ['order' => $order]);
    }
});
```

### Guards disponíveis

```
role:admin                        → $user->hasRole('admin')
field:approved_by:null            → $data['approved_by'] === null
field:is_locked:false             → $data['is_locked'] === false
callable:App\Guards\Order::canApprove  → estático, recebe TransitionRequest
```

### Guard customizado

```php
final class BudgetGuard implements WorkflowGuardInterface
{
    public function supports(string $guard): bool
    {
        return str_starts_with($guard, 'budget:');
    }

    public function passes(string $guard, TransitionRequest $request): bool
    {
        $limit     = (float) substr($guard, 7); // 'budget:5000' → 5000
        $orderTotal = $request->data['total'] ?? 0;

        // Manager pode aprovar qualquer valor; supervisor só até ao limite
        if ($request->user?->hasRole('manager')) return true;

        return $orderTotal <= $limit;
    }
}

// Usar na config:
// 'guards' => ['budget:5000']  → supervisor só aprova até €5000
```

### Disponibilidade de transições (para UI)

```php
// Mostra só os botões que o utilizador pode usar:
$available = $workflow->availableTransitions(Order::class, $order->status, $user);
// ['approved', 'rejected'] — ou [] se nenhuma transição disponível
```

---

## 6. ReactionEngine

### Quando usar

Para qualquer efeito colateral que deve acontecer **após** uma operação ser persistida. A engine desacopla o código de negócio dos efeitos colaterais — a Action não sabe que existe uma notificação ou um job.

Regra fundamental: **a ReactionEngine é sempre chamada em `DB::afterCommit()`**. Nunca dentro de uma transacção.

### Padrão de chamada

```php
// Na Action, após o transaction():
DB::afterCommit(function () use ($order, $user) {
    $this->reaction->react(
        new ReactionContext(
            event:   'order.submitted',
            payload: array_merge($order->toArray(), ['customer' => $order->customer->toArray()]),
            actor:   $user,
            meta:    ['tenant_id' => $user->tenant_id, 'locale' => app()->getLocale()],
        ),
        config('engines.extended.reactions.definitions'),
    );
});
```

### Configuração de reacções

```php
// config/engines/extended.php:
'reactions' => [
    'definitions' => [
        // Notificação ao cliente quando encomenda é submetida
        [
            'event'   => 'order.submitted',
            'handler' => 'notify',
            'async'   => true,
            'params'  => [
                'template_key'   => 'order_submitted',
                'recipient_path' => 'customer.email',
            ],
        ],

        // Só para encomendas grandes: notificar gestor
        [
            'event'   => 'order.submitted',
            'handler' => 'notify',
            'async'   => true,
            'only_if' => 'order.total > 5000',
            'params'  => [
                'template_key'   => 'large_order_alert',
                'recipient_path' => 'manager.email',
                'channels'       => ['slack', 'mail'],
            ],
        ],

        // Despachar job para qualquer evento de encomenda
        [
            'event'   => 'order.*',          // wildcard — todos os eventos de order
            'handler' => 'dispatch_job',
            'async'   => true,
            'params'  => ['job' => 'App\Jobs\AuditOrderEventJob'],
        ],

        // Actualizar campo denormalizado sincronamente
        [
            'event'   => 'order.approved',
            'handler' => 'update_field',
            'async'   => false,             // sync — precisa de confirmar antes de continuar
            'params'  => [
                'model'      => 'App\Models\Customer',
                'id_path'    => 'customer.id',
                'field'      => 'last_order_approved_at',
                'value_path' => null,        // null = usa 'value' literal
                'value'      => 'now()',
            ],
        ],
    ],
],
```

### Handler customizado

```php
final class SlackAlertHandler implements ReactionHandlerInterface
{
    public function __construct(private readonly SlackClient $slack) {}

    public function key(): string { return 'slack_alert'; }

    public function handle(ReactionContext $ctx, array $params): HandlerResult
    {
        try {
            $channel = $params['channel']   ?? '#alerts';
            $message = $params['message']   ?? $ctx->event;

            $this->slack->post($channel, $this->buildMessage($ctx, $message));

            return HandlerResult::ok();
        } catch (\Throwable $e) {
            return HandlerResult::failed($e->getMessage());
        }
    }

    public function dispatchAsync(ReactionContext $ctx, array $params, ?string $queue, int $delay): void
    {
        SlackAlertJob::dispatch($ctx->payload, $params)->onQueue($queue ?? 'notifications');
    }
}

// Registar:
$this->app->tag(SlackAlertHandler::class, 'engine.reaction.handlers');
```

### Verificar resultados

```php
$summary = $reaction->react($ctx, $defs);

Log::info('Reactions processed.', [
    'matched'    => $summary->matched,
    'dispatched' => $summary->dispatched,
    'failed'     => $summary->failed,
]);

if ($summary->hasFailures()) {
    // Os erros já estão logados pela engine.
    // Decide se esta falha é crítica para o fluxo.
    Sentry::captureMessage("Reaction failures for {$ctx->event}", extra: $summary->results);
}
```

---

## 7. NotificationEngine

### Quando usar

Para qualquer envio de mensagem a utilizadores. Centraliza templates, canais, e rate limiting num único ponto.

### Canais disponíveis

| Canal | Classe | Requisito |
|---|---|---|
| `mail` | `MailChannel` | SMTP configurado |
| `database` | `DatabaseChannel` | Tabela `notifications`, trait `Notifiable` |
| `sms` | `SmsChannel` | `TWILIO_*` env vars |
| `push` | `PushChannel` | `FIREBASE_*` env vars |
| `slack` | `SlackChannel` | `SLACK_WEBHOOK_URL` ou webhook por utilizador |

### Configuração de templates

```php
// config/engines/extended.php:
'notifications' => [
    'templates' => [
        'invoice_paid' => [
            'channels' => ['mail', 'database'],
            'subject'  => 'Factura #{{invoice.number}} paga',
            'body'     => "Olá {{client.name}},\n\nA factura #{{invoice.number}} foi paga com sucesso.\n\nValor: {{invoice.total}}",
            'defaults' => [],
        ],

        // Alerta Slack com cor
        'system_alert' => [
            'channels' => ['slack'],
            'subject'  => 'Alerta: {{title}}',
            'body'     => '{{description}}',
            'defaults' => ['data' => ['color' => 'danger']],
        ],

        // Template com view Laravel
        'welcome_email' => [
            'channels' => ['mail'],
            'subject'  => 'Bem-vindo, {{user.name}}!',
            'body'     => 'view:emails.welcome', // usa resources/views/emails/welcome.blade.php
            'defaults' => [],
        ],
    ],

    'rate_limits' => [
        'per_recipient_template' => ['limit' => 1,   'window_seconds' => 86400],
        'per_recipient'          => ['limit' => 20,  'window_seconds' => 3600],
        'per_template'           => ['limit' => 5000,'window_seconds' => 3600],
    ],
],
```

### Envio directo

```php
// Via ReactionEngine (mais comum — desacoplado):
// Ver secção ReactionEngine acima.

// Envio directo numa Action (casos específicos):
$result = $notification->send(new NotificationRequest(
    templateKey: 'invoice_paid',
    recipient:   $client->email,           // ou objeto User com Notifiable
    data:        ['invoice' => $invoice->toArray(), 'client' => $client->toArray()],
    channels:    ['mail'],                 // override dos canais do template
));

if ($result->failed()) {
    Log::error('Notification failed.', ['error' => $result->errorMessage]);
    // Não lançar exception — notificações nunca devem bloquear o fluxo principal
}
```

### Canal customizado — exemplo SMS via gateway próprio

```php
final class CustomSmsChannel implements NotificationChannelInterface
{
    public function channel(): string { return 'custom_sms'; }

    public function deliver(string|object $recipient, array $rendered): NotificationResult
    {
        $phone = is_string($recipient) ? $recipient : ($recipient->phone ?? null);
        if (! $phone) return NotificationResult::failed('custom_sms', 'NO_PHONE', 'Sem telefone.');

        try {
            $response = Http::post('https://api.meusgateway.pt/sms', [
                'to'   => $phone,
                'text' => $rendered['body'],
            ]);

            return $response->successful()
                ? NotificationResult::sent('custom_sms', $response->json('message_id'))
                : NotificationResult::failed('custom_sms', 'GATEWAY_ERROR', $response->body());
        } catch (\Throwable $e) {
            return NotificationResult::failed('custom_sms', 'EXCEPTION', $e->getMessage());
        }
    }
}

// Registar:
$this->app->tag(CustomSmsChannel::class, 'engine.notification.channels');
```

---

## 8. DynamicPolicyEngine

### Quando usar

Para controlo de acesso que depende dos **dados** do registo, não apenas do role do utilizador. Complementa (não substitui) o `Gate` e `Policies` do Laravel.

Exemplos certos: utilizador só vê registos do seu tenant, manager aprova só até certo montante, publicado só visto por subscritores.
Exemplos errados: admin pode tudo (usa role no guard), campo obrigatório (Form Request).

### Integração com Laravel Policy

```php
final class OrderPolicy
{
    public function __construct(
        private readonly DynamicPolicyEngineInterface $policyEngine,
    ) {}

    private function definition(): PolicyDefinition
    {
        return PolicyDefinition::fromArray(Order::class, [
            'default_effect' => 'deny',
            'rules' => [
                // Admins passam sempre
                ['id' => 'admin', 'ability' => '*', 'effect' => 'allow',
                 'condition' => null, 'bypass_roles' => ['admin', 'super_admin'], 'priority' => 100],

                // Tenant isolation — utilizador só vê o seu tenant
                ['id' => 'tenant_view', 'ability' => 'view', 'effect' => 'allow',
                 'condition' => 'record.tenant_id == user.tenant_id', 'priority' => 50],

                // Só o criador pode editar (ou manager do mesmo tenant)
                ['id' => 'own_edit', 'ability' => 'update', 'effect' => 'allow',
                 'condition' => 'record.created_by == user.id', 'priority' => 40],

                ['id' => 'manager_edit', 'ability' => 'update', 'effect' => 'allow',
                 'condition' => 'record.tenant_id == user.tenant_id', 'priority' => 30,
                 'bypass_roles' => ['manager']],

                // Ninguém edita registos arquivados
                ['id' => 'no_archive_edit', 'ability' => 'update', 'effect' => 'deny',
                 'condition' => 'record.status == archived', 'priority' => 90],
            ],
        ]);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->policyEngine->can(
            new PolicyContext(user: $user, ability: 'view', record: $order),
            $this->definition(),
        );
    }

    public function update(User $user, Order $order): bool
    {
        return $this->policyEngine->can(
            new PolicyContext(user: $user, ability: 'update', record: $order),
            $this->definition(),
        );
    }
}
```

### Row-Level Security em Query Objects

```php
final class OrderQueryObject
{
    public function __construct(
        private readonly DynamicPolicyEngineInterface $policyEngine,
    ) {}

    public function forUser(User $user): Builder
    {
        $query = Order::query();

        // A engine adiciona WHERE clauses baseada nas condições das regras
        return $this->policyEngine->scope(
            $query,
            new PolicyContext(user: $user, ability: 'view'),
            $this->definition(),
        );
        // Resultado: SELECT * FROM orders WHERE tenant_id = ? AND status != 'archived'
    }
}
```

### Cache

Por defeito, `can()` é cacheado por `entity + ability + user_id + record_id` durante 300s. Em testes, desactivar:

```php
// config/engines/extended.php:
'policy' => ['cache_enabled' => false, 'cache_ttl' => 300]
```

---

## 9. SearchEngine

### Quando usar

Para qualquer listagem com pesquisa por texto, filtros, e ordenação. Unifica a interface independentemente do driver.

### Escolha do driver

| Driver | Quando usar |
|---|---|
| `database` | < 50K registos, sem infra extra, pesquisa não crítica |
| `meilisearch` | Typo-tolerant, self-hosted, até alguns milhões |
| `algolia` | SaaS gerido, latência global baixa, free tier disponível |
| `null` | Testes |

### Configuração por entidade

```php
'search' => [
    'driver'   => env('SEARCH_DRIVER', 'database'),
    'entities' => [
        'App\Models\Order' => [
            'table'             => 'orders',           // só para database driver
            'index_name'        => 'orders_production', // override do nome do índice
            'searchable_fields' => ['reference', 'customer_name', 'notes'],
            'filterable_fields' => ['status', 'tenant_id', 'created_at'],
            'sortable_fields'   => ['created_at', 'total'],
        ],
    ],
],
```

### Pesquisa com filtros, facets e scope

```php
$result = $search->search(new SearchRequest(
    query:    $request->input('q', ''),
    entities: [Order::class],
    filters:  ['status' => $request->input('status')],  // exact match
    sort:     [['field' => 'created_at', 'direction' => 'desc']],
    facets:   ['status', 'tenant_id'],  // contagens por valor
    scope:    ['tenant_id' => $user->tenant_id], // sempre aplicado (RLS)
    perPage:  $request->input('per_page', 20),
    page:     $request->input('page', 1),
));

return [
    'hits'       => $result->hits,
    'total'      => $result->total,
    'pages'      => $result->totalPages(),
    'facets'     => $result->facets, // ['status' => ['active' => 42, 'draft' => 8]]
];
```

### Indexação

```php
// Indexar um registo (normalmente via Observer ou ReactionEngine)
$search->index(new IndexRequest(
    entityClass: Order::class,
    document:    $order->toSearchableArray(),
));

// Indexação em batch (importação inicial)
$search->indexMany(
    Order::all()->map(fn ($o) => new IndexRequest(Order::class, $o->toSearchableArray()))->all()
);

// Remover do índice
$search->delete(Order::class, $order->id);

// Limpar índice completamente
$search->flush(Order::class);
```

---

## 10. IntegrationEngine

### Quando usar

Para qualquer chamada a APIs externas. A engine garante retry exponencial, circuit breaker, e logging estruturado sem que o código de negócio saiba dos detalhes.

### Configuração de uma integração

```php
'integrations' => [
    'stripe_payment' => [
        'url'     => 'https://api.stripe.com/v1/payment_intents',
        'method'  => 'POST',
        'auth'    => ['type' => 'bearer', 'token_env' => 'STRIPE_SECRET_KEY'],
        'timeout' => 30,
        'retry'   => [
            'attempts'  => 3,
            'backoff'   => [1, 5, 15],         // segundos entre tentativas
            'on_status' => [429, 500, 502, 503], // só estes status fazem retry
        ],
        'mapping' => [
            'order.total'    => 'amount',        // source dot-path => destination key
            'order.currency' => 'currency',
            'client.email'   => 'receipt_email',
        ],
        'async' => false, // sync — precisa da resposta imediatamente
    ],

    'erp_sync' => [
        'url'    => env('ERP_WEBHOOK_URL'),
        'method' => 'POST',
        'async'  => true, // async — dispara e esquece
        'retry'  => ['attempts' => 5, 'backoff' => [2, 10, 30, 60, 120]],
        'mapping'=> ['order' => 'order_data'],
    ],
],
```

### Chamada síncrona

```php
$result = $integration->call(new IntegrationRequest(
    integrationKey: 'stripe_payment',
    payload:        ['order' => $order->toArray(), 'client' => $client->toArray()],
));

if (! $result->success) {
    if ($result->isClientError()) {
        // 4xx — problema nos nossos dados, não tentar novamente
        throw new PaymentDataException($result->errorMessage);
    }
    // 5xx ou timeout — Stripe teve problema, já fez retry automaticamente
    throw new PaymentServiceException('Serviço de pagamento indisponível.');
}

$paymentIntentId = $result->response['id'];
```

### Webhooks de entrada

```php
// routes/api.php:
use ExpressCodeEngines\Extended\IntegrationEngine\Webhooks\WebhookRoute;

WebhookRoute::register('stripe', '/webhooks/stripe', ['throttle:100,1']);
WebhookRoute::register('github', '/webhooks/github');

// Handler:
final class StripeWebhookHandler implements WebhookHandlerInterface
{
    public function supports(string $key, string $event): bool
    {
        return $key === 'stripe'; // aceita todos os eventos Stripe
    }

    public function handle(string $key, string $event, array $payload, Request $request): void
    {
        match ($event) {
            'payment_intent.succeeded' => ProcessPaymentJob::dispatch($payload),
            'customer.subscription.deleted' => CancelSubscriptionJob::dispatch($payload),
            default => null, // ignora eventos desconhecidos
        };
    }
}
```

---

## 11. OrchestrationEngine

### Quando usar

Para fluxos complexos com múltiplos passos onde uma falha a meio precisa de desfazer os passos anteriores (Saga pattern). A engine coordena sem conter lógica de negócio.

### Definição de um fluxo

```php
OrchestrationFlow::fromArray('process_checkout', [
    'steps' => [
        [
            'id'           => 'validate_stock',
            'handler'      => 'App\Orchestration\ValidateStockStep',
            'compensation' => 'App\Orchestration\ReleaseStockStep',
        ],
        [
            'id'           => 'charge_payment',
            'handler'      => 'App\Orchestration\ChargePaymentStep',
            'compensation' => 'App\Orchestration\RefundPaymentStep',
            'retries'      => 2,           // 2 tentativas adicionais
            'input'        => [            // mapeamento do payload para o step
                'order_id' => 'order.id',
                'amount'   => 'order.total',
                'currency' => 'order.currency',
            ],
        ],
        [
            'id'                  => 'send_confirmation',
            'handler'             => 'App\Orchestration\SendConfirmationStep',
            'continue_on_failure' => true, // não crítico — fluxo continua mesmo se falhar
        ],
    ],
]);
```

### Implementar um step handler

```php
final class ChargePaymentStep
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    // Recebe o input mapeado + o payload completo do fluxo
    public function execute(array $input, array $fullPayload): array
    {
        $charge = $this->gateway->charge(
            amount:   $input['amount'],
            currency: $input['currency'],
            orderId:  $input['order_id'],
        );

        if (! $charge->success) {
            throw new PaymentFailedException($charge->error);
            // Exceção → engine captura, tenta retry se configurado,
            // depois chama compensate() dos steps anteriores
        }

        // O array retornado é merged no payload do fluxo
        // Próximos steps podem aceder a 'payment.id', 'payment.status', etc.
        return ['payment' => ['id' => $charge->id, 'status' => 'captured']];
    }

    public function compensate(array $input, array $fullPayload): void
    {
        // Chamado em LIFO order se um step posterior falhar
        if (isset($fullPayload['payment']['id'])) {
            $this->gateway->refund($fullPayload['payment']['id']);
        }
    }
}
```

### Executar e tratar resultado

```php
$result = $orchestration->run(
    OrchestrationFlow::fromArray('process_checkout', config('engines.extended.orchestration.process_checkout')),
    ['order' => $order->toArray(), 'customer' => $customer->toArray()]
);

if ($result->failed()) {
    Log::error('Checkout orchestration failed.', [
        'failed_at'        => $result->failedAt,
        'error'            => $result->errorMessage,
        'compensated_steps'=> $result->compensatedSteps,
        'log'              => $result->log,
    ]);
    throw new CheckoutException("Falha no passo '{$result->failedAt}': {$result->errorMessage}");
}

// Payload final acumulado de todos os steps
$paymentId = $result->payload['payment']['id'];
```

---

## 12. AiEngine

### Quando usar

Para qualquer interacção com LLMs — extracção de dados estruturados, classificação, geração de texto, embeddings para RAG.

### Selecção de provider

| Provider | Quando usar |
|---|---|
| `openai` | Qualidade geral, JSON mode robusto, embeddings |
| `anthropic` | Instruções longas e complexas, contexto > 100K tokens |
| `groq` | Latência crítica (< 1s), real-time chat |
| `openrouter` | Flexibilidade de modelo, modelos gratuitos, fallback automático |
| `ollama` | Privacidade, sem custos, ambiente airgapped |
| `huggingface` | Embeddings gratuitos de alta qualidade |

### Completion simples

```php
$response = $ai->complete(new AiRequest(
    model:    new ModelConfig(
        provider:    'anthropic',
        model:       'claude-haiku-4',
        maxTokens:   500,
        temperature: 0.1, // baixo para extracção factual
    ),
    messages: [
        AiMessage::system('Extrai os dados da factura e responde em JSON.'),
        AiMessage::user($invoiceText),
    ],
));

if ($response->failed()) {
    Log::error('AI extraction failed.', ['error' => $response->errorCode]);
    return null;
}

$extracted = $response->content; // JSON string
```

### JSON mode — extracção estruturada

```php
$response = $ai->complete(new AiRequest(
    model:    new ModelConfig(
        provider:   'openai',
        model:      'gpt-4o-mini',
        jsonMode:   true,              // força JSON válido
        jsonSchema: [                   // valida a estrutura
            'type'       => 'object',
            'required'   => ['client_name', 'total', 'due_date'],
            'properties' => [
                'client_name' => ['type' => 'string'],
                'total'       => ['type' => 'number'],
                'due_date'    => ['type' => 'string'],
                'line_items'  => ['type' => 'array'],
            ],
        ],
    ),
    messages: [
        AiMessage::system('Extrai os dados da factura. Responde apenas com JSON.'),
        AiMessage::user($invoiceText),
    ],
));

$data = $response->data; // array já parseado e validado contra o schema
```

### Embeddings com cache

```php
// O CachedEmbeddingEngine (activado por defeito) cacheia automaticamente:
$vector = $ai->embed('texto a vectorizar', 'openai');  // 1536 floats
$vector = $ai->embed('texto a vectorizar', 'openai');  // retornado do cache

// Invalidação por provider (com Redis tags):
$ai->clearByProvider('openai');

// Invalidação global:
Cache::tags(['engine.embedding'])->flush();
```

### Fallback chain

```php
// config/engines/extended.php:
'ai' => [
    'fallback_chain' => ['anthropic', 'openai', 'groq', 'ollama', 'null'],
]

// Se Anthropic falhar → tenta OpenAI → Groq → Ollama → Null
// O Null provider retorna sempre resposta vazia (não lança exception)
```

---

## 13. DocumentEngine

### Quando usar

Para qualquer funcionalidade de RAG (Retrieval-Augmented Generation) que precise de indexar documentos e recuperar chunks relevantes para contexto de IA.

### Pipeline completo

```php
// 1. Processar documento (normalmente via Job após upload)
final class ProcessDocumentJob implements ShouldQueue
{
    public function __construct(
        private readonly string $filePath,
        private readonly string $entityClass,
        private readonly int    $entityId,
    ) {}

    public function handle(DocumentEngineInterface $engine): void
    {
        $result = $engine->process(new DocumentInput(
            source:        $this->filePath,
            entityClass:   $this->entityClass,
            entityId:      $this->entityId,
            chunkStrategy: 'paragraph',  // paragraph | fixed | page | semantic
            chunkSize:     500,           // palavras por chunk
            chunkOverlap:  50,            // overlap entre chunks
        ));

        if ($result->failed()) {
            Log::error('Document processing failed.', [
                'error'  => $result->errorMessage,
                'source' => $this->filePath,
            ]);
            return;
        }

        Log::info('Document processed.', [
            'document_id' => $result->documentId,
            'chunks'      => $result->chunkCount(),
            'embedded'    => $result->embedded,
        ]);
    }
}

// 2. Recuperar chunks relevantes (na ChatEngine ou directamente)
$chunks = $engine->retrieve(
    query:         'prazo de entrega internacional',
    entityClasses: [Article::class, FaqEntry::class], // só nestas entidades
    topK:          5,
);

foreach ($chunks as $chunk) {
    echo $chunk->text;              // texto do chunk
    echo $chunk->documentId;        // ID do documento fonte
    echo $chunk->meta['entity_id']; // ID da entidade Laravel
}

// 3. Eliminar chunks de um documento
$engine->delete($documentId);
```

### Escolha do vector store

```php
// config/engines/extended.php:
'documents' => [
    'vector_store'   => env('VECTOR_STORE', 'default'), // default | pgvector | mysql
    'embed_provider' => env('EMBED_PROVIDER', 'openai'),// openai | huggingface | ollama

    'pgvector' => ['table' => 'document_chunks', 'dimensions' => 1536],
    'mysql'    => ['table' => 'document_chunks', 'dimensions' => 384],
]
```

---

## 14. ChatEngine

### Quando usar

Para assistentes conversacionais que precisam de responder com base nos dados da empresa. A engine garante que o RAG é obrigatório para dados empresariais e que a política de acesso filtra o contexto.

### Configuração de personas

```php
'chat' => [
    'personas' => [
        'support' => [
            'system_prompt' => 'És um agente de suporte ao cliente. Responde apenas com base no contexto fornecido. Se não souberes a resposta, diz que não tens essa informação.',
            'model'         => ['provider' => 'anthropic', 'model' => 'claude-haiku-4',
                                'max_tokens' => 800, 'temperature' => 0.2],
            'rag_enabled'   => true,
            'rag_top_k'     => 5,
            'rag_entities'  => ['App\Models\Article', 'App\Models\FaqEntry'],
            'cite_sources'  => true,  // [Source id#0] nas respostas
        ],

        'internal_ops' => [
            'system_prompt' => 'És um assistente interno. Tens acesso aos dados operacionais da empresa.',
            'model'         => ['provider' => 'openai', 'model' => 'gpt-4o',
                                'max_tokens' => 2000, 'temperature' => 0.3],
            'rag_enabled'   => true,
            'rag_top_k'     => 10,
            'rag_entities'  => [], // todas as entidades indexadas
            'cite_sources'  => true,
        ],
    ],
],
```

### Integração num Controller

```php
final class ChatController extends Controller
{
    public function __construct(
        private readonly ChatEngineInterface $chat,
    ) {}

    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'message'    => 'required|string|max:2000',
            'session_id' => 'required|string',
            'persona'    => 'sometimes|string|in:support,internal_ops',
        ]);

        $response = $this->chat->chat(new ChatMessage(
            content:   $request->input('message'),
            sessionId: $request->input('session_id'),
            persona:   $request->input('persona', 'support'),
            user:      $request->user(),
        ));

        if ($response->failed()) {
            return response()->json(['error' => 'Serviço temporariamente indisponível.'], 503);
        }

        return response()->json([
            'answer'    => $response->answer,
            'sources'   => $response->sources,   // chunks citados
            'usage'     => [
                'tokens' => $response->inputTokens + $response->outputTokens,
                'cost'   => $response->estimatedCost,
            ],
        ]);
    }

    public function clearSession(string $sessionId): JsonResponse
    {
        $this->chat->clearSession($sessionId);
        return response()->json(['status' => 'cleared']);
    }
}
```

### Sessões persistentes (base de dados)

Por defeito, o `CacheMemory` guarda o histórico no Laravel Cache (expiração configurável). Para sessões persistentes:

```php
final class DatabaseChatMemory implements ChatMemoryInterface
{
    public function load(string $sessionId): array
    {
        return ChatSession::where('session_id', $sessionId)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($msg) => new AiMessage($msg->role, $msg->content))
            ->all();
    }

    public function save(string $sessionId, array $messages): void
    {
        ChatSession::where('session_id', $sessionId)->delete();
        foreach ($messages as $msg) {
            ChatSession::create(['session_id' => $sessionId, 'role' => $msg->role, 'content' => $msg->content]);
        }
    }

    public function append(string $sessionId, AiMessage $message): void
    {
        ChatSession::create(['session_id' => $sessionId, 'role' => $message->role, 'content' => $message->content]);
    }

    public function clear(string $sessionId): void
    {
        ChatSession::where('session_id', $sessionId)->delete();
    }
}

// Registar no AppServiceProvider:
$this->app->bind(ChatMemoryInterface::class, DatabaseChatMemory::class);
```

---

## 15. Padrões transversais

### Tratamento de erros por camada

```
Form Request    → valida formato, campos obrigatórios, tipos
ConstraintEngine → valida integridade referencial (BD)
BusinessRuleEngine → valida regras de negócio
WorkflowEngine  → valida transições de estado
Repository      → persiste
```

Cada camada tem o seu tipo de erro. Nunca misturar: um erro de formato não é uma regra de negócio.

### Logging estruturado

As engines já logam internamente com contexto. Adiciona o teu próprio contexto nas Actions:

```php
Log::withContext([
    'action'    => 'CreateOrder',
    'tenant_id' => $user->tenant_id,
    'user_id'   => $user->id,
]);

// Agora todos os logs das engines neste request têm este contexto
```

### Testar Actions com engines reais

```php
it('CreateOrderAction validates uniqueness and computes total', function () {
    // Usar NullCheckers e engines concretas — sem mocks de engines
    $constraints = new ConstraintEngine(checkers: [
        new class implements ConstraintCheckerInterface {
            public function supports(string $t): bool { return true; }
            public function check(ConstraintDefinition $d, ConstraintContext $c): bool { return true; }
        },
    ]);

    $computation = new ComputationEngine(OperationRegistry::withDefaults());
    $bre         = new BusinessRuleEngine(new RuleEvaluatorRegistry());
    $workflow    = new WorkflowEngine(definitions: [], guards: []);
    $repo        = Mockery::mock(OrderRepository::class);
    $repo->shouldReceive('create')->once()->andReturn(new Order(['id' => 1, 'total' => 215.25]));
    $repo->shouldReceive('updateState')->once();

    $action = new CreateOrderAction($constraints, $computation, $bre, $workflow, $repo);

    $order = $action->execute(
        CreateOrderDTO::from(['reference' => 'ORD-001', 'lines' => [['amount' => 175.00], ['amount' => 40.25]]]),
        new User(['id' => 1, 'tenant_id' => 1]),
    );

    expect($order->total)->toBeGreaterThan(0);
});
```

### Regras de ouro — resumo

1. **Ordem na Action é imutável**: Constraint → Computation → BRE → transaction() → Workflow → afterCommit() → Reaction
2. **Injecta sempre a interface**: nunca a classe concreta
3. **As engines não lançam**: a Action decide se lança exception
4. **ReactionEngine sempre em afterCommit()**: nunca dentro de transaction
5. **Precisão financeira**: sempre `precision: 2` em cálculos monetários
6. **Sem lógica de negócio nas engines**: as engines coordenam, as Actions decidem
7. **Erros de configuração falham rápido**: o `EngineConfigValidator` apanha em desenvolvimento
