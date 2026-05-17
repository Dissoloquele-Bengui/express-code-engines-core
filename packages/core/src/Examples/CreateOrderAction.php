<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\Contracts\BusinessRuleEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ComputationEngineInterface;
use ExpressCodeEngines\Shared\Contracts\ConstraintEngineInterface;
use ExpressCodeEngines\Shared\Contracts\WorkflowEngineInterface;
use ExpressCodeEngines\Shared\DTOs\ConstraintContext;
use ExpressCodeEngines\Shared\DTOs\ConstraintDefinition;
use ExpressCodeEngines\Shared\DTOs\ComputationRequest;
use ExpressCodeEngines\Shared\DTOs\RuleContext;
use ExpressCodeEngines\Shared\DTOs\RuleDefinition;
use ExpressCodeEngines\Shared\DTOs\TransitionRequest;
use App\DTOs\CreateOrderDTO;
use App\Exceptions\ConstraintException;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Repositories\OrderRepository;

/**
 * Example of a real Action orchestrating all 4 core engines.
 *
 * Orchestration order (always):
 *   1. ConstraintEngine  — hard integrity checks (fail fast)
 *   2. ComputationEngine — compute derived fields before BRE
 *   3. BusinessRuleEngine — business rules evaluated on complete data
 *   4. (Repository)      — persist inside DB::transaction()
 *   5. WorkflowEngine    — transition state (in memory, persisted by Action)
 *   6. Post-actions      — dispatch after commit (ReactionEngine / Events)
 */
final class CreateOrderAction
{
    public function __construct(
        private readonly ConstraintEngineInterface  $constraints,
        private readonly ComputationEngineInterface $computation,
        private readonly BusinessRuleEngineInterface $bre,
        private readonly WorkflowEngineInterface    $workflow,
        private readonly OrderRepository            $orders,
    ) {}

    public function execute(CreateOrderDTO $dto, object $user): Order
    {
        // ── Step 1: Constraint validation (fail fast, before any computation) ──

        $constraintResult = $this->constraints->validate(
            context: new ConstraintContext(
                entityClass: Order::class,
                data:        $dto->toArray(),
                meta:        ['tenant_id' => $user->tenant_id],
            ),
            definitions: [
                new ConstraintDefinition(
                    type:        'uniqueness',
                    scopeFields: ['reference', 'tenant_id'],
                    message:     'An order with this reference already exists.',
                    config:      ['table' => 'orders'],
                ),
                new ConstraintDefinition(
                    type:    'limit',
                    message: 'Daily order limit reached.',
                    config:  ['table' => 'orders', 'limit' => 100, 'period' => 'today'],
                ),
            ],
        );

        if (! $constraintResult->passed) {
            throw new ConstraintException($constraintResult->firstMessage());
        }

        // ── Step 2: Compute derived fields ──

        $totals = $this->computation->computeMany([
            'subtotal' => new ComputationRequest(
                operation: 'sum',
                args:      array_column($dto->items, 'line_total'),
            ),
            'tax' => new ComputationRequest(
                operation: 'percentage',
                args:      ['subtotal', $dto->taxRate],
                context:   ['subtotal' => /* resolved after subtotal */ 0], // filled below
                precision: 2,
            ),
        ]);

        $subtotal = $totals['subtotal']->value;
        $tax      = (new ComputationRequest('percentage', [$subtotal, $dto->taxRate], precision: 2));
        $taxValue = $this->computation->compute($tax)->value;
        $total    = $subtotal + $taxValue;

        // ── Step 3: Business rule evaluation on complete data ──

        $breResponse = $this->bre->evaluate(
            context: new RuleContext(
                data: [
                    'order'    => array_merge($dto->toArray(), ['total' => $total]),
                    'customer' => $dto->customer->toArray(),
                ],
                user: $user,
            ),
            rules: [
                new RuleDefinition(
                    id:       'min_order_value',
                    ruleType: 'comparison',
                    field:    'order.total',
                    params:   ['operator' => '>=', 'value' => 10.00],
                    severity: 'deny',
                    message:  'Minimum order value is €10.00.',
                ),
                new RuleDefinition(
                    id:       'large_order_flag',
                    ruleType: 'comparison',
                    field:    'order.total',
                    params:   ['operator' => '>', 'value' => 5000.00],
                    severity: 'allow',
                    message:  'Large order — manager notification approved.',
                    effects:  ['notify_manager'],
                ),
            ],
        );

        if ($breResponse->hasDenials()) {
            throw new BusinessRuleException($breResponse->firstDenialMessage());
        }

        // ── Steps 4 & 5: Persist + state transition inside a single transaction ──

        return DB::transaction(function () use ($dto, $user, $total, $taxValue, $subtotal, $breResponse) {

            $order = $this->orders->create([
                ...$dto->toArray(),
                'subtotal'   => $subtotal,
                'tax'        => $taxValue,
                'total'      => $total,
                'created_by' => $user->id,
            ]);

            // WorkflowEngine transitions the state in memory — Action persists it
            $transition = $this->workflow->transition(new TransitionRequest(
                entityClass:  Order::class,
                currentState: 'draft',
                transition:   'submit',
                data:         $order->toArray(),
                user:         $user,
            ));

            if ($transition->wasAllowed()) {
                $this->orders->updateState($order, $transition->newState, $transition->metadata);
            }

            // ── Step 6: Dispatch post-actions AFTER commit ──
            // Using Laravel's afterCommit to guarantee order
            DB::afterCommit(function () use ($order, $breResponse, $transition) {
                // Approved BRE effects
                foreach ($breResponse->approvedEffects as $effect) {
                    event("engine.effect.{$effect}", ['order' => $order]);
                }

                // Workflow post-actions
                foreach ($transition->postActions as $action) {
                    event("engine.workflow.{$action}", ['order' => $order]);
                }
            });

            return $order;
        });
    }
}
