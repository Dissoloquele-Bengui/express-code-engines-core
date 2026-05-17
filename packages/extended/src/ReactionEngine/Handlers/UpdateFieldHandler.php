<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ReactionEngine\Handlers;

use ExpressCodeEngines\Shared\Contracts\HandlerResult;
use ExpressCodeEngines\Shared\Contracts\ReactionHandlerInterface;
use ExpressCodeEngines\Shared\DTOs\ReactionContext;

/**
 * Built-in reaction handler: 'update_field'
 *
 * Updates a field on a related Eloquent model when an event fires.
 * Supports atomic operations for financial accuracy and stock management.
 *
 * Required params:
 *   model       string  Fully-qualified Model class, e.g. 'App\Models\Account'
 *   id_path     string  Dot-path to the model's ID in the context payload
 *   field       string  The field to update
 *
 * Value resolution (one of these is required):
 *   value_path  string  Dot-path to the value in the context payload
 *   value       mixed   Literal value (used when value_path is absent)
 *
 * Optional params:
 *   operation   string  How to apply the value to the field. Default: 'set'
 *                       Supported: 'set', 'increment', 'decrement', 'multiply', 'append'
 *
 * ──────────────────────────────────────────────────────────────
 * Operations explained:
 *
 *   set        →  UPDATE table SET field = value          (default, replaces)
 *   increment  →  UPDATE table SET field = field + value  (atomic, for stock in)
 *   decrement  →  UPDATE table SET field = field - value  (atomic, for stock out / balance debit)
 *   multiply   →  UPDATE table SET field = field * value  (for percentage adjustments)
 *   append     →  UPDATE table SET field = CONCAT(field, value) (for audit trails / notes)
 *
 * ──────────────────────────────────────────────────────────────
 * ERP Examples:
 *
 *   Decrement balance after expense:
 *   [
 *       'event'   => 'expense.created',
 *       'handler' => 'update_field',
 *       'async'   => false,
 *       'params'  => [
 *           'model'      => 'App\Models\Account',
 *           'id_path'    => 'expense.account_id',
 *           'field'      => 'dc_balance',
 *           'operation'  => 'decrement',
 *           'value_path' => 'expense.amount',
 *       ],
 *   ]
 *
 *   Increment stock after purchase:
 *   [
 *       'event'   => 'purchase.confirmed',
 *       'handler' => 'update_field',
 *       'async'   => false,
 *       'params'  => [
 *           'model'      => 'App\Models\Product',
 *           'id_path'    => 'line.product_id',
 *           'field'      => 'it_stock_quantity',
 *           'operation'  => 'increment',
 *           'value_path' => 'line.quantity',
 *       ],
 *   ]
 *
 *   Reverse balance on cancellation:
 *   [
 *       'event'   => 'expense.cancelled',
 *       'handler' => 'update_field',
 *       'async'   => false,
 *       'params'  => [
 *           'model'      => 'App\Models\Account',
 *           'id_path'    => 'expense.account_id',
 *           'field'      => 'dc_balance',
 *           'operation'  => 'increment',
 *           'value_path' => 'expense.amount',
 *       ],
 *   ]
 */
final class UpdateFieldHandler implements ReactionHandlerInterface
{
    /** Allowed operations — whitelist for safety */
    private const OPERATIONS = ['set', 'increment', 'decrement', 'multiply', 'append'];

    public function key(): string
    {
        return 'update_field';
    }

    public function handle(ReactionContext $context, array $params): HandlerResult
    {
        $modelClass = $params['model']     ?? null;
        $idPath     = $params['id_path']   ?? null;
        $field      = $params['field']     ?? null;
        $valuePath  = $params['value_path'] ?? null;
        $operation  = $params['operation']  ?? 'set';

        // ── Validation ────────────────────────────────────────
        if (! $modelClass || ! $idPath || ! $field) {
            return HandlerResult::failed('update_field requires model, id_path and field params.');
        }

        if (! in_array($operation, self::OPERATIONS, true)) {
            return HandlerResult::failed(
                "Unknown operation '{$operation}'. Allowed: " . implode(', ', self::OPERATIONS)
            );
        }

        $id = $context->resolve($idPath);
        if ($id === null) {
            return HandlerResult::failed("Could not resolve id from path '{$idPath}'.");
        }

        $value = $valuePath
            ? $context->resolve($valuePath)
            : ($params['value'] ?? null);

        if ($value === null && $operation !== 'set') {
            return HandlerResult::failed("Operation '{$operation}' requires a value.");
        }

        // ── Execute ───────────────────────────────────────────
        try {
            $query = $modelClass::where('id', $id);

            $result = match ($operation) {
                'set'       => $query->update([$field => $value]),
                'increment' => $query->increment($field, $value),
                'decrement' => $query->decrement($field, $value),
                'multiply'  => $query->update([$field => \Illuminate\Support\Facades\DB::raw("{$field} * {$value}")]),
                'append'    => $query->update([$field => \Illuminate\Support\Facades\DB::raw("CONCAT(COALESCE({$field}, ''), " . \Illuminate\Support\Facades\DB::connection()->getPdo()->quote((string) $value) . ")")]),
            };

            return HandlerResult::ok([
                'model'     => $modelClass,
                'id'        => $id,
                'field'     => $field,
                'operation' => $operation,
                'value'     => $value,
                'affected'  => $result,
            ]);
        } catch (\Throwable $e) {
            try { \Illuminate\Support\Facades\Log::error('[UpdateFieldHandler] DB update failed.', [
                'error' => $e->getMessage(), 'operation' => $operation,
            ]); } catch (\Throwable $_) {}

            return HandlerResult::failed($e->getMessage());
        }
    }

    public function dispatchAsync(ReactionContext $context, array $params, ?string $queue, int $delaySeconds): void
    {
        // Field updates are always sync — data integrity requires immediate execution
        $this->handle($context, $params);
    }
}