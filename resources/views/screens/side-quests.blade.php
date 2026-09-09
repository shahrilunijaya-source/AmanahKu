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
    <div>
        <span class="uj-sq-k">{{ $plain ? 'Optional challenges' : 'NOT A KPI. NEVER WILL BE.' }}</span>
        <p style="font-size:13px;color:var(--muted);margin:4px 0 0;">Two to three small quests, live until HR swaps them. Finish one, post the proof, wear the badge for 30 days. No points, nothing counts.</p>
    </div>

    <div class="uj-sq-quests">
        @foreach ($quests as $quest)
            @php
                $myPost = $myPosts->get($quest->id);
                $expiry = $myBadgeExpiry->get($quest->id);
            @endphp
            <div class="uj-card uj-sq-quest @if ($myPost) is-done @endif" data-quest="{{ $quest->id }}"
                 @unless ($myPost) x-data="{ open: false }" @endunless>
                @if ($canCurate)
                    <form method="POST" action="{{ route('side-quests.retire', $quest) }}">
                        @csrf
                        <button type="submit" class="retire">Retire</button>
                    </form>
                @endif
                @unless ($plain)
                    <span class="uj-sq-art" aria-hidden="true">🎯</span>
                @endunless
                <span class="t">{{ $quest->title }}</span>
                @if ($quest->blurb)
                    <span class="b">{{ $quest->blurb }}</span>
                @endif
                @if ($myPost)
                    <span class="done">{{ $plain ? 'Done' : '✓ Done' }}@if ($expiry) · badge until {{ \Illuminate\Support\Carbon::parse($expiry)->format('j M') }}@endif</span>
                @else
                    <button type="button" class="uj-btn-primary" @click="open = !open">I did this</button>
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
                @endif
            </div>
        @endforeach
    </div>

    @if ($canCurate)
        <div class="uj-card uj-sq-sugg">
            <span class="uj-sq-k" style="color:var(--muted)">Suggested by staff</span>
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
                <span style="font-size:12.5px;color:var(--muted);">Nothing suggested yet.</span>
            @endforelse
            <form method="POST" action="{{ route('side-quests.store') }}" class="row">
                @csrf
                <input type="text" name="title" placeholder="New quest title" maxlength="255" required style="flex:1;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;">
                <button type="submit" class="uj-btn-ghost">Publish</button>
            </form>
        </div>
    @else
        <form method="POST" action="{{ route('side-quests.suggest') }}" class="uj-card uj-sq-form" style="flex-direction:row;align-items:center;gap:10px;">
            @csrf
            <input type="text" name="title" placeholder="Suggest a quest for HR to pick up" maxlength="255" required style="flex:1;height:36px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;">
            <button type="submit" class="uj-btn-ghost">Suggest</button>
        </form>
    @endif

    <span class="uj-sq-k" style="color:var(--muted)">Side Quest feed</span>
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
            <span style="font-size:12.5px;color:var(--muted);">Nobody has posted a Side Quest yet.</span>
        @endforelse
    </div>
</div>
@endsection
