<?php

namespace App\Services\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;

/** RM08 : chaque changement important d'une tâche est historisé. */
class TaskHistory
{
    public function record(Task $task, string $event, ?TaskStatus $from, ?TaskStatus $to, ?User $by, ?string $reason = null, array $details = []): TaskStatusHistory
    {
        return TaskStatusHistory::query()->create([
            'task_id' => $task->id,
            'event' => $event,
            'previous_status' => $from?->value,
            'new_status' => $to?->value,
            'changed_by' => $by?->id,
            'reason' => $reason,
            'details' => $details ?: null,
            'changed_at' => now(),
        ]);
    }
}
