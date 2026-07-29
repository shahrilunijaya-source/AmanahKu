{{--
    One row of the team board table — one work item, owned by one person. The
    row markup has exactly this one home; team-board.blade.php only loops
    @include-ing this per entry in $teamRows.

    Columns: Person, Task, Type, Status, Priority, Project, Due, Labels — see
    .tb-cols in app.css for the shared grid. Every data-* attribute here is
    read by resources/js/team-board.js for client-side filter/sort; nothing
    here is server round-tripped.

    Not clickable yet: rows carry tabindex/role/data-card-id so Task 3 can
    wire them to the existing card drawer, but no click handler exists in
    this task.

    @param array $row  One entry from $teamRows: ['item' => WorkItem,
                        'owner_id', 'owner_name', 'owner_initials', 'owner_avatar_color'].
--}}
@php
    $tbItem = $row['item'];
    $tbStatusLabel = ['todo' => 'To Do', 'prog' => 'In Progress', 'review' => 'In Review', 'done' => 'Done'];
    $tbPriLabel = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
    $tbTypeTag = ['assignment' => ['Assignment', 'var(--red)'], 'task' => ['Task', 'var(--info)'], 'adhoc' => ['Adhoc', 'var(--amber)']];
    [$tbTypeLabel, $tbTypeColor] = $tbTypeTag[$tbItem->type] ?? ['Task', 'var(--info)'];
    $tbLabelDef = \App\Models\WorkItem::LABELS;
    // Same overdue rule as partials.work-card: due_at set, before today, not done.
    $tbOverdue = $tbItem->due_at && $tbItem->status !== 'done' && $tbItem->due_at->lt(today());
@endphp
<div class="tb-row tb-cols"
     tabindex="0" role="button"
     data-card-id="{{ $tbItem->id }}"
     data-owner-id="{{ $row['owner_id'] }}"
     data-owner-name="{{ mb_strtolower($row['owner_name']) }}"
     data-title="{{ mb_strtolower($tbItem->title) }}"
     data-type="{{ $tbItem->type }}"
     data-status="{{ $tbItem->status }}"
     data-priority="{{ $tbItem->priority }}"
     data-project="{{ $tbItem->project_id ?? '' }}"
     data-labels="{{ implode(',', $tbItem->labels ?? []) }}"
     data-due="{{ $tbItem->due_at?->toDateString() }}"
     data-overdue="{{ $tbOverdue ? '1' : '0' }}">

    <div class="tb-row-top">
        <span class="tb-cell tb-cell--task" title="{{ $tbItem->title }}">{{ $tbItem->title }}</span>
        <span class="tb-cell tb-cell--person" title="{{ $row['owner_name'] }}">
            <span class="tb-av tb-av--sm" style="background:{{ $row['owner_avatar_color'] ?? 'var(--muted)' }};">{{ $row['owner_initials'] }}</span>
            <span class="tb-person-name">{{ $row['owner_name'] }}</span>
        </span>
    </div>

    <div class="tb-row-mid">
        <span class="tb-cell tb-cell--type">
            <span class="wc-type"><span class="wc-dot" style="--wc-type:{{ $tbTypeColor }};"></span>{{ $tbTypeLabel }}</span>
        </span>
        <span class="tb-cell tb-cell--priority" data-priority="{{ $tbItem->priority }}">{{ $tbPriLabel[$tbItem->priority] ?? $tbItem->priority }}</span>
        <span class="tb-cell tb-cell--project">{{ $tbItem->projectRef->name ?? '—' }}</span>
    </div>

    <div class="tb-row-bottom">
        <span class="tb-cell tb-cell--status">
            <span class="tb-pill" data-status="{{ $tbItem->status }}">{{ $tbStatusLabel[$tbItem->status] ?? $tbItem->status }}</span>
        </span>
        <span class="tb-cell tb-cell--due @if ($tbOverdue) tb-due--over @endif">
            @if ($tbItem->due_at)
                {{ $tbItem->due_at->format('d M') }}
            @else
                <span class="tb-due--none" x-text="$store.ui.lang==='en' ? 'No due date' : 'Tiada tarikh akhir'">No due date</span>
            @endif
        </span>
        <span class="tb-cell tb-cell--labels">
            @foreach ($tbItem->labels ?? [] as $tbLabelKey)
                @if (isset($tbLabelDef[$tbLabelKey]))
                    <span class="wc-label" style="--wc-l:{{ $tbLabelDef[$tbLabelKey][1] }};">{{ $tbLabelDef[$tbLabelKey][0] }}</span>
                @endif
            @endforeach
        </span>
    </div>
</div>
