<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MeetingType;
use App\Enums\TaskPriority;
use App\Http\Controllers\Controller;
use App\Http\Resources\CoordinationPresenter as P;
use App\Models\HealthEvent;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\OrganizationUnit;
use App\Services\Coordination\MeetingService;
use App\Services\Tasks\TaskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Réunions, participants et recommandations (§4.5, API §23). */
class MeetingController extends Controller
{
    public function __construct(private MeetingService $meetings, private TaskService $tasks) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(Meeting::STATUSES)],
            'meeting_type' => ['nullable', Rule::enum(MeetingType::class)],
            'health_event_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Meeting::query()
            ->with(['organizationUnit', 'healthEvent', 'chair', 'recommendations.tasks'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('meeting_type'), fn ($q) => $q->where('meeting_type', $request->string('meeting_type')))
            ->when($request->filled('health_event_id'), fn ($q) => $q->where('health_event_id', $request->string('health_event_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'ilike', '%'.$request->string('search').'%')
                ->orWhere('reference', 'ilike', '%'.$request->string('search').'%')))
            ->orderByDesc('starts_at')
            ->paginate((int) $request->integer('per_page', 20));

        return ApiResponse::success($page->through(fn (Meeting $m) => P::meeting($m, $this->meetings->summary($m->recommendations))));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('meetings.manage'), 403, __('messages.forbidden'));
        $data = $request->validate($this->rules());
        $this->checkLinks($data);

        $meeting = $this->meetings->create($data, $request->user());

        return ApiResponse::success(P::meeting($this->loaded($meeting), $this->meetings->summary([])), __('messages.created'), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $meeting = $this->loaded(Meeting::query()->findOrFail($id));

        // §6.3 : à la réunion suivante, on revoit le taux d'exécution des recommandations précédentes.
        $previous = Meeting::query()
            ->where('meeting_type', $meeting->meeting_type)
            ->where('starts_at', '<', $meeting->starts_at)
            ->where('status', '!=', 'cancelled')
            ->with(['recommendations.tasks.assignments.user', 'recommendations.tasks.organizationUnit', 'recommendations.responsible'])
            ->orderByDesc('starts_at')
            ->first();

        return ApiResponse::success(P::meeting($meeting, $this->meetings->summary($meeting->recommendations)) + [
            'participants' => $meeting->participants->map(fn ($p) => P::participant($p))->values(),
            'recommendations' => $meeting->recommendations->map(fn ($r) => P::recommendation($r))->values(),
            'previous_meeting' => $previous ? P::meeting($previous, $this->meetings->summary($previous->recommendations)) + [
                'recommendations' => $previous->recommendations->map(fn ($r) => P::recommendation($r))->values(),
            ] : null,
            'can' => ['manage' => $this->meetings->canManage($request->user(), $meeting)],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $meeting = Meeting::query()->findOrFail($id);
        $this->authorizeManage($request, $meeting);

        $data = $request->validate(array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), $this->rules()) + [
            'minutes' => ['nullable', 'string', 'max:100000'],
            'status' => ['sometimes', Rule::in(Meeting::STATUSES)],
        ]);
        $this->checkLinks($data);
        $this->meetings->update($meeting, $data);

        return ApiResponse::success(P::meeting($this->loaded($meeting), $this->meetings->summary($meeting->recommendations)), __('messages.updated'));
    }

    public function addParticipants(Request $request, string $id): JsonResponse
    {
        $meeting = Meeting::query()->findOrFail($id);
        $this->authorizeManage($request, $meeting);

        $data = $request->validate([
            'participants' => ['required', 'array', 'min:1', 'max:200'],
            'participants.*.user_id' => ['nullable', 'uuid'],
            'participants.*.external_name' => ['nullable', 'string', 'max:190'],
            'participants.*.organization' => ['nullable', 'string', 'max:190'],
            'participants.*.attendance' => ['nullable', Rule::in(MeetingParticipant::ATTENDANCE)],
        ]);

        foreach ($data['participants'] as $i => $p) {
            if (empty($p['user_id']) && blank($p['external_name'] ?? null)) {
                throw ValidationException::withMessages(["participants.$i.user_id" => [__('coordination.participant_required')]]);
            }
            if (! empty($p['user_id'])) {
                $this->tasks->ensureMember($p['user_id'], "participants.$i.user_id");
            }
        }

        foreach ($data['participants'] as $p) {
            $values = ['external_name' => $p['user_id'] ?? null ? null : $p['external_name'], 'organization' => $p['organization'] ?? null, 'attendance' => $p['attendance'] ?? 'invited'];
            ! empty($p['user_id'])
                ? MeetingParticipant::query()->updateOrCreate(['meeting_id' => $meeting->id, 'user_id' => $p['user_id']], $values)
                : MeetingParticipant::query()->create(['meeting_id' => $meeting->id] + $values);
        }

        return ApiResponse::success($meeting->participants()->with('user')->get()->map(fn ($p) => P::participant($p))->values(), __('messages.updated'), 201);
    }

    public function updateParticipant(Request $request, string $id, string $participantId): JsonResponse
    {
        $meeting = Meeting::query()->findOrFail($id);
        $this->authorizeManage($request, $meeting);
        $data = $request->validate(['attendance' => ['required', Rule::in(MeetingParticipant::ATTENDANCE)]]);

        $participant = $meeting->participants()->findOrFail($participantId);
        $participant->update($data);

        return ApiResponse::success(P::participant($participant->load('user')), __('messages.updated'));
    }

    public function removeParticipant(Request $request, string $id, string $participantId): JsonResponse
    {
        $meeting = Meeting::query()->findOrFail($id);
        $this->authorizeManage($request, $meeting);
        $meeting->participants()->findOrFail($participantId)->delete();

        return ApiResponse::success(null, __('messages.ok'));
    }

    public function addRecommendation(Request $request, string $id): JsonResponse
    {
        $meeting = Meeting::query()->findOrFail($id);
        $this->authorizeManage($request, $meeting);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'expected_result' => ['nullable', 'string', 'max:5000'],
            'organization_unit_id' => ['nullable', 'uuid'],
            'responsible_id' => ['nullable', 'uuid'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
        ]);
        $this->checkLinks($data);

        $recommendation = $this->meetings->addRecommendation($meeting, $data, $request->user());

        return ApiResponse::success(P::recommendation($recommendation->load(['responsible', 'organizationUnit', 'tasks'])), __('messages.created'), 201);
    }

    private function loaded(Meeting $meeting): Meeting
    {
        return $meeting->load([
            'organizationUnit', 'healthEvent', 'chair', 'secretary', 'participants.user',
            'recommendations.responsible', 'recommendations.organizationUnit',
            'recommendations.tasks.assignments.user', 'recommendations.tasks.organizationUnit',
        ]);
    }

    private function authorizeManage(Request $request, Meeting $meeting): void
    {
        abort_unless($this->meetings->canManage($request->user(), $meeting), 403, __('messages.forbidden'));
    }

    /** Les identifiants liés doivent appartenir à l'institution courante. */
    private function checkLinks(array $data): void
    {
        if (! empty($data['organization_unit_id']) && ! OrganizationUnit::query()->whereKey($data['organization_unit_id'])->exists()) {
            throw ValidationException::withMessages(['organization_unit_id' => [__('validation.exists', ['attribute' => 'organization_unit_id'])]]);
        }
        if (! empty($data['health_event_id']) && ! HealthEvent::query()->whereKey($data['health_event_id'])->exists()) {
            throw ValidationException::withMessages(['health_event_id' => [__('validation.exists', ['attribute' => 'health_event_id'])]]);
        }
        foreach (['chair_id', 'secretary_id', 'responsible_id'] as $field) {
            if (! empty($data[$field])) {
                $this->tasks->ensureMember($data[$field], $field);
            }
        }
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'meeting_type' => ['required', Rule::enum(MeetingType::class)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'agenda' => ['nullable', 'string', 'max:20000'],
            'organization_unit_id' => ['nullable', 'uuid'],
            'health_event_id' => ['nullable', 'uuid'],
            'chair_id' => ['nullable', 'uuid'],
            'secretary_id' => ['nullable', 'uuid'],
        ];
    }
}
