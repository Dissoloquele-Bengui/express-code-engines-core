<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\IntegrationEngine\Http;

/**
 * Maps a local payload to the remote API format using a field mapping definition.
 *
 * Mapping format:
 *   'local.dot.path' => 'remote.dot.path'
 *
 * If the mapping array is empty, the payload is sent as-is.
 *
 * Examples:
 *   ['order.reference' => 'externalRef']
 *   → ['order' => ['reference' => 'ORD-001']] becomes ['externalRef' => 'ORD-001']
 *
 *   ['customer.email' => 'contact.email']
 *   → nested on both sides: ['contact' => ['email' => 'user@example.com']]
 */
final class PayloadMapper
{
    /**
     * @param  array  $payload  Local payload
     * @param  array  $mapping  ['local.path' => 'remote.path']
     * @return array            Mapped payload for the remote API
     */
    public function map(array $payload, array $mapping): array
    {
        if (empty($mapping)) {
            return $payload;
        }

        $result = [];

        foreach ($mapping as $localPath => $remotePath) {
            $value = $this->resolveLocal($localPath, $payload);

            if ($value === null) {
                continue; // skip unmapped fields silently
            }

            $this->setRemote($remotePath, $value, $result);
        }

        return $result;
    }

    private function resolveLocal(string $path, array $data): mixed
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

    private function setRemote(string $path, mixed $value, array &$target): void
    {
        $segments = explode('.', $path);
        $current  = &$target;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }
    }
}
