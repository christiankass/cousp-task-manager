<?php

namespace App\Http\Resources;

use App\Services\Tasks\ApprovalService;
use App\Services\Tasks\TaskAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $iso = fn ($d) => $d?->toIso8601ZuluString();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'title' => $this->title,
            'description' => $this->description,
            'expected_result' => $this->expected_result,
            'task_type' => $this->task_type->value,
            'category' => $this->category,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'status_label' => __('tasks.status.'.$this->status->value),
            'approval_status' => $this->approval_status,
            'is_overdue' => $this->isOverdue(),
            'progress' => $this->progress,
            'organization_unit' => $this->whenLoaded('organizationUnit', fn () => ['id' => $this->organizationUnit->id, 'name' => $this->organizationUnit->name]),
            'parent_task_id' => $this->parent_task_id,
            'health_event' => $this->whenLoaded('healthEvent', fn () => $this->healthEvent ? ['id' => $this->healthEvent->id, 'reference' => $this->healthEvent->reference, 'title' => $this->healthEvent->title] : null),
            'recommendation' => $this->whenLoaded('recommendation', fn () => $this->recommendation ? [
                'id' => $this->recommendation->id, 'reference' => $this->recommendation->reference, 'meeting_id' => $this->recommendation->meeting_id,
            ] : null),
            'mission' => $this->whenLoaded('mission', fn () => $this->mission ? ['id' => $this->mission->id, 'reference' => $this->mission->reference] : null),
            'start_at' => $iso($this->start_at),
            'due_at' => $iso($this->due_at),
            'submitted_at' => $iso($this->submitted_at),
            'validated_at' => $iso($this->validated_at),
            'estimated_hours' => $this->estimated_hours,
            'created_by' => $this->whenLoaded('creator', fn () => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'assignees' => $this->whenLoaded('assignments', fn () => $this->assignments->map(fn ($a) => [
                'assignment_id' => $a->id,
                'user_id' => $a->user_id,
                'name' => $a->user?->name,
                'role' => $a->assignment_role,
                'accepted_at' => $iso($a->accepted_at),
            ])->values()),
            'subtasks' => $this->whenLoaded('subtasks', fn () => $this->subtasks->map(fn ($s) => [
                'id' => $s->id, 'reference' => $s->reference, 'title' => $s->title, 'status' => $s->status->value,
                'is_overdue' => $s->isOverdue(),
            ])->values()),
            'created_at' => $iso($this->created_at),
            'updated_at' => $iso($this->updated_at),
            'can' => $this->when($request->user() !== null && $this->relationLoaded('assignments'), fn () => $this->abilities($request)),
        ];
    }

    /** Actions possibles pour l'utilisateur connecté (pour l'affichage des boutons). */
    private function abilities(Request $request): array
    {
        $user = $request->user();
        $access = app(TaskAccess::class);
        $step = $this->status->value === 'submitted' ? $this->activeWorkflow?->currentStep() : null;
        $isResponsible = $this->responsibleId() === $user->id;
        $isAssignee = $this->assignments->contains('user_id', $user->id);

        return [
            'update' => ! $this->status->isClosed() && $access->canManage($user, $this->resource),
            'execute' => $isAssignee && ! $this->status->isClosed(),
            'submit' => $isResponsible && $this->status->canSubmit(),
            'decide' => $step !== null && app(ApprovalService::class)->canDecide($user, $this->resource, $step),
            'delete' => ! $this->status->isClosed() && $access->hasForUnit($user, 'tasks.delete', $this->organization_unit_id),
        ];
    }
}
