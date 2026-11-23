<?php

namespace App\Services\Reports;

use App\Models\ApprovalWorkflow;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs du §4.8, calculés sur un ensemble de tâches déjà limité au périmètre
 * de l'utilisateur. Partagés par le tableau de bord et les rapports exportés.
 */
class TaskIndicators
{
    public const STATUSES = ['todo', 'in_progress', 'on_hold', 'submitted', 'validated', 'rejected'];

    /** @param  Closure(): Builder  $base  fabrique une requête neuve à chaque appel */
    public function compute(Closure $base): array
    {
        $now = now();

        $byStatus = $base()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $dueCount = $base()->where('due_at', '<=', $now)->count();
        $validatedDue = $base()->where('due_at', '<=', $now)->whereNotNull('validated_at')->count();

        $decided = ApprovalWorkflow::query()->whereIn('task_id', $base()->select('tasks.id'))->whereIn('status', ['approved', 'rejected']);
        $decidedCount = (clone $decided)->count();
        $approvedCount = (clone $decided)->where('status', 'approved')->count();

        $avgHours = $base()->whereNotNull('validated_at')
            ->selectRaw('avg(extract(epoch from (validated_at - coalesce(start_at, created_at))) / 3600) as h')
            ->value('h');

        return [
            'total' => (int) array_sum($byStatus->all()),
            'by_status' => collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)])->all(),
            'overdue' => $base()->overdue()->count(),
            'critical_open' => $base()->where('priority', 'critical')->whereNull('validated_at')->count(),
            // §4.8 : on ne pénalise pas les tâches pas encore échues.
            'execution_rate' => $dueCount ? round($validatedDue * 100 / $dueCount, 1) : null,
            'validation_rate' => $decidedCount ? round($approvedCount * 100 / $decidedCount, 1) : null,
            'average_execution_hours' => $avgHours !== null ? round((float) $avgHours, 1) : null,
        ];
    }

    /** Comparaison entre structures (§4.8 « Tâches par service »). */
    public function byUnit(Closure $base, ?int $limit = 20): array
    {
        $rows = $base()
            ->join('organization_units', 'organization_units.id', '=', 'tasks.organization_unit_id')
            ->groupBy('organization_units.id', 'organization_units.name')
            ->selectRaw('organization_units.id, organization_units.name, count(*) as total')
            ->selectRaw("sum(case when tasks.status = 'validated' then 1 else 0 end) as validated")
            ->selectRaw("sum(case when tasks.status in ('in_progress', 'on_hold', 'submitted', 'rejected') then 1 else 0 end) as in_progress")
            ->selectRaw('sum(case when tasks.due_at < now() and tasks.validated_at is null then 1 else 0 end) as overdue')
            ->selectRaw('sum(case when tasks.due_at <= now() then 1 else 0 end) as due')
            ->selectRaw('sum(case when tasks.due_at <= now() and tasks.validated_at is not null then 1 else 0 end) as validated_due')
            ->orderByDesc('total')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'total' => (int) $r->total,
            'validated' => (int) $r->validated,
            'in_progress' => (int) $r->in_progress,
            'overdue' => (int) $r->overdue,
            'execution_rate' => (int) $r->due ? round($r->validated_due * 100 / $r->due, 1) : null,
        ])->all();
    }
}
