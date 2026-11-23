<?php

namespace App\Services\Coordination;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\HealthEvent;
use App\Models\MissionMember;
use App\Models\OperationalMission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ReferenceGenerator;
use App\Services\Tasks\ApprovalService;
use App\Services\Tasks\TaskService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Missions de terrain (§24). Chaque mission crée une tâche opérationnelle : on réutilise
 * ainsi les échéances, les alertes de retard, les notifications et le circuit de validation.
 */
class MissionService
{
    public function __construct(
        private TenantContext $context,
        private ReferenceGenerator $references,
        private TaskService $tasks,
        private ApprovalService $approvals,
        private AuditLogger $audit,
    ) {}

    public function create(array $data, User $user): OperationalMission
    {
        $this->tasks->ensureMember($data['mission_leader_id'], 'mission_leader_id');
        if (! empty($data['health_event_id'])) {
            $event = HealthEvent::query()->find($data['health_event_id'])
                ?? throw ValidationException::withMessages(['health_event_id' => [__('validation.exists', ['attribute' => 'health_event_id'])]]);
            if ($event->isClosed()) {
                throw ValidationException::withMessages(['health_event_id' => [__('coordination.event_closed')]]);
            }
        }

        $members = collect($data['members'] ?? [])->reject(fn ($m) => $m['user_id'] === $data['mission_leader_id'])->unique('user_id')->values();
        $reference = $this->references->next($this->context->institution(), ReferenceGenerator::MISSION);

        return DB::transaction(function () use ($data, $user, $members, $reference) {
            $task = $this->tasks->create([
                'organization_unit_id' => $data['organization_unit_id'],
                'title' => __('coordination.mission_task_title', ['reference' => $reference, 'destination' => $data['destination']]),
                'description' => $data['objectives'],
                'expected_result' => __('coordination.mission_expected_result'),
                'task_type' => TaskType::Operational->value,
                'category' => 'mission',
                'priority' => $data['priority'] ?? 'high',
                'start_at' => $data['departure_at'],
                'due_at' => $data['return_at'],
                'health_event_id' => $data['health_event_id'] ?? null,
                'assignees' => $members->map(fn ($m) => ['user_id' => $m['user_id'], 'role' => 'collaborator'])
                    ->prepend(['user_id' => $data['mission_leader_id'], 'role' => 'responsible'])->all(),
            ], $user);

            $mission = OperationalMission::query()->create([
                'task_id' => $task->id,
                'reference' => $reference,
                'title' => $data['title'],
                'destination' => $data['destination'],
                'objectives' => $data['objectives'],
                'departure_at' => $data['departure_at'],
                'return_at' => $data['return_at'],
                'health_event_id' => $data['health_event_id'] ?? null,
                'mission_leader_id' => $data['mission_leader_id'],
                'created_by' => $user->id,
            ]);

            foreach ($members as $member) {
                MissionMember::query()->create([
                    'mission_id' => $mission->id, 'user_id' => $member['user_id'], 'assignment' => $member['assignment'] ?? null,
                ]);
            }

            $this->audit->log('mission.created', $mission, [], ['reference' => $mission->reference, 'task' => $task->reference]);

            return $mission;
        });
    }

    public function addMember(OperationalMission $mission, string $userId, ?string $assignment, User $by): MissionMember
    {
        if ($userId === $mission->mission_leader_id) {
            throw ValidationException::withMessages(['user_id' => [__('coordination.leader_already_in_team')]]);
        }

        // Ajoute aussi la personne comme collaboratrice de la tâche (notification comprise).
        $this->tasks->assign($mission->task, $userId, 'collaborator', $by);

        return MissionMember::query()->updateOrCreate(
            ['mission_id' => $mission->id, 'user_id' => $userId],
            ['assignment' => $assignment, 'participation_status' => 'assigned']
        );
    }

    /** Le chef de mission dépose son rapport ; il part en validation comme un livrable de tâche. */
    public function submitReport(OperationalMission $mission, string $report, User $user): OperationalMission
    {
        if ($user->id !== $mission->mission_leader_id) {
            abort(403, __('coordination.only_leader_reports'));
        }

        $task = $mission->task;
        if ($task->status === TaskStatus::Todo) {
            // Un rapport atteste que la mission a eu lieu : la tâche passe d'abord « en cours ».
            $this->tasks->changeStatus($task, TaskStatus::InProgress, $user);
        }

        DB::transaction(function () use ($mission, $report, $task, $user) {
            $mission->update(['report' => $report, 'report_submitted_at' => now()]);
            $this->approvals->submit($task->refresh(), $user, __('coordination.report_submitted_comment', ['reference' => $mission->reference]));
            $this->audit->log('mission.report_submitted', $mission, [], ['reference' => $mission->reference]);
        });

        return $mission->refresh();
    }
}
