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
            : ($session->presenter?->name ?? $session->presenter_name
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
                <div class="tot-mast-s" x-text="$store.ui.lang==='en' ? 'First Saturday of every month. One person, one topic.' : 'Sabtu pertama setiap bulan. Seorang membentang satu topik.'">First Saturday of every month. One person, one topic.</div>
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
        </div>
    </div>

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
                $isPresenterOfSlot = $session->exists && $employee && $session->presenter_employee_id === $employee->id;
                $canEditSlot = $canManage || $canAssignPresenter || $isPresenterOfSlot;
                // A rejected save redirects back to the whole board, so the slot that failed
                // names itself in the flashed input and reopens with the error showing.
                $slotFailed = $session->exists && $errors->any() && old('totform') === (string) $session->id;
            @endphp
            <div x-data="{ open: {{ $slotFailed ? 'true' : 'false' }}, editing: {{ $slotFailed ? 'true' : 'false' }} }">
                <button type="button" class="tot-row" @if ($rm['kind']) data-kind="{{ $rm['kind'] }}" @endif
                        @click="open = !open" :aria-expanded="open">
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
                        <svg class="tot-chev" :style="open ? 'transform:rotate(180deg)' : ''" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                    </div>
                </button>

                <div class="tot-wrap" :data-open="open ? 1 : 0">
                    <div><div class="tot-panel" @if ($session->exists) x-data="totCard({
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
                    })" @endif>

                        @if ($session->exists)
                            {{-- Full date and watched count --}}
                            <div class="tot-panel-date">{{ $session->session_date->format('l j F Y') }} · <span x-text="$store.ui.lang==='en' ? @js($watched.' watched') : @js($watched.' sudah tonton')">{{ $watched }} watched</span></div>

                            {{-- Links --}}
                            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
                                @forelse ($session->links ?? [] as $link)
                                    <a class="tot-lk" href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--muted);"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14L21 3"/></svg>
                                        {{ $link['label'] }}
                                    </a>
                                @empty
                                    <span class="tot-note" x-text="$store.ui.lang==='en' ? 'No material uploaded yet.' : 'Belum ada bahan dimuat naik.'">No material uploaded yet.</span>
                                @endforelse
                            </div>

                            {{-- Reaction pills: one per emoji that somebody actually pressed, count only, never names. --}}
                            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:14px;" x-show="reactionTotal > 0">
                                <template x-for="(count, emoji) in reactions" :key="emoji">
                                    <span class="tot-pill" :data-mine="mine.includes(emoji) ? '1' : null">
                                        <span x-text="emoji"></span><b x-text="count"></b>
                                    </span>
                                </template>
                            </div>

                            <div class="tot-actions">
                                <span class="tot-fw">
                                    <span class="tot-fly" x-show="flyout === 'react'" x-cloak
                                          @mouseleave="flyout = null" @keydown.escape.window="flyout = null">
                                        @foreach (\App\Models\TotSession::EMOJI as $i => $emoji)
                                            <button type="button" class="tot-fly-e" style="--d:{{ $i * 30 }}ms"
                                                    @click="react(@js($emoji)); flyout = null"
                                                    :data-mine="mine.includes(@js($emoji)) ? '1' : null"
                                                    aria-label="React {{ $emoji }}">{{ $emoji }}</button>
                                        @endforeach
                                    </span>
                                    <button type="button" class="tot-act" :data-on="mine.length ? '1' : null"
                                            @click="flyout = flyout === 'react' ? null : 'react'"
                                            @mouseenter="flyout = 'react'"
                                            :aria-label="$store.ui.lang==='en' ? 'React to this session' : 'Beri reaksi'">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                                        <span x-text="reactionTotal || ''"></span>
                                    </button>
                                </span>

                                <button type="button" class="tot-act" @click="openThread()"
                                        :aria-label="$store.ui.lang==='en' ? 'Open comments' : 'Buka komen'">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-4-.9L3 21l1.9-4.9A8.4 8.4 0 0 1 12 3.1a8.4 8.4 0 0 1 9 8.4z"/></svg>
                                    <span x-text="comments || ''"></span>
                                </button>

                                <button type="button" class="tot-act" :data-on="iWatched ? '1' : null"
                                        @click="toggleWatched()" x-show="canParticipate"
                                        :aria-label="$store.ui.lang==='en' ? 'Mark as watched' : 'Tanda sudah tonton'">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <span x-text="watched || ''"></span>
                                </button>

                                <span class="tot-fw" x-show="canParticipate">
                                    {{-- noting resets whenever the flyout closes. x-show only hides,
                                         it does not tear the component down, so without this a rater
                                         who scores and then moves the mouse away reopens on the note
                                         box instead of the scores, with no way back to change the
                                         number until the box has been focused and blurred. --}}
                                    <span class="tot-fly tot-fly-rate" x-show="flyout === 'rate'" x-cloak
                                          @mouseleave="flyout = null; noting = false"
                                          @keydown.escape.window="flyout = null; noting = false"
                                          x-data="{ noting: false }">
                                        {{-- Two rows: the five scores, then the reassurance under them. Side by
                                             side the sentence had to compete with the circles for width, which
                                             either overflowed the pill or squeezed the text into a narrow column. --}}
                                        <template x-if="!noting">
                                            <span style="display:flex;flex-direction:column;align-items:flex-start;gap:8px;">
                                                <span style="display:flex;align-items:center;gap:6px;">
                                                    @foreach ([1, 2, 3, 4, 5] as $n)
                                                        <button type="button" class="tot-sc" :data-mine="myScore === {{ $n }} ? '1' : null"
                                                                @click="rate({{ $n }}); noting = true">{{ $n }}</button>
                                                    @endforeach
                                                </span>
                                                {{-- white-space:normal because .tot-fly is nowrap, which the
                                                     sentence would otherwise inherit and run past the pill. --}}
                                                <span class="tot-note" style="font-size:11.5px;max-width:300px;white-space:normal;line-height:1.4;"
                                                      x-text="$store.ui.lang==='en' ? @js('Only '.($session->presenter?->name ?? $session->presenter_name ?? 'the presenter').' and management see scores, and never with your name.') : @js('Hanya '.($session->presenter?->name ?? $session->presenter_name ?? 'pembentang').' dan pengurusan nampak skor, dan tidak sekali dengan nama anda.')">Only {{ $session->presenter?->name ?? $session->presenter_name ?? 'the presenter' }} and management see scores, and never with your name.</span>
                                            </span>
                                        </template>
                                        <template x-if="noting">
                                            <span style="display:flex;align-items:center;gap:6px;">
                                                <input type="text" maxlength="1000" class="tot-field" style="height:30px;width:210px;"
                                                       :value="myNote"
                                                       :placeholder="$store.ui.lang==='en' ? 'Add a note, optional' : 'Tambah nota, pilihan'"
                                                       @keydown.enter.prevent="saveNote($event.target.value); flyout = null; noting = false"
                                                       @blur="saveNote($event.target.value); noting = false">
                                            </span>
                                        </template>
                                    </span>
                                    <button type="button" class="tot-act" :data-on="myScore ? '1' : null"
                                            @click="flyout = flyout === 'rate' ? null : 'rate'"
                                            @mouseenter="flyout = 'rate'"
                                            :aria-label="$store.ui.lang==='en' ? 'Rate this session' : 'Nilai sesi ini'">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21l1.2-6.9-5-4.9 6.9-1z"/></svg>
                                        <span x-text="score ? `${score.average} (${score.count})` : ''"></span>
                                    </button>
                                </span>
                            </div>

                            {{-- Related Knowledge Bank entry --}}
                            @if ($session->entry_id && $session->entry)
                                <div class="tot-rule">
                                    <span class="tot-note" x-text="$store.ui.lang==='en' ? 'Related Knowledge Bank entry: ' : 'Entri Bank Pengetahuan berkaitan: '">Related Knowledge Bank entry: </span>
                                    <a href="{{ route('app.screen', ['screen' => 'knowledge-bank']) }}" class="uj-link">{{ $session->entry->title }}</a>
                                </div>
                            @endif

                            @if ($canEditSlot)
                                <div class="tot-rule">
                                    <button type="button" class="tot-pillbtn" @click="editing = !editing">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        <span x-text="$store.ui.lang==='en' ? 'Edit slot' : 'Sunting slot'">Edit slot</span>
                                    </button>

                                    <div x-show="editing" x-cloak style="margin-top:14px;">
                                        @if ($slotFailed)
                                            <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;max-width:620px;">{{ $errors->first() }}</div>
                                        @endif
                                        <form method="post" action="{{ route('tot.update', $session) }}">
                                            @csrf
                                            <input type="hidden" name="totform" value="{{ $session->id }}">
                                            @if ($canManage || $canAssignPresenter)
                                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;max-width:620px;">
                                                    @if ($canManage)
                                                        <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Presenter name' : 'Nama pembentang'">Presenter name</label><input class="tot-field" name="presenter_name" value="{{ $session->presenter_name }}"></div>
                                                    @endif
                                                    @if ($canAssignPresenter)
                                                        <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Presenter' : 'Pembentang'">Presenter</label>
                                                            <select class="tot-field" name="presenter_employee_id">
                                                                {{-- Blank first, so a presenter can be cleared: an empty value nulls
                                                                     both presenter_employee_id and presenter_name server-side. --}}
                                                                <option value="" x-text="$store.ui.lang==='en' ? 'Nobody yet' : 'Belum ada'">Nobody yet</option>
                                                                @foreach ($assignableEmployees as $person)
                                                                    <option value="{{ $person->id }}" @selected($session->presenter_employee_id === $person->id)>{{ $person->display_name }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($canManage)
                                                <div class="tot-note" style="margin-top:6px;max-width:620px;" x-text="$store.ui.lang==='en' ? 'Picking a presenter overrides the presenter name above, everywhere this session is shown.' : 'Memilih pembentang mengatasi nama pembentang di atas, di mana sahaja sesi ini dipaparkan.'">Picking a presenter overrides the presenter name above, everywhere this session is shown.</div>
                                                <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</label>
                                                    <select class="tot-field" name="status">
                                                        @foreach (\App\Models\TotSession::STATUSES as $st)
                                                            <option value="{{ $st }}" @selected($session->status === $st) x-text="$store.ui.lang==='en' ? @js($statusLabels[$st]['en']) : @js($statusLabels[$st]['ms'])">{{ $statusLabels[$st]['en'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @endif
                                            {{-- The material belongs to the presenter of this slot or to a
                                                 privileged role, matching TotController::update(), which gives
                                                 a tot.assign holder no rule for any of these. Rendering them
                                                 to a holder would show fields the save silently discards. --}}
                                            @if ($canManage || $isPresenterOfSlot)
                                            <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Topic' : 'Topik'">Topic</label><input class="tot-field" name="title" value="{{ $session->title }}"></div>
                                            <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label><textarea class="tot-field" name="description" style="height:64px;padding-top:9px;resize:vertical;">{{ $session->description }}</textarea></div>

                                            <div style="margin-top:16px;max-width:620px;" x-data="{ links: {{ \Illuminate\Support\Js::from(! empty($session->links) ? $session->links : [['label' => '', 'url' => '']]) }} }">
                                                <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Links' : 'Pautan'">Links</label>
                                                <template x-for="(link, idx) in links" :key="idx">
                                                    <div style="display:grid;grid-template-columns:150px 1fr 38px;gap:8px;margin-bottom:8px;">
                                                        <input class="tot-field" :name="`links[${idx}][label]`" x-model="link.label" placeholder="Label">
                                                        <input class="tot-field" :name="`links[${idx}][url]`" x-model="link.url" placeholder="https://...">
                                                        <button type="button" class="tot-btn-g" style="padding:0;width:38px;" @click="links.splice(idx, 1)">&times;</button>
                                                    </div>
                                                </template>
                                                <button type="button" class="tot-pillbtn" @click="links.push({ label: '', url: '' })"><span x-text="$store.ui.lang==='en' ? '+ Add a link' : '+ Tambah pautan'">+ Add a link</span></button>
                                            </div>

                                            <div style="margin-top:16px;max-width:620px;">
                                                <label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Related Knowledge Bank entry ID' : 'ID entri Bank Pengetahuan berkaitan'">Related Knowledge Bank entry ID</label>
                                                <input class="tot-field" type="number" name="entry_id" value="{{ $session->entry_id }}">
                                                <div class="tot-note" style="margin-top:6px;"><span x-text="$store.ui.lang==='en' ? @js('Optional. Links this session to a lesson the presenter already wrote. It never creates one.'.($session->entry ? ' Currently: '.$session->entry->title.'.' : '')) : @js('Pilihan. Kaitkan sesi ini dengan pengajaran yang telah ditulis pembentang. Ia tidak pernah mencipta satu.'.($session->entry ? ' Sekarang: '.$session->entry->title.'.' : ''))">Optional. Links this session to a lesson the presenter already wrote. It never creates one.@if ($session->entry) Currently: {{ $session->entry->title }}.@endif</span></div>
                                            </div>
                                            @endif

                                            <div class="tot-rule" style="max-width:620px;display:flex;gap:8px;align-items:center;">
                                                <button type="submit" class="tot-btn-p" x-text="$store.ui.lang==='en' ? 'Save slot' : 'Simpan slot'">Save slot</button>
                                                <button type="button" class="tot-btn-g" @click="editing = false" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                                                @if ($canManage && $session->status !== 'done')
                                                    <div class="tot-note" style="margin-left:auto;"><span x-text="$store.ui.lang==='en' ? @js('Marking this Done credits '.($session->presenter?->name ?? 'the presenter').'’s Knowledge Bank month.') : @js('Menandakan ini Selesai mengkredit bulan Bank Pengetahuan '.($session->presenter?->name ?? 'pembentang').'.')">Marking this <b style="color:var(--ink);font-weight:600;">Done</b> credits {{ $session->presenter?->name ?? 'the presenter' }}&rsquo;s Knowledge Bank month.</span></div>
                                                @endif
                                            </div>
                                        </form>

                                        @if ($canManage)
                                            <form method="post" action="{{ route('tot.destroy', $session) }}" style="margin-top:10px;" @submit="if (! confirm($store.ui.lang==='en' ? 'Remove this slot? This cannot be undone.' : 'Buang slot ini? Tindakan ini tidak boleh dibatalkan.')) $event.preventDefault();">
                                                @csrf
                                                <button type="submit" class="tot-btn-g" style="color:var(--error);" x-text="$store.ui.lang==='en' ? 'Delete slot' : 'Padam slot'">Delete slot</button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <div class="tot-modal" x-show="modalOpen" x-cloak @keydown.escape.window="modalOpen = false">
                                <div class="tot-modal-back" @click="modalOpen = false"></div>
                                <div class="tot-modal-card" role="dialog" aria-modal="true"
                                     :aria-label="$store.ui.lang==='en' ? 'Discussion' : 'Perbincangan'">
                                    <div class="tot-modal-head">
                                        <span style="flex:1;font-size:14.5px;color:var(--ink);">{{ $session->title ?: ($session->session_date->format('F Y')) }}</span>
                                        <button type="button" class="tot-act" @click="modalOpen = false"
                                                :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">&times;</button>
                                    </div>

                                    <div class="tot-modal-body">
                                        {{-- Anonymous rating notes. Present only for a viewer the server decided may see
                                             scores, which is the presenter and management. Never a name, never a score
                                             beside a note. This is where the note list from the old card lives now. --}}
                                        <template x-if="notes.length">
                                            <div style="margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid var(--hairline-soft);">
                                                <div class="tot-note" style="margin-bottom:7px;"
                                                     x-text="$store.ui.lang==='en' ? 'Anonymous notes from raters' : 'Nota tanpa nama daripada penilai'">Anonymous notes from raters</div>
                                                <template x-for="(n, i) in notes" :key="i">
                                                    <div style="font-size:13.5px;color:var(--body);margin-bottom:5px;" x-text="n"></div>
                                                </template>
                                            </div>
                                        </template>

                                        <template x-if="thread === null">
                                            <div class="tot-note" x-text="$store.ui.lang==='en' ? 'Loading' : 'Memuatkan'">Loading</div>
                                        </template>
                                        <template x-if="thread !== null && thread.length === 0">
                                            <div class="tot-note" x-text="$store.ui.lang==='en' ? 'No comments yet. Start the discussion.' : 'Belum ada komen. Mulakan perbincangan.'">No comments yet. Start the discussion.</div>
                                        </template>
                                        <template x-for="c in (thread || [])" :key="c.id">
                                            <div style="display:flex;gap:11px;margin-bottom:16px;">
                                                <div class="tot-av" :style="`background:${c.color};color:#fff;`" x-text="c.initials"></div>
                                                <div style="min-width:0;flex:1;">
                                                    <div style="display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;">
                                                        <span style="font-size:13.5px;font-weight:600;color:var(--ink);" x-text="c.name"></span>
                                                        <span class="tot-presenter-tag" x-show="c.presenter"
                                                              x-text="$store.ui.lang==='en' ? 'Presenter' : 'Pembentang'">Presenter</span>
                                                        <span class="tot-note" style="font-size:12px;" x-text="c.at"></span>
                                                        <button type="button" x-show="c.canDelete" style="margin-left:auto;font-size:11px;color:var(--muted);background:none;border:0;cursor:pointer;"
                                                                @click="removeComment(c.id)"
                                                                :aria-label="$store.ui.lang==='en' ? 'Remove comment' : 'Buang komen'">&times;</button>
                                                    </div>
                                                    <div style="font-size:13.5px;color:var(--body);margin-top:2px;" x-text="c.body"></div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    @if ($canParticipate)
                                        <div class="tot-modal-foot">
                                            <input type="text" maxlength="2000" class="tot-field" x-ref="composer"
                                                   :placeholder="$store.ui.lang==='en' ? 'Ask a question or add what you learned' : 'Tanya soalan atau kongsi apa yang anda pelajari'"
                                                   @keydown.enter.prevent="postComment($event.target.value); $event.target.value = ''">
                                            <button type="button" class="tot-btn-p" style="height:34px;font-size:12.5px;"
                                                    @click="postComment($refs.composer.value); $refs.composer.value = ''"
                                                    x-text="$store.ui.lang==='en' ? 'Post' : 'Hantar'">Post</button>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @else
                            {{-- Nothing saved for this month yet --}}
                            @if ($canManage || $canAssignPresenter)
                                {{-- A tot.assign holder opens the month with a presenter and nothing else.
                                     Their slot lands planned, which is what TotController::store()
                                     forces for a non-privileged caller, so the privileged fields below
                                     are hidden rather than merely ignored on submit. --}}
                                <form method="post" action="{{ route('tot.store') }}">
                                    @csrf
                                    <input type="hidden" name="year" value="{{ $session->year }}">
                                    <input type="hidden" name="month" value="{{ $session->month }}">
                                    <div style="display:grid;grid-template-columns:{{ $canManage ? '1fr 1fr' : '1fr' }};gap:12px;max-width:620px;">
                                        @if ($canManage)
                                            <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Presenter name' : 'Nama pembentang'">Presenter name</label><input class="tot-field" name="presenter_name"></div>
                                        @endif
                                        <div><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Presenter (optional)' : 'Pembentang (pilihan)'">Presenter (optional)</label>
                                            <select class="tot-field" name="presenter_employee_id">
                                                <option value="" x-text="$store.ui.lang==='en' ? 'Nobody yet' : 'Belum ada'">Nobody yet</option>
                                                @foreach ($assignableEmployees as $person)
                                                    <option value="{{ $person->id }}">{{ $person->display_name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                    @if ($canManage)
                                        <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Topic' : 'Topik'">Topic</label><input class="tot-field" name="title"></div>
                                        <div style="margin-top:12px;max-width:620px;"><label class="tot-lbl" x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</label>
                                            <select class="tot-field" name="status">
                                                @foreach (\App\Models\TotSession::STATUSES as $st)
                                                    <option value="{{ $st }}" @selected($st === 'planned') x-text="$store.ui.lang==='en' ? @js($statusLabels[$st]['en']) : @js($statusLabels[$st]['ms'])">{{ $statusLabels[$st]['en'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif
                                    <div class="tot-rule" style="max-width:620px;">
                                        <button type="submit" class="tot-btn-p">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M12 5v14M5 12h14"/></svg>
                                            <span x-text="$store.ui.lang==='en' ? 'Assign PIC' : 'Tetapkan PIC'">Assign PIC</span>
                                        </button>
                                    </div>
                                </form>
                            @else
                                <div class="tot-note" x-text="$store.ui.lang==='en' ? 'Nobody has been assigned to this session yet.' : 'Belum ada sesiapa ditugaskan untuk sesi ini.'">Nobody has been assigned to this session yet.</div>
                            @endif
                        @endif

                    </div></div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
