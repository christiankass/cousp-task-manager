<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\OrganizationUnit;
use App\Models\TaskRecurrence;
use App\Services\AuditLogger;
use App\Services\Tasks\RecurrenceService;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Séries de tâches récurrentes : visibles et gérées par ceux qui peuvent créer des tâches dans la structure. */
class TaskRecurrenceController extends Controller
{
    public function __construct(
        private RecurrenceService $recurrences,
        private TaskAccess $access,
        private TaskService $tasks,
        private AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $units = $this->access->unitIdsFor($user, 'tasks.create');

        $items = TaskRecurrence::query()
            ->with(['organizationUnit', 'responsible'])
            ->where(fn ($q) => $q->where('responsible_id', $user->id)->orWhere('created_by', $user->id)
                ->when($units === null, fn ($w) => $w->orWhereRaw('true'))
                ->when(is_array($units) && $units !== [], fn ($w) => $w->orWhereIn('organization_unit_id', $units)))
            ->orderByDesc('is_active')->orderBy('next_occurrence_on')
            ->get();

        return ApiResponse::success($items->map(fn ($r) => $this->present($r))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $this->authorizeFor($request, $data);

        $recurrence = new TaskRecurrence($data + ['created_by' => $request->user()->id, 'is_active' => true]);
        $this->recurrences->schedule($recurrence);
        $this->audit->log('task_recurrence.created', $recurrence, [], ['title' => $recurrence->title, 'frequency' => $recurrence->frequency]);

        return ApiResponse::success($this->present($recurrence->load(['organizationUnit', 'responsible'])), __('messages.created'), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $recurrence = TaskRecurrence::query()->findOrFail($id);
        $this->authorizeFor($request, $recurrence->only(['organization_unit_id', 'responsible_id']));

        $data = $request->validate(array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), $this->rules()) + [
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->authorizeFor($request, $data + $recurrence->only(['organization_unit_id', 'responsible_id']));

        $recurrence->fill($data);
        $this->recurrences->schedule($recurrence);
        $this->audit->log('task_recurrence.updated', $recurrence, [], $data);

        return ApiResponse::success($this->present($recurrence->load(['organizationUnit', 'responsible'])), __('messages.updated'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $recurrence = TaskRecurrence::query()->findOrFail($id);
        $this->authorizeFor($request, $recurrence->only(['organization_unit_id', 'responsible_id']));

        // Les tâches déjà créées restent ; la série s'arrête simplement.
        $recurrence->update(['is_active' => false, 'next_occurrence_on' => null]);
        $this->audit->log('task_recurrence.stopped', $recurrence);

        return ApiResponse::success(null, __('messages.updated'));
    }

    /** Mêmes règles qu'une création de tâche : droit de créer dans la structure, et d'attribuer à autrui. */
    private function authorizeFor(Request $request, array $data): void
    {
        $user = $request->user();
        $unitId = $data['organization_unit_id'];
        if (! OrganizationUnit::query()->whereKey($unitId)->exists()) {
            throw ValidationException::withMessages(['organization_unit_id' => [__('validation.exists', ['attribute' => 'organization_unit_id'])]]);
        }
        abort_unless($this->access->hasForUnit($user, 'tasks.create', $unitId), 403, __('tasks.cannot_create_in_unit'));

        $people = collect([$data['responsible_id'] ?? null, ...($data['collaborator_ids'] ?? [])])->filter()->unique();
        foreach ($people as $personId) {
            $this->tasks->ensureMember($personId, 'responsible_id');
        }
        if ($people->contains(fn ($id) => $id !== $user->id)) {
            abort_unless($this->access->hasForUnit($user, 'tasks.assign', $unitId), 403, __('tasks.cannot_assign_in_unit'));
        }
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'expected_result' => ['nullable', 'string', 'max:5000'],
            'organization_unit_id' => ['required', 'uuid'],
            'task_type' => ['required', Rule::enum(TaskType::class)],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'responsible_id' => ['required', 'uuid'],
            'collaborator_ids' => ['nullable', 'array', 'max:20'],
            'collaborator_ids.*' => ['uuid'],
            'frequency' => ['required', Rule::in(TaskRecurrence::FREQUENCIES)],
            'interval' => ['nullable', 'integer', 'min:1', 'max:52'],
            'weekdays' => ['nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'min:1', 'max:7'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'lead_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    private function present(TaskRecurrence $r): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'description' => $r->description,
            'expected_result' => $r->expected_result,
            'task_type' => $r->task_type->value,
            'priority' => $r->priority->value,
            'organization_unit' => $r->organizationUnit ? ['id' => $r->organizationUnit->id, 'name' => $r->organizationUnit->name] : null,
            'responsible' => $r->responsible ? ['id' => $r->responsible->id, 'name' => $r->responsible->name] : null,
            'collaborator_ids' => $r->collaborator_ids ?? [],
            'frequency' => $r->frequency,
            'interval' => $r->interval,
            'weekdays' => $r->weekdays ?? [],
            'day_of_month' => $r->day_of_month,
            'due_time' => substr((string) $r->due_time, 0, 5),
            'lead_days' => $r->lead_days,
            'starts_on' => $r->starts_on?->toDateString(),
            'ends_on' => $r->ends_on?->toDateString(),
            'next_occurrence_on' => $r->next_occurrence_on?->toDateString(),
            'upcoming' => $r->is_active ? $this->recurrences->preview($r) : [],
            'is_active' => $r->is_active,
            'generated_count' => $r->tasks()->count(),
        ];
    }
}
