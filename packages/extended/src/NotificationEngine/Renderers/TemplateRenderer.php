<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\NotificationEngine\Renderers;

use ExpressCodeEngines\Shared\DTOs\NotificationTemplate;

/**
 * Renders a NotificationTemplate against a data array.
 *
 * Supports two template body formats:
 *   1. Inline string with {{variable}} placeholders
 *      e.g. "Hello {{customer.name}}, your order #{{order.reference}} was received."
 *
 *   2. Laravel view name prefixed with 'view:'
 *      e.g. "view:emails.order_submitted"
 *      The view receives the full data array as variables.
 *
 * Subject always uses inline substitution (no view prefix support).
 */
final class TemplateRenderer
{
    /**
     * @param  array  $data  Flat or nested array for variable substitution
     * @return array{subject: ?string, body: ?string}
     */
    public function render(NotificationTemplate $template, array $data): array
    {
        $merged = array_merge($template->defaults, $data);

        return [
            'subject' => $template->subject
                ? $this->substituteInline($template->subject, $merged)
                : null,

            'body' => $template->body
                ? $this->renderBody($template->body, $merged)
                : null,

            'data' => $merged,
        ];
    }

    private function renderBody(string $body, array $data): string
    {
        if (str_starts_with($body, 'view:')) {
            $viewName = substr($body, 5);
            return view($viewName, $data)->render();
        }

        return $this->substituteInline($body, $data);
    }

    /**
     * Replaces {{dot.path}} placeholders with resolved values from the data array.
     */
    private function substituteInline(string $template, array $data): string
    {
        return preg_replace_callback('/\{\{([\w.]+)\}\}/', function (array $matches) use ($data) {
            $value = $this->resolve($matches[1], $data);
            return $value !== null ? (string) $value : $matches[0];
        }, $template);
    }

    private function resolve(string $path, array $data): mixed
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
