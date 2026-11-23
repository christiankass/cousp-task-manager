<?php

namespace App\Services\Tasks;

use App\Enums\ApproverType;
use App\Enums\TaskStatus;
use App\Models\ApprovalStep;
use App\Models\ApprovalTemplate;
use App\Models\ApprovalWorkflow;
use App\Models\OrganizationUnit;
use App\Models\Task;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Circuit de validation hiérarchique (§1.1, RM05, RM06, RM12).
 *
 * Le serveur détermine seul l'étape courante et les personnes autorisées à décider :
 * aucun identifiant de valideur n'est accepté du client (§22). Le responsable de la
 * tâche ne peut jamais valider son propre travail.
 */
class ApprovalService
{
    public function __construct(private TaskHistory $history, private Notifier $notifier) {}

    /** Choisit le circuit applicable : celui de la tâche, sinon le plus spécifique. */
    public function templateFor(Task $task): ?ApprovalTemplate
    {
        if ($task->approval_template_id) {
            return ApprovalTemplate::query()->with('steps')->find($task->approval_template_id);
        }

        $ancestors = $task->organizationUnit->ancestorIds();

        return ApprovalTemplate::query()
            ->with('steps')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('task_type')->orWhere('task_type', $task->task_type->value))
            ->where(fn ($q) => $q->whereNull('organization_unit_id')->orWhereIn('organization_unit_id', $ancestors))
            ->get()
            ->filter(fn (ApprovalTemplate $t) => $t->steps->isNotEmpty())
            ->sortBy(fn (ApprovalTemplate $t) => [
                $t->organization_unit_id ? array_search($t->organization_unit_id, $ancestors, true) : count($ancestors),
                $t->task_type ? 0 : 1,
                $t->is_default ? 0 : 1,
            ])
            ->first();
    }

    public function submit(Task $task, User $submitter, ?string $comment = null): ApprovalWorkflow
    {
        if ($task->responsibleId() !== $submitter->id) {
            throw new ConflictHttpException(__('tasks.only_responsible_can_submit'));
        }

        if (! $task->status->canSubmit()) {
            throw new ConflictHttpException(__('tasks.cannot_submit_from', ['status' => __('tasks.status.'.$task->status->value)]));
        }

        $steps = $this->planSteps($task);

        if ($steps->isEmpty()) {
            throw new ConflictHttpException(__('tasks.no_approver'));
        }

        $workflow = DB::transaction(function () use ($task, $submitter, $steps, $comment) {
            $workflow = ApprovalWorkflow::query()->create([
                'task_id' => $task->id,
                'template_id' => $steps->first()['template_id'],
                'current_step' => 1,
                'status' => 'pending',
                'started_at' => now(),
            ]);

            foreach ($steps->values() as $i => $step) {
                ApprovalStep::query()->create([
                    'workflow_id' => $workflow->id,
                    'step_order' => $i + 1,
                    'approver_type' => $step['type']->value,
                    'approver_id' => $step['approver_id'],
                    'approver_role_id' => $step['role_id'],
                    'status' => $i === 0 ? 'pending' : 'waiting',
                ]);
            }

            $previous = $task->status;
            $task->forceFill([
                'status' => TaskStatus::Submitted,
                'approval_status' => 'pending',
                'submitted_at' => now(),
                'completed_at' => now(),
                'progress' => 100,
            ])->save();

            $this->history->record($task, 'submitted', $previous, $task->status, $submitter, $comment, ['workflow_id' => $workflow->id]);

            return $workflow;
        });

        $this->notifier->task('task_submitted', $task, $this->approversOf($workflow->currentStep(), $task), $submitter);

        return $workflow;
    }

    public function approve(Task $task, User $user, ?string $comments = null): Task
    {
        [$workflow, $step] = $this->currentStepFor($task, $user);

        $finished = DB::transaction(function () use ($task, $user, $comments, $workflow, $step) {
            $step->update([
                'status' => 'approved', 'decision' => 'approved', 'decided_by' => $user->id,
                'comments' => $comments, 'decided_at' => now(),
            ]);

            $next = $workflow->steps()->where('step_order', '>', $step->step_order)->first();

            if ($next) {
                $workflow->update(['current_step' => $next->step_order]);
                $next->update(['status' => 'pending']);
                $this->history->record($task, 'approval_step_approved', null, null, $user, $comments, ['step' => $step->step_order]);

                return false;
            }

            // RM12 : seule la dernière validation clôture le workflow.
            $workflow->update(['status' => 'approved', 'completed_at' => now()]);
            $previous = $task->status;
            $task->forceFill([
                'status' => TaskStatus::Validated,
                'approval_status' => 'approved',
                'validated_at' => now(),
            ])->save();
            $this->history->record($task, 'validated', $previous, $task->status, $user, $comments, ['step' => $step->step_order]);

            return true;
        });

        if ($finished) {
            $this->notifier->task('task_validated', $task, [$task->responsibleId(), $task->created_by], $user);
        } else {
            $this->notifier->task('task_submitted', $task, $this->approversOf($workflow->refresh()->currentStep(), $task), $task->responsibleAssignment->user);
        }

        return $task->refresh();
    }

    /** RM06 : un rejet renvoie la tâche à l'exécution ; le motif est obligatoire et historisé. */
    public function reject(Task $task, User $user, string $comments): Task
    {
        [$workflow, $step] = $this->currentStepFor($task, $user);

        DB::transaction(function () use ($task, $user, $comments, $workflow, $step) {
            $step->update([
                'status' => 'rejected', 'decision' => 'rejected', 'decided_by' => $user->id,
                'comments' => $comments, 'decided_at' => now(),
            ]);
            $workflow->steps()->where('status', 'waiting')->update(['status' => 'skipped']);
            $workflow->update(['status' => 'rejected', 'completed_at' => now()]);

            $previous = $task->status;
            $task->forceFill([
                'status' => TaskStatus::Rejected,
                'approval_status' => 'rejected',
                'completed_at' => null,
            ])->save();
            $this->history->record($task, 'rejected', $previous, $task->status, $user, $comments, ['step' => $step->step_order]);
        });

        $this->notifier->task('task_rejected', $task, [$task->responsibleId()], $user, ['comments' => $comments]);

        return $task->refresh();
    }

    public function canDecide(User $user, Task $task, ApprovalStep $step): bool
    {
        if ($step->status !== 'pending' || $task->responsibleId() === $user->id) {
            return false;
        }

        return match ($step->approver_type) {
            ApproverType::Role => $this->holdsRoleFor($user, $step->approver_role_id, $task->organizationUnit),
            default => $step->approver_id === $user->id,
        };
    }

    /** @return Collection<int, string> identifiants des personnes pouvant décider de l'étape */
    public function approversOf(?ApprovalStep $step, Task $task): Collection
    {
        if (! $step) {
            return collect();
        }

        if ($step->approver_type !== ApproverType::Role) {
            return collect([$step->approver_id])->filter();
        }

        $ancestors = $task->organizationUnit->ancestorIds();

        return UserRole::query()
            ->where('role_id', $step->approver_role_id)
            ->where(fn ($q) => $q->whereNull('organization_unit_id')->orWhereIn('organization_unit_id', $ancestors))
            ->pluck('user_id')
            ->reject(fn ($id) => $id === $task->responsibleId())
            ->unique()
            ->values();
    }

    /**
     * Étapes concrètes pour cette tâche. Sans circuit configuré : le responsable de la
     * structure (ou de la plus proche parente), à défaut le créateur de la tâche.
     *
     * @return Collection<int, array{type: ApproverType, approver_id: ?string, role_id: ?string, template_id: ?string}>
     */
    private function planSteps(Task $task): Collection
    {
        $responsibleId = $task->responsibleId();
        $template = $this->templateFor($task);
        $unit = $task->organizationUnit;

        if (! $template) {
            $managerId = $this->managerAbove($unit, $responsibleId);
            $fallback = $managerId ?? ($task->created_by !== $responsibleId ? $task->created_by : null);

            return $fallback
                ? collect([['type' => ApproverType::UnitManager, 'approver_id' => $fallback, 'role_id' => null, 'template_id' => null]])
                : collect();
        }

        return $template->steps->map(function ($step) use ($unit, $responsibleId, $template) {
            $approverId = match ($step->approver_type) {
                ApproverType::UnitManager => $this->managerAbove($unit, $responsibleId),
                ApproverType::ParentUnitManager => $unit->parent ? $this->managerAbove($unit->parent, $responsibleId) : null,
                ApproverType::User => $step->approver_user_id !== $responsibleId ? $step->approver_user_id : null,
                ApproverType::Role => null,
            };

            if ($step->approver_type !== ApproverType::Role && ! $approverId) {
                if ($step->is_required) {
                    throw new ConflictHttpException(__('tasks.no_approver'));
                }

                return null;
            }

            return [
                'type' => $step->approver_type,
                'approver_id' => $approverId,
                'role_id' => $step->approver_role_id,
                'template_id' => $template->id,
            ];
        })->filter()->values();
    }

    /** Premier responsable de structure, en remontant, qui n'est pas l'exécutant lui-même. */
    private function managerAbove(OrganizationUnit $unit, ?string $excludedUserId): ?string
    {
        foreach ($unit->ancestorIds() as $id) {
            $managerId = OrganizationUnit::query()->whereKey($id)->value('manager_id');
            if ($managerId && $managerId !== $excludedUserId) {
                return $managerId;
            }
        }

        return null;
    }

    private function holdsRoleFor(User $user, ?string $roleId, OrganizationUnit $unit): bool
    {
        return UserRole::query()
            ->where('user_id', $user->id)
            ->where('role_id', $roleId)
            ->where(fn ($q) => $q->whereNull('organization_unit_id')->orWhereIn('organization_unit_id', $unit->ancestorIds()))
            ->exists();
    }

    /** @return array{0: ApprovalWorkflow, 1: ApprovalStep} */
    private function currentStepFor(Task $task, User $user): array
    {
        $workflow = $task->activeWorkflow;
        $step = $workflow?->currentStep();

        if (! $workflow || ! $step || $task->status !== TaskStatus::Submitted) {
            throw new ConflictHttpException(__('tasks.not_awaiting_approval'));
        }

        if (! $this->canDecide($user, $task, $step)) {
            abort(403, __('tasks.not_your_approval'));
        }

        return [$workflow, $step];
    }
}
