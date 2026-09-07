{{--
    One wish row: avatar, author, body, relative time, reaction chips.
    $w         BirthdayWish (with author, reactions eager loaded)
    $viewerId  int|null  signed-in employee id
--}}
@php
    $byEmoji = $w->reactions->groupBy('emoji');
@endphp
<div class="uj-db-wish-row">
    <span class="uj-db-wish-avatar" style="background:{{ $w->author->avatar_color ?? '#3a6ea5' }};">{{ $w->author->initials }}</span>
    <div class="uj-db-wish-body">
        <div class="uj-db-wish-meta">
            <span class="uj-db-wish-name">{{ $w->author->display_name }}</span>
            @if ($w->is_thanks)<span class="uj-db-wish-thanks" title="Thank-you">🙏</span>@endif
            <span class="uj-db-wish-at">{{ $w->created_at?->diffForHumans() }}</span>
        </div>
        <div class="uj-db-wish-text">{{ $w->body }}</div>
        @php
            // The viewer's own keys as a literal, not a nested x-data: react() swaps $root,
            // and a nested scope would make $root this span instead of the whole region.
            $mineKeys = json_encode($w->reactions->where('employee_id', $viewerId)->pluck('emoji')->values()->all(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        @endphp
        <div class="uj-db-wish-reactions">
            {{-- CR-30: the tenant's own set; the tally keeps a retired reaction readable. --}}
            @include('partials.reaction-picker', ['onPick' => 'react('.$w->id.", 'KEY')", 'mine' => $mineKeys])
            @include('partials.reaction-tally', ['counts' => $byEmoji->map->count()->all()])
        </div>
    </div>
</div>
