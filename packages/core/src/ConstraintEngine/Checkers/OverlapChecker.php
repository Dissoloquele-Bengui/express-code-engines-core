<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ConstraintEngine\Checkers;

use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\Contracts\ConstraintCheckerInterface;
use ExpressCodeEngines\Shared\DTOs\ConstraintContext;
use ExpressCodeEngines\Shared\DTOs\ConstraintDefinition;

/**
 * Checks for date/time overlaps in SCHEDULABLE entities.
 *
 * Detects whether a proposed time range conflicts with any existing
 * record in the database using the standard overlap condition:
 *   existing.start < proposed.end AND existing.end > proposed.start
 *
 * config keys:
 *   table        string   (required) DB table to query
 *   start_field  string   (default: 'starts_at') Column holding the range start
 *   end_field    string   (default: 'ends_at')   Column holding the range end
 *   scope_fields array    Extra WHERE conditions for tenant/resource scoping
 *                         e.g. ['resource_id', 'tenant_id']
 *
 * data keys (resolved from ConstraintContext::data):
 *   The values for start_field, end_field and all scope_fields
 *   must be present in $context->data.
 *
 * Example definition:
 *   new ConstraintDefinition(
 *       type:        'overlap',
 *       scopeFields: ['resource_id', 'tenant_id'],
 *       message:     'This time slot is already booked.',
 *       config:      [
 *           'table'       => 'bookings',
 *           'start_field' => 'starts_at',
 *           'end_field'   => 'ends_at',
 *       ],
 *   )
 */
final class OverlapChecker implements ConstraintCheckerInterface
{
    public function supports(string $constraintType): bool
    {
        return $constraintType === 'overlap';
    }

    public function check(ConstraintDefinition $definition, ConstraintContext $context): bool
    {
        $table      = $definition->config['table']       ?? null;
        $startField = $definition->config['start_field'] ?? 'starts_at';
        $endField   = $definition->config['end_field']   ?? 'ends_at';

        if (! $table) {
            return true; // misconfigured — pass silently
        }

        $proposedStart = $context->data[$startField] ?? null;
        $proposedEnd   = $context->data[$endField]   ?? null;

        if ($proposedStart === null || $proposedEnd === null) {
            return true; // missing dates — not this checker's concern
        }

        $query = DB::table($table)
            ->where($startField, '<', $proposedEnd)
            ->where($endField,   '>', $proposedStart);

        // Scope to the same resource/tenant
        foreach ($definition->scopeFields as $field) {
            $query->where($field, $context->data[$field] ?? null);
        }

        // Exclude the current record on updates
        if ($context->existingId !== null) {
            $query->where('id', '!=', $context->existingId);
        }

        // Returns true (passes) when NO overlap exists
        return ! $query->exists();
    }
}
