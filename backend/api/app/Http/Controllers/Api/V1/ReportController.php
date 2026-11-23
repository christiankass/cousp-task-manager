<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\HealthEvent;
use App\Models\Meeting;
use App\Services\AuditLogger;
use App\Services\Coordination\MeetingService;
use App\Services\Reports\ReportService;
use App\Services\Reports\XlsxBuilder;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapports (§4.8, API §25) : aperçu JSON, ou fichier Excel / PDF avec format=xlsx|pdf.
 * Consulter exige reports.view ; télécharger exige reports.export. Chaque export est journalisé.
 */
class ReportController extends Controller
{
    public function __construct(
        private ReportService $reports,
        private XlsxBuilder $xlsx,
        private MeetingService $meetings,
        private AuditLogger $audit,
        private TenantContext $context,
    ) {}

    public function tasks(Request $request): Response
    {
        $data = $request->validate([
            'format' => ['nullable', Rule::in(['json', 'xlsx', 'pdf'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'organization_unit_id' => ['nullable', 'uuid'],
            'task_type' => ['nullable', Rule::enum(TaskType::class)],
            'health_event_id' => ['nullable', 'uuid'],
        ]);
        $format = $data['format'] ?? 'json';
        $this->authorizeReport($request, $format);

        $report = $this->reports->tasks($request->user(), $data);
        if ($format === 'json') {
            return ApiResponse::success(ReportService::json($report));
        }

        $this->audit->log('report.exported', null, [], ['report' => 'tasks', 'format' => $format, 'filters' => collect($data)->except('format')->all()]);
        $name = 'rapport-taches-'.now()->format('Ymd-Hi');

        if ($format === 'pdf') {
            return $this->pdf('reports.tasks', $report, __('reports.tasks_title'), $name, $request, landscape: true);
        }

        $i = $report['indicators'];
        $pct = fn ($v) => $v === null ? '' : $v;

        return $this->xlsx->download([
            __('reports.sheet_indicators') => [
                [__('reports.indicator'), __('reports.value')],
                [__('reports.total'), $i['total']],
                [__('reports.validated'), $i['by_status']['validated']],
                [__('reports.in_progress'), $i['by_status']['in_progress'] + $i['by_status']['on_hold'] + $i['by_status']['submitted'] + $i['by_status']['rejected']],
                [__('reports.overdue'), $i['overdue']],
                [__('reports.critical_open'), $i['critical_open']],
                [__('reports.execution_rate').' (%)', $pct($i['execution_rate'])],
                [__('reports.validation_rate').' (%)', $pct($i['validation_rate'])],
                [__('reports.average_execution_hours'), $pct($i['average_execution_hours'])],
                [__('reports.period'), trim(($report['period']['from'] ?? '…').' → '.($report['period']['to'] ?? '…'))],
                [__('reports.scope'), $report['scope'] ?? __('reports.whole_scope')],
            ],
            __('reports.sheet_units') => [
                [__('reports.unit'), __('reports.total'), __('reports.validated'), __('reports.in_progress'), __('reports.overdue'), __('reports.execution_rate').' (%)'],
                ...array_map(fn ($u) => [$u['name'], $u['total'], $u['validated'], $u['in_progress'], $u['overdue'], $pct($u['execution_rate'])], $report['by_unit']),
            ],
            __('reports.sheet_tasks') => [
                ReportService::taskHeader(),
                ...$report['tasks']->map(fn ($t) => ReportService::taskRow($t, $report['timezone']))->all(),
            ],
        ], $name.'.xlsx');
    }

    public function meeting(Request $request, string $id): Response
    {
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'pdf';
        $meeting = Meeting::query()->findOrFail($id);
        abort_unless($request->user()->hasPermission('reports.export') || $this->meetings->canManage($request->user(), $meeting), 403, __('messages.forbidden'));

        $report = $this->reports->meeting($meeting);
        $this->audit->log('report.exported', $meeting, [], ['report' => 'meeting', 'format' => $format]);
        $name = 'suivi-'.strtolower($meeting->reference);

        if ($format === 'pdf') {
            return $this->pdf('reports.meeting', $report, __('reports.meeting_title'), $name, $request, landscape: true);
        }

        $fmt = fn ($d) => $d?->timezone($report['timezone'])->format('d/m/Y H:i');

        return $this->xlsx->download([
            __('reports.sheet_recommendations') => [
                [__('reports.reference'), __('reports.recommendation'), __('reports.unit'), __('reports.responsible'), __('reports.due_at'), __('reports.tasks'), __('reports.execution')],
                ...$meeting->recommendations->map(fn ($r) => [
                    $r->reference,
                    $r->content,
                    $r->tasks->first()?->organizationUnit?->name ?? $r->organizationUnit?->name,
                    $r->tasks->first()?->assignments->firstWhere('assignment_role', 'responsible')?->user?->name ?? $r->responsible?->name,
                    $fmt($r->tasks->first()?->due_at ?? $r->due_at),
                    $r->tasks->map(fn ($t) => $t->reference.' ('.__('tasks.status.'.$t->status->value).')')->implode(', '),
                    __('coordination.recommendation_status.'.$r->executionStatus()),
                ])->all(),
            ],
        ], $name.'.xlsx');
    }

    public function healthEvent(Request $request, string $id): Response
    {
        $event = HealthEvent::query()->findOrFail($id);
        $user = $request->user();
        abort_unless($user->hasPermission('reports.export') || $user->hasPermission('health_events.manage') || $event->coordinator_id === $user->id, 403, __('messages.forbidden'));

        $report = $this->reports->healthEvent($event);
        $this->audit->log('report.exported', $event, [], ['report' => 'health_event', 'format' => 'pdf']);

        return $this->pdf('reports.health-event', $report, __('reports.event_title'), 'rapport-'.strtolower($event->reference), $request);
    }

    private function authorizeReport(Request $request, string $format): void
    {
        $permission = $format === 'json' ? 'reports.view' : 'reports.export';
        abort_unless($request->user()->hasPermission($permission), 403, __('messages.forbidden'));
    }

    private function pdf(string $view, array $report, string $title, string $name, Request $request, bool $landscape = false): Response
    {
        return Pdf::loadView($view, [
            'report' => $report,
            'title' => $title,
            'institution' => $report['institution'],
            'timezone' => $report['timezone'] ?: config('app.timezone'),
            'generatedBy' => $request->user()->name,
        ])->setPaper('a4', $landscape ? 'landscape' : 'portrait')->download($name.'.pdf');
    }
}
