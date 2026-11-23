@extends('reports.layout')
@php
    $e = $report['event'];
    $tz = $timezone;
    $fmt = fn ($d) => $d?->timezone($tz)->format('d/m/Y H:i') ?? '—';
    $s = $report['stats'];
@endphp
@section('content')
<table class="meta">
    <tr><td>{{ __('reports.event') }}</td><td><b>{{ $e->reference }}</b> — {{ $e->title }} ({{ __('coordination.event_type.'.$e->event_type->value) }})</td></tr>
    <tr><td>{{ __('reports.status') }}</td><td>{{ __('coordination.event_status.'.$e->status) }} — {{ __('reports.severity') }} : {{ __('coordination.severity.'.$e->severity->value) }}</td></tr>
    @if ($e->location)<tr><td>{{ __('reports.location') }}</td><td>{{ $e->location }}</td></tr>@endif
    <tr><td>{{ __('reports.opened') }}</td><td>{{ $fmt($e->start_at) }}@if ($e->end_at) — {{ __('reports.closed') }} {{ $fmt($e->end_at) }}@endif</td></tr>
    @if ($e->coordinator)<tr><td>{{ __('reports.coordinator') }}</td><td>{{ $e->coordinator->name }}</td></tr>@endif
</table>
@if ($e->description)<p class="pre">{{ $e->description }}</p>@endif

<table class="kpis"><tr>
    <td><div class="v">{{ $s['total'] }}</div><div class="l">{{ __('reports.response_actions') }}</div></td>
    <td><div class="v green">{{ $s['validated'] }}</div><div class="l">{{ __('reports.validated') }}</div></td>
    <td><div class="v red">{{ $s['overdue'] }}</div><div class="l">{{ __('reports.overdue') }}</div></td>
    <td><div class="v">{{ $s['completion_rate'] === null ? '—' : $s['completion_rate'].' %' }}</div><div class="l">{{ __('reports.execution') }}</div></td>
</tr></table>

@if ($e->closure_note)
<h2>{{ __('reports.closure_note') }}</h2>
<p class="pre">{{ $e->closure_note }}</p>
@endif

@php $latest = $e->situationReports->first(); @endphp
@if ($latest)
<h2>{{ __('reports.sitreps') }} — {{ $latest->report_date->format('d/m/Y') }}</h2>
<p class="pre">{{ $latest->summary }}</p>
@if ($latest->actions)<p class="pre"><b>{{ __('reports.actions_taken') }} :</b> {{ $latest->actions }}</p>@endif
@if ($latest->needs)<p class="pre"><b>{{ __('reports.needs') }} :</b> {{ $latest->needs }}</p>@endif
@endif

<h2>{{ __('reports.teams') }}</h2>
<table>
    <tr><th>{{ __('reports.unit') }}</th><th>{{ __('reports.total') }}</th><th>{{ __('reports.validated') }}</th><th>{{ __('reports.overdue') }}</th></tr>
    @foreach ($report['teams'] as $t)
        <tr><td>{{ $t['name'] }}</td><td>{{ $t['total'] }}</td><td>{{ $t['validated'] }}</td><td class="{{ $t['overdue'] ? 'red' : '' }}">{{ $t['overdue'] }}</td></tr>
    @endforeach
</table>

<h2>{{ __('reports.response_actions') }} ({{ $report['tasks']->count() }})</h2>
<table>
    <tr>@foreach (\App\Services\Reports\ReportService::taskHeader() as $h)<th>{{ $h }}</th>@endforeach</tr>
    @foreach ($report['tasks'] as $t)
        <tr>@foreach (\App\Services\Reports\ReportService::taskRow($t, $tz) as $v)<td>{{ $v }}</td>@endforeach</tr>
    @endforeach
</table>

@if ($e->missions->isNotEmpty())
<h2>{{ __('reports.missions') }}</h2>
<table>
    <tr><th>{{ __('reports.reference') }}</th><th>{{ __('reports.title') }}</th><th>{{ __('reports.destination') }}</th><th>{{ __('reports.leader') }}</th><th>{{ __('reports.date') }}</th><th>{{ __('reports.status') }}</th></tr>
    @foreach ($e->missions as $m)
        <tr><td>{{ $m->reference }}</td><td>{{ $m->title }}</td><td>{{ $m->destination }}</td><td>{{ $m->leader?->name }}</td><td>{{ $fmt($m->departure_at) }}</td><td>{{ __('coordination.mission_status.'.$m->status()) }}</td></tr>
    @endforeach
</table>
@endif
@endsection
