<?php

namespace App\Http\Resources;

use App\Models\HealthEvent;
use App\Models\Meeting;
use App\Models\OperationalMission;
use App\Models\Recommendation;
use App\Models\SituationReport;
use App\Models\Task;
use App\Models\User;

/** Format JSON des réunions, recommandations, événements et missions. */
class CoordinationPresenter
{
    private static function iso($date): ?string
    {
        return $date?->toIso8601ZuluString();
    }

    private static function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    public static function taskBrief(Task $task): array
    {
        $responsible = $task->relationLoaded('assignments') ? $task->assignments->firstWhere('assignment_role', 'responsible') : null;

        return [
            'id' => $task->id,
            'reference' => $task->reference,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'is_overdue' => $task->isOverdue(),
            'due_at' => self::iso($task->due_at),
            'responsible' => $responsible ? self::person($responsible->user) : null,
            'organization_unit' => $task->relationLoaded('organizationUnit') && $task->organizationUnit
                ? ['id' => $task->organizationUnit->id, 'name' => $task->organizationUnit->name] : null,
        ];
    }

    public static function event(HealthEvent $event): array
    {
        return [
            'id' => $event->id,
            'reference' => $event->reference,
            'title' => $event->title,
            'event_type' => $event->event_type->value,
            'event_type_label' => __('coordination.event_type.'.$event->event_type->value),
            'description' => $event->description,
            'location' => $event->location,
            'severity' => $event->severity->value,
            'status' => $event->status,
            'status_label' => __('coordination.event_status.'.$event->status),
            'start_at' => self::iso($event->start_at),
            'end_at' => self::iso($event->end_at),
            'closure_note' => $event->closure_note,
            'coordinator' => $event->relationLoaded('coordinator') ? self::person($event->coordinator) : null,
            'tasks_count' => $event->tasks_count ?? null,
            'open_tasks_count' => $event->open_tasks_count ?? null,
            'overdue_tasks_count' => $event->overdue_tasks_count ?? null,
            'missions_count' => $event->missions_count ?? null,
            'created_at' => self::iso($event->created_at),
        ];
    }

    public static function situationReport(SituationReport $report): array
    {
        return [
            'id' => $report->id,
            'report_date' => $report->report_date?->toDateString(),
            'summary' => $report->summary,
            'actions' => $report->actions,
            'needs' => $report->needs,
            'author' => self::person($report->author),
            'created_at' => self::iso($report->created_at),
        ];
    }

    public static function meeting(Meeting $meeting, ?array $summary = null): array
    {
        return [
            'id' => $meeting->id,
            'reference' => $meeting->reference,
            'title' => $meeting->title,
            'meeting_type' => $meeting->meeting_type->value,
            'meeting_type_label' => __('coordination.meeting_type.'.$meeting->meeting_type->value),
            'status' => $meeting->status,
            'starts_at' => self::iso($meeting->starts_at),
            'ends_at' => self::iso($meeting->ends_at),
            'location' => $meeting->location,
            'agenda' => $meeting->agenda,
            'minutes' => $meeting->minutes,
            'organization_unit' => $meeting->relationLoaded('organizationUnit') && $meeting->organizationUnit
                ? ['id' => $meeting->organizationUnit->id, 'name' => $meeting->organizationUnit->name] : null,
            'health_event' => $meeting->relationLoaded('healthEvent') && $meeting->healthEvent
                ? ['id' => $meeting->healthEvent->id, 'reference' => $meeting->healthEvent->reference, 'title' => $meeting->healthEvent->title] : null,
            'chair' => $meeting->relationLoaded('chair') ? self::person($meeting->chair) : null,
            'secretary' => $meeting->relationLoaded('secretary') ? self::person($meeting->secretary) : null,
            'follow_up' => $summary,
        ];
    }

    public static function participant($p): array
    {
        return [
            'id' => $p->id,
            'user' => self::person($p->user),
            'external_name' => $p->external_name,
            'organization' => $p->organization,
            'attendance' => $p->attendance,
        ];
    }

    public static function recommendation(Recommendation $r): array
    {
        $status = $r->executionStatus();

        return [
            'id' => $r->id,
            'meeting_id' => $r->meeting_id,
            'meeting' => $r->relationLoaded('meeting') ? ['id' => $r->meeting->id, 'reference' => $r->meeting->reference, 'title' => $r->meeting->title, 'starts_at' => self::iso($r->meeting->starts_at)] : null,
            'number' => $r->number,
            'reference' => $r->reference,
            'content' => $r->content,
            'expected_result' => $r->expected_result,
            'priority' => $r->priority?->value,
            'due_at' => self::iso($r->due_at),
            'responsible' => $r->relationLoaded('responsible') ? self::person($r->responsible) : null,
            'organization_unit' => $r->relationLoaded('organizationUnit') && $r->organizationUnit
                ? ['id' => $r->organizationUnit->id, 'name' => $r->organizationUnit->name] : null,
            'status' => $r->status,
            'execution_status' => $status,
            'execution_status_label' => __('coordination.recommendation_status.'.$status),
            'tasks' => $r->tasks->map(fn (Task $t) => self::taskBrief($t))->values(),
        ];
    }

    public static function mission(OperationalMission $m, bool $full = false): array
    {
        $status = $m->status();

        return [
            'id' => $m->id,
            'reference' => $m->reference,
            'title' => $m->title,
            'destination' => $m->destination,
            'objectives' => $m->objectives,
            'departure_at' => self::iso($m->departure_at),
            'return_at' => self::iso($m->return_at),
            'status' => $status,
            'status_label' => __('coordination.mission_status.'.$status),
            'leader' => self::person($m->leader),
            'health_event' => $m->healthEvent ? ['id' => $m->healthEvent->id, 'reference' => $m->healthEvent->reference, 'title' => $m->healthEvent->title] : null,
            'task' => $m->task ? self::taskBrief($m->task) : null,
            'members_count' => $m->relationLoaded('members') ? $m->members->count() + 1 : null,
            'report_submitted_at' => self::iso($m->report_submitted_at),
        ] + ($full ? [
            'report' => $m->report,
            'members' => $m->members->map(fn ($mm) => [
                'id' => $mm->id, 'user' => self::person($mm->user), 'assignment' => $mm->assignment, 'participation_status' => $mm->participation_status,
            ])->values(),
        ] : []);
    }
}
