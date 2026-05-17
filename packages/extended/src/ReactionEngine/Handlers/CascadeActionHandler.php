<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ReactionEngine\Handlers;

use ExpressCodeEngines\Shared\Contracts\HandlerResult;
use ExpressCodeEngines\Shared\Contracts\ReactionHandlerInterface;
use ExpressCodeEngines\Shared\DTOs\ReactionContext;

/**
 * Built-in reaction handler: 'cascade_action'
 *
 * Performs bulk operations on related records when a parent event fires.
 * Covers scenarios that DB-level cascades can't handle (cross-table business logic,
 * soft-deletes, status propagation).
 *
 * Required params:
 *   model         string  Target Model class
 *   foreign_key   string  Column in target table that references the parent
 *   parent_id_path string Dot-path to the parent's ID in the context payload
 *   action        string  What to do: 'delete', 'soft_delete', 'update'
 *
 * Optional params (when action = 'update'):
 *   update_fields  array  Key-value pairs to set on matching records
 *
 * ──────────────────────────────────────────────────────────────
 * ERP Examples:
 *
 *   Cancel all order lines when order is cancelled:
 *   [
 *       'event'   => 'order.cancelled',
 *       'handler' => 'cascade_action',
 *       'async'   => false,
 *       'params'  => [
 *           'model'          => 'App\Models\OrderLine',
 *           'foreign_key'    => 'order_id',
 *           'parent_id_path' => 'order.id',
 *           'action'         => 'update',
 *           'update_fields'  => ['status' => 'cancelled'],
 *       ],
 *   ]
 *
 *   Release stock reservations when order is cancelled:
 *   [
 *       'event'   => 'order.cancelled',
 *       'handler' => 'cascade_action',
 *       'async'   => false,
 *       'params'  => [
 *           'model'          => 'App\Models\StockReservation',
 *           'foreign_key'    => 'order_id',
 *           'parent_id_path' => 'order.id',
 *           'action'         => 'delete',
 *       ],
 *   ]
 *
 *   Soft-delete invoice lines when invoice is voided:
 *   [
 *       'event'   => 'invoice.voided',
 *       'handler' => 'cascade_action',
 *       'async'   => false,
 *       'params'  => [
 *           'model'          => 'App\Models\InvoiceLine',
 *           'foreign_key'    => 'invoice_id',
 *           'parent_id_path' => 'invoice.id',
 *           'action'         => 'soft_delete',
 *       ],
 *   ]
 */
final class CascadeActionHandler implements ReactionHandlerInterface
{
    private const ALLOWED_ACTIONS = ['delete', 'soft_delete', 'update'];

    public function key(): string
    {
        return 'cascade_action';
    }

    public function handle(ReactionContext $context, array $params): HandlerResult
    {
        $modelClass   = $params['model']          ?? null;
        $foreignKey   = $params['foreign_key']    ?? null;
        $parentIdPath = $params['parent_id_path'] ?? null;
        $action       = $params['action']         ?? null;

        if (! $modelClass || ! $foreignKey || ! $parentIdPath || ! $action) {
            return HandlerResult::failed('cascade_action requires model, foreign_key, parent_id_path and action.');
        }

        if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
            return HandlerResult::failed(
                "Unknown action '{$action}'. Allowed: " . implode(', ', self::ALLOWED_ACTIONS)
            );
        }

        $parentId = $context->resolve($parentIdPath);
        if ($parentId === null) {
            return HandlerResult::failed("Could not resolve parent id from path '{$parentIdPath}'.");
        }

        try {
            $query = $modelClass::where($foreignKey, $parentId);

            $affected = match ($action) {
                'delete'      => $query->delete(),
                'soft_delete' => $query->update(['deleted_at' => now()]),
                'update'      => $query->update($params['update_fields'] ?? []),
            };

            return HandlerResult::ok([
                'model'    => $modelClass,
                'action'   => $action,
                'parent_id'=> $parentId,
                'affected' => $affected,
            ]);
        } catch (\Throwable $e) {
            try { \Illuminate\Support\Facades\Log::error('[CascadeActionHandler] Failed.', [
                'error' => $e->getMessage(), 'action' => $action,
            ]); } catch (\Throwable $_) {}

            return HandlerResult::failed($e->getMessage());
        }
    }

    public function dispatchAsync(ReactionContext $context, array $params, ?string $queue, int $delaySeconds): void
    {
        $this->handle($context, $params);
    }
}