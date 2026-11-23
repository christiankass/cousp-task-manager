<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Notifications du §4.7 : enregistrées dans l'application et envoyées par e-mail
 * (en file d'attente), dans la langue de chaque destinataire.
 */
class Notifier
{
    public const TYPES = [
        'task_assigned', 'task_updated', 'task_due_soon', 'task_overdue', 'task_submitted',
        'task_rejected', 'task_validated', 'task_commented', 'task_critical',
    ];

    public function __construct(private TenantContext $context) {}

    /**
     * @param  iterable<User|string>  $recipients  utilisateurs ou identifiants
     */
    public function task(string $type, Task $task, iterable $recipients, ?User $actor = null, array $params = []): void
    {
        $users = $this->resolve($recipients)->reject(fn (User $u) => $actor && $u->id === $actor->id);

        foreach ($users as $user) {
            $locale = $user->preferred_language ?: 'fr';
            $replace = $params + [
                'reference' => $task->reference,
                'title' => $task->title,
                'actor' => $actor?->name ?? __('notifications.system', [], $locale),
                'due' => $task->due_at?->timezone($this->context->institution()?->timezone ?? 'UTC')->format('d/m/Y H:i') ?? '-',
            ];

            $notification = AppNotification::query()->create([
                'user_id' => $user->id,
                'notification_type' => $type,
                'title' => __("notifications.{$type}.title", $replace, $locale),
                'message' => __("notifications.{$type}.message", $replace, $locale),
                'related_entity_type' => 'task',
                'related_entity_id' => $task->id,
                'data' => ['reference' => $task->reference],
            ]);

            if (config('cousp.mail_notifications')) {
                $user->notify((new TaskEmail($notification->title, $notification->message, $task->id))->locale($locale));
            }
        }
    }

    /** @return Collection<int, User> */
    private function resolve(iterable $recipients): Collection
    {
        $ids = collect($recipients)
            ->map(fn ($r) => $r instanceof User ? $r->id : $r)
            ->filter()
            ->unique()
            ->values();

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->where('status', 'active')->get();
    }
}
