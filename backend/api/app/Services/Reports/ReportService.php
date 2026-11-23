<?php

namespace App\Services\Reports;

use App\Enums\TaskStatus;
use App\Http\Resources\CoordinationPresenter as P;
use App\Models\HealthEvent;
use App\Models\Meeting;
use App\Models\OrganizationUnit;
use App\Models\Task;
use App\Models\User;
use App\Services\Coordination\MeetingService;
use App\Services\Tasks\TaskAccess;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/** Données des rapports exportables (§4.8, §25), toujours limitées au périmètre de l'utilisateur. */
class ReportService
{
    public const MAX_ROWS = 2000;

    public function __construct(
        private TaskAccess $access,
        private TaskIndicators $indicators,
        private MeetingService $meetings,
        private TenantContext $context,
    ) {}

    /** @param  array{from?: string, to?: string, organization_unit_id?: string, task_type?: string, health_event_id?: string}  $filters */
    public function tasks(User $user, array $filters): array
    {
        $unit = ! empty($filters['organization_unit_id']) ? OrganizationUnit::query()->findOrFail($filters['organization_unit_id']) : null;
        $tz = $this->context->institution()->timezone ?: config('app.timezone');
        $from = ! empty($filters['from']) ? CarbonImmutable::parse($filters['from'], $tz)->startOfDay() : null;
        $to = ! empty($filters['to']) ? CarbonImmutable::parse($filters['to'], $tz)->endOfDay() : null;

        $base = fn () => $this->access->visibleQuery($user)
            ->when($unit, fn ($q) => $q->whereIn('organization_unit_id', $unit->descendantIds()))
            ->when(! empty($filters['task_type']), fn ($q) => $q->where('task_type', $filters['task_type']))
            ->when(! empty($filters['health_event_id']), fn ($q) => $q->where('health_event_id', $filters['health_event_id']))
            ->when($from, fn ($q) => $q->where('due_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('due_at', '<=', $to));

        $total = $base()->count();
        $tasks = $base()->with(['organizationUnit', 'assignments.user'])
            ->orderByRaw('validated_at IS NOT NULL')->orderBy('due_at')
            ->limit(self::MAX_ROWS)->get();

        return [
            'institution' => $this->context->institution()->name,
            'generated_at' => now()->timezone($tz),
            'generated_by' => $user->name,
            'timezone' => $tz,
            'period' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'scope' => $unit?->name,
            'indicators' => $this->indicators->compute($base),
            'by_unit' => $this->indicators->byUnit($base, null),
            'tasks' => $tasks,
            'truncated' => $total > self::MAX_ROWS,
        ];
    }

    public function meeting(Meeting $meeting): array
    {
        $meeting->load([
            'chair', 'secretary', 'participants.user', 'healthEvent',
            'recommendations.responsible', 'recommendations.organizationUnit',
            'recommendations.tasks.assignments.user', 'recommendations.tasks.organizationUnit',
        ]);
        $previous = Meeting::query()->where('meeting_type', $meeting->meeting_type)
            ->where('starts_at', '<', $meeting->starts_at)->where('status', '!=', 'cancelled')
            ->with(['recommendations.responsible', 'recommendations.tasks.assignments.user'])
            ->orderByDesc('starts_at')->first();

        return [
            'institution' => $this->context->institution()->name,
            'timezone' => $this->context->institution()->timezone,
            'meeting' => $meeting,
            'summary' => $this->meetings->summary($meeting->recommendations),
            'previous' => $previous,
            'previous_summary' => $previous ? $this->meetings->summary($previous->recommendations) : null,
        ];
    }

    public function healthEvent(HealthEvent $event): array
    {
        $event->load(['coordinator', 'situationReports.author', 'missions.leader', 'missions.task']);
        $tasks = Task::query()->where('health_event_id', $event->id)
            ->with(['organizationUnit', 'assignments.user'])->orderBy('due_at')->get();
        $total = $tasks->count();
        $validated = $tasks->where('status', TaskStatus::Validated)->count();

        return [
            'institution' => $this->context->institution()->name,
            'timezone' => $this->context->institution()->timezone,
            'event' => $event,
            'tasks' => $tasks,
            'stats' => [
                'total' => $total,
                'validated' => $validated,
                'overdue' => $tasks->filter->isOverdue()->count(),
                'completion_rate' => $total ? round($validated * 100 / $total, 1) : null,
            ],
            'teams' => $tasks->groupBy('organization_unit_id')->map(fn ($g) => [
                'name' => $g->first()->organizationUnit?->name,
                'total' => $g->count(),
                'validated' => $g->where('status', TaskStatus::Validated)->count(),
                'overdue' => $g->filter->isOverdue()->count(),
            ])->values(),
        ];
    }

    /** Une ligne de tâche lisible, pour le tableur et le PDF. */
    public static function taskRow(Task $task, string $tz): array
    {
        $fmt = fn ($d) => $d?->timezone($tz)->format('d/m/Y H:i');
        $responsible = $task->assignments->firstWhere('assignment_role', 'responsible')?->user?->name;

        return [
            $task->reference,
            $task->title,
            $task->organizationUnit?->name,
            $responsible,
            __('tasks.priority.'.$task->priority->value),
            __('tasks.status.'.$task->status->value),
            $fmt($task->due_at),
            $fmt($task->validated_at),
            $task->isOverdue() ? __('reports.yes') : __('reports.no'),
        ];
    }

    public static function taskHeader(): array
    {
        return array_map(fn ($k) => __('reports.'.$k), ['reference', 'title', 'unit', 'responsible', 'priority', 'status', 'due_at', 'validated_at', 'late']);
    }

    /** Présentation JSON (aperçu à l'écran avant export). */
    public static function json(array $report): array
    {
        return [
            'institution' => $report['institution'],
            'generated_at' => $report['generated_at']->toIso8601String(),
            'period' => $report['period'],
            'scope' => $report['scope'],
            'indicators' => $report['indicators'],
            'by_unit' => $report['by_unit'],
            'truncated' => $report['truncated'],
            'tasks' => $report['tasks']->map(fn (Task $t) => P::taskBrief($t))->values(),
        ];
    }
}
