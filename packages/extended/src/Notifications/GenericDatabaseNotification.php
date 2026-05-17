<?php

declare(strict_types=1);

namespace ExpressCodeEngines\Extended\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\DatabaseMessage;

/**
 * Generic Laravel Notification used by DatabaseChannel to persist
 * rendered notifications in the 'notifications' table.
 *
 * Receives the pre-rendered array from the TemplateRenderer:
 *   ['subject' => '...', 'body' => '...', 'data' => [...]]
 *
 * The full rendered array is stored in the notification's 'data' column,
 * making it available to the frontend for in-app notification UIs.
 *
 * Usage: the DatabaseChannel calls $recipient->notify(new GenericDatabaseNotification($rendered))
 * which requires the recipient model to use the Laravel Notifiable trait.
 */
final class GenericDatabaseNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly array $rendered,
    ) {}

    /**
     * @param  mixed  $notifiable
     * @return string[]
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(mixed $notifiable): DatabaseMessage
    {
        return new DatabaseMessage([
            'subject' => $this->rendered['subject'] ?? null,
            'body'    => $this->rendered['body']    ?? null,
            'data'    => $this->rendered['data']    ?? [],
        ]);
    }

    /**
     * Expose the rendered data for testing without requiring a full Laravel app.
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'subject' => $this->rendered['subject'] ?? null,
            'body'    => $this->rendered['body']    ?? null,
            'data'    => $this->rendered['data']    ?? [],
        ];
    }
}
