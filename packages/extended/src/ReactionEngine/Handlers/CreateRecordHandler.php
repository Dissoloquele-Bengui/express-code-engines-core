<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ReactionEngine\Handlers;

use ExpressCodeEngines\Shared\Contracts\HandlerResult;
use ExpressCodeEngines\Shared\Contracts\ReactionHandlerInterface;
use ExpressCodeEngines\Shared\DTOs\ReactionContext;

/**
 * Built-in reaction handler: 'create_record'
 *
 * Creates a new record in a related table when an event fires.
 * Essential for audit trails, financial movements, and denormalised logs.
 *
 * Required params:
 *   model       string  Fully-qualified Model class, e.g. 'App\Models\AccountMovement'
 *   fields      array   Map of field_name => source, where source is:
 *                        - A dot-path string (resolved from context payload)
 *                        - A literal value prefixed with '=' (e.g. '=credit')
 *                        - 'now' for current timestamp
 *                        - 'actor.id' for the triggering user's ID
 *
 * ──────────────────────────────────────────────────────────────
 * ERP Examples:
 *
 *   Log financial movement after expense:
 *   [
 *       'event'   => 'expense.created',
 *       'handler' => 'create_record',
 *       'async'   => false,
 *       'params'  => [
 *           'model'  => 'App\Models\AccountMovement',
 *           'fields' => [
 *               'account_id'  => 'expense.account_id',
 *               'amount'      => 'expense.amount',
 *               'type'        => '=debit',
 *               'reference'   => 'expense.reference',
 *               'description' => 'expense.description',
 *               'created_by'  => 'actor.id',
 *               'created_at'  => 'now',
 *           ],
 *       ],
 *   ]
 *
 *   Audit trail for status changes:
 *   [
 *       'event'   => 'order.status_changed',
 *       'handler' => 'create_record',
 *       'async'   => false,
 *       'params'  => [
 *           'model'  => 'App\Models\OrderAuditLog',
 *           'fields' => [
 *               'order_id'   => 'order.id',
 *               'from_status'=> 'transition.from',
 *               'to_status'  => 'transition.to',
 *               'changed_by' => 'actor.id',
 *               'reason'     => 'transition.reason',
 *               'changed_at' => 'now',
 *           ],
 *       ],
 *   ]
 *
 *   Stock movement log:
 *   [
 *       'event'   => 'sale.confirmed',
 *       'handler' => 'create_record',
 *       'async'   => false,
 *       'params'  => [
 *           'model'  => 'App\Models\StockMovement',
 *           'fields' => [
 *               'product_id'  => 'line.product_id',
 *               'quantity'    => 'line.quantity',
 *               'type'        => '=out',
 *               'reference'   => 'sale.reference',
 *               'warehouse_id'=> 'line.warehouse_id',
 *               'created_at'  => 'now',
 *           ],
 *       ],
 *   ]
 */
final class CreateRecordHandler implements ReactionHandlerInterface
{
    public function key(): string
    {
        return 'create_record';
    }

    public function handle(ReactionContext $context, array $params): HandlerResult
    {
        $modelClass = $params['model']  ?? null;
        $fields     = $params['fields'] ?? null;

        if (! $modelClass || ! is_array($fields) || empty($fields)) {
            return HandlerResult::failed('create_record requires model and fields params.');
        }

        try {
            $data = $this->resolveFields($context, $fields);
            $record = $modelClass::create($data);

            return HandlerResult::ok([
                'model' => $modelClass,
                'id'    => $record->id ?? null,
                'data'  => $data,
            ]);
        } catch (\Throwable $e) {
            try { \Illuminate\Support\Facades\Log::error('[CreateRecordHandler] Insert failed.', [
                'error' => $e->getMessage(), 'model' => $modelClass,
            ]); } catch (\Throwable $_) {}

            return HandlerResult::failed($e->getMessage());
        }
    }

    public function dispatchAsync(ReactionContext $context, array $params, ?string $queue, int $delaySeconds): void
    {
        // Audit and movement records should be sync for integrity
        $this->handle($context, $params);
    }

    /**
     * Resolve each field source to its value.
     *
     * Sources:
     *   'expense.amount'    → dot-path from payload
     *   '=credit'           → literal value (strip the =)
     *   'now'               → current timestamp
     *   'actor.id'          → user ID from actor
     */
    private function resolveFields(ReactionContext $context, array $fields): array
    {
        $data = [];

        foreach ($fields as $column => $source) {
            $data[$column] = match (true) {
                // Literal value: '=credit', '=debit', '=pending'
                str_starts_with((string) $source, '=') => substr((string) $source, 1),

                // Current timestamp
                $source === 'now' => now(),

                // Actor ID
                $source === 'actor.id' => $context->actor?->id ?? null,

                // Actor property: 'actor.name', 'actor.email'
                str_starts_with((string) $source, 'actor.') => $context->actor?->{substr($source, 6)} ?? null,

                // Meta values: 'meta.tenant_id'
                str_starts_with((string) $source, 'meta.') => $context->meta[substr($source, 5)] ?? null,

                // Default: dot-path from payload
                default => $context->resolve((string) $source),
            };
        }

        return $data;
    }
}