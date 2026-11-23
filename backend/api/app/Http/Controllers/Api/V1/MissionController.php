<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskPriority;
use App\Http\Controllers\Controller;
use App\Http\Resources\CoordinationPresenter as P;
use App\Models\OperationalMission;
use App\Services\Coordination\MissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Missions opérationnelles (§24). */
class MissionController extends Controller
{
    public function __construct(private MissionService $missions) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'health_event_id' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();

        $page = OperationalMission::query()
            ->with(['leader', 'healthEvent', 'task.assignments.user', 'task.organizationUnit', 'members'])
            ->when($request->filled('health_event_id'), fn ($q) => $q->where('health_event_id', $request->string('health_event_id')))
            ->when($request->boolean('mine'), fn ($q) => $q->where(fn ($w) => $w
                ->where('mission_leader_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id))))
            ->orderByDesc('departure_at')
            ->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::success($page->through(fn ($m) => P::mission($m)));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('health_events.manage'), 403, __('messages.forbidden'));

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'objectives' => ['required', 'string', 'max:10000'],
            'departure_at' => ['required', 'date'],
            'return_at' => ['required', 'date', 'after_or_equal:departure_at'],
            'organization_unit_id' => ['required', 'uuid'],
            'health_event_id' => ['nullable', 'uuid'],
            'mission_leader_id' => ['required', 'uuid'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'members' => ['nullable', 'array', 'max:50'],
            'members.*.user_id' => ['required', 'uuid'],
            'members.*.assignment' => ['nullable', 'string', 'max:120'],
        ]);

        $mission = $this->missions->create($data, $request->user());

        return ApiResponse::success(P::mission($this->loaded($mission), true), __('messages.created'), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $mission = $this->loaded(OperationalMission::query()->findOrFail($id));
        $user = $request->user();

        return ApiResponse::success(P::mission($mission, true) + [
            'can' => [
                'manage' => $user->hasPermission('health_events.manage'),
                'report' => $user->id === $mission->mission_leader_id && $mission->task?->status->canSubmit(),
            ],
        ]);
    }

    public function addMember(Request $request, string $id): JsonResponse
    {
        abort_unless($request->user()->hasPermission('health_events.manage'), 403, __('messages.forbidden'));
        $mission = OperationalMission::query()->with('task')->findOrFail($id);

        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'assignment' => ['nullable', 'string', 'max:120'],
        ]);
        $this->missions->addMember($mission, $data['user_id'], $data['assignment'] ?? null, $request->user());

        return ApiResponse::success(P::mission($this->loaded($mission), true), __('messages.updated'), 201);
    }

    public function report(Request $request, string $id): JsonResponse
    {
        $mission = OperationalMission::query()->with('task')->findOrFail($id);
        $data = $request->validate(['report' => ['required', 'string', 'max:50000']]);

        $this->missions->submitReport($mission, $data['report'], $request->user());

        return ApiResponse::success(P::mission($this->loaded($mission), true), __('tasks.submitted'));
    }

    private function loaded(OperationalMission $mission): OperationalMission
    {
        return $mission->load(['leader', 'healthEvent', 'task.assignments.user', 'task.organizationUnit', 'members.user']);
    }
}
