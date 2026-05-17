<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\ReactionEngine\Handlers;

use ExpressCodeEngines\Shared\Contracts\HandlerResult;
use ExpressCodeEngines\Shared\Contracts\ReactionHandlerInterface;
use ExpressCodeEngines\Shared\DTOs\ReactionContext;

/**
 * Built-in reaction handler: 'recalculate_totals'
 *
 * Recalculates aggregate fields on a parent record when child lines change.
 * Uses SQL aggregation for accuracy — never trusts cached values.
 *
 * Required params:
 *   parent_model    string  Parent Model class, e.g. 'App\Models\Invoice'
 *   parent_id_path  string  Dot-path to the parent's ID in the context payload
 *   lines_table     string  Child table name, e.g. 'invoice_lines'
 *   foreign_key     string  FK column in child table, e.g. 'invoice_id'
 *   aggregations    array   Map of parent_field => ['function' => 'sum|count|avg|min|max', 'column' => 'line_column']
 *
 * ──────────────────────────────────────────────────────────────
 * ERP Examples:
 *
 *   Recalculate invoice totals after line change:
 *   [
 *       'event'   => 'invoice_line.*',
 *       'handler' => 'recalculate_totals',
 *       'async'   => false,
 *       'params'  => [
 *           'parent_model'   => 'App\Models\Invoice',
 *           'parent_id_path' => 'line.invoice_id',
 *           'lines_table'    => 'invoice_lines',
 *           'foreign_key'    => 'invoice_id',
 *           'aggregations'   => [
 *               'dc_subtotal'   => ['function' => 'sum', 'column' => 'dc_line_total'],
 *               'dc_tax_total'  => ['function' => 'sum', 'column' => 'dc_tax_amount'],
 *               'it_line_count' => ['function' => 'count', 'column' => 'id'],
 *           ],
 *       ],
 *   ]
 *
 *   Recalculate order total after order line change:
 *   [
 *       'event'   => 'order_line.*',
 *       'handler' => 'recalculate_totals',
 *       'async'   => false,
 *       'params'  => [
 *           'parent_model'   => 'App\Models\Order',
 *           'parent_id_path' => 'line.order_id',
 *           'lines_table'    => 'order_lines',
 *           'foreign_key'    => 'order_id',
 *           'aggregations'   => [
 *               'dc_total'      => ['function' => 'sum', 'column' => 'dc_line_total'],
 *               'it_line_count' => ['function' => 'count', 'column' => 'id'],
 *           ],
 *       ],
 *   ]
 */
final class RecalculateTotalsHandler implements ReactionHandlerInterface
{
    private const ALLOWED_FUNCTIONS = ['sum', 'count', 'avg', 'min', 'max'];

    public function key(): string
    {
        return 'recalculate_totals';
    }

    public function handle(ReactionContext $context, array $params): HandlerResult
    {
        $parentModel  = $params['parent_model']   ?? null;
        $parentIdPath = $params['parent_id_path'] ?? null;
        $linesTable   = $params['lines_table']    ?? null;
        $foreignKey   = $params['foreign_key']    ?? null;
        $aggregations = $params['aggregations']   ?? null;

        if (! $parentModel || ! $parentIdPath || ! $linesTable || ! $foreignKey || ! is_array($aggregations)) {
            return HandlerResult::failed('recalculate_totals requires parent_model, parent_id_path, lines_table, foreign_key and aggregations.');
        }

        $parentId = $context->resolve($parentIdPath);
        if ($parentId === null) {
            return HandlerResult::failed("Could not resolve parent id from path '{$parentIdPath}'.");
        }

        try {
            // Build SQL aggregations
            $selects = [];
            foreach ($aggregations as $parentField => $agg) {
                $func   = strtolower($agg['function'] ?? '');
                $column = $agg['column'] ?? null;

                if (! in_array($func, self::ALLOWED_FUNCTIONS, true) || ! $column) {
                    return HandlerResult::failed("Invalid aggregation for '{$parentField}': function must be one of " . implode(', ', self::ALLOWED_FUNCTIONS));
                }

                $selects[$parentField] = \Illuminate\Support\Facades\DB::raw(
                    strtoupper($func) . "({$column})"
                );
            }

            // Execute single query to get all aggregates
            $result = \Illuminate\Support\Facades\DB::table($linesTable)
                ->where($foreignKey, $parentId)
                ->selectRaw(
                    collect($aggregations)->map(function ($agg, $alias) {
                        return strtoupper($agg['function']) . "({$agg['column']}) as {$alias}";
                    })->implode(', ')
                )
                ->first();

            // Update parent with computed values
            $updates = [];
            foreach ($aggregations as $parentField => $agg) {
                $updates[$parentField] = $result?->{$parentField} ?? 0;
            }

            $parentModel::where('id', $parentId)->update($updates);

            return HandlerResult::ok([
                'parent_model' => $parentModel,
                'parent_id'    => $parentId,
                'totals'       => $updates,
            ]);
        } catch (\Throwable $e) {
            try { \Illuminate\Support\Facades\Log::error('[RecalculateTotalsHandler] Failed.', [
                'error' => $e->getMessage(),
            ]); } catch (\Throwable $_) {}

            return HandlerResult::failed($e->getMessage());
        }
    }

    public function dispatchAsync(ReactionContext $context, array $params, ?string $queue, int $delaySeconds): void
    {
        $this->handle($context, $params);
    }
}