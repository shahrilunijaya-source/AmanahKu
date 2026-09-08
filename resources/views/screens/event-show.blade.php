@extends('layouts.app')

@php
    // ponytail: a plain server-rendered page, not the fetch-a-partial pattern the rest of
    // the app follows for in-screen actions — this page has no live board/list to keep in
    // sync with, every action here is "add a row and reload".
    $responseLabel = [
        'going' => 'Going',
        'registered' => 'Registered',
        'attended' => 'Attended',
        'maybe' => 'Maybe',
        'declined' => 'Declined',
    ];
@endphp

@section('screen')
<div class="uj-panel" style="max-width:900px;margin:0 auto;">
    <h1>{{ $event->title }}</h1>
    <p>{{ $event->location }}</p>
    @if ($event->description)
        <p>{{ $event->description }}</p>
    @endif

    @if ($canManageAttendees)
        <h2>Attendees</h2>
        <form method="post" action="{{ route('events.attendees', $event) }}" data-js="event-attendees-form">
            @csrf
            @method('POST')
            <select name="attendees[]" multiple size="8" style="min-width:280px;">
                @foreach ($assignableEmployees as $person)
                    <option value="{{ $person->id }}" @selected($attendees->firstWhere('employee.id', $person->id))>
                        {{ $person->display_name }}
                    </option>
                @endforeach
            </select>
            <button type="submit">Save attendees</button>
        </form>
    @endif

    <h2>Who's going</h2>
    <ul>
        @forelse ($attendees as $a)
            <li>{{ $a['employee']?->display_name }} — {{ $a['responseLabel'] }}</li>
        @empty
            <li>No attendees yet.</li>
        @endforelse
    </ul>

    @if ($isOver)
        <nav>
            <a href="#event-photos" data-event-tab="photos">Photos</a>
            <a href="#event-comments" data-event-tab="comments">Comments</a>
            <a href="#event-lessons" data-event-tab="lessons">Lessons learnt</a>
        </nav>

        <section id="event-photos">
            <h2>Photos</h2>
            @if ($isAttendee)
                <form method="post" action="{{ route('events.photos.store', $event) }}" enctype="multipart/form-data">
                    @csrf
                    <input type="file" name="photos[]" multiple accept="image/*">
                    <input type="text" name="captions[]" placeholder="Caption">
                    <button type="submit">Add photos</button>
                </form>
            @endif
            <div>
                @foreach ($photos as $photo)
                    <figure>
                        <img src="{{ route('events.photos.show', $photo) }}" alt="{{ $photo->caption }}">
                        @if ($photo->caption)
                            <figcaption>{{ $photo->caption }}</figcaption>
                        @endif
                    </figure>
                @endforeach
            </div>
        </section>

        <section id="event-comments">
            <h2>Comments</h2>
            <form method="post" action="{{ route('events.comments.store', $event) }}" data-js="event-comment-form">
                @csrf
                <textarea name="body" required></textarea>
                <button type="submit">Post comment</button>
            </form>
            <ul>
                @foreach ($comments as $comment)
                    <li>
                        <strong>{{ $comment->employee?->display_name }}</strong>: {{ $comment->body }}
                        @if ($comment->replies->isNotEmpty())
                            <ul>
                                @foreach ($comment->replies as $reply)
                                    <li><strong>{{ $reply->employee?->display_name }}</strong>: {{ $reply->body }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section id="event-lessons">
            <h2>Lessons learnt</h2>
            @if ($isAttendee)
                <form method="post" action="{{ route('events.lessons.store', $event) }}" data-js="event-lesson-form">
                    @csrf
                    <label>What we learnt<textarea name="learnt" required></textarea></label>
                    <label>How to use it<textarea name="how_to_use"></textarea></label>
                    <label>Link<input type="url" name="links[]"></label>
                    <button type="submit">Share lesson</button>
                </form>
            @endif
            @foreach ($lessons as $lesson)
                <article>
                    <h3>{{ $lesson->learnt }} — {{ $lesson->employee?->display_name }}</h3>
                    @if ($lesson->how_to_use)
                        <p>{{ $lesson->how_to_use }}</p>
                    @endif
                    @foreach ((array) $lesson->links as $link)
                        <p><a href="{{ $link }}">{{ $link }}</a></p>
                    @endforeach
                    <div class="uj-reactions" data-reaction-target="lesson-{{ $lesson->id }}">
                        @foreach ($reactionCatalog as $r)
                            <button type="button" data-react-url="{{ route('events.lessons.react', [$event, $lesson]) }}" data-reaction-key="{{ $r['key'] }}">
                                {{ $r['icon'] }} {{ $lessonReactions[$lesson->id]['reactions'][$r['key']] ?? 0 }}
                            </button>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </section>
    @endif
</div>
@endsection
