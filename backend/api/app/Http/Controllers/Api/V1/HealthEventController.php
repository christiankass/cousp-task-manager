<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HealthEventSeverity;
use App\Enums\HealthEventType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CoordinationPresenter as P;
use App\Http\Resources\TaskResource;
use App\Models\HealthEvent;
use App\Models\SituationReport;
use App\Models\Task;
use App\Services\AuditLogger;
use App\Services\ReferenceGenerator;
use App\Services\Tasks\TaskService;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Espaces de coordination des urgences sanitaires (§4.6). Consultables par tous les membres
 * de l'institution ; gérés par health_events.manage ou par le coordonnateur désigné.
 */
class HealthEventController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private ReferenceGenerator $references,
        private TaskService $tasks,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(HealthEvent::STATUSES)],
            'event_type' => ['nullable', Rule::enum(HealthEventType::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = HealthEvent::query()
            ->with('coordinator')
            ->withCount([
                'tasks',
                'tasks as open_tasks_count' => fn ($q) => $q->whereNull('validated_at'),
                'tasks as overdue_tasks_count' => fn ($q) => $q->overdue(),
                'missions',
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->string('event_type')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'ilike', '%'.$request->string('search').'%')
                ->orWhere('reference', 'ilike', '%'.$request->string('search').'%')
                ->orWhere('location', 'ilike', '%'.$request->string('search').'%')))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'monitoring' THEN 1 ELSE 2 END")
            ->orderByDesc('start_at')
            ->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::success($events->through(fn ($e) => P::event($e)));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('health_events.manage'), 403, __('messages.forbidden'));
        $data = $request->validate($this->rules());
        if (! empty($data['coordinator_id'])) {
            $this->tasks->ensureMember($data['coordinator_id'], 'coordinator_id');
        }

        $event = DB::transaction(function () use ($data, $request) {
            $event = HealthEvent::query()->create($data + [
                'reference' => $this->references->next($this->context->institution(), ReferenceGenerator::EVENT),
                'status' => 'active',
                'created_by' => $request->user()->id,
            ]);
            $this->audit->log('health_event.created', $event, [], ['reference' => $event->reference]);

            return $event;
        });

        return ApiResponse::success(P::event($event->load('coordinator')), __('messages.created'), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $event = HealthEvent::query()->with('coordinator')->findOrFail($id);

        $tasks = Task::query()->where('health_event_id', $event->id)
            ->with(['organizationUnit', 'assignments.user'])
            ->orderByRaw('validated_at IS NOT NULL')->orderBy('due_at')
            ->get();

        // Équipes impliquées : structures qui portent des actions de riposte.
        $teams = $tasks->groupBy('organization_unit_id')->map(fn ($group) => [
            'id' => $group->first()->organizationUnit?->id,
            'name' => $group->first()->organizationUnit?->name,
            'total' => $group->count(),
            'validated' => $group->where('status', TaskStatus::Validated)->count(),
            'overdue' => $group->filter->isOverdue()->count(),
        ])->values();

        $byStatus = collect(TaskStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $tasks->where('status', $s)->count()]);
        $total = $tasks->count();

        return ApiResponse::success(P::event($event) + [
            'stats' => [
                'tasks_total' => $total,
                'by_status' => $byStatus,
                'overdue' => $tasks->filter->isOverdue()->count(),
                'completion_rate' => $total ? round($byStatus['validated'] * 100 / $total, 1) : null,
            ],
            'teams' => $teams,
            'tasks' => $tasks->map(fn ($t) => P::taskBrief($t))->values(),
            'missions' => $event->missions()->with(['leader', 'task', 'members'])->orderByDesc('departure_at')->get()->map(fn ($m) => P::mission($m))->values(),
            'meetings' => $event->meetings()->orderByDesc('starts_at')->get()->map(fn ($m) => P::meeting($m))->values(),
            'situation_reports' => $event->situationReports()->with('author')->get()->map(fn ($r) => P::situationReport($r))->values(),
            'can' => ['manage' => $this->canManage($request, $event)],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $event = HealthEvent::query()->findOrFail($id);
        abort_unless($this->canManage($request, $event), 403, __('messages.forbidden'));

        $data = $request->validate(array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), $this->rules()) + [
            'status' => ['sometimes', Rule::in(['active', 'monitoring'])],
        ]);
        if ($event->isClosed()) {
            abort(409, __('coordination.event_already_closed'));
        }
        if (! empty($data['coordinator_id'])) {
            $this->tasks->ensureMember($data['coordinator_id'], 'coordinator_id');
        }

        $old = $event->only(array_keys($data));
        $event->update($data);
        $this->audit->log('health_event.updated', $event, array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $old), $data);

        return ApiResponse::success(P::event($event->load('coordinator')), __('messages.updated'));
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $event = HealthEvent::query()->findOrFail($id);
        abort_unless($this->canManage($request, $event), 403, __('messages.forbidden'));
        abort_if($event->isClosed(), 409, __('coordination.event_already_closed'));

        $data = $request->validate([
            'closure_note' => ['required', 'string', 'max:5000'],
            'end_at' => ['nullable', 'date', 'after_or_equal:'.$event->start_at->toIso8601String()],
        ]);

        $event->update(['status' => 'closed', 'end_at' => $data['end_at'] ?? now(), 'closure_note' => $data['closure_note']]);
        $this->audit->log('health_event.closed', $event, [], ['closure_note' => $data['closure_note']]);

        return ApiResponse::success(P::event($event->load('coordinator')), __('messages.updated'));
    }

    public function addSituationReport(Request $request, string $id): JsonResponse
    {
        $event = HealthEvent::query()->findOrFail($id);
        abort_unless($this->canManage($request, $event), 403, __('messages.forbidden'));

        $data = $request->validate([
            'report_date' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:10000'],
            'actions' => ['nullable', 'string', 'max:10000'],
            'needs' => ['nullable', 'string', 'max:10000'],
        ]);

        $report = SituationReport::query()->create($data + ['health_event_id' => $event->id, 'author_id' => $request->user()->id]);
        $this->audit->log('health_event.situation_report', $event, [], ['report_date' => $data['report_date']]);

        return ApiResponse::success(P::situationReport($report->load('author')), __('messages.created'), 201);
    }

    /** Crée une action de riposte rattachée à l'événement (mêmes règles qu'une tâche ordinaire). */
    public function addTask(Request $request, string $id): JsonResponse
    {
        $event = HealthEvent::query()->findOrFail($id);

        $data = $request->validate([
            'organization_unit_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'expected_result' => ['nullable', 'string', 'max:5000'],
            'task_type' => ['nullable', Rule::enum(TaskType::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'start_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'assignees' => ['required', 'array', 'min:1', 'max:50'],
            'assignees.*.user_id' => ['required', 'uuid'],
            'assignees.*.role' => ['required', Rule::in(['responsible', 'collaborator'])],
        ]);

        $task = $this->tasks->create($data + [
            'task_type' => TaskType::Operational->value,
            'priority' => TaskPriority::High->value,
            'health_event_id' => $event->id,
        ], $request->user());

        return ApiResponse::success(new TaskResource($task->load(['organizationUnit', 'assignments.user', 'healthEvent'])), __('messages.created'), 201);
    }

    private function canManage(Request $request, HealthEvent $event): bool
    {
        return $request->user()->hasPermission('health_events.manage') || $event->coordinator_id === $request->user()->id;
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'event_type' => ['required', Rule::enum(HealthEventType::class)],
            'description' => ['nullable', 'string', 'max:10000'],
            'location' => ['nullable', 'string', 'max:255'],
            'severity' => ['required', Rule::enum(HealthEventSeverity::class)],
            'start_at' => ['required', 'date'],
            'coordinator_id' => ['nullable', 'uuid'],
        ];
    }
}
