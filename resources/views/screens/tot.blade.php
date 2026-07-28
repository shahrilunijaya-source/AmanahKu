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
        'Topic still blank' => 'Topik belum diisi',
        'Topic to be confirmed' => 'Topik belum disahkan',
    ];
    $nameTextMs = [
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
            $session->status === 'skipped' => 'No session held',
            $session->status === 'not_tot' => $session->title,
            filled($session->title) => $session->title,
            $session->status === 'planned' && $session->session_date->isPast() => 'Topic still blank',
            default => 'Topic to be confirmed',
        };
        $stale = $subline === 'Topic still blank';
        $subTone = $stale ? 'amber' : ((filled($session->title) && $session->status !== 'not_tot') ? 'body' : 'soft');

        $nameText = $session->presenter?->name
            ?? $session->presenter_name
            ?? ($session->status === 'not_tot' ? 'Company event' : 'Nobody assigned');
        $nameTone = $session->status === 'skipped' ? 'soft' : ($session->status === 'not_tot' ? 'muted' : null);

        $opacity = match ($session->status) {
            'skipped' => .45,
            'not_tot' => .72,
            default => 1,
        };

        $rowMeta[$i] = [
            'subline' => $subline, 'stale' => $stale, 'subTone' => $subTone,
            'sublineMs' => $sublineMs[$subline] ?? $subline,
            'nameText' => $nameText, 'nameTone' => $nameTone, 'opacity' => $opacity,
            'nameTextMs' => $nameTextMs[$nameText] ?? $nameText,
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


<style>
    /* ── TOT year lineup, ported from docs/superpowers/specs/2026-07-27-tot-sessions-design.html ── */
    .tot-screen{--tot-eo:cubic-bezier(.23,1,.32,1);background:var(--canvas);border:1px solid var(--hairline);border-radius:14px;overflow:hidden;}
    .tot-mast{background:var(--red);padding:24px 26px 20px;}
    .tot-mast-k{font-size:11px;letter-spacing:.19em;text-transform:uppercase;color:rgba(255,255,255,.92);font-weight:600;}
    .tot-mast-y{font:600 54px/1 var(--font-mono);color:#fff;margin-top:8px;letter-spacing:-.03em;}
    .tot-mast-s{font-size:13px;color:rgba(255,255,255,.94);margin-top:7px;}
    .tot-yr-list{display:flex;flex-direction:column;gap:5px;align-items:flex-end;padding-top:4px;}
    .tot-yr{background:none;border:0;cursor:pointer;font:600 15px var(--font-mono);color:rgba(255,255,255,.72);padding:2px 0;transition:color 160ms var(--tot-eo);text-decoration:none;}
    .tot-yr:hover{color:rgba(255,255,255,.9);}
    .tot-yr[aria-selected="true"]{color:#fff;}
    .tot-chips{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;}
    .tot-chip{background:rgba(0,0,0,.15);border-radius:9px;padding:8px 13px;display:flex;align-items:baseline;gap:7px;}
    .tot-chip b{font:600 18px var(--font-mono);color:#fff;line-height:1;}
    .tot-chip span{font-size:11px;letter-spacing:.04em;color:#fff;font-weight:500;}

    .tot-invite{padding:26px 20px 6px;text-align:center;}
    .tot-invite h4{font-size:17px;font-weight:600;color:var(--ink);margin:0;}
    .tot-invite p{font-size:13.5px;color:var(--body);max-width:52ch;margin:8px auto 0;}

    .tot-list{padding:0 14px 10px;}
    .tot-row{position:relative;display:grid;grid-template-columns:56px minmax(0,1fr) auto;gap:16px;align-items:center;width:100%;text-align:left;background:transparent;border:0;border-top:1px solid var(--hairline);padding:14px 6px;cursor:pointer;font-family:inherit;transition:background 170ms var(--tot-eo);}
    .tot-row:hover,.tot-row[aria-expanded="true"]{background:var(--red-tint);}
    .tot-tile{border-radius:9px;padding:5px 0;text-align:center;transition:background 170ms var(--tot-eo),transform 170ms var(--tot-eo);}
    .tot-tile .m{font:600 11px var(--font-sans);letter-spacing:.14em;text-transform:uppercase;color:var(--muted);}
    .tot-tile .d{font:600 26px/1 var(--font-mono);color:var(--ink);margin-top:2px;letter-spacing:-.03em;}
    .tot-tile[data-tone="soft"] .d{color:var(--muted);}
    .tot-row:hover .tot-tile,.tot-row[aria-expanded="true"] .tot-tile{background:var(--red);transform:translateY(-1px);}
    .tot-row:hover .tot-tile .m,.tot-row:hover .tot-tile .d,.tot-row[aria-expanded="true"] .tot-tile .m,.tot-row[aria-expanded="true"] .tot-tile .d{color:#fff;}
    .tot-row:active .tot-tile{transform:scale(.96);}
    .tot-nm{font-size:19px;font-weight:600;color:var(--ink);line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;transition:color 160ms var(--tot-eo);}
    .tot-nm[data-tone="soft"]{color:var(--muted-soft);}
    .tot-nm[data-tone="muted"]{color:var(--muted);}
    .tot-row:hover .tot-nm,.tot-row[aria-expanded="true"] .tot-nm{color:var(--red-active);}
    .tot-sb{font-size:13px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--muted-soft);}
    .tot-sb[data-tone="amber"]{color:var(--amber);}
    .tot-sb[data-tone="body"]{color:var(--body);}
    .tot-sb[data-tone="soft"]{color:var(--muted-soft);}
    .tot-meta{display:flex;align-items:center;gap:12px;flex-shrink:0;}
    .tot-rx{font-size:13px;white-space:nowrap;color:var(--body);}
    .tot-rx b{font:600 11px var(--font-mono);color:var(--muted);margin-left:2px;}
    .tot-wt{font:600 11px var(--font-mono);color:var(--muted);}
    .tot-up{font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);font-weight:600;}
    .tot-chev{transition:transform 220ms var(--tot-eo);color:var(--muted);flex-shrink:0;}
    .tot-meta-mobile{display:none;gap:9px;align-items:center;margin-top:5px;font-size:12px;color:var(--muted);}
    .tot-meta-mobile b{font:600 11px var(--font-mono);color:var(--muted);margin-left:2px;}

    .tot-wrap{display:grid;grid-template-rows:0fr;transition:grid-template-rows 280ms var(--tot-eo);}
    .tot-wrap[data-open="1"]{grid-template-rows:1fr;}
    .tot-wrap>div{overflow:hidden;}
    .tot-panel{padding:2px 6px 22px 72px;background:var(--red-tint);border-radius:0 0 10px 10px;}
    .tot-panel-date{font:600 11px var(--font-mono);color:var(--muted);letter-spacing:.04em;text-transform:uppercase;}

    .tot-emo{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;border-radius:999px;border:1px solid var(--hairline);background:var(--card);font-size:14px;line-height:1;cursor:pointer;font-family:inherit;transition:transform 140ms var(--tot-eo),border-color 140ms var(--tot-eo),background 140ms var(--tot-eo);}
    .tot-emo:hover{border-color:var(--muted);}
    .tot-emo:active{transform:scale(.94);}
    .tot-emo[data-on="1"]{background:var(--red-tint);border-color:var(--red);}
    .tot-emo b{font:600 11px var(--font-mono);color:var(--muted);margin-left:3px;}
    .tot-emo[data-on="1"] b{color:var(--red-active);}
    .tot-lk{display:inline-flex;align-items:center;gap:6px;padding:6px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;color:var(--ink);text-decoration:none;background:var(--card);transition:border-color 160ms var(--tot-eo);}
    .tot-lk:hover{border-color:var(--red);}
    .tot-pillbtn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--hairline);border-radius:999px;background:var(--card);font-size:12.5px;color:var(--ink);font-family:inherit;cursor:pointer;transition:border-color 160ms var(--tot-eo),transform 140ms var(--tot-eo);}
    .tot-pillbtn:hover{border-color:var(--muted);}
    .tot-pillbtn:active{transform:scale(.97);}
    .tot-score{width:36px;height:32px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--hairline);border-radius:8px;background:var(--card);font:600 13px var(--font-mono);color:var(--ink);cursor:pointer;transition:border-color 140ms var(--tot-eo),transform 140ms var(--tot-eo);}
    .tot-score:hover{border-color:var(--red);}
    .tot-score:active{transform:scale(.94);}
    .tot-score[data-mine="1"]{border-color:var(--red);color:var(--red-active);}
    .tot-note{font-size:12px;color:var(--muted);line-height:1.6;}
    .tot-rule{border-top:1px solid var(--hairline-soft);margin-top:16px;padding-top:14px;}
    .tot-presenter-tag{font-size:11px;letter-spacing:.07em;text-transform:uppercase;font-weight:600;color:var(--red-active);background:#fff;border:1px solid var(--red);border-radius:5px;padding:1px 6px;}

    .tot-av{width:28px;height:28px;border-radius:50%;background:var(--hairline-soft);color:var(--muted);display:flex;align-items:center;justify-content:center;font:600 11px var(--font-mono);flex-shrink:0;}
    .tot-field{height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);font-family:inherit;outline:none;width:100%;}
    .tot-field:focus{border-color:var(--red);box-shadow:0 0 0 3px var(--red-tint);}
    .tot-lbl{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:600;margin-bottom:5px;display:block;}
    .tot-btn-p{height:38px;padding:0 16px;border:0;border-radius:8px;background:var(--red);color:#fff;font:500 13px var(--font-sans);cursor:pointer;transition:background 150ms var(--tot-eo),transform 140ms var(--tot-eo);}
    .tot-btn-p:hover{background:var(--red-active);}
    .tot-btn-p:active{transform:scale(.97);}
    .tot-btn-g{height:38px;padding:0 16px;border:1px solid var(--hairline);border-radius:8px;background:#fff;color:var(--ink);font:500 13px var(--font-sans);cursor:pointer;transition:border-color 150ms var(--tot-eo),transform 140ms var(--tot-eo);}
    .tot-btn-g:hover{border-color:var(--muted);}
    .tot-btn-g:active{transform:scale(.97);}

    @media (max-width:640px){
        .tot-mast-y{font-size:40px;}
        .tot-row{grid-template-columns:46px minmax(0,1fr) 14px;gap:12px;}
        .tot-tile .d{font-size:21px;}
        .tot-nm{font-size:16px;}
        .tot-panel{padding:2px 4px 18px 4px;}
        .tot-row .tot-meta{display:none;}
        .tot-meta-mobile{display:flex;}
        .tot-yr-list{flex-direction:row;gap:6px;}
        .tot-yr{background:rgba(255,255,255,.18);color:rgba(255,255,255,.8);border-radius:999px;padding:4px 11px;font-size:12px;}
        .tot-yr[aria-selected="true"]{background:#fff;color:var(--red-active);}
        .tot-chips{display:grid;grid-template-columns:1fr 1fr;}
        .tot-score{flex:1;width:auto;}
    }
    @media (prefers-reduced-motion:reduce){
        .tot-row,.tot-tile,.tot-wrap,.tot-emo,.tot-nm,.tot-yr,.tot-lk,.tot-chev,.tot-pillbtn,.tot-score,.tot-btn-p,.tot-btn-g{transition:none;}
        .tot-row:hover .tot-tile,.tot-row:active .tot-tile,.tot-emo:active,.tot-pillbtn:active,.tot-score:active,.tot-btn-p:active,.tot-btn-g:active{transform:none;}
    }
</style>

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
                <button type="button" class="tot-row" @click="open = !open" :aria-expanded="open" style="opacity:{{ $rm['opacity'] }}">
                    <div class="tot-tile" @if ($session->status === 'skipped') data-tone="soft" @endif>
                        <div class="m">{{ $session->session_date->format('M') }}</div>
                        <div class="d">{{ $session->session_date->format('d') }}</div>
                    </div>
                    <div style="min-width:0;">
                        <div class="tot-nm" @if ($rm['nameTone']) data-tone="{{ $rm['nameTone'] }}" @endif x-text="$store.ui.lang==='en' ? @js($rm['nameText']) : @js($rm['nameTextMs'])">{{ $rm['nameText'] }}</div>
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
                                    <span class="tot-fly" x-show="flyout === 'rate'" x-cloak
                                          @mouseleave="flyout = null" @keydown.escape.window="flyout = null"
                                          x-data="{ noting: false }">
                                        <template x-if="!noting">
                                            <span style="display:flex;align-items:center;gap:6px;">
                                                @foreach ([1, 2, 3, 4, 5] as $n)
                                                    <button type="button" class="tot-sc" :data-mine="myScore === {{ $n }} ? '1' : null"
                                                            @click="rate({{ $n }}); noting = true">{{ $n }}</button>
                                                @endforeach
                                                <span class="tot-note" style="font-size:11.5px;padding-left:4px;max-width:210px;"
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

                            {{-- Discussion --}}
                            <div class="tot-rule">
                                <div class="tot-note">{{ $sessionCommentCount }} <span x-text="$store.ui.lang==='en' ? 'comments' : 'komen'">comments</span></div>
                            </div>

                            {{-- Edit this slot. HR and management change everything; the presenter
                                 of this slot changes only the material (topic, description, links,
                                 Knowledge Bank cross-link), never the roster fields. Same form for
                                 both: the presenter's version is the privileged version minus the
                                 privileged fields, guarded here to match TotController::update(). --}}
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
