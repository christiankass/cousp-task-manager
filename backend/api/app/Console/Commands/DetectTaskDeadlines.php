<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Models\Institution;
use App\Models\Task;
use App\Services\Notifier;
use App\Services\Tasks\TaskHistory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Scénario 2 (§6.2) : repère les tâches en retard et celles dont l'échéance approche,
 * puis prévient les personnes concernées. Chaque alerte n'est envoyée qu'une fois par échéance.
 */
class DetectTaskDeadlines extends Command
{
    protected $signature = 'tasks:detect-deadlines';

    protected $description = 'Signale les tâches en retard et les échéances proches';

    public function handle(TenantContext $context, Notifier $notifier, TaskHistory $history): int
    {
        $overdueCount = 0;
        $dueSoonCount = 0;

        $institutions = $context->withoutIsolation(fn () => Institution::query()->where('status', 'active')->get());

        foreach ($institutions as $institution) {
            $context->runAs($institution, function () use ($notifier, $history, &$overdueCount, &$dueSoonCount) {
                Task::query()
                    ->overdue()
                    ->whereNull('overdue_notified_at')
                    ->with(['organizationUnit.parent', 'assignments'])
                    ->chunkById(200, function ($tasks) use ($notifier, $history, &$overdueCount) {
                        foreach ($tasks as $task) {
                            $task->forceFill(['overdue_notified_at' => now()])->save();
                            $history->record($task, 'overdue_detected', null, null, null, null, ['due_at' => $task->due_at->toIso8601ZuluString()]);

                            // Agent responsable, chef de service, puis niveau hiérarchique supérieur pour les priorités hautes.
                            $recipients = [$task->responsibleId(), $task->organizationUnit->manager_id, $task->created_by];
                            if (in_array($task->priority->value, ['high', 'critical'], true)) {
                                $recipients[] = $task->organizationUnit->parent?->manager_id;
                            }

                            $notifier->task('task_overdue', $task, array_filter($recipients));
                            $overdueCount++;
                        }
                    });

                Task::query()
                    ->whereNull('validated_at')
                    ->whereNull('due_soon_notified_at')
                    ->whereNotIn('status', [TaskStatus::Submitted->value, TaskStatus::Validated->value])
                    ->whereBetween('due_at', [now(), now()->addHours((int) config('cousp.due_soon_hours'))])
                    ->with('assignments')
                    ->chunkById(200, function ($tasks) use ($notifier, &$dueSoonCount) {
                        foreach ($tasks as $task) {
                            $task->forceFill(['due_soon_notified_at' => now()])->save();
                            $notifier->task('task_due_soon', $task, $task->assignments->pluck('user_id'));
                            $dueSoonCount++;
                        }
                    });
            });
        }

        $this->info("Retards signalés : {$overdueCount} ; échéances proches : {$dueSoonCount}");

        return self::SUCCESS;
    }
}
