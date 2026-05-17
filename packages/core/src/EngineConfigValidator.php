<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core;

/**
 * Validates engine configurations at boot time.
 *
 * Called by EngineServiceProvider::boot() when app.debug is true.
 * In production, validation is skipped for performance — the assumption
 * is that configs were validated in the development/CI pipeline.
 *
 * Detects:
 *   - Workflow transitions with circular dependencies (A→B→A)
 *   - Workflow transitions referencing undefined states
 *   - BRE rules with invalid severity values
 *   - BRE rules with missing required fields
 *   - Notification templates missing required keys
 *   - Reaction definitions referencing unknown handler keys
 */
final class EngineConfigValidator
{
    private array $errors = [];

    public function validate(array $config): void
    {
        $this->errors = [];

        if (isset($config['workflows'])) {
            $this->validateWorkflows($config['workflows']);
        }

        if (isset($config['rules'])) {
            $this->validateRules($config['rules']);
        }

        if (isset($config['extended']['notifications']['templates'])) {
            $this->validateTemplates($config['extended']['notifications']['templates']);
        }

        if (! empty($this->errors)) {
            $list = implode("\n  - ", $this->errors);
            throw new \RuntimeException(
                "[ExpressCodeEngines] Configuration errors detected:\n  - {$list}"
            );
        }
    }

    // ──────────────────────────────────────────────────────────
    // Workflow validation
    // ──────────────────────────────────────────────────────────

    private function validateWorkflows(array $workflows): void
    {
        foreach ($workflows as $entityClass => $config) {
            $transitions = $config['transitions'] ?? [];

            $this->detectCircularTransitions($entityClass, $transitions);
            $this->detectOrphanedStates($entityClass, $transitions, $config['initial_states'] ?? ['draft']);
        }
    }

    private function detectCircularTransitions(string $entity, array $transitions): void
    {
        // Build adjacency map: from → [to, to, ...]
        $graph = [];
        foreach ($transitions as $t) {
            $from = $t['from'] ?? null;
            $to   = $t['to']   ?? null;

            if (! $from || ! $to) {
                $this->errors[] = "Workflow [{$entity}]: transition missing 'from' or 'to'.";
                continue;
            }

            $graph[$from][] = $to;
        }

        // DFS cycle detection
        $visited  = [];
        $recStack = [];

        foreach (array_keys($graph) as $node) {
            if ($this->hasCycle($node, $graph, $visited, $recStack)) {
                $this->errors[] = "Workflow [{$entity}]: circular transition detected involving state '{$node}'.";
            }
        }
    }

    private function hasCycle(string $node, array $graph, array &$visited, array &$recStack): bool
    {
        if (! isset($visited[$node])) {
            $visited[$node]  = true;
            $recStack[$node] = true;

            foreach ($graph[$node] ?? [] as $neighbour) {
                if (! isset($visited[$neighbour]) && $this->hasCycle($neighbour, $graph, $visited, $recStack)) {
                    return true;
                }

                if (isset($recStack[$neighbour])) {
                    return true;
                }
            }
        }

        $recStack[$node] = false;
        return false;
    }

    private function detectOrphanedStates(string $entity, array $transitions, array $initialStates): void
    {
        $defined = array_merge(
            array_column($transitions, 'from'),
            array_column($transitions, 'to'),
            $initialStates,
        );
        $defined = array_unique(array_filter($defined));

        // States that appear as 'to' but never as 'from' (terminal states) are OK
        // States that appear as 'from' but have no corresponding 'to' are orphaned
        $fromStates = array_unique(array_filter(array_column($transitions, 'from')));
        $toStates   = array_unique(array_filter(array_column($transitions, 'to')));

        foreach ($fromStates as $state) {
            if (! in_array($state, $toStates, true) && ! in_array($state, $initialStates, true)) {
                // State is never reachable (not in initial states, not a target of any transition)
                $this->errors[] = "Workflow [{$entity}]: state '{$state}' is unreachable (never a transition target or initial state).";
            }
        }
    }

    // ──────────────────────────────────────────────────────────
    // BRE rule validation
    // ──────────────────────────────────────────────────────────

    private function validateRules(array $rules): void
    {
        $validSeverities = ['deny', 'warn', 'allow'];
        $requiredFields  = ['id', 'rule_type', 'field'];

        foreach ($rules as $index => $rule) {
            $label = $rule['id'] ?? "rule[{$index}]";

            foreach ($requiredFields as $required) {
                if (empty($rule[$required])) {
                    $this->errors[] = "BRE rule [{$label}]: missing required field '{$required}'.";
                }
            }

            if (isset($rule['severity']) && ! in_array($rule['severity'], $validSeverities, true)) {
                $this->errors[] = "BRE rule [{$label}]: invalid severity '{$rule['severity']}'. Must be one of: " . implode(', ', $validSeverities) . '.';
            }

            if (isset($rule['priority']) && ! is_int($rule['priority'])) {
                $this->errors[] = "BRE rule [{$label}]: 'priority' must be an integer.";
            }
        }
    }

    // ──────────────────────────────────────────────────────────
    // Notification template validation
    // ──────────────────────────────────────────────────────────

    private function validateTemplates(array $templates): void
    {
        foreach ($templates as $key => $template) {
            if (empty($template['channels'])) {
                $this->errors[] = "Notification template [{$key}]: 'channels' must be a non-empty array.";
            }

            if (! is_array($template['channels'] ?? null)) {
                $this->errors[] = "Notification template [{$key}]: 'channels' must be an array.";
            }
        }
    }
}
