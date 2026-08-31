@extends('layouts.app')

@php
    $canParticipate = $employee !== null;

    // Per-row display data, computed once so the masthead chips and the row markup
    // below use exactly the same classification instead of two logics drifting apart.
    $heldCount = 0;
    $needsTopicCount = 0;
    $upcomingCount = 0;
    $notTotCount = 0;
    $rowMeta = [];

    // Fixed UI copy that also needs a Malay counterpart for the bilingual toggle. Dynamic
    // content (a real session title, a real presenter's name) has no translation and stays
    // identical in both languages, so only these known static phrases get a Malay pair.
    $sublineMs = [
        'No session held' => 'Tiada sesi diadakan',
        'Nobody was assigned' => 'Belum ada pembentang',
        'Topic still blank' => 'Topik belum diisi',
        'Topic to be confirmed' => 'Topik belum disahkan',
    ];
    $nameTextMs = [
        'No session held' => 'Tiada sesi diadakan',
        'Company event' => 'Acara syarikat',
        'Nobody assigned' => 'Belum ada pembentang',
        \App\Models\TotSession::TEAM_LABEL => 'Berkumpulan',
    ];
    $statusLabels = [
        'planned' => ['en' => 'Planned', 'ms' => 'Dirancang'],
        'confirmed' => ['en' => 'Confirmed', 'ms' => 'Disahkan'],
        'done' => ['en' => 'Done', 'ms' => 'Selesai'],
        'skipped' => ['en' => 'Skipped', 'ms' => 'Dilangkau'],
        'not_tot' => ['en' => 'Not TOT', 'ms' => 'Bukan TOT'],
    ];

    foreach ($sessions as $i => $session) {
        $subline = match (true) {
            $session->status === 'skipped' => 'Nobody was assigned',
            $session->status === 'not_tot' => $session->title,
            filled($session->title) => $session->title,
            $session->status === 'planned' && $session->session_date->isPast() => 'Topic still blank',
            default => 'Topic to be confirmed',
        };
        $stale = $subline === 'Topic still blank';
        $subTone = $stale ? 'amber' : ((filled($session->title) && $session->status !== 'not_tot') ? 'body' : 'soft');

        $nameText = $session->status === 'skipped'
            ? 'No session held'
            : ($session->presenterLabel()
                ?? ($session->status === 'not_tot' ? 'Company event' : 'Nobody assigned'));

        $kind = match ($session->status) {
            'not_tot' => 'event',
            'skipped' => 'skipped',
            default => null,
        };
        $kickEn = match ($kind) { 'event' => 'Event', 'skipped' => 'Skipped', default => null };
        $kickMs = match ($kind) { 'event' => 'Acara', 'skipped' => 'Dilangkau', default => null };

        $rowMeta[$i] = [
            'subline' => $subline, 'stale' => $stale, 'subTone' => $subTone,
            'sublineMs' => $sublineMs[$subline] ?? $subline,
            'nameText' => $nameText,
            'nameTextMs' => $nameTextMs[$nameText] ?? $nameText,
            'kind' => $kind, 'kickEn' => $kickEn, 'kickMs' => $kickMs,
        ];

        if ($session->status === 'done') {
            $heldCount++;
        } elseif ($session->status === 'not_tot') {
            $notTotCount++;
        } elseif ($session->status === 'skipped') {
            // Not counted in any of the four chips: a deliberate "no session" carries
            // no weight of its own on the masthead.
        } elseif ($stale) {
            $needsTopicCount++;
        } else {
            $upcomingCount++;
        }
    }

    $allUnassigned = collect($sessions)->every(fn ($s) => ! $s->exists);
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'tot',
    'en'  => [
        'title' => 'TOT sessions',
        'body'  => 'Transfer of Technology runs on the first Saturday of every month. One person presents one topic. This board holds the whole year: who is presenting, what they covered, the slides and recordings, and what everyone thought.',
        'who'   => 'Everyone reads, reacts and rates · HR and management set the roster',
        'steps' => [
            'Pick a year at the top right. The board always shows all twelve months.',
            'Click a month to open it. Slides and recordings sit inside.',
            'React with an emoji, ask a question in the thread, and mark it watched.',
            'Rate the session 1 to 5. Only the presenter and management see scores, and never with your name.',
        ],
    ],
    'ms'  => [
        'title' => 'Sesi TOT',
        'body'  => 'Transfer of Technology diadakan pada Sabtu pertama setiap bulan. Seorang membentang satu topik. Papan ini menyimpan sepanjang tahun: siapa membentang, apa yang dibincangkan, slaid dan rakaman, serta pandangan semua orang.',
        'who'   => 'Semua baca, beri reaksi dan nilai · HR dan pengurusan tetapkan jadual',
        'steps' => [
            'Pilih tahun di bahagian kanan atas. Papan sentiasa tunjuk kesemua dua belas bulan.',
            'Klik satu bulan untuk membukanya. Slaid dan rakaman ada di dalam.',
            'Beri reaksi emoji, tanya soalan dalam bicara, dan tanda sudah tonton.',
            'Nilai sesi 1 hingga 5. Hanya pembentang dan pengurusan nampak skor, dan tidak sekali dengan nama anda.',
        ],
    ],
])

<div class="tot-screen">
    <div class="tot-mast">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;">
            <div>
                <div class="tot-mast-k">Transfer of technology</div>
                <div class="tot-mast-y">{{ $year }}</div>
            </div>
            <div class="tot-yr-list">
                @foreach ($years as $y)
                    <a href="{{ route('app.screen', ['screen' => 'tot', 'year' => $y]) }}" class="tot-yr" @if ($y === $year) aria-selected="true" @endif>{{ $y }}</a>
                @endforeach
            </div>
        </div>
        <div class="tot-chips">
            <div class="tot-chip"><b>{{ $heldCount }}</b><span x-text="$store.ui.lang==='en' ? 'Held' : 'Selesai'">Held</span></div>
            <div class="tot-chip"><b>{{ $needsTopicCount }}</b><span x-text="$store.ui.lang==='en' ? 'Needs a topic' : 'Perlu topik'">Needs a topic</span></div>
            <div class="tot-chip"><b>{{ $upcomingCount }}</b><span x-text="$store.ui.lang==='en' ? 'Upcoming' : 'Akan datang'">Upcoming</span></div>
            <div class="tot-chip"><b>{{ $notTotCount }}</b><span x-text="$store.ui.lang==='en' ? 'Not TOT' : 'Bukan TOT'">Not TOT</span></div>
            @if ($canAssignPresenter)
                <a href="{{ route('app.screen', ['screen' => 'tot-roster', 'year' => $year]) }}"
                   class="tot-mast-action"
                   x-text="$store.ui.lang==='en' ? 'Edit roster' : 'Sunting jadual'">Edit roster</a>
            @endif
        </div>
    </div>

@if ($myMonth)
    <p class="tot-pic">
        <span>
            <b x-text="$store.ui.lang==='en' ? @js('You present in '.$myMonth->session_date->format('F').'.') : @js('Anda membentang pada '.$myMonth->session_date->format('F').'.')">You present in {{ $myMonth->session_date->format('F') }}.</b>
            <span class="tot-pic-sub">{{ filled($myMonth->title) ? $myMonth->title : '' }}@if (filled($myMonth->title)) · @endif{{ $myMonth->session_date->format('l j F Y') }}</span>
        </span>
        <button type="button" @click="$dispatch('tot-open', { month: {{ $myMonth->month }} })"
                x-text="$store.ui.lang==='en' ? 'Open my slot' : 'Buka slot saya'">Open my slot</button>
    </p>
