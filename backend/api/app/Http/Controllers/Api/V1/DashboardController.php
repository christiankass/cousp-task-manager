<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\ApprovalWorkflow;
use App\Models\OrganizationUnit;
use App\Services\Tasks\TaskAccess;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs du §7 et du §4.8, toujours calculés sur les seules tâches visibles
 * par l'utilisateur : la vue est institutionnelle, de direction, de service ou
 * individuelle selon ses rôles.
 */
class DashboardController extends Controller
{
    public function __construct(private TaskAccess $access) {}

    public function overview(Request $request): JsonResponse
    {
        $base = fn () => $this->scoped($request);
        $now = now();

        $byStatus = $base()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $dueCount = $base()->where('due_at', '<=', $now)->count();
        $validatedOnTimeBase = $base()->where('due_at', '<=', $now)->whereNotNull('validated_at')->count();

        $taskIds = $base()->select('tasks.id');
        $decided = ApprovalWorkflow::query()->whereIn('task_id', $taskIds)->whereIn('status', ['approved', 'rejected']);
        $decidedCount = (clone $decided)->count();
        $approvedCount = (clone $decided)->where('status', 'approved')->count();

        $avgHours = $base()->whereNotNull('validated_at')
            ->selectRaw('avg(extract(epoch from (validated_at - coalesce(start_at, created_at))) / 3600) as h')
            ->value('h');

        return ApiResponse::success([
            'total' => array_sum($byStatus->all()),
            'by_status' => collect(['todo', 'in_progress', 'on_hold', 'submitted', 'validated', 'rejected'])
                ->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)]),
            'overdue' => $base()->overdue()->count(),
            'critical_open' => $base()->where('priority', 'critical')->whereNull('validated_at')->count(),
            // §4.8 : on ne pénalise pas les tâches pas encore échues.
            'execution_rate' => $dueCount ? round($validatedOnTimeBase * 100 / $dueCount, 1) : null,
            'validation_rate' => $decidedCount ? round($approvedCount * 100 / $decidedCount, 1) : null,
            'average_execution_hours' => $avgHours !== null ? round((float) $avgHours, 1) : null,
            'by_unit' => $this->byUnit($request),
            'monthly' => $this->monthly($request),
        ]);
    }

    public function overdue(Request $request): JsonResponse
    {
        $tasks = $this->scoped($request)->overdue()
            ->with(['organizationUnit', 'assignments.user'])
            ->orderBy('due_at')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return ApiResponse::success(TaskResource::collection($tasks));
    }

    private function scoped(Request $request)
    {
        return $this->access->visibleQuery($request->user())
            ->when($request->filled('organization_unit_id'), fn ($q) => $q->whereIn(
                'organization_unit_id',
                OrganizationUnit::query()->find($request->string('organization_unit_id'))?->descendantIds() ?? []
            ))
            ->when($request->filled('task_type'), fn ($q) => $q->where('task_type', $request->string('task_type')));
    }

    private function byUnit(Request $request): array
    {
        $rows = $this->scoped($request)
            ->join('organization_units', 'organization_units.id', '=', 'tasks.organization_unit_id')
            ->groupBy('organization_units.id', 'organization_units.name')
            ->selectRaw('organization_units.id, organization_units.name, count(*) as total')
            ->selectRaw("sum(case when tasks.status = 'validated' then 1 else 0 end) as validated")
            ->selectRaw('sum(case when tasks.due_at < now() and tasks.validated_at is null then 1 else 0 end) as overdue')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        return $rows->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'total' => (int) $r->total,
            'validated' => (int) $r->validated, 'overdue' => (int) $r->overdue,
        ])->all();
    }

    /** Six derniers mois : tâches créées et tâches validées. */
    private function monthly(Request $request): array
    {
        $from = now()->startOfMonth()->subMonths(5);
        $created = $this->scoped($request)->where('created_at', '>=', $from)
            ->selectRaw("to_char(created_at, 'YYYY-MM') as m, count(*) as c")->groupBy('m')->pluck('c', 'm');
        $validated = $this->scoped($request)->where('validated_at', '>=', $from)
            ->selectRaw("to_char(validated_at, 'YYYY-MM') as m, count(*) as c")->groupBy('m')->pluck('c', 'm');

        return collect(range(0, 5))->map(function ($i) use ($from, $created, $validated) {
            $m = $from->copy()->addMonths($i)->format('Y-m');

            return ['month' => $m, 'created' => (int) ($created[$m] ?? 0), 'validated' => (int) ($validated[$m] ?? 0)];
        })->all();
    }
}
