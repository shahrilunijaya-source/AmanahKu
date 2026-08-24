{{--
    Single source of truth for a work-item card's markup. Rendered in three
    places that must stay identical: the personal board's column loop, the
    team board's view + comment only cards (with $compact = true), and every
    write response in WorkItemController via cardHtml().

    Reads title-first: the type is a 5px dot, priority only draws when High,
    and labels are tinted rather than filled. See docs/superpowers/specs/
    2026-07-29-taa-board-redesign-design.md ("Card face") for the rationale.

    @param \App\Models\WorkItem $c        Must have participants, projectRef,
                                            assignedBy loaded and comments_count set.
    @param bool $compact                   Smaller type, used by team-board.
--}}
@php
    $wcTag = ['assignment' => ['Assignment', 'var(--red)'], 'task' => ['Task', 'var(--info)'], 'adhoc' => ['Adhoc', 'var(--amber)']];
    [$wcTypeLabel, $wcTypeColor] = $wcTag[$c->type] ?? ['Task', 'var(--info)'];
    $wcLabelDef = \App\Models\WorkItem::LABELS;
    $wcCompact = $compact ?? false;
    $wcOverdue = $c->due_at && $c->status !== 'done' && $c->due_at->lt(today());

    // How many days late (+) or early (-) against due_at: done cards compare
    // against done_at (stamped once on the done transition), open cards
    // compare against today — but only once actually overdue, matching $wcOverdue.
    $wcDueBadge = null;
    if ($c->due_at) {
        $wcRef = $c->status === 'done' ? $c->done_at : ($wcOverdue ? today() : null);
        if ($wcRef) {
            $wcDiffDays = (int) $c->due_at->copy()->startOfDay()->diffInDays($wcRef->copy()->startOfDay(), false);
            if ($wcDiffDays > 0) {
                $wcDueBadge = ['text' => '+'.$wcDiffDays.' days', 'class' => 'wc-when--over'];
            } elseif ($wcDiffDays < 0) {
                $wcDueBadge = ['text' => $wcDiffDays.' days', 'class' => 'wc-when--early'];
            }
        }
    }

    // The footer avatar stack: the assigner (if this is a tac) first, then the
    // shared card's participants. Capped at 3 with a "+N" for the rest — the
    // assigner is not exempt from the cap, it is just the first in line.
    $wcAvatars = collect();
    if ($c->assigned_by_id && $c->assignedBy) {
        $wcAvatars->push([
            'initials' => $c->assignedBy->initials,
            'color' => $c->assignedBy->avatar_color ?? 'var(--muted)',
            'title' => 'Assigned by '.$c->assignedBy->display_name,
        ]);
    }
    foreach ($c->participants as $wcPerson) {
        $wcAvatars->push([
            'initials' => $wcPerson->initials,
            'color' => $wcPerson->avatar_color ?? 'var(--muted)',
            'title' => $wcPerson->display_name,
        ]);
    }
    $wcAvatarsShown = $wcAvatars->take(3);
    $wcAvatarOverflow = max(0, $wcAvatars->count() - 3);
@endphp
<div class="wc @if ($wcCompact) wc--sm @endif"
     data-card
     data-id="{{ $c->id }}"
     data-status="{{ $c->status }}"
     data-type="{{ $c->type }}"
     data-priority="{{ $c->priority }}"
     data-labels="{{ implode(',', $c->labels ?? []) }}"
     data-project="{{ $c->project_id }}"
     @if ($owner ?? null) data-owner-id="{{ $owner['id'] }}" @endif
     @if ($c->assigned_by_id) data-assigned="1" @endif
     {{-- Keyboard path to the drawer — both the personal board and the team board's
          compact cards open a (view + comment only, on team-board) drawer on click
          or Enter/Space. See work-board.js / team-board.js's click delegation. --}}
     tabindex="0" role="button" aria-haspopup="dialog"
>
    <div class="wc-top">
        <span class="wc-type"><span class="wc-dot" style="--wc-type:{{ $wcTypeColor }};"></span>{{ $wcTypeLabel }}</span>
        @if ($c->priority === 'high')
            <span class="wc-pri">High</span>
        @endif
    </div>

    <p class="wc-title">{{ $c->title }}</p>

    @if (! empty($c->labels))
        <div class="wc-labels">
            @foreach ($c->labels as $wcLabelKey)
                @if (isset($wcLabelDef[$wcLabelKey]))
                    <span class="wc-label" style="--wc-l:{{ $wcLabelDef[$wcLabelKey][1] }};">{{ $wcLabelDef[$wcLabelKey][0] }}</span>
                @endif
            @endforeach
        </div>
    @endif

    <div class="wc-foot">
        @if ($c->due_at)
            <span class="wc-when @if ($wcOverdue) wc-when--over @endif">{{ $c->due_at->format('d M') }}</span>
            @if ($wcDueBadge)
                <span class="wc-when-badge {{ $wcDueBadge['class'] }}">{{ $wcDueBadge['text'] }}</span>
            @endif
        @else
            <span class="wc-when wc-when--none">No due date</span>
        @endif
        @if ($c->projectRef)
            <span class="wc-sep">·</span>
            <span class="wc-proj">{{ $c->projectRef->name }}</span>
        @endif
        <span class="wc-right">
            @if ($wcAvatarsShown->isNotEmpty())
                <span class="wa-stack">
                    @foreach ($wcAvatarsShown as $wcAvatar)
                        <span class="wa" style="background:{{ $wcAvatar['color'] }};" title="{{ $wcAvatar['title'] }}">{{ $wcAvatar['initials'] }}</span>
                    @endforeach
                    @if ($wcAvatarOverflow > 0)
                        <span class="wa wa--more">+{{ $wcAvatarOverflow }}</span>
                    @endif
                </span>
            @endif
            @if (($c->comments_count ?? 0) > 0)
                <span class="wc-cmt"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>{{ $c->comments_count }}</span>
            @endif
        </span>
    </div>
</div>
