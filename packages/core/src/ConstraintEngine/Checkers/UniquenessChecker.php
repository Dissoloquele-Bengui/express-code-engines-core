<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\ConstraintEngine\Checkers;

use Illuminate\Support\Facades\DB;
use ExpressCodeEngines\Shared\Contracts\ConstraintCheckerInterface;
use ExpressCodeEngines\Shared\DTOs\ConstraintContext;
use ExpressCodeEngines\Shared\DTOs\ConstraintDefinition;

/**
 * Checks that a combination of fields is unique in the database.
 *
 * Config keys:
 *   table        (string, required) — DB table to query
 *   scope_fields (array)            — fields whose values must be unique together
 *
 * Example definition:
 *   new ConstraintDefinition(
 *       type: 'uniqueness',
 *       scopeFields: ['email', 'tenant_id'],
 *       message: 'This email is already registered for this tenant.',
 *       config: ['table' => 'users'],
 *   )
 */
final class UniquenessChecker implements ConstraintCheckerInterface
{
    public function supports(string $constraintType): bool
    {
        return $constraintType === 'uniqueness';
    }

    public function check(ConstraintDefinition $definition, ConstraintContext $context): bool
    {
        $table = $definition->config['table'] ?? null;

        if (! $table) {
            return true; // misconfigured — pass silently, log in production
        }

        $query = DB::table($table);

        foreach ($definition->scopeFields as $field) {
            $query->where($field, $context->data[$field] ?? null);
        }

        // Exclude the current record on updates
        if ($context->existingId !== null) {
            $query->where('id', '!=', $context->existingId);
        }

        return ! $query->exists();
    }
}
