<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\OrganizationUnit;
use App\Services\Reports\TaskIndicators;
use App\Services\Tasks\TaskAccess;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Indicateurs du §7 et du §4.8, toujours calculés sur les seules tâches visibles
 * par l'utilisateur : la vue est institutionnelle, de direction, de service ou
 * individuelle selon ses rôles.
 */
class DashboardController extends Controller
{
    public function __construct(private TaskAccess $access, private TaskIndicators $indicators) {}

    public function overview(Request $request): JsonResponse
    {
        $base = fn () => $this->scoped($request);

        return ApiResponse::success($this->indicators->compute($base) + [
            'by_unit' => $this->indicators->byUnit($base),
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
