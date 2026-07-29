{{--
    One task line inside the team board's floating window
    (resources/views/screens/team-board.blade.php). The whole-company task
    table this used to belong to is gone — every $teamRows entry now renders
    once, INSIDE the single shared floating window (teleported to <body>),
    and resources/js/team-board.js's applyWinFilter() shows only the lines
    belonging to whichever person's window is currently open. No fetch: all
    tasks are already on the page from the first response.

    Read-only and unclickable — the window IS the detail, there is no further
    per-task panel to open from here. No Person cell: the window's own header
    already names the owner, so repeating it per line would be noise.

    Reuses the card face's vocabulary (.wc-type/.wc-dot, .wc-when/.wc-sep/
    .wc-proj, .wc-labels/.wc-label — see partials/work-card.blade.php) rather
    than inventing a second one; only the status pill (.tb-pill) and the
    line's own shell (.tb-tline*) are team-board-specific.

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
<div class="tb-tline"
     data-card-id="{{ $tbItem->id }}"
     data-owner-id="{{ $row['owner_id'] }}"
     data-type="{{ $tbItem->type }}"
     data-status="{{ $tbItem->status }}"
     data-priority="{{ $tbItem->priority }}"
     data-project="{{ $tbItem->project_id ?? '' }}"
     data-labels="{{ implode(',', $tbItem->labels ?? []) }}"
     data-due="{{ $tbItem->due_at?->toDateString() }}"
     data-overdue="{{ $tbOverdue ? '1' : '0' }}">

    <div class="tb-tline-top">
        <span class="wc-type"><span class="wc-dot" style="--wc-type:{{ $tbTypeColor }};"></span>{{ $tbTypeLabel }}</span>
        <span class="tb-pill" data-status="{{ $tbItem->status }}">{{ $tbStatusLabel[$tbItem->status] ?? $tbItem->status }}</span>
    </div>

    <p class="tb-tline-title">{{ $tbItem->title }}</p>

    <div class="tb-tline-meta">
        <span class="tb-tline-pri" data-priority="{{ $tbItem->priority }}">{{ $tbPriLabel[$tbItem->priority] ?? $tbItem->priority }}</span>
        <span class="wc-sep">·</span>
        <span class="wc-when @if ($tbOverdue) wc-when--over @endif">
            @if ($tbItem->due_at)
                {{ $tbItem->due_at->format('d M') }}
            @else
                <span class="wc-when--none" x-text="$store.ui.lang==='en' ? 'No due date' : 'Tiada tarikh akhir'">No due date</span>
            @endif
        </span>
        <span class="wc-sep">·</span>
        <span class="wc-proj">{{ $tbItem->projectRef->name ?? '—' }}</span>
    </div>

    @if (! empty($tbItem->labels))
        <div class="wc-labels">
            @foreach ($tbItem->labels as $tbLabelKey)
                @if (isset($tbLabelDef[$tbLabelKey]))
                    <span class="wc-label" style="--wc-l:{{ $tbLabelDef[$tbLabelKey][1] }};">{{ $tbLabelDef[$tbLabelKey][0] }}</span>
                @endif
            @endforeach
        </div>
    @endif
</div>
