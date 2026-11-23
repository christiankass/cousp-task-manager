<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CoordinationPresenter as P;
use App\Http\Resources\TaskResource;
use App\Models\Recommendation;
use App\Services\Coordination\MeetingService;
use App\Services\Tasks\TaskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Suivi transversal des recommandations et conversion en tâches (§23). */
class RecommendationController extends Controller
{
    public const EXECUTION_STATUSES = ['not_converted', 'in_progress', 'overdue', 'done', 'cancelled'];

    public function __construct(private MeetingService $meetings, private TaskService $tasks) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'meeting_id' => ['nullable', 'uuid'],
            'organization_unit_id' => ['nullable', 'uuid'],
            'execution_status' => ['nullable', Rule::in(self::EXECUTION_STATUSES)],
        ]);

        $items = Recommendation::query()
            ->with(['meeting', 'responsible', 'organizationUnit', 'tasks.assignments.user', 'tasks.organizationUnit'])
            ->when($request->filled('meeting_id'), fn ($q) => $q->where('meeting_id', $request->string('meeting_id')))
            ->when($request->filled('organization_unit_id'), fn ($q) => $q->where('organization_unit_id', $request->string('organization_unit_id')))
            ->join('meetings', 'meetings.id', '=', 'recommendations.meeting_id')
            ->whereNull('meetings.deleted_at')
            ->orderByDesc('meetings.starts_at')->orderBy('recommendations.number')
            ->select('recommendations.*')
            ->limit(500)
            ->get();

        // L'état d'exécution se calcule à partir des tâches : on filtre après chargement.
        if ($request->filled('execution_status')) {
            $items = $items->filter(fn ($r) => $r->executionStatus() === $request->string('execution_status')->toString())->values();
        }

        return ApiResponse::success($items->map(fn ($r) => P::recommendation($r))->values(), meta: ['summary' => $this->meetings->summary($items)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $recommendation = Recommendation::query()->with('meeting')->findOrFail($id);
        abort_unless($this->meetings->canManage($request->user(), $recommendation->meeting), 403, __('messages.forbidden'));

        $data = $request->validate([
            'content' => ['sometimes', 'string', 'max:5000'],
            'expected_result' => ['nullable', 'string', 'max:5000'],
            'organization_unit_id' => ['nullable', 'uuid'],
            'responsible_id' => ['nullable', 'uuid'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'status' => ['sometimes', Rule::in(['open', 'cancelled'])],
        ]);
        if (! empty($data['responsible_id'])) {
            $this->tasks->ensureMember($data['responsible_id'], 'responsible_id');
        }
        if (($data['status'] ?? null) === 'open' && $recommendation->tasks()->exists()) {
            $data['status'] = 'converted';
        }
        $recommendation->update($data);

        return ApiResponse::success(P::recommendation($recommendation->load(['responsible', 'organizationUnit', 'tasks'])), __('messages.updated'));
    }

    public function convert(Request $request, string $id): JsonResponse
    {
        $recommendation = Recommendation::query()->with('meeting')->findOrFail($id);
        abort_unless($this->meetings->canManage($request->user(), $recommendation->meeting), 403, __('messages.forbidden'));

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'organization_unit_id' => ['nullable', 'uuid'],
            'responsible_id' => ['nullable', 'uuid'],
            'collaborator_ids' => ['nullable', 'array', 'max:50'],
            'collaborator_ids.*' => ['uuid'],
            'due_at' => ['nullable', 'date'],
            'expected_result' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'task_type' => ['nullable', Rule::enum(TaskType::class)],
        ]);

        $task = $this->meetings->convertToTask($recommendation, $data, $request->user());

        return ApiResponse::success(new TaskResource($task->load(['organizationUnit', 'assignments.user', 'recommendation'])), __('messages.created'), 201);
    }
}
