@extends('reports.layout')
@php
    use App\Services\Reports\ReportService;
    $i = $report['indicators'];
    $pct = fn ($v) => $v === null ? __('reports.not_applicable') : $v.' %';
    $overdueTasks = $report['tasks']->filter->isOverdue();
@endphp
@section('content')
<table class="meta">
    <tr><td>{{ __('reports.period') }}</td><td>
        @if ($report['period']['from'] || $report['period']['to'])
            {{ $report['period']['from'] ? \Carbon\Carbon::parse($report['period']['from'])->format('d/m/Y') : '…' }}
            → {{ $report['period']['to'] ? \Carbon\Carbon::parse($report['period']['to'])->format('d/m/Y') : '…' }}
        @else {{ __('reports.all_periods') }} @endif
    </td></tr>
    <tr><td>{{ __('reports.scope') }}</td><td>{{ $report['scope'] ?? __('reports.whole_scope') }}</td></tr>
</table>

<h2>{{ __('reports.indicators') }}</h2>
<table class="kpis"><tr>
    <td><div class="v">{{ $i['total'] }}</div><div class="l">{{ __('reports.total') }}</div></td>
    <td><div class="v green">{{ $i['by_status']['validated'] }}</div><div class="l">{{ __('reports.validated') }}</div></td>
    <td><div class="v">{{ $i['by_status']['in_progress'] + $i['by_status']['on_hold'] + $i['by_status']['submitted'] + $i['by_status']['rejected'] }}</div><div class="l">{{ __('reports.in_progress') }}</div></td>
    <td><div class="v red">{{ $i['overdue'] }}</div><div class="l">{{ __('reports.overdue') }}</div></td>
</tr></table>
<table>
    <tr><th>{{ __('reports.indicator') }}</th><th style="width:110px">{{ __('reports.value') }}</th></tr>
    <tr><td>{{ __('reports.execution_rate') }}</td><td>{{ $pct($i['execution_rate']) }}</td></tr>
    <tr><td>{{ __('reports.validation_rate') }}</td><td>{{ $pct($i['validation_rate']) }}</td></tr>
    <tr><td>{{ __('reports.average_execution_hours') }}</td><td>{{ $i['average_execution_hours'] ?? __('reports.not_applicable') }}</td></tr>
    <tr><td>{{ __('reports.critical_open') }}</td><td>{{ $i['critical_open'] }}</td></tr>
</table>

<h2>{{ __('reports.by_unit') }}</h2>
<table>
    <tr><th>{{ __('reports.unit') }}</th><th>{{ __('reports.total') }}</th><th>{{ __('reports.validated') }}</th><th>{{ __('reports.in_progress') }}</th><th>{{ __('reports.overdue') }}</th><th>{{ __('reports.execution_rate') }}</th></tr>
    @foreach ($report['by_unit'] as $u)
        <tr><td>{{ $u['name'] }}</td><td>{{ $u['total'] }}</td><td>{{ $u['validated'] }}</td><td>{{ $u['in_progress'] }}</td><td class="{{ $u['overdue'] ? 'red' : '' }}">{{ $u['overdue'] }}</td><td>{{ $pct($u['execution_rate']) }}</td></tr>
    @endforeach
</table>

@if ($overdueTasks->isNotEmpty())
<h2>{{ __('reports.overdue_list') }} ({{ $overdueTasks->count() }})</h2>
<table>
    <tr>@foreach (array_slice(ReportService::taskHeader(), 0, 7) as $h)<th>{{ $h }}</th>@endforeach</tr>
    @foreach ($overdueTasks as $t)
        <tr>@foreach (array_slice(ReportService::taskRow($t, $timezone), 0, 7) as $k => $v)<td class="{{ $k === 6 ? 'red' : '' }}">{{ $v }}</td>@endforeach</tr>
    @endforeach
</table>
@endif

<h2>{{ __('reports.task_list') }} ({{ $report['tasks']->count() }})</h2>
@if ($report['truncated'])<p class="note">{{ __('reports.truncated', ['count' => ReportService::MAX_ROWS]) }}</p>@endif
<table>
    <tr>@foreach (ReportService::taskHeader() as $h)<th>{{ $h }}</th>@endforeach</tr>
    @foreach ($report['tasks'] as $t)
        <tr>@foreach (ReportService::taskRow($t, $timezone) as $v)<td>{{ $v }}</td>@endforeach</tr>
    @endforeach
</table>
@endsection
