@extends('layouts.app')

@php
    // ponytail: a plain server-rendered page, not the fetch-a-partial pattern the rest of
    // the app follows for in-screen actions — this page has no live board/list to keep in
    // sync with, every action here is "add a row and reload".
    $responseLabel = [
        'going' => 'Going',
        'registered' => 'Registered',
        'attended' => 'Attended',
        // CR-19: the post-event mark-off's other outcome.
        'did_not_attend' => 'Did not attend',
        'maybe' => 'Maybe',
        'declined' => 'Declined',
    ];
    $responseTone = [
        'going' => 'is-going', 'registered' => 'is-going', 'attended' => 'is-good',
        'did_not_attend' => 'is-bad', 'maybe' => 'is-muted', 'declined' => 'is-bad',
    ];
    $starts = $event->starts_at ? \Carbon\Carbon::parse($event->starts_at) : null;
    $ends = $event->ends_at ? \Carbon\Carbon::parse($event->ends_at) : null;
    $attendeeCount = $attendees->count();
@endphp

@section('screen')
<div class="uj-ev" x-data="{ tab: 'photos' }">
    @if (session('ok'))
        <div class="uj-card" style="padding:12px 18px;font-size:13px;color:var(--ink);border-left:3px solid var(--green,#1c7c54);">{{ session('ok') }}</div>
    @endif
    @if ($errors->any())
        <div class="uj-card" style="padding:12px 18px;font-size:13px;color:var(--ink);border-left:3px solid var(--red,#b42318);">{{ $errors->first() }}</div>
    @endif

    <header class="uj-ev-head uj-card">
        <div class="uj-ev-head-main">
            <span class="uj-pill {{ $isOver ? 'uj-ev-pill-over' : 'uj-ev-pill-upcoming' }}">{{ $isOver ? 'Wrapped up' : 'Upcoming' }}</span>
            <dl class="uj-ev-meta">
                @if ($starts)
                    <div><dt>When</dt><dd>{{ $starts->format('D j M Y, g:ia') }}@if ($ends) &ndash; {{ $ends->isSameDay($starts) ? $ends->format('g:ia') : $ends->format('D j M Y, g:ia') }}@endif</dd></div>
                @endif
                @if ($event->location)
                    <div><dt>Where</dt><dd>{{ $event->location }}</dd></div>
                @endif
                <div><dt>Who</dt><dd>{{ $attendeeCount }} {{ \Illuminate\Support\Str::plural('attendee', $attendeeCount) }}</dd></div>
            </dl>
            @if ($event->description)
                <p class="uj-ev-desc">{{ $event->description }}</p>
            @endif
        </div>
    </header>

    <div class="uj-ev-grid {{ $canManageAttendees ? '' : 'uj-ev-grid--single' }}">
        <section class="uj-card uj-ev-panel">
            <div class="uj-card-head"><h2 class="uj-card-title">Who's going</h2><span class="uj-ev-count">{{ $attendeeCount }}</span></div>
            <ul class="uj-ev-people">
                @forelse ($attendees as $a)
                    <li class="uj-ev-person">
                        <span class="uj-ev-avatar">{{ \Illuminate\Support\Str::of($a['employee']?->display_name ?? '?')->substr(0, 1)->upper() }}</span>
                        <span class="uj-ev-person-name">{{ $a['employee']?->display_name }}</span>
                        @if ($canManageAttendees && $a['employee'])
                            {{-- QA F5: organiser marks Registered / Attended per person (scope 1), same events.rsvp route with employee_id. --}}
                            <form method="post" action="{{ route('events.rsvp', $event) }}" class="uj-ev-status-form" data-js="event-attendee-status">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $a['employee']->id }}">
                                <select name="response" class="uj-ev-select" onchange="this.form.requestSubmit()">
                                    @foreach ($responseLabel as $value => $label)
                                        <option value="{{ $value }}" @selected($a['response'] === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="uj-ev-sr">Update</button>
                            </form>
                        @else
                            <span class="uj-pill uj-ev-status {{ $responseTone[$a['response']] ?? 'is-muted' }}">{{ $a['responseLabel'] }}</span>
                        @endif
                    </li>
                @empty
                    <li class="uj-ev-empty">No attendees yet.</li>
                @endforelse
            </ul>
        </section>

        @if ($canManageAttendees)
            <section class="uj-card uj-ev-panel">
                <div class="uj-card-head"><h2 class="uj-card-title">Attendees</h2></div>
                <p class="uj-ev-hint">Pick who is invited. Each person gets a card and a calendar entry.</p>
                <form method="post" action="{{ route('events.attendees', $event) }}" class="uj-ma-form uj-ev-pick" data-js="event-attendees-form">
                    @csrf
                    @method('POST')
                    <input type="hidden" name="attendees[]" value="">
                    <input type="search" placeholder="Type to filter names" autocomplete="off"
                           oninput="const q = this.value.toLowerCase(); [...this.nextElementSibling.options].forEach(o => { o.hidden = q && !o.text.toLowerCase().includes(q); });" />
                    <select name="attendees[]" multiple size="9" class="uj-ev-multi">
                        @foreach ($assignableEmployees as $person)
                            <option value="{{ $person->id }}" @selected($attendees->firstWhere('employee.id', $person->id))>
                                {{ $person->display_name }}
                            </option>
                        @endforeach
                    </select>
                    <span class="uj-ev-hint">Hold Ctrl (or Cmd) to pick more than one.</span>
                    <button type="submit" class="uj-btn-primary">Save attendees</button>
                </form>
            </section>
        @endif
    </div>

    @if ($isOver)
        <nav class="uj-seg uj-ev-tabs">
            <a href="#event-photos" data-event-tab="photos" :data-on="tab === 'photos' ? '' : null" @click.prevent="tab = 'photos'">Photos <span class="uj-ev-n">{{ $photos->count() }}</span></a>
            <a href="#event-comments" data-event-tab="comments" :data-on="tab === 'comments' ? '' : null" @click.prevent="tab = 'comments'">Comments <span class="uj-ev-n">{{ $comments->count() }}</span></a>
            <a href="#event-lessons" data-event-tab="lessons" :data-on="tab === 'lessons' ? '' : null" @click.prevent="tab = 'lessons'">Lessons learnt <span class="uj-ev-n">{{ $lessons->count() }}</span></a>
        </nav>

        <section id="event-photos" class="uj-card uj-ev-panel" x-show="tab === 'photos'">
            <div class="uj-card-head"><h2 class="uj-card-title">Photos</h2></div>
            @if ($isAttendee)
                <form method="post" action="{{ route('events.photos.store', $event) }}" enctype="multipart/form-data" class="uj-ev-row-form">
                    @csrf
                    <input type="file" name="photos[]" multiple accept="image/*">
                    <input type="text" name="captions[]" placeholder="Caption">
                    <button type="submit" class="uj-btn-primary">Add photos</button>
                </form>
            @endif
            <div class="uj-ev-photos">
                @forelse ($photos as $photo)
                    <figure class="uj-ev-photo">
                        <img src="{{ route('events.photos.show', $photo) }}" alt="{{ $photo->caption }}" loading="lazy">
                        @if ($photo->caption)
                            <figcaption>{{ $photo->caption }}</figcaption>
                        @endif
                    </figure>
                @empty
                    <p class="uj-ev-empty">No photos yet.</p>
                @endforelse
            </div>
        </section>

        <section id="event-comments" class="uj-card uj-ev-panel" x-show="tab === 'comments'">
            <div class="uj-card-head"><h2 class="uj-card-title">Comments</h2></div>
            <form method="post" action="{{ route('events.comments.store', $event) }}" class="uj-ma-form uj-ev-compose" data-js="event-comment-form">
                @csrf
                <textarea name="body" required placeholder="Say something about the event"></textarea>
                <button type="submit" class="uj-btn-primary">Post comment</button>
            </form>
            <ul class="uj-ev-thread">
                @forelse ($comments as $comment)
                    <li class="uj-ev-comment">
                        <span class="uj-ev-avatar">{{ \Illuminate\Support\Str::of($comment->employee?->display_name ?? '?')->substr(0, 1)->upper() }}</span>
                        <div class="uj-ev-comment-body">
                            <strong>{{ $comment->employee?->display_name }}</strong>
                            <p>{{ $comment->body }}</p>
                            @if ($comment->replies->isNotEmpty())
                                <ul class="uj-ev-replies">
                                    @foreach ($comment->replies as $reply)
                                        <li><strong>{{ $reply->employee?->display_name }}</strong> {{ $reply->body }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            {{-- QA F8 (scope 3, "others can react and reply"): a reply posts the same route with parent_id. --}}
                            <form method="post" action="{{ route('events.comments.store', $event) }}" class="uj-ev-reply" data-js="event-reply-form">
                                @csrf
                                <input type="hidden" name="parent_id" value="{{ $comment->id }}">
                                <input type="text" name="body" required placeholder="Reply">
                                <button type="submit" class="uj-btn-ghost">Reply</button>
                            </form>
                        </div>
                    </li>
                @empty
                    <li class="uj-ev-empty">No comments yet.</li>
                @endforelse
            </ul>
        </section>

        <section id="event-lessons" class="uj-card uj-ev-panel" x-show="tab === 'lessons'">
            <div class="uj-card-head"><h2 class="uj-card-title">Lessons learnt</h2></div>
            @if ($isAttendee)
                <form method="post" action="{{ route('events.lessons.store', $event) }}" class="uj-ma-form uj-ev-compose" data-js="event-lesson-form">
                    @csrf
                    <div><label>What we learnt</label><textarea name="learnt" required></textarea></div>
                    <div><label>How to use it</label><textarea name="how_to_use"></textarea></div>
                    <div><label>Link</label><input type="url" name="links[]" placeholder="https://"></div>
                    <button type="submit" class="uj-btn-primary">Share lesson</button>
                </form>
            @endif
            <div class="uj-ev-lessons">
                @forelse ($lessons as $lesson)
                    <article class="uj-ev-lesson">
                        <h3>{{ $lesson->learnt }}</h3>
                        <span class="uj-ev-by">{{ $lesson->employee?->display_name }}</span>
                        @if ($lesson->how_to_use)
                            <p>{{ $lesson->how_to_use }}</p>
                        @endif
                        @foreach ((array) $lesson->links as $link)
                            <p><a href="{{ $link }}" target="_blank" rel="noopener">{{ $link }}</a></p>
                        @endforeach
                        <div class="uj-reactions uj-ev-reactions" data-reaction-target="lesson-{{ $lesson->id }}">
                            @foreach ($reactionCatalog as $r)
                                <button type="button" class="uj-btn-ghost" data-react-url="{{ route('events.lessons.react', [$event, $lesson]) }}" data-reaction-key="{{ $r['key'] }}"
                                        @if (in_array($r['key'], $lessonReactions[$lesson->id]['mine'] ?? [], true)) style="font-weight:700" @endif>
                                    <span>{{ $r['icon'] }}</span> {{ $lessonReactions[$lesson->id]['reactions'][$r['key']] ?? 0 }}
                                </button>
                            @endforeach
                        </div>
                    </article>
                @empty
                    <p class="uj-ev-empty">No lessons shared yet.</p>
                @endforelse
            </div>
        </section>
    @endif
</div>
{{-- QA F7: the reaction buttons post through fetch and redraw their own counts (toggle semantics, see EventController::toggleReaction). --}}
<script>
document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('button[data-react-url]');
    if (! btn) return;
    const token = document.querySelector('meta[name=csrf-token]')?.content;
    const res = await fetch(btn.dataset.reactUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ reaction: btn.dataset.reactionKey }),
    });
    if (! res.ok) return;
    const state = await res.json();
    btn.parentElement.querySelectorAll('button[data-reaction-key]').forEach((b) => {
        const key = b.dataset.reactionKey;
        b.lastChild.textContent = ' ' + (state.reactions[key] ?? 0);
        b.style.fontWeight = state.mine.includes(key) ? '700' : '';
    });
});
</script>
@endsection
