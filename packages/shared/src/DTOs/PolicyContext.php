<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * The context passed into the DynamicPolicyEngine for ability checks.
 */
final class PolicyContext
{
    public function __construct(
        /** The user requesting access */
        public readonly object $user,

        /** The ability being requested (e.g. 'view', 'update') */
        public readonly string $ability,

        /**
         * The model or data being accessed.
         * Can be a Model instance (toArray() is called internally)
         * or a plain array of record data.
         */
        public readonly object|array|null $record = null,

        /** Extra context data (tenant_id, region, flags, etc.) */
        public readonly array $meta = [],
    ) {}

    /**
     * Resolves a dot-path against the appropriate data source.
     *
     * Prefix 'record.' → resolves against the record array
     * Prefix 'user.'   → resolves against the user object properties
     * Prefix 'meta.'   → resolves against meta array
     * No prefix        → tries record first, then meta
     */
    public function resolve(string $path): mixed
    {
        if (str_starts_with($path, 'record.')) {
            return $this->resolveFromRecord(substr($path, 7));
        }

        if (str_starts_with($path, 'user.')) {
            return $this->resolveFromUser(substr($path, 5));
        }

        if (str_starts_with($path, 'meta.')) {
            return $this->resolveFromArray(substr($path, 5), $this->meta);
        }

        // No prefix — try record, then meta
        $fromRecord = $this->resolveFromRecord($path);
        return $fromRecord ?? $this->resolveFromArray($path, $this->meta);
    }

    private function resolveFromRecord(string $path): mixed
    {
        $data = match (true) {
            is_array($this->record)  => $this->record,
            $this->record !== null   => method_exists($this->record, 'toArray')
                ? $this->record->toArray()
                : (array) $this->record,
            default                  => [],
        };

        return $this->resolveFromArray($path, $data);
    }

    private function resolveFromUser(string $path): mixed
    {
        $data = method_exists($this->user, 'toArray')
            ? $this->user->toArray()
            : (array) $this->user;

        return $this->resolveFromArray($path, $data);
    }

    private function resolveFromArray(string $path, array $data): mixed
    {
        $segments = explode('.', $path);
        $value    = $data;

        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }
}
