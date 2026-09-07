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
        <div class="uj-db-wish-reactions">
            @foreach (\App\Models\TotSession::EMOJI as $emoji)
                @php $group = $byEmoji->get($emoji); @endphp
                <button type="button" class="uj-db-wish-chip" data-mine="{{ $group && $group->contains('employee_id', $viewerId) ? '1' : '' }}" data-count="{{ $group ? '1' : '' }}"
                        @click="react({{ $w->id }}, @js($emoji))">{{ $emoji }}@if ($group) {{ $group->count() }}@endif</button>
            @endforeach
        </div>
    </div>
</div>
