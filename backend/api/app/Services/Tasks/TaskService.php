<?php

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\HealthEvent;
use App\Models\InstitutionUserMembership;
use App\Models\OrganizationUnit;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TaskService
{
    public function __construct(
        private TenantContext $context,
        private TaskAccess $access,
        private TaskReferenceGenerator $references,
        private TaskHistory $history,
        private Notifier $notifier,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{organization_unit_id: string, title: string, assignees: list<array{user_id: string, role: string}>}  $data
     */
    public function create(array $data, User $creator): Task
    {
        $data = $this->normalizeDates($data);
        $unit = $this->unit($data['organization_unit_id']);
        $assignees = $this->normalizeAssignees($data['assignees']);
        $this->checkDates($data['start_at'] ?? null, $data['due_at'] ?? null);

        // Confier une tâche à quelqu'un d'autre exige tasks.assign sur la structure.
        $onlySelf = collect($assignees)->every(fn ($a) => $a['user_id'] === $creator->id);
        if (! $onlySelf && ! $this->access->hasForUnit($creator, 'tasks.assign', $unit->id)) {
            abort(403, __('tasks.cannot_assign_in_unit'));
        }
        if (! $this->access->hasForUnit($creator, 'tasks.create', $unit->id)) {
            abort(403, __('tasks.cannot_create_in_unit'));
        }

        if (! empty($data['parent_task_id'])) {
            $parent = $this->access->visibleQuery($creator)->find($data['parent_task_id'])
                ?? throw ValidationException::withMessages(['parent_task_id' => [__('validation.exists', ['attribute' => 'parent_task_id'])]]);
            if ($parent->status->isClosed()) {
                throw ValidationException::withMessages(['parent_task_id' => [__('tasks.parent_closed')]]);
            }
        }

        if (! empty($data['health_event_id'])) {
            $event = HealthEvent::query()->find($data['health_event_id'])
                ?? throw ValidationException::withMessages(['health_event_id' => [__('validation.exists', ['attribute' => 'health_event_id'])]]);
            if ($event->isClosed()) {
                throw ValidationException::withMessages(['health_event_id' => [__('coordination.event_closed')]]);
            }
        }

        $task = DB::transaction(function () use ($data, $creator, $unit, $assignees) {
            $task = Task::query()->create([
                ...collect($data)->only([
                    'parent_task_id', 'health_event_id', 'recommendation_id', 'recurrence_id', 'occurrence_on', 'title', 'description', 'expected_result', 'task_type', 'category',
                    'priority', 'start_at', 'due_at', 'estimated_hours', 'approval_template_id',
                ])->all(),
                'organization_unit_id' => $unit->id,
                'reference' => $this->references->next($this->context->institution()),
                'status' => TaskStatus::Todo,
                'approval_status' => 'none',
                'created_by' => $creator->id,
            ]);

            foreach ($assignees as $assignee) {
                TaskAssignment::query()->create([
                    'task_id' => $task->id,
                    'user_id' => $assignee['user_id'],
                    'assignment_role' => $assignee['role'],
                    'assigned_by' => $creator->id,
                    'assigned_at' => now(),
                ]);
            }

            $this->history->record($task, 'created', null, TaskStatus::Todo, $creator);
            $this->audit->log('task.created', $task, [], ['reference' => $task->reference]);

            return $task;
        });

        $recipients = array_column($assignees, 'user_id');
        $this->notifier->task('task_assigned', $task, $recipients, $creator);

        if ($task->priority === TaskPriority::Critical) {
            // §28.1 : une tâche critique alerte immédiatement le responsable de la structure.
            $this->notifier->task('task_critical', $task, array_filter([$unit->manager_id]), $creator);
        }

        return $task;
    }

    /** Modification ; un report d'échéance exige une justification (§4.2). */
    public function update(Task $task, array $data, User $user): Task
    {
        $this->ensureOpen($task);

        if (isset($data['organization_unit_id']) && $data['organization_unit_id'] !== $task->organization_unit_id) {
            // Transfert vers une autre équipe : il faut aussi pouvoir attribuer dans la structure cible.
            $target = $this->unit($data['organization_unit_id']);
            if (! $this->access->hasForUnit($user, 'tasks.assign', $target->id)) {
                abort(403, __('tasks.cannot_assign_in_unit'));
            }
        }

        $data = $this->normalizeDates($data);
        $start = array_key_exists('start_at', $data) ? $data['start_at'] : $task->start_at;
        $due = array_key_exists('due_at', $data) ? $data['due_at'] : $task->due_at;
        $this->checkDates($start, $due);

        $dueChanged = array_key_exists('due_at', $data)
            && ! $this->sameInstant($data['due_at'], $task->due_at);

        if ($dueChanged && $task->due_at && blank($data['due_at_reason'] ?? null)) {
            throw ValidationException::withMessages(['due_at_reason' => [__('tasks.due_change_reason_required')]]);
        }

        $fields = collect($data)->only([
            'title', 'description', 'expected_result', 'task_type', 'category', 'priority',
            'start_at', 'due_at', 'estimated_hours', 'organization_unit_id', 'approval_template_id',
        ])->all();

        $old = collect($task->only(array_keys($fields)))->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->all();

        DB::transaction(function () use ($task, $fields, $user, $dueChanged, $data, $old) {
            $task->fill($fields);

            if ($dueChanged) {
                // Le rappel et l'alerte de retard repartent sur la nouvelle échéance.
                $task->due_soon_notified_at = null;
                $task->overdue_notified_at = null;
                $this->history->record($task, 'due_date_changed', null, null, $user, $data['due_at_reason'] ?? null, [
                    'from' => $old['due_at'] ?? null, 'to' => $data['due_at'],
                ]);
            }

            if (isset($fields['organization_unit_id']) && $fields['organization_unit_id'] !== $old['organization_unit_id']) {
                $this->history->record($task, 'transferred', null, null, $user, $data['transfer_reason'] ?? null, [
                    'from' => $old['organization_unit_id'], 'to' => $fields['organization_unit_id'],
                ]);
            }

            $task->save();
            $this->audit->log('task.updated', $task, $old, collect($task->only(array_keys($fields)))->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->all());
        });

        $this->notifier->task('task_updated', $task, $task->assignments()->pluck('user_id'), $user, [
            'changes' => $dueChanged ? __('tasks.new_due_date', ['due' => $task->due_at?->timezone($this->context->institution()->timezone)->format('d/m/Y H:i')]) : '',
        ]);

        return $task->refresh();
    }

    public function changeStatus(Task $task, TaskStatus $to, User $user, ?string $reason = null): Task
    {
        if (! in_array($to, $task->status->manualTransitions(), true)) {
            throw new ConflictHttpException(__('tasks.invalid_transition', [
                'from' => __('tasks.status.'.$task->status->value),
                'to' => __('tasks.status.'.$to->value),
            ]));
        }

        if ($to === TaskStatus::OnHold && blank($reason)) {
            throw ValidationException::withMessages(['reason' => [__('tasks.on_hold_reason_required')]]);
        }

        $from = $task->status;
        $task->status = $to;

        if ($to === TaskStatus::InProgress) {
            TaskAssignment::query()->where('task_id', $task->id)->where('user_id', $user->id)->whereNull('accepted_at')->update(['accepted_at' => now()]);
        }

        DB::transaction(function () use ($task, $from, $to, $user, $reason) {
            $task->save();
            $this->history->record($task, 'status_changed', $from, $to, $user, $reason);
        });

        return $task;
    }

    public function updateProgress(Task $task, int $progress, User $user): Task
    {
        $this->ensureOpen($task);
        $old = $task->progress;
        $task->update(['progress' => $progress]);
        $this->history->record($task, 'progress_updated', null, null, $user, null, ['from' => $old, 'to' => $progress]);

        return $task;
    }

    public function assign(Task $task, string $userId, string $role, User $by): TaskAssignment
    {
        $this->ensureOpen($task);
        $this->ensureMember($userId, 'user_id');

        $assignment = DB::transaction(function () use ($task, $userId, $role, $by) {
            if ($role === 'responsible') {
                // Délégation : l'ancien responsable devient collaborateur.
                TaskAssignment::query()->where('task_id', $task->id)->where('assignment_role', 'responsible')
                    ->where('user_id', '!=', $userId)->update(['assignment_role' => 'collaborator']);
            }

            $assignment = TaskAssignment::query()->updateOrCreate(
                ['task_id' => $task->id, 'user_id' => $userId],
                ['assignment_role' => $role, 'assigned_by' => $by->id, 'assigned_at' => now()]
            );

            $this->history->record($task, $role === 'responsible' ? 'delegated' : 'collaborator_added', null, null, $by, null, ['user_id' => $userId]);

            return $assignment;
        });

        $this->notifier->task('task_assigned', $task, [$userId], $by);

        return $assignment;
    }

    public function unassign(Task $task, TaskAssignment $assignment, User $by): void
    {
        $this->ensureOpen($task);

        if ($assignment->assignment_role === 'responsible') {
            throw new ConflictHttpException(__('tasks.cannot_remove_responsible'));
        }

        $assignment->delete();
        $this->history->record($task, 'collaborator_removed', null, null, $by, null, ['user_id' => $assignment->user_id]);
    }

    /** RM09 : une tâche validée ne peut pas être supprimée ; sinon archivage logique. */
    public function delete(Task $task, User $user, ?string $reason): void
    {
        if ($task->status->isClosed()) {
            throw new ConflictHttpException(__('tasks.cannot_delete_validated'));
        }

        DB::transaction(function () use ($task, $user, $reason) {
            $this->history->record($task, 'archived', $task->status, null, $user, $reason);
            $task->delete();
            $this->audit->log('task.archived', $task, [], ['reason' => $reason]);
        });
    }

    private function ensureOpen(Task $task): void
    {
        if ($task->status->isClosed()) {
            throw new ConflictHttpException(__('tasks.task_closed'));
        }
    }

    private function unit(string $id): OrganizationUnit
    {
        return OrganizationUnit::query()->where('is_active', true)->find($id)
            ?? throw ValidationException::withMessages(['organization_unit_id' => [__('validation.exists', ['attribute' => 'organization_unit_id'])]]);
    }

    /**
     * @param  list<array{user_id: string, role: string}>  $assignees
     * @return list<array{user_id: string, role: string}>
     */
    private function normalizeAssignees(array $assignees): array
    {
        $assignees = collect($assignees)->unique('user_id')->values();

        // RM02 : exactement un responsable principal.
        if ($assignees->where('role', 'responsible')->count() !== 1) {
            throw ValidationException::withMessages(['assignees' => [__('tasks.exactly_one_responsible')]]);
        }

        foreach ($assignees as $i => $assignee) {
            $this->ensureMember($assignee['user_id'], "assignees.{$i}.user_id");
        }

        return $assignees->all();
    }

    public function ensureMember(string $userId, string $field): void
    {
        $ok = InstitutionUserMembership::query()->where('user_id', $userId)->where('status', 'active')->exists();
        if (! $ok) {
            throw ValidationException::withMessages([$field => [__('messages.user_not_in_institution')]]);
        }
    }

    /**
     * Les dates reçues avec un décalage horaire (« +01:00 ») sont ramenées en UTC :
     * la base stocke des horodatages sans fuseau, interprétés en UTC.
     */
    private function normalizeDates(array $data): array
    {
        foreach (['start_at', 'due_at'] as $key) {
            if (filled($data[$key] ?? null)) {
                $data[$key] = CarbonImmutable::parse($data[$key])->utc()->toIso8601ZuluString();
            }
        }

        return $data;
    }

    private function checkDates(mixed $start, mixed $due): void
    {
        // RM04 : l'échéance ne peut pas précéder le début.
        if ($start && $due && CarbonImmutable::parse($due)->lt(CarbonImmutable::parse($start))) {
            throw ValidationException::withMessages(['due_at' => [__('tasks.due_before_start')]]);
        }
    }

    private function sameInstant(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return CarbonImmutable::parse($a)->equalTo(CarbonImmutable::parse($b));
    }
}
