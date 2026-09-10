@extends('layouts.app')

@php
    $story = $wrappedStory ?? null;
    $plain = $wrappedPlain ?? false;
    $cards = $story?->cards ?? [];
    $arcTitle = $story?->arc_title ?? '';
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'wrapped',
    'en'  => [
        'title' => 'Wrapped',
        'body'  => 'Your month, story-card style. Numbers and dates only, from the same frozen numbers the awards use. Private until you share it.',
    ],
    'ms'  => [
        'title' => 'Wrapped',
        'body'  => 'Bulan anda, gaya kad cerita. Nombor dan tarikh sahaja, daripada nombor beku yang sama digunakan anugerah. Peribadi sehingga anda kongsikan.',
    ],
])

<div class="uj-wr-wrap"@if ($story) data-wrapped="{{ $story->id }}" @endif>
    <span class="uj-wr-k">{{ $plain ? 'Your month in numbers' : 'AMANAHKU WRAPPED' }}</span>

    @if ($story === null)
        <p style="font-size:13px;color:var(--muted);">Nothing built for this month yet. Wrapped stories are built on the first working day of the month, for the month before.</p>
    @elseif ($plain)
        {{-- One flat text node, no nested tags: CR22Test's plain-summary check slices
             from `data-wrapped-plain` to the FIRST "</" it finds, so a <b> around the
             first number would truncate the slice before the other three needles
             (high_priority, best_day, arc title) are reached. Deviates from the mockup's
             "every number bold with data-wrapped-stat" for the same reason the dashboard
             sentence does — see OPEN.md. --}}
        <p class="uj-card uj-wr-plain" data-wrapped-plain>Your {{ $story->month->format('F') }}, from the same frozen numbers the awards use. You closed {{ $cards['cards_closed'] }} cards and survived {{ $cards['high_priority'] }} high-priority situations. @if (($cards['best_day'] ?? '') !== '')Your most productive day was {{ $cards['best_day'] }}, with {{ $cards['best_day_count'] ?? 0 }} of your {{ $cards['cards_closed'] }} cards landing there. @else No cards closed this month, and that is fine. @endif You helped {{ $cards['helped_people'] }} different people finish their work, and shared {{ $cards['lessons_shared'] }} lesson(s) in the Knowledge Bank. Your character arc this month: {{ $arcTitle }}.</p>
        @if ($story->shared_at === null)
            <form method="POST" action="{{ route('wrapped.share', $story->id) }}"><input type="hidden" name="_token" value="{{ csrf_token() }}">
                <button type="submit" class="uj-btn-ghost" data-tip-end data-tip="Post your Wrapped to the Wins wall">Share to the Wall</button>
            </form>
        @else
            <span class="uj-wr-shared">Shared on the Wall
                <form method="POST" action="{{ route('wrapped.unshare', $story->id) }}" style="display:inline;"><input type="hidden" name="_token" value="{{ csrf_token() }}">
                    <button type="submit" style="background:none;border:0;color:inherit;text-decoration:underline;cursor:pointer;font:inherit;">Unshare</button>
                </form>
            </span>
        @endif
    @else
        @php
            $deck = [
                ['tone' => 'red', 'art' => '🎬', 'stat' => null, 'big' => $story->month->format('F'), 'line' => "{$story->employee->name}, this was your month.", 'sub' => 'Numbers and dates only. Nobody else sees this unless you share it.'],
                ['tone' => 'ink', 'art' => '✅', 'stat' => 'cards_closed', 'big' => $cards['cards_closed'], 'line' => "You closed {$cards['cards_closed']} cards.", 'sub' => 'Same count the awards used, frozen 30 '.$story->month->format('M').'.'],
                ['tone' => 'amber', 'art' => '🔥', 'stat' => 'high_priority', 'big' => $cards['high_priority'], 'line' => "You survived {$cards['high_priority']} high-priority situations.", 'sub' => ''],
                ['tone' => 'teal', 'art' => '📅', 'stat' => 'best_day', 'big' => ($cards['best_day'] ?? '') !== '' ? $cards['best_day'] : '—',
                    'line' => ($cards['best_day'] ?? '') !== '' ? "Most productive day: {$cards['best_day']}." : 'No cards closed this month, and that is fine.',
                    'sub' => ($cards['best_day'] ?? '') !== '' ? "{$cards['best_day_count']} of your {$cards['cards_closed']} cards landed on a {$cards['best_day']}." : ''],
                ['tone' => 'plum', 'art' => '🤝', 'stat' => 'helped_people', 'big' => $cards['helped_people'], 'line' => "You helped {$cards['helped_people']} different people finish their work.", 'sub' => ''],
                ['tone' => 'ink', 'art' => '📚', 'stat' => 'lessons_shared', 'big' => $cards['lessons_shared'], 'line' => $cards['lessons_shared'].' lesson'.($cards['lessons_shared'] === 1 ? '' : 's').' shared in the Knowledge Bank.', 'sub' => ''],
                ['tone' => 'red', 'art' => '🎭', 'stat' => 'arc', 'big' => $arcTitle, 'line' => 'Your '.$story->month->format('F').' character arc.', 'sub' => "Picked from HR's list by simple rules. No comparison to anyone."],
            ];
        @endphp
        <div class="uj-wr-deck" x-data="{ i: 0, n: {{ count($deck) }} }">
            @foreach ($deck as $idx => $card)
                <div class="uj-wr-card" data-tone="{{ $card['tone'] }}" data-wrapped-card="{{ $idx + 1 }}" x-show="i === {{ $idx }}" @if ($idx !== 0) style="display:none" @endif>
                    <span class="uj-wr-art" aria-hidden="true">{{ $card['art'] }}</span>
                    @if ($card['stat'])
                        <span class="big" data-wrapped-stat="{{ $card['stat'] }}">{{ $card['big'] }}</span>
                    @else
                        <span class="big">{{ $card['big'] }}</span>
                    @endif
                    <span class="line">{{ $card['line'] }}</span>
                    @if ($card['sub'] !== '')<span class="sub">{{ $card['sub'] }}</span>@endif
                </div>
            @endforeach
            <div class="uj-wr-nav">
                <button type="button" class="arrow" @click="i = (i - 1 + n) % n" aria-label="Previous">‹</button>
                @foreach ($deck as $idx => $card)
                    <button type="button" class="dot" :class="{ on: i === {{ $idx }} }" @click="i = {{ $idx }}" aria-label="Card {{ $idx + 1 }}"></button>
                @endforeach
                <button type="button" class="arrow" @click="i = (i + 1) % n" aria-label="Next">›</button>
                @if ($story->shared_at === null)
                    <form method="POST" action="{{ route('wrapped.share', $story->id) }}"><input type="hidden" name="_token" value="{{ csrf_token() }}">
                        <button type="submit" class="uj-btn-primary share" data-tip="Post your Wrapped to the Wins wall">Share to the Wall</button>
                    </form>
                @else
                    <span class="uj-wr-shared">Shared on the Wall ·
                        <form method="POST" action="{{ route('wrapped.unshare', $story->id) }}" style="display:inline;"><input type="hidden" name="_token" value="{{ csrf_token() }}">
                            <button type="submit" style="background:none;border:0;color:inherit;text-decoration:underline;cursor:pointer;font:inherit;">Unshare</button>
                        </form>
                    </span>
                @endif
            </div>
        </div>
    @endif

    @if ($isWrappedArcCurator)
        @php
            // Same order the builder checks them in; first match wins.
            $arcRules = [
                'firefighter' => ['Firefighter', '3+ high-priority situations'],
                'helper' => ['Helper', 'helped 3+ people'],
                'closer' => ['Closer', '10+ cards closed'],
                'quiet' => ['Quiet', '0 cards closed'],
                'steady' => ['Steady', 'everyone else'],
            ];
            $arcsByRule = $wrappedArcs->groupBy('rule');
        @endphp
        <div class="uj-card uj-wr-arcs" x-data="{ open: false }">
            <div class="uj-wr-arcs-head">
                <div>
                    <b>Character arcs</b> <span class="uj-mgmt-n" data-tip="Live arc titles across all rules">{{ $wrappedArcs->count() }}</span>
                    <div class="uj-wr-arcs-sub">The title a person's Wrapped gets. Rules are checked top to bottom, first match wins, then one title is drawn from that group.</div>
                </div>
                <button type="button" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12.5px;" @click="open = ! open" x-text="open ? 'Close' : 'Add an arc'" data-tip-end :data-tip="open ? 'Hide the form' : 'Add a title the story builder can draw'">Add an arc</button>
            </div>
            <form method="POST" action="{{ route('wrapped.arcs.store') }}" class="uj-wr-arcs-add" x-show="open" x-cloak>
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <input type="text" name="title" placeholder="Title, e.g. The Closer" required maxlength="120" style="flex:1 1 220px;">
                <select name="rule" required>
                    @foreach ($arcRules as $rule => [$label, $when])
                        <option value="{{ $rule }}">{{ $label }} · {{ $when }}</option>
                    @endforeach
                </select>
                <button type="submit" class="uj-btn-primary" data-tip-end data-tip="Saved as live, used from the next Wrapped">Add</button>
            </form>
            <div class="uj-wr-arc-groups">
                @foreach ($arcRules as $rule => [$label, $when])
                    @php $group = $arcsByRule->get($rule, collect()); @endphp
                    <section class="uj-wr-arc-group" data-arc-rule="{{ $rule }}">
                        <header>
                            <span class="uj-wr-arc-step">{{ $loop->iteration }}</span>
                            <b>{{ $label }}</b>
                            <span class="uj-wr-arc-when">{{ $when }}</span>
                            <span class="uj-mgmt-n" @if ($group->count() < 3) data-low data-tip-wrap data-tip="Fewer than 3 live titles: the builder needs at least 3 per rule" @else data-tip="Live titles for this rule" @endif>{{ $group->count() }}</span>
                        </header>
                        <div class="uj-wr-arc-chips">
                            @forelse ($group as $arc)
                                <form method="POST" action="{{ route('wrapped.arcs.retire', $arc->id) }}" class="uj-wr-arc-chip" data-wrapped-arc="{{ $arc->id }}">
                                    <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                    <span>{{ $arc->title }}</span>
                                    <button type="submit" aria-label="Retire {{ $arc->title }}" data-tip="Retire" onclick="return confirm('Retire this arc? It stops being drawn for new Wrapped stories.')">&times;</button>
                                </form>
                            @empty
                                <span class="uj-wr-arc-empty">No live titles.</span>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
