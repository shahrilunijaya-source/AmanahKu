{{--
    Single source of truth for a work-item card's markup. Rendered in three
    places that must stay identical: the personal board's column loop, the
    team board's view + comment only cards (with $compact = true), and every
    write response in WorkItemController via cardHtml().

    Reads title-first: the type is a 5px dot, priority only draws when High,
    and labels are tinted rather than filled. See docs/superpowers/specs/
    2026-07-29-taa-board-redesign-design.md ("Card face") for the rationale.

    @param \App\Models\WorkItem $c        Must have participants, projectRef,
                                            assignedBy, children loaded and comments_count set.
    @param bool $compact                   Smaller type, used by team-board.
    @param int|null $viewerId              Whose board this face sits on (CR-04): sets
                                            data-role and the Tagged / Reviewer label.
--}}
@php
    $wcTag = ['assignment' => ['Assignment', 'var(--red)'], 'task' => ['Task', 'var(--info)'], 'adhoc' => ['Adhoc', 'var(--amber)'], 'event' => ['Event', 'var(--success)']]; // QA F6 (CR-11): an Event card is not a Task
    [$wcTypeLabel, $wcTypeColor] = $wcTag[$c->type] ?? ['Task', 'var(--info)'];
    $wcLabelDef = \App\Models\WorkItem::LABELS;
    $wcCompact = $compact ?? false;
    $wcOverdue = $c->due_at && $c->status !== 'done' && $c->due_at->lt(today()) && $c->type !== 'event' && ! $c->cancelled_at;

    // How many days late (+) or early (-) against due_at: done cards compare
    // against done_at (stamped once on the done transition), open cards
    // compare against today — but only once actually overdue, matching $wcOverdue.
    $wcDueBadge = null;
    if ($c->due_at) {
        $wcRef = $c->status === 'done' ? $c->done_at : ($wcOverdue ? today() : null);
        if ($wcRef) {
            $wcDiffDays = (int) $c->due_at->copy()->startOfDay()->diffInDays($wcRef->copy()->startOfDay(), false);
            if ($wcDiffDays > 0) {
                // Not the bare "wc-when--over" class: BoardCardTest counts occurrences of
                // that exact string as "how many cards are overdue", and the date span
                // already carries it — a second match here would double-count every card.
                $wcDueBadge = ['text' => '+'.$wcDiffDays.' days', 'class' => 'wc-when-badge--over'];
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
    $wcChildren = $c->childSummary();

    // A subtask assigned to someone other than its parent's owner shows on THAT
    // person's board as a normal card (see BuildsWorkData::boardColumns()) — this
    // muted line is the only thing that marks it as belonging to a bigger card.
    $wcParentTitle = $c->parent_id ? $c->parent?->title : null;

    // CR-04: the role this card holds for the person whose lane it is drawn in.
    // Assigned is the default and carries no label; the other three are named.
    $wcRole = isset($viewerId) ? ($c->roleFor((int) $viewerId) ?? 'assigned') : 'assigned';
    $wcRoleLabel = \App\Models\WorkItem::ROLE_LABELS[$wcRole] ?? null;

    // The earliest still-open subtask past its due date, shown red next to the
    // "n/m" badge — reuses the wc-when--over COLOR only, never that class name
    // itself: BoardCardTest counts occurrences of that exact string to count how
    // many cards on the page are overdue, and this badge is not a card.
    $wcChildOverdue = $wcChildren ? ($c->relationLoaded('children') ? $c->children : $c->children()->get())
        ->where('status', '!=', 'done')
        ->filter(fn ($ch) => $ch->due_at && $ch->due_at->lt(today()) && $ch->type !== 'event' && ! $ch->cancelled_at)
        ->sortBy('due_at')
        ->first()?->due_at : null;

    // CR-19: the "Auto" chip's title carries the full reason off the trail comment the
    // close left behind ("Closed automatically – <reason>"); falls back to a bare label
    // if the comment somehow isn't there.
    // ponytail: one query per auto-closed card on the board (and one per event card for
    // isPendingAttendance below) — fine at board scale, revisit with eager-loading if a
    // board ever carries enough auto-closed/event cards to matter.
    $wcAutoReason = null;
    if ($c->auto_closed_at) {
        $wcAutoComment = $c->comments()->whereNull('employee_id')->where('body', 'like', 'Closed automatically%')->latest('id')->first();
        $wcAutoReason = $wcAutoComment->body ?? 'Closed automatically';
    }
    $wcPendingAttendance = $c->isPendingAttendance();
@endphp
<div class="wc @if ($wcCompact) wc--sm @endif @if ($wcChildren) wc--stack @endif"
     data-card
     data-id="{{ $c->id }}"
     data-status="{{ $c->status }}"
     data-type="{{ $c->type }}"
     data-priority="{{ $c->priority }}"
     data-labels="{{ implode(',', $c->labels ?? []) }}"
     data-project="{{ $c->project_id }}"
     data-due-at="{{ $c->due_at?->toDateString() }}"
     data-role="{{ $wcRole }}"
     @if ($owner ?? null) data-owner-id="{{ $owner['id'] }}" @endif
     @if ($c->assigned_by_id) data-assigned="1" @endif
     @if ($c->auto_closed_at) data-auto-closed="1" @endif
     @if ($wcPendingAttendance) data-pending-attendance="1" @endif
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
        @if ($wcRoleLabel)
            <span class="wc-role wc-role--{{ $wcRole }}">{{ $wcRoleLabel }}</span>
        @endif
        @if ($c->auto_closed_at)
            <span class="wc-auto" title="{{ $wcAutoReason }}">
                <svg viewBox="0 0 24 24" fill="currentColor" width="11" height="11"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
                Auto
            </span>
        @endif
    </div>

    @if ($wcParentTitle)
        <p class="wc-parent-of">Subtask of {{ $wcParentTitle }}</p>
    @endif
    <p class="wc-title">@if ($c->is_milestone)<span class="wc-milestone" title="Milestone" aria-hidden="true">🔔</span>@endif{{ $c->title }}</p>

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
            @if ($wcPendingAttendance)
                <span class="wc-when-badge wc-when--pending">Pending Attendance</span>
            @elseif ($wcDueBadge)
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
            @if ($wcChildren)
                <span class="wc-sub @if ($wcChildren['done'] === $wcChildren['total']) wc-sub--all @endif" title="Subtasks">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>{{ $wcChildren['done'] }}/{{ $wcChildren['total'] }}
                </span>
                @if ($wcChildOverdue)
                    <span class="wc-sub-overdue" title="Earliest overdue subtask">{{ $wcChildOverdue->format('d M') }}</span>
                @endif
            @endif
            @if (($c->comments_count ?? 0) > 0)
                <span class="wc-cmt"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>{{ $c->comments_count }}</span>
            @endif
        </span>
    </div>
</div>
