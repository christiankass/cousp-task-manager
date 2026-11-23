<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Services\Tasks\ApprovalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Soumission et validation hiérarchique (§22). */
class TaskWorkflowController extends Controller
{
    public function __construct(private ApprovalService $approvals) {}

    public function submit(Request $request, string $id): JsonResponse
    {
        $task = TaskController::findVisible($request, $id);
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);
        $this->approvals->submit($task, $request->user(), $data['comment'] ?? null);

        return ApiResponse::success(new TaskResource($task->refresh()->load(['organizationUnit', 'assignments.user'])), __('tasks.submitted'));
    }

    public function approvals(Request $request, string $id): JsonResponse
    {
        $task = TaskController::findVisible($request, $id);

        return ApiResponse::success($task->workflows()->with(['steps.approver', 'steps.approverRole', 'steps.decider'])->get()->map(fn ($w) => [
            'id' => $w->id,
            'status' => $w->status,
            'current_step' => $w->current_step,
            'started_at' => $w->started_at?->toIso8601ZuluString(),
            'completed_at' => $w->completed_at?->toIso8601ZuluString(),
            'steps' => $w->steps->map(fn ($s) => [
                'step_order' => $s->step_order,
                'approver_type' => $s->approver_type->value,
                'approver' => $s->approver ? ['id' => $s->approver->id, 'name' => $s->approver->name] : null,
                'approver_role' => $s->approverRole ? ['code' => $s->approverRole->code, 'name' => __('roles.'.$s->approverRole->code)] : null,
                'status' => $s->status,
                'decision' => $s->decision,
                'decided_by' => $s->decider ? ['id' => $s->decider->id, 'name' => $s->decider->name] : null,
                'comments' => $s->comments,
                'decided_at' => $s->decided_at?->toIso8601ZuluString(),
            ]),
        ]));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $task = TaskController::findVisible($request, $id);
        $data = $request->validate(['comments' => ['nullable', 'string', 'max:5000']]);
        $task = $this->approvals->approve($task, $request->user(), $data['comments'] ?? null);

        return ApiResponse::success(new TaskResource($task->load(['organizationUnit', 'assignments.user'])), __('tasks.approved'));
    }

    /** Rejet et demande de correction ont le même effet : la tâche revient à l'exécutant. */
    public function reject(Request $request, string $id): JsonResponse
    {
        $task = TaskController::findVisible($request, $id);
        $data = $request->validate(['comments' => ['required', 'string', 'max:5000']]);
        $task = $this->approvals->reject($task, $request->user(), $data['comments']);

        return ApiResponse::success(new TaskResource($task->load(['organizationUnit', 'assignments.user'])), __('tasks.rejected'));
    }
}
