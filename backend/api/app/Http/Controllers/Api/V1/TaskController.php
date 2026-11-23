<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public const SORTABLE = ['due_at', 'created_at', 'priority', 'reference', 'status', 'title'];

    public function __construct(private TaskAccess $access, private TaskService $tasks) {}

    /**
     * Liste filtrée et paginée, limitée aux tâches visibles (§27.1).
     * Filtres : status, priority, task_type, organization_unit_id, assignee (me|uuid),
     * overdue, parent_task_id (ou root=1), search, due_from, due_to ; tri : sort=-due_at.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'priority' => ['nullable', 'string'],
            'task_type' => ['nullable', Rule::enum(TaskType::class)],
            'organization_unit_id' => ['nullable', 'uuid'],
            'assignee' => ['nullable', 'string'],
            'overdue' => ['nullable', 'boolean'],
            'parent_task_id' => ['nullable', 'uuid'],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = $this->access->visibleQuery($user)->with(['organizationUnit', 'assignments.user']);

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', $request->string('status')));
        }
        if ($request->filled('priority')) {
            $query->whereIn('priority', explode(',', $request->string('priority')));
        }
        $query->when($request->filled('task_type'), fn ($q) => $q->where('task_type', $request->string('task_type')))
            ->when($request->filled('organization_unit_id'), fn ($q) => $q->where('organization_unit_id', $request->string('organization_unit_id')))
            ->when($request->filled('parent_task_id'), fn ($q) => $q->where('parent_task_id', $request->string('parent_task_id')))
            ->when($request->boolean('root'), fn ($q) => $q->whereNull('parent_task_id'))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($request->filled('due_from'), fn ($q) => $q->where('due_at', '>=', $request->date('due_from')))
            ->when($request->filled('due_to'), fn ($q) => $q->where('due_at', '<=', $request->date('due_to')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'ilike', '%'.$request->string('search').'%')
                ->orWhere('reference', 'ilike', '%'.$request->string('search').'%')));

        if ($request->filled('assignee')) {
            $assignee = $request->string('assignee')->toString() === 'me' ? $user->id : $request->string('assignee')->toString();
            $query->whereIn('tasks.id', TaskAssignment::query()->select('task_id')->where('user_id', $assignee));
        }

        $sort = $request->string('sort', '-created_at')->toString();
        $column = ltrim($sort, '-');
        if (in_array($column, self::SORTABLE, true)) {
            if ($column === 'priority') {
                $query->orderByRaw("CASE priority WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'normal' THEN 2 ELSE 1 END ".(str_starts_with($sort, '-') ? 'DESC' : 'ASC'));
            } else {
                $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
            }
        }

        return ApiResponse::success(TaskResource::collection($query->paginate((int) $request->integer('per_page', 20))));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'organization_unit_id' => ['required', 'uuid'],
            'parent_task_id' => ['nullable', 'uuid'],
            'assignees' => ['required', 'array', 'min:1', 'max:50'],
            'assignees.*.user_id' => ['required', 'uuid'],
            'assignees.*.role' => ['required', Rule::in(['responsible', 'collaborator'])],
        ]);

        $task = $this->tasks->create($data, $request->user());

        return ApiResponse::success(new TaskResource($this->loaded($task)), __('messages.created'), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id);

        return ApiResponse::success(new TaskResource($this->loaded($task)->load('subtasks')));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id);
        abort_unless($this->access->canManage($request->user(), $task), 403, __('messages.forbidden'));

        $data = $request->validate(array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), $this->rules()) + [
            'organization_unit_id' => ['sometimes', 'uuid'],
            'due_at_reason' => ['nullable', 'string', 'max:2000'],
            'transfer_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success(new TaskResource($this->loaded($this->tasks->update($task, $data, $request->user()))), __('messages.updated'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id);
        abort_unless($this->access->hasForUnit($request->user(), 'tasks.delete', $task->organization_unit_id), 403, __('messages.forbidden'));
        $this->tasks->delete($task, $request->user(), $request->string('reason')->toString() ?: null);

        return response()->json(null, 204);
    }

    public function status(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id);
        abort_unless($task->isAssignee($request->user()), 403, __('tasks.not_assignee'));

        $data = $request->validate([
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $task = $this->tasks->changeStatus($task, TaskStatus::from($data['status']), $request->user(), $data['reason'] ?? null);

        return ApiResponse::success(new TaskResource($this->loaded($task)), __('messages.updated'));
    }

    public function progress(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id);
        abort_unless($task->isAssignee($request->user()), 403, __('tasks.not_assignee'));
        $data = $request->validate(['progress' => ['required', 'integer', 'between:0,100']]);

        return ApiResponse::success(new TaskResource($this->loaded($this->tasks->updateProgress($task, $data['progress'], $request->user()))));
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id);
        $user = $request->user();
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'role' => ['required', Rule::in(['responsible', 'collaborator'])],
        ]);

        // Délégation par le responsable lui-même, ou attribution par un gestionnaire.
        $selfDelegation = $task->responsibleId() === $user->id;
        abort_unless($selfDelegation || $this->access->canManage($user, $task), 403, __('messages.forbidden'));

        $this->tasks->assign($task, $data['user_id'], $data['role'], $user);

        return ApiResponse::success(new TaskResource($this->loaded($task)), __('messages.updated'), 201);
    }

    public function unassign(Request $request, string $id, string $assignmentId): JsonResponse
    {
        $task = $this->find($request, $id);
        abort_unless($this->access->canManage($request->user(), $task), 403, __('messages.forbidden'));
        $assignment = TaskAssignment::query()->where('task_id', $task->id)->findOrFail($assignmentId);
        $this->tasks->unassign($task, $assignment, $request->user());

        return response()->json(null, 204);
    }

    public function history(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id);

        return ApiResponse::success($task->history()->with('author')->get()->map(fn ($h) => [
            'id' => $h->id,
            'event' => $h->event,
            'previous_status' => $h->previous_status,
            'new_status' => $h->new_status,
            'by' => $h->author ? ['id' => $h->author->id, 'name' => $h->author->name] : null,
            'reason' => $h->reason,
            'details' => $h->details,
            'changed_at' => $h->changed_at?->toIso8601ZuluString(),
        ]));
    }

    public static function findVisible(Request $request, string $id): Task
    {
        return app(TaskAccess::class)->visibleQuery($request->user())->findOrFail($id);
    }

    private function find(Request $request, string $id): Task
    {
        return self::findVisible($request, $id);
    }

    private function loaded(Task $task): Task
    {
        return $task->load(['organizationUnit', 'creator', 'assignments.user']);
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'expected_result' => ['nullable', 'string', 'max:5000'],
            'task_type' => ['required', Rule::enum(TaskType::class)],
            'category' => ['nullable', 'string', 'max:50'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'start_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'approval_template_id' => ['nullable', 'uuid', Rule::exists('approval_templates', 'id')],
        ];
    }
}
