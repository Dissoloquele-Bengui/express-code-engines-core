<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\NotificationEngine;

use ExpressCodeEngines\Shared\DTOs\NotificationTemplate;

/**
 * Holds all registered notification templates.
 *
 * Templates are registered at boot time from config arrays.
 * The registry is the single source of truth — no template is
 * fetched from the database directly by the NotificationEngine.
 *
 * If a project needs DB-driven templates, it should implement
 * a custom TemplateRegistry that loads from the DB and caches.
 */
final class TemplateRegistry
{
    /** @var array<string, NotificationTemplate> */
    private array $templates = [];

    /**
     * @param  array<string, array>  $config  Keyed by template key
     *                                        e.g. ['order_submitted' => ['channels' => ['mail'], ...]]
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $key => $templateConfig) {
            $this->register(NotificationTemplate::fromArray($key, $templateConfig));
        }
    }

    public function register(NotificationTemplate $template): void
    {
        $this->templates[$template->key] = $template;
    }

    public function find(string $key): ?NotificationTemplate
    {
        return $this->templates[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->templates[$key]);
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->templates);
    }
}
