<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ConstraintEngine\Checkers;

use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\Contracts\ConstraintCheckerInterface;
use ExpressCodeEngines\Shared\DTOs\ConstraintContext;
use ExpressCodeEngines\Shared\DTOs\ConstraintDefinition;

/**
 * Checks that a count-based limit has not been reached.
 *
 * Counts existing records (optionally scoped by period and fields)
 * and fails if the count equals or exceeds the configured limit.
 *
 * config keys:
 *   table        string   (required) DB table to query
 *   limit        int      (required) Maximum number of records allowed
 *   period       string   (optional) Time window: 'today', 'this_week', 'this_month',
 *                                    'this_year', or an ISO duration string like 'P30D'
 *   date_field   string   (default: 'created_at') Column used for period filtering
 *   scope_fields array    Scoping fields resolved from context->data and context->meta
 *                         e.g. ['tenant_id', 'user_id']
 *
 * Example definitions:
 *
 *   // Max 100 orders per day per tenant
 *   new ConstraintDefinition(
 *       type:        'limit',
 *       scopeFields: ['tenant_id'],
 *       message:     'Daily order limit of 100 reached.',
 *       config:      ['table' => 'orders', 'limit' => 100, 'period' => 'today'],
 *   )
 *
 *   // Max 3 active subscriptions per user (no period — counts all)
 *   new ConstraintDefinition(
 *       type:        'limit',
 *       scopeFields: ['user_id'],
 *       message:     'Maximum of 3 active subscriptions allowed.',
 *       config:      ['table' => 'subscriptions', 'limit' => 3],
 *   )
 */
final class LimitChecker implements ConstraintCheckerInterface
{
    public function supports(string $constraintType): bool
    {
        return $constraintType === 'limit';
    }

    public function check(ConstraintDefinition $definition, ConstraintContext $context): bool
    {
        $table     = $definition->config['table']      ?? null;
        $limit     = $definition->config['limit']      ?? null;
        $period    = $definition->config['period']     ?? null;
        $dateField = $definition->config['date_field'] ?? 'created_at';

        if (! $table || $limit === null) {
            return true; // misconfigured — pass silently
        }

        // Merge data + meta for scope resolution (tenant_id often lives in meta)
        $scopeSource = array_merge($context->meta, $context->data);

        $query = DB::table($table);

        // Apply scope fields
        foreach ($definition->scopeFields as $field) {
            if (array_key_exists($field, $scopeSource)) {
                $query->where($field, $scopeSource[$field]);
            }
        }

        // Apply period filter
        if ($period !== null) {
            [$from, $to] = $this->resolvePeriod($period);
            $query->whereBetween($dateField, [$from, $to]);
        }

        $count = $query->count();

        // Returns true (passes) when the count is strictly below the limit
        return $count < (int) $limit;
    }

    /**
     * Resolves a period string to a [from, to] pair of Carbon/datetime strings.
     *
     * @return array{string, string}
     */
    private function resolvePeriod(string $period): array
    {
        $now = now();

        return match ($period) {
            'today'      => [$now->copy()->startOfDay(),   $now->copy()->endOfDay()],
            'this_week'  => [$now->copy()->startOfWeek(),  $now->copy()->endOfWeek()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'this_year'  => [$now->copy()->startOfYear(),  $now->copy()->endOfYear()],
            default      => $this->resolveIsoDuration($period, $now),
        };
    }

    /**
     * Resolves an ISO 8601 duration string like 'P30D', 'PT1H', 'P1Y'.
     * Returns [now - duration, now].
     *
     * @return array{string, string}
     */
    private function resolveIsoDuration(string $duration, \Illuminate\Support\Carbon $now): array
    {
        try {
            $interval = new \DateInterval($duration);
            $from     = $now->copy()->sub($interval);
            return [$from, $now];
        } catch (\Exception) {
            // Unrecognised period — use current day as fallback
            return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
        }
    }
}
