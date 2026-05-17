<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Shared\DTOs;

/**
 * The data context evaluated by the BRE.
 * Usually built from a DTO populated by the Action before calling the engine.
 */
final class RuleContext
{
    public function __construct(
        /** Flat or nested array of data to evaluate rules against */
        public readonly array $data,

        /** Optional: resolved user for permission-aware rules */
        public readonly ?object $user = null,

        /** Optional: extra metadata (tenant, locale, flags) */
        public readonly array $meta = [],
    ) {}

    /**
     * Resolves a dot-path safely. Returns null if any segment is missing.
     * Example: resolve('order.items.0.qty') on nested $data array.
     */
    public function resolve(string $path): mixed
    {
        $segments = explode('.', $path);
        $value    = $this->data;

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
