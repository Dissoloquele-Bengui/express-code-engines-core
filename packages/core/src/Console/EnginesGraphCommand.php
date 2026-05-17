<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Core\Console;

use Illuminate\Console\Command;
use ExpressCodeEngines\Shared\DTOs\WorkflowDefinition;

/**
 * Exports workflow state machines as visual diagrams.
 *
 * Supported formats:
 *   --format=mermaid  (default) → stateDiagram-v2 syntax, renders on GitHub/Notion
 *   --format=dot                → Graphviz DOT language, render with `dot -Tsvg`
 *
 * Usage:
 *   php artisan engines:graph
 *   php artisan engines:graph --format=dot --output=storage/diagrams/workflows.dot
 *   php artisan engines:graph --entity=App\\Models\\Order
 */
final class EnginesGraphCommand extends Command
{
    protected $signature = 'engines:graph
                            {--format=mermaid : Output format: mermaid or dot}
                            {--output= : File path to write the output (default: stdout)}
                            {--entity= : Only export the workflow for this entity class}';

    protected $description = 'Export workflow state machines as Mermaid or Graphviz diagrams';

    public function handle(): int
    {
        $format   = $this->option('format');
        $output   = $this->option('output');
        $entity   = $this->option('entity');

        if (! in_array($format, ['mermaid', 'dot'], true)) {
            $this->error("Unknown format '{$format}'. Use 'mermaid' or 'dot'.");
            return self::FAILURE;
        }

        $definitions = $this->loadDefinitions($entity);

        if (empty($definitions)) {
            $this->warn('No workflow definitions found. Configure workflows in config/engines.php.');
            return self::SUCCESS;
        }

        $diagram = $format === 'mermaid'
            ? $this->renderMermaid($definitions)
            : $this->renderDot($definitions);

        if ($output) {
            $dir = dirname($output);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, recursive: true);
            }
            file_put_contents($output, $diagram);
            $this->info("Diagram written to: {$output}");
        } else {
            $this->line($diagram);
        }

        $this->info(sprintf(
            'Exported %d workflow(s) in %s format.',
            count($definitions),
            strtoupper($format),
        ));

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────
    // Renderers
    // ──────────────────────────────────────────────────────────

    /**
     * @param  WorkflowDefinition[]  $definitions
     */
    private function renderMermaid(array $definitions): string
    {
        $lines = [];

        foreach ($definitions as $definition) {
            $entityShort = class_basename($definition->entityClass);
            $lines[]     = "---";
            $lines[]     = "title: {$entityShort} Workflow";
            $lines[]     = "---";
            $lines[]     = "stateDiagram-v2";

            // Initial states
            foreach ($definition->initialStates as $initial) {
                $lines[] = "    [*] --> {$initial}";
            }

            // Transitions
            foreach ($definition->transitions as $transition) {
                $from  = $this->sanitiseState($transition->from);
                $to    = $this->sanitiseState($transition->to);
                $label = $transition->to;

                if (! empty($transition->guards)) {
                    $guardsStr = implode(', ', array_map(
                        fn ($g) => $this->formatGuard($g),
                        $transition->guards,
                    ));
                    $label .= " [{$guardsStr}]";
                }

                $lines[] = "    {$from} --> {$to} : {$label}";
            }

            // Metadata label (post_actions)
            foreach ($definition->transitions as $transition) {
                if (! empty($transition->postActions)) {
                    $actions = implode(', ', $transition->postActions);
                    $to      = $this->sanitiseState($transition->to);
                    $lines[] = "    note right of {$to}";
                    $lines[] = "        post: {$actions}";
                    $lines[] = "    end note";
                }
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  WorkflowDefinition[]  $definitions
     */
    private function renderDot(array $definitions): string
    {
        $graphs = [];

        foreach ($definitions as $definition) {
            $entityShort = class_basename($definition->entityClass);
            $nodes       = $this->collectNodes($definition);
            $lines       = [];

            $lines[] = "digraph {$entityShort} {";
            $lines[] = '    rankdir=LR;';
            $lines[] = '    node [shape=rectangle, style=filled, fillcolor="#E8E8F8", fontname="Helvetica"];';
            $lines[] = '    edge [fontname="Helvetica", fontsize=10];';
            $lines[] = '';

            // Initial state marker
            $lines[] = '    __start [shape=point, width=0.2, fillcolor=black];';

            foreach ($definition->initialStates as $initial) {
                $lines[] = "    __start -> {$initial};";
            }

            // Nodes
            foreach ($nodes as $state) {
                $color = $this->stateColor($state, $definition);
                $lines[] = "    {$state} [fillcolor=\"{$color}\"];";
            }

            // Edges
            foreach ($definition->transitions as $transition) {
                $from  = $transition->from;
                $to    = $transition->to;
                $guard = ! empty($transition->guards)
                    ? '\\n[' . implode(', ', array_map(fn ($g) => $this->formatGuard($g), $transition->guards)) . ']'
                    : '';
                $label = "{$to}{$guard}";

                $lines[] = "    {$from} -> {$to} [label=\"{$label}\"];";
            }

            $lines[]  = '}';
            $graphs[] = implode("\n", $lines);
        }

        return implode("\n\n", $graphs);
    }

    // ──────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────

    /**
     * @return WorkflowDefinition[]
     */
    private function loadDefinitions(?string $entityFilter): array
    {
        $workflowsConfig = config('engines.workflows', []);

        if (empty($workflowsConfig)) {
            return [];
        }

        $definitions = [];

        foreach ($workflowsConfig as $entityClass => $config) {
            if ($entityFilter && $entityClass !== $entityFilter) {
                continue;
            }

            $definitions[] = WorkflowDefinition::fromArray($entityClass, $config);
        }

        return $definitions;
    }

    /** @return string[] */
    private function collectNodes(WorkflowDefinition $definition): array
    {
        $states = array_merge(
            $definition->initialStates,
            array_column($definition->transitions, 'from'),
            array_column($definition->transitions, 'to'),
        );

        // WorkflowDefinition stores TransitionDefinition objects
        $states = array_merge($definition->initialStates);

        foreach ($definition->transitions as $t) {
            $states[] = $t->from;
            $states[] = $t->to;
        }

        return array_values(array_unique(array_filter($states)));
    }

    private function stateColor(string $state, WorkflowDefinition $definition): string
    {
        // Terminal states (no outgoing transitions)
        $fromStates = array_map(fn ($t) => $t->from, $definition->transitions);

        if (! in_array($state, $fromStates, true)) {
            return '#F8D7DA'; // red-ish — terminal
        }

        if (in_array($state, $definition->initialStates, true)) {
            return '#D4EDDA'; // green-ish — initial
        }

        return '#E8E8F8'; // default — intermediate
    }

    private function sanitiseState(string $state): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $state);
    }

    private function formatGuard(string $guard): string
    {
        // Shorten guard strings for readability in diagrams
        if (str_starts_with($guard, 'role:'))     return substr($guard, 5);
        if (str_starts_with($guard, 'field:'))    return substr($guard, 6);
        if (str_starts_with($guard, 'callable:')) return class_basename(substr($guard, 9));
        return $guard;
    }
}