@endif

    @if ($allUnassigned)
        <div class="tot-invite">
            <h4 x-text="$store.ui.lang==='en' ? 'Twelve Saturdays, nobody assigned yet' : 'Dua belas Sabtu, belum ada pembentang'">Twelve Saturdays, nobody assigned yet</h4>
            <p x-text="$store.ui.lang==='en' ? 'The dates are already set. Click a month below and assign a presenter, and the reminders take care of the rest.' : 'Tarikh sudah ditetapkan. Klik satu bulan di bawah dan tetapkan pembentang, peringatan akan uruskan selebihnya.'">The dates are already set. Click a month below and assign a presenter, and the reminders take care of the rest.</p>
        </div>
    @endif

    <div class="tot-list">
        @foreach ($sessions as $i => $session)
            @php
                $rm = $rowMeta[$i];
                $top3 = $session->exists ? collect($reactionCounts[$session->id] ?? [])->sortDesc()->take(3) : collect();
                $myReact = $session->exists ? ($myReactions[$session->id] ?? []) : [];
                $myPart = $session->exists ? ($myParticipation[$session->id] ?? null) : null;
                $sessionCommentCount = $session->exists ? ($commentCounts[$session->id] ?? 0) : 0;
                $sessionScore = $session->exists ? ($scores[$session->id] ?? null) : null;
                $watched = $session->exists ? ($watchedCounts[$session->id] ?? 0) : 0;
                $showUpcoming = in_array($session->status, ['planned', 'confirmed'], true) && $watched === 0;
                $isPresenterOfSlot = $session->exists && $session->isPresentedBy($employee);
                $canEditSlot = $canManage || $canAssignPresenter || $isPresenterOfSlot;
                // A rejected save redirects back to the whole board, so the slot that failed
                // names itself in the flashed input and reopens with the error showing.
                $slotFailed = $session->exists && $errors->any() && old('totform') === (string) $session->id;
            @endphp
            @if ($session->exists)
                <div x-data="totCard({
                    id: {{ $session->id }},
                    reactions: @js($reactionCounts[$session->id] ?? (object) []),
                    mine: @js($myReact),
                    watched: {{ $watched }},
                    iWatched: @js((bool) $myPart?->watched_at),
                    comments: {{ $sessionCommentCount }},
                    myScore: @js($myPart?->score),
                    myNote: @js($myPart?->note),
                    score: @js($sessionScore ? ['average' => $sessionScore['average'], 'count' => $sessionScore['count']] : null),
                    canParticipate: @js($canParticipate),
                    editing: {{ $slotFailed ? 'true' : 'false' }},
                    drawerOpen: {{ $slotFailed ? 'true' : 'false' }},
                })"
                     @tot-open.window="if ($event.detail.month === {{ $session->month }}) { openDrawer() }">
            @else
                <div x-data="{ drawerOpen: false }"
                     @tot-open.window="if ($event.detail.month === {{ $session->month }}) { drawerOpen = true }">
            @endif
                <button type="button" class="tot-row" @if ($rm['kind']) data-kind="{{ $rm['kind'] }}" @endif
                        @if ($session->exists) @click="openDrawer()" @else @click="drawerOpen = true" @endif>
                    <div class="tot-tile">
                        <div class="m">{{ $session->session_date->format('M') }}</div>
                        <div class="d">{{ $session->session_date->format('d') }}</div>
                    </div>
                    <div style="min-width:0;">
                        @if ($rm['kickEn'])
                            <span class="tot-kick" x-text="$store.ui.lang==='en' ? @js($rm['kickEn']) : @js($rm['kickMs'])">{{ $rm['kickEn'] }}</span>
                        @endif
                        <div class="tot-nm" x-text="$store.ui.lang==='en' ? @js($rm['nameText']) : @js($rm['nameTextMs'])">{{ $rm['nameText'] }}</div>
                        <div class="tot-sb" data-tone="{{ $rm['subTone'] }}" x-text="$store.ui.lang==='en' ? @js($rm['subline']) : @js($rm['sublineMs'])">{{ $rm['subline'] }}</div>
                        @if ($session->exists)
                            <div class="tot-meta-mobile">
                                @foreach ($top3 as $emoji => $count)
                                    <span>{{ $emoji }}<b>{{ $count }}</b></span>
                                @endforeach
                                @if ($watched > 0)
                                    <span x-text="$store.ui.lang==='en' ? @js($watched.' watched') : @js($watched.' sudah tonton')">{{ $watched }} watched</span>
                                @elseif ($showUpcoming)
                                    <span class="tot-up" x-text="$store.ui.lang==='en' ? 'Upcoming' : 'Akan datang'">Upcoming</span>
                                @endif
                            </div>
                        @endif
                    </div>
                    <div class="tot-meta">
                        @if ($session->exists)
                            @foreach ($top3 as $emoji => $count)
                                <span class="tot-rx">{{ $emoji }}<b>{{ $count }}</b></span>
                            @endforeach
                            @if ($watched > 0)
                                <span class="tot-wt" x-text="$store.ui.lang==='en' ? @js($watched.' watched') : @js($watched.' sudah tonton')">{{ $watched }} watched</span>
                            @elseif ($showUpcoming)
                                <span class="tot-up" x-text="$store.ui.lang==='en' ? 'Upcoming' : 'Akan datang'">Upcoming</span>
                            @endif
                        @endif
                        <svg class="tot-chev" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
                    </div>
                </button>

                @include('partials.tot-drawer', [
                    'session' => $session,
                    'canEditSlot' => $canEditSlot,
                    'canManage' => $canManage,
                    'canAssignPresenter' => $canAssignPresenter,
                    'isPresenterOfSlot' => $isPresenterOfSlot,
                    'canParticipate' => $canParticipate,
                    'assignableEmployees' => $assignableEmployees,
                    'statusLabels' => $statusLabels,
                    'slotFailed' => $slotFailed,
                ])
            </div>
        @endforeach
    </div>
</div>
@endsection
