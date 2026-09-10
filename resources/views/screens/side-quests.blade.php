@extends('layouts.app')

@php
    $quests = $quests ?? collect();
    $myPosts = $myPosts ?? collect();
    $myBadgeExpiry = $myBadgeExpiry ?? collect();
    $posts = $posts ?? collect();
    $suggestions = $suggestions ?? collect();
    $canCurate = $canCurate ?? false;
    $plain = (bool) \App\Support\DashboardPrefs::forUser(auth()->user()?->dashboard_prefs)['plain'];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'side-quests',
    'en' => [
        'title' => 'Side Quests',
        'body' => 'Small optional challenges with nothing to do with KPI. Finish one, post it, wear the badge.',
    ],
    'ms' => [
        'title' => 'Side Quests',
        'body' => 'Cabaran pilihan kecil yang tiada kaitan dengan KPI. Selesaikan satu, pos, dan pakai lencana.',
    ],
])

<div class="uj-sq-wrap">
    <div class="uj-sq-head">
        <span class="uj-sq-k">{{ $plain ? 'Optional challenges' : 'NOT A KPI. NEVER WILL BE.' }}</span>
        <span class="uj-sq-sub">Finish one, post the proof, wear the badge for 30 days. HR swaps the quests now and then. Nothing counts.</span>
    </div>

    <div class="uj-sq-quests">
        @foreach ($quests as $quest)
            @php
                $myPost = $myPosts->get($quest->id);
                $expiry = $myBadgeExpiry->get($quest->id);
            @endphp
            <div class="uj-card uj-sq-quest @if ($myPost) is-done @endif" data-quest="{{ $quest->id }}"
                 @unless ($myPost) x-data="{ open: false }" @endunless>
                @unless ($plain)
                    <span class="uj-sq-art" aria-hidden="true">🎯</span>
                @endunless
                <span class="t">{{ $quest->title }}</span>
                @if ($quest->blurb)
                    <span class="b">{{ $quest->blurb }}</span>
                @endif
                <div class="uj-sq-quest-foot">
                @if ($myPost)
                    <span class="done">{{ $plain ? 'Done' : '✓ Done' }}@if ($expiry) · badge until {{ \Illuminate\Support\Carbon::parse($expiry)->format('j M') }}@endif</span>
                @else
                    <button type="button" class="uj-btn-primary" :aria-expanded="open" @click="open = !open">I did this</button>
                @endif
                @if ($canCurate)
                    <form method="POST" action="{{ route('side-quests.retire', $quest) }}" onsubmit="return confirm('Retire this quest? It leaves the board; posts and badges stay.')">
                        @csrf
                        <button type="submit" class="retire" data-tip-end data-tip="Take it off the board">Retire</button>
                    </form>
                @endif
                </div>
                @unless ($myPost)
                    <div class="uj-card uj-sq-form" data-quest-complete="{{ $quest->id }}" x-show="open" x-cloak>
                        <form method="POST" action="{{ route('side-quests.complete', $quest) }}" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:10px;">
                            @csrf
                            <label>One-liner (or a photo, or both)
                                <textarea name="note" rows="2" maxlength="280" placeholder="What did you do?"></textarea>
                            </label>
                            <div class="row">
                                <label class="uj-sq-file">{{ $plain ? '' : '📎 ' }}Add a photo <input type="file" name="photo" accept="image/*" hidden></label>
                                <button type="submit" class="uj-btn-primary">Post it</button>
                            </div>
                        </form>
                    </div>
                @endunless
            </div>
        @endforeach
    </div>

    @if ($canCurate)
        <div class="uj-sq-curate">
            <div class="uj-card uj-sq-sugg">
                <span class="uj-sq-k uj-sq-k--muted">Suggested by staff <span class="uj-doc-n">{{ $suggestions->count() }}</span></span>
                @forelse ($suggestions as $suggestion)
                    <div class="row" data-quest-suggestion="{{ $suggestion->id }}">
                        <span>{{ $suggestion->title }}</span>
                        <small>{{ $suggestion->suggestedBy?->name }}</small>
                        <form method="POST" action="{{ route('side-quests.approve', $suggestion) }}">
                            @csrf
                            <button type="submit" class="uj-btn-ghost">Make it live</button>
                        </form>
                    </div>
                @empty
                    <span class="uj-sq-empty">Nothing suggested yet. Staff can suggest one from this screen.</span>
                @endforelse
            </div>
            <form method="POST" action="{{ route('side-quests.store') }}" class="uj-card uj-sq-sugg">
                @csrf
                <span class="uj-sq-k uj-sq-k--muted">Publish a new quest</span>
                <div class="row">
                    <input type="text" name="title" placeholder="e.g. Teach someone a keyboard shortcut" maxlength="255" required class="uj-sq-in">
                    <button type="submit" class="uj-btn-primary">Publish</button>
                </div>
                <small>Goes live straight away, next to the quests above.</small>
            </form>
        </div>
    @else
        <form method="POST" action="{{ route('side-quests.suggest') }}" class="uj-card uj-sq-sugg">
            @csrf
            <span class="uj-sq-k uj-sq-k--muted">Got a quest idea?</span>
            <div class="row">
                <input type="text" name="title" placeholder="Suggest one for HR to pick up" maxlength="255" required class="uj-sq-in">
                <button type="submit" class="uj-btn-ghost">Suggest</button>
            </div>
        </form>
    @endif

    <span class="uj-sq-k uj-sq-k--muted" style="margin-top:6px;">Side Quest feed <span class="uj-doc-n">{{ $posts->count() }}</span></span>
    <div class="uj-sq-feed">
        @forelse ($posts as $row)
            @php $post = $row['post']; @endphp
            <div class="uj-card uj-sq-post" data-quest-post="{{ $post->id }}">
                <div class="who">
                    <span class="uj-db-avatar" style="background:{{ $post->employee?->avatar_color ?? '#8a8f98' }};">{{ $post->employee?->initials }}</span>
                    <span class="n">{{ $post->employee?->name }}</span>
                    <span class="q">{{ $post->quest?->title }}</span>
                    <span class="w">{{ $post->created_at->format('D j M') }}</span>
                </div>
                @if ($post->note)
                    <span class="note">{{ $post->note }}</span>
                @endif
                @if ($post->photo_path)
                    <img src="{{ route('side-quests.posts.photo', $post) }}" alt="">
                @endif
                {!! $row['reactHtml'] !!}
            </div>
        @empty
            <div class="uj-card uj-sq-empty-card">Nobody has posted a Side Quest yet. Finish one above and be first.</div>
        @endforelse
    </div>
</div>
@endsection
