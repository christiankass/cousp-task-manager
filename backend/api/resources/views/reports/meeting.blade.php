@extends('reports.layout')
@php
    $m = $report['meeting'];
    $tz = $timezone;
    $fmt = fn ($d) => $d?->timezone($tz)->format('d/m/Y H:i') ?? '—';
@endphp
@section('content')
<table class="meta">
    <tr><td>{{ __('reports.meeting') }}</td><td><b>{{ $m->reference }}</b> — {{ $m->title }}</td></tr>
    <tr><td>{{ __('reports.date') }}</td><td>{{ $fmt($m->starts_at) }}{{ $m->location ? ' — '.$m->location : '' }}</td></tr>
    @if ($m->chair)<tr><td>{{ __('reports.chair') }}</td><td>{{ $m->chair->name }}{{ $m->secretary ? ' / '.$m->secretary->name : '' }}</td></tr>@endif
    @if ($m->healthEvent)<tr><td>{{ __('reports.event') }}</td><td>{{ $m->healthEvent->reference }} — {{ $m->healthEvent->title }}</td></tr>@endif
</table>

@php $s = $report['summary']; @endphp
<table class="kpis"><tr>
    <td><div class="v">{{ $s['total'] }}</div><div class="l">{{ __('reports.recommendations') }}</div></td>
    <td><div class="v green">{{ $s['done'] }}</div><div class="l">{{ __('coordination.recommendation_status.done') }}</div></td>
    <td><div class="v">{{ $s['in_progress'] + $s['not_converted'] }}</div><div class="l">{{ __('coordination.recommendation_status.in_progress') }}</div></td>
    <td><div class="v red">{{ $s['overdue'] }}</div><div class="l">{{ __('coordination.recommendation_status.overdue') }}</div></td>
    <td><div class="v">{{ $s['execution_rate'] === null ? '—' : $s['execution_rate'].' %' }}</div><div class="l">{{ __('reports.execution') }}</div></td>
</tr></table>

<h2>{{ __('reports.follow_up') }}</h2>
@include('reports.partials.recommendations', ['recommendations' => $m->recommendations, 'fmt' => $fmt])

@if ($m->minutes)
<h2>{{ __('reports.minutes') }}</h2>
<p class="pre">{{ $m->minutes }}</p>
@endif

@if ($m->participants->isNotEmpty())
<h2>{{ __('reports.participants') }} ({{ $m->participants->count() }})</h2>
<p>{{ $m->participants->map(fn ($p) => ($p->user?->name ?? $p->external_name).($p->organization ? ' ('.$p->organization.')' : '').' — '.__('reports.attendance.'.$p->attendance))->implode(' · ') }}</p>
@endif

@if ($report['previous'])
<h2>{{ __('reports.previous_meeting') }} — {{ $report['previous']->reference }} ({{ $fmt($report['previous']->starts_at) }}) : {{ $report['previous_summary']['execution_rate'] ?? 0 }} %</h2>
@include('reports.partials.recommendations', ['recommendations' => $report['previous']->recommendations, 'fmt' => $fmt])
@endif
@endsection
