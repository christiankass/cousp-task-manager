<?php

namespace App\Services\Coordination;

use App\Enums\TaskType;
use App\Models\Meeting;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ReferenceGenerator;
use App\Services\Tasks\TaskService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Réunions, participants et recommandations (§4.5, §6.3). */
class MeetingService
{
    public function __construct(
        private TenantContext $context,
        private ReferenceGenerator $references,
        private TaskService $tasks,
        private AuditLogger $audit,
    ) {}

    /** Le gestionnaire des réunions, le président et le secrétaire peuvent tenir la réunion à jour. */
    public function canManage(User $user, Meeting $meeting): bool
    {
        return $user->hasPermission('meetings.manage')
            || in_array($user->id, array_filter([$meeting->chair_id, $meeting->secretary_id, $meeting->created_by]), true);
    }

    public function create(array $data, User $user): Meeting
    {
        foreach (['chair_id', 'secretary_id'] as $field) {
            if (! empty($data[$field])) {
                $this->tasks->ensureMember($data[$field], $field);
            }
        }

        return DB::transaction(function () use ($data, $user) {
            $meeting = Meeting::query()->create([
                ...$data,
                'reference' => $this->references->next($this->context->institution(), ReferenceGenerator::MEETING),
                'status' => $data['status'] ?? 'planned',
                'created_by' => $user->id,
            ]);
            $this->audit->log('meeting.created', $meeting, [], ['reference' => $meeting->reference]);

            return $meeting;
        });
    }

    public function update(Meeting $meeting, array $data): Meeting
    {
        $old = $meeting->only(array_keys($data));
        $meeting->update($data);
        $this->audit->log('meeting.updated', $meeting, array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $old), $data);

        return $meeting;
    }

    public function addRecommendation(Meeting $meeting, array $data, User $user): Recommendation
    {
        if (! empty($data['responsible_id'])) {
            $this->tasks->ensureMember($data['responsible_id'], 'responsible_id');
        }

        return DB::transaction(function () use ($meeting, $data, $user) {
            // Verrou sur la réunion : numérotation R01, R02… sans doublon.
            Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            $number = (int) $meeting->recommendations()->max('number') + 1;

            $recommendation = $meeting->recommendations()->create([
                ...$data,
                'institution_id' => $meeting->institution_id,
                'number' => $number,
                'reference' => sprintf('%s-R%02d', $meeting->reference, $number),
                'status' => 'open',
                'created_by' => $user->id,
            ]);
            $this->audit->log('recommendation.created', $recommendation, [], ['reference' => $recommendation->reference]);

            return $recommendation;
        });
    }

    /**
     * Transforme une recommandation en tâche (§4.5) : la tâche garde la référence d'origine
     * et passe par les règles habituelles (droits sur la structure, notifications, validation).
     */
    public function convertToTask(Recommendation $recommendation, array $data, User $user): Task
    {
        if ($recommendation->status === 'cancelled') {
            throw ValidationException::withMessages(['recommendation' => [__('coordination.recommendation_cancelled')]]);
        }

        $meeting = $recommendation->meeting;
        $responsible = $data['responsible_id'] ?? $recommendation->responsible_id;
        $unit = $data['organization_unit_id'] ?? $recommendation->organization_unit_id ?? $meeting->organization_unit_id;

        $errors = array_filter([
            'responsible_id' => $responsible ? null : [__('coordination.responsible_required')],
            'organization_unit_id' => $unit ? null : [__('coordination.unit_required')],
        ]);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $assignees = collect($data['collaborator_ids'] ?? [])
            ->reject(fn ($id) => $id === $responsible)->unique()
            ->map(fn ($id) => ['user_id' => $id, 'role' => 'collaborator'])
            ->prepend(['user_id' => $responsible, 'role' => 'responsible'])->values()->all();

        $task = $this->tasks->create([
            'organization_unit_id' => $unit,
            'title' => $data['title'] ?? str($recommendation->content)->squish()->limit(240)->toString(),
            'description' => trim(__('coordination.task_from_recommendation', [
                'reference' => $recommendation->reference, 'meeting' => $meeting->title,
            ])."\n\n".$recommendation->content),
            'expected_result' => $data['expected_result'] ?? $recommendation->expected_result,
            'task_type' => $data['task_type'] ?? TaskType::Administrative->value,
            'category' => 'recommendation',
            'priority' => $data['priority'] ?? $recommendation->priority?->value ?? 'normal',
            'start_at' => now()->toIso8601String(),
            'due_at' => $data['due_at'] ?? $recommendation->due_at?->toIso8601String(),
            'health_event_id' => $meeting->health_event_id,
            'recommendation_id' => $recommendation->id,
            'assignees' => $assignees,
        ], $user);

        $recommendation->update(['status' => 'converted']);
        $this->audit->log('recommendation.converted', $recommendation, [], ['task' => $task->reference]);

        return $task;
    }

    /**
     * Suivi d'exécution des recommandations d'une ou plusieurs réunions (taux §6.3).
     *
     * @param  iterable<Recommendation>  $recommendations  avec la relation tasks chargée
     */
    public function summary(iterable $recommendations): array
    {
        $counts = ['total' => 0, 'not_converted' => 0, 'in_progress' => 0, 'overdue' => 0, 'done' => 0, 'cancelled' => 0];
        foreach ($recommendations as $recommendation) {
            $counts['total']++;
            $counts[$recommendation->executionStatus()]++;
        }
        $active = $counts['total'] - $counts['cancelled'];

        return $counts + ['execution_rate' => $active > 0 ? round($counts['done'] * 100 / $active, 1) : null];
    }
}
