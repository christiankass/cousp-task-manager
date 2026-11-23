<table>
    <tr>
        <th style="width:26px">N°</th><th>{{ __('reports.recommendation') }}</th><th>{{ __('reports.responsible') }}</th>
        <th style="width:70px">{{ __('reports.due_at') }}</th><th>{{ __('reports.tasks') }}</th><th style="width:70px">{{ __('reports.execution') }}</th>
    </tr>
    @foreach ($recommendations as $r)
        @php $status = $r->executionStatus(); $first = $r->tasks->first(); @endphp
        <tr>
            <td>R{{ str_pad($r->number, 2, '0', STR_PAD_LEFT) }}</td>
            <td>{{ $r->content }}</td>
            <td>{{ $first?->assignments->firstWhere('assignment_role', 'responsible')?->user?->name ?? $r->responsible?->name ?? '—' }}</td>
            <td>{{ $fmt($first?->due_at ?? $r->due_at) }}</td>
            <td>{{ $r->tasks->map(fn ($t) => $t->reference.' ('.__('tasks.status.'.$t->status->value).')')->implode(', ') ?: '—' }}</td>
            <td class="{{ $status === 'overdue' ? 'red' : ($status === 'done' ? 'green' : '') }}">{{ __('coordination.recommendation_status.'.$status) }}</td>
        </tr>
    @endforeach
</table>
