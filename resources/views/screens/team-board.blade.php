@extends('layouts.app')

@section('screen')
{{-- Reciprocal of the "see all staff" icon on the personal board screen: this board
     is reached by that one-way shortcut, so offer a one-tap way back to My tasks
     rather than leaving the browser Back button as the only exit. --}}
<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
    <a href="{{ route('app.screen', 'board') }}" class="uj-btn-ghost" style="font-size:12px;padding:7px 12px;text-decoration:none;">
        <span x-text="$store.ui.lang==='en' ? '← My tasks' : '← Tugasan saya'">← My tasks</span>
    </a>
</div>
@include('partials.guide', [
    'key' => 'team-board',
    'en'  => [
        'title' => 'Team board — all tasks',
        'body'  => 'A read-only, company-wide view of every staff member\'s work: one row per person, showing what they are carrying. Click a person to see their tasks in a window, without leaving this screen.',
        'who'   => 'Management · HR · Immediate superiors',
        'steps' => [
            'Each row is one person, ranked by open items. Click a row (or press Enter) to open that person\'s tasks.',
            'Search a name or a task title, or switch on Overdue / Blocked to see only people carrying that trouble.',
            'Inside a person\'s window, filter by type, priority, project, label or status to narrow their own list.',
            'Click a sortable column heading to sort by it; click again to reverse the order.',
            'Press Escape, or the close button, to leave a person\'s window — the table underneath keeps your search, toggles and sort exactly as you left them.',
        ],
    ],
    'ms'  => [
        'title' => 'Papan pasukan — semua tugasan',
        'body'  => 'Paparan baca-sahaja seluruh syarikat bagi kerja setiap staf: satu baris bagi setiap orang, menunjukkan apa yang mereka pikul. Klik seseorang untuk lihat tugasan mereka dalam satu tetingkap, tanpa perlu tinggalkan skrin ini.',
        'who'   => 'Pengurusan · HR · Penyelia terdekat',
        'steps' => [
            'Setiap baris mewakili seorang staf, disusun mengikut item terbuka. Klik baris itu (atau tekan Enter) untuk buka tugasan orang berkenaan.',
            'Cari mengikut nama atau tajuk tugasan, atau hidupkan suis Lewat / Tersekat untuk lihat sesiapa sahaja yang menghadapi masalah itu.',
            'Di dalam tetingkap seseorang, tapis mengikut jenis, keutamaan, projek, label atau status untuk sempitkan senarai mereka sendiri.',
            'Klik kepala lajur yang boleh disusun untuk susun mengikutnya; klik lagi untuk terbalikkan susunan.',
            'Tekan Escape, atau butang tutup, untuk keluar dari tetingkap seseorang — jadual di bawah kekal dengan carian, suis dan susunan seperti sebelumnya.',
        ],
    ],
])

@php
    // One table, one line per person — ranked by open count descending. See
    // the design doc's "Person table". teamPeople itself stays ordered by
    // name (that contract belongs to BuildsWorkData::teamBoardData()); this
    // re-sort is presentation-only, scoped to this view.
    $tbPeopleByOpen = $teamPeople->sortByDesc('open')->values();
    // A zero renders as an en dash rather than "0" so a non-zero count is
    // what draws the eye down the column.
    $tbZero = fn (int $n) => $n > 0 ? (string) $n : '—';

    // The search box matches a person's name AND any of their own task
    // titles ("payroll" surfaces the people who have payroll work), so each
    // row carries a combined, lowercased haystack built once here.
    $tbRowsByOwner = $teamRows->groupBy('owner_id');
    $tbSearchText = fn (array $p) => mb_strtolower(
        trim($p['name'].' '.$tbRowsByOwner->get($p['id'], collect())->pluck('item.title')->implode(' '))
    );

    // Distinct projects actually referenced among today's rows, for the
    // floating window's task-level project filter — narrower than, and
    // always in sync with, loading every active tenant project.
    $tbProjects = $teamRows->pluck('item.projectRef')->filter()->unique('id')->sortBy('name')->values();
    $tbLabelDef = \App\Models\WorkItem::LABELS;
@endphp

<div x-data="teamBoard(@js($tbPeopleByOpen))">
    {{-- ═══════ Filter bar — always-visible, person-level controls ═══════ --}}
    <div class="tb-filters">
        <input x-model="search" @input="applyFilter()" type="search"
               class="tb-search"
               :placeholder="$store.ui.lang==='en' ? 'Search a name or task…' : 'Cari nama atau tugasan…'" />

        <button type="button" class="tb-chip" @click="toggleOverdue()" :data-on="overdueOnly ? '' : null">
            <span x-text="$store.ui.lang==='en' ? 'Overdue' : 'Lewat'">Overdue</span>
        </button>
        <button type="button" class="tb-chip" @click="toggleBlocked()" :data-on="blockedOnly ? '' : null">
            <span x-text="$store.ui.lang==='en' ? 'Blocked' : 'Tersekat'">Blocked</span>
        </button>

        <span style="flex:1;"></span>

        <button type="button" class="tb-clear" @click="clearAll()" x-show="search || overdueOnly || blockedOnly" x-cloak
                x-text="$store.ui.lang==='en' ? 'Clear' : 'Kosongkan'">Clear</button>
    </div>

    {{-- ═══════ The one table: one line per person, sortable, click to open ═══════ --}}
    <div class="tb-strip">
        <div class="tb-strip-head tb-strip-cols">
            <span>
                <button type="button" class="tb-sortbtn" @click="sortPeople('person')">
                    <span x-text="$store.ui.lang==='en' ? 'Person' : 'Orang'">Person</span>
                    <span class="tb-sort-arrow" x-show="peopleSort.key==='person'" x-cloak x-text="peopleSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span>
                <button type="button" class="tb-sortbtn" @click="sortPeople('open')">
                    <span x-text="$store.ui.lang==='en' ? 'Open' : 'Terbuka'">Open</span>
                    <span class="tb-sort-arrow" x-show="peopleSort.key==='open'" x-cloak x-text="peopleSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span>
                <button type="button" class="tb-sortbtn" @click="sortPeople('overdue')">
                    <span x-text="$store.ui.lang==='en' ? 'Overdue' : 'Lewat'">Overdue</span>
                    <span class="tb-sort-arrow" x-show="peopleSort.key==='overdue'" x-cloak x-text="peopleSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span>
                <button type="button" class="tb-sortbtn" @click="sortPeople('blocked')">
                    <span x-text="$store.ui.lang==='en' ? 'Blocked' : 'Tersekat'">Blocked</span>
                    <span class="tb-sort-arrow" x-show="peopleSort.key==='blocked'" x-cloak x-text="peopleSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span>
                <button type="button" class="tb-sortbtn" @click="sortPeople('review')">
                    <span x-text="$store.ui.lang==='en' ? 'In review' : 'Disemak'">In review</span>
                    <span class="tb-sort-arrow" x-show="peopleSort.key==='review'" x-cloak x-text="peopleSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
        </div>

        <div x-ref="peopleBody">
            @foreach ($tbPeopleByOpen as $p)
                <div class="tb-strip-row tb-strip-cols"
                     tabindex="0" role="button"
                     data-person-id="{{ $p['id'] }}"
                     data-person-name="{{ mb_strtolower($p['name']) }}"
                     data-search="{{ $tbSearchText($p) }}"
                     data-open="{{ $p['open'] }}"
                     data-overdue="{{ $p['overdue'] }}"
                     data-blocked="{{ $p['blocked'] }}"
                     data-review="{{ $p['in_review'] }}"
                     :data-active="win.show && win.person && win.person.id === {{ $p['id'] }} ? '' : null">
                    <span class="tb-strip-who">
                        <span class="tb-av" style="background:{{ $p['avatar_color'] ?? 'var(--muted)' }};">{{ $p['initials'] }}</span>
                        <span style="min-width:0;">
                            <span class="tb-strip-name">{{ $p['name'] }}</span>
                            <span class="tb-strip-sub">{{ trim(($p['position'] ?? '').' · '.($p['department'] ?? ''), ' ·') ?: '—' }}</span>
                        </span>
                    </span>
                    <span class="tb-num {{ $p['open'] === 0 ? 'tb-num--zero' : '' }}">{{ $tbZero($p['open']) }}</span>
                    <span class="tb-num {{ $p['overdue'] > 0 ? 'tb-num--overdue' : 'tb-num--zero' }}">{{ $tbZero($p['overdue']) }}</span>
                    <span class="tb-num {{ $p['blocked'] === 0 ? 'tb-num--zero' : '' }}">{{ $tbZero($p['blocked']) }}</span>
                    <span class="tb-num {{ $p['in_review'] === 0 ? 'tb-num--zero' : '' }}">{{ $tbZero($p['in_review']) }}</span>
                </div>
            @endforeach
        </div>

        @if ($tbPeopleByOpen->isEmpty())
            <div class="tb-empty">
                <div class="tb-empty-title" x-text="$store.ui.lang==='en' ? 'No tasks yet' : 'Belum ada tugasan'">No tasks yet</div>
                <div class="tb-empty-body" x-text="$store.ui.lang==='en' ? 'Nobody has any work items on their board.' : 'Tiada sesiapa mempunyai item kerja pada papan mereka.'">Nobody has any work items on their board.</div>
            </div>
        @endif

        {{-- Filtered-to-zero state. Only reachable when people exist server-side
             but the active search/toggles match none of them. --}}
        @if ($tbPeopleByOpen->isNotEmpty())
            <div class="tb-empty" x-show="visibleCount === 0" x-cloak>
                <div class="tb-empty-title" x-text="$store.ui.lang==='en' ? 'No matches' : 'Tiada padanan'">No matches</div>
                <div class="tb-empty-body" x-text="$store.ui.lang==='en' ? 'Try widening the search or turning off a toggle.' : 'Cuba luaskan carian atau matikan suis.'">Try widening the search or turning off a toggle.</div>
            </div>
        @endif
    </div>

    {{-- ═══════ Floating window: one person's tasks ═══════
         Reuses the personal board's .wd-* slide-over shell wholesale (see
         resources/css/app.css and board.blade.php's drawer) so it looks and
         moves identically — 560px, 280ms cubic-bezier(.32,.72,0,1), the same
         prefers-reduced-motion cross-fade. Every task line below is already
         rendered server-side from $teamRows; opening a person only toggles
         which lines are visible (resources/js/team-board.js's
         openWindow()/applyWinFilter()) — no fetch, nothing here writes. --}}
    <template x-teleport="body">
    <div>
        <div class="wd-scrim" x-show="win.show" x-cloak :data-open="win.open ? '' : null" @click="closeWindow()"></div>

        <aside class="wd" x-show="win.show" x-cloak :data-open="win.open ? '' : null" x-ref="winEl"
               tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="tb-win-name"
               @keydown.escape.window="win.show && closeWindow()" @keydown.tab="trapFocusWindow($event)">

            <div class="wd-head" style="gap:12px;">
                <span class="tb-av" :style="'background:' + (win.person ? (win.person.avatar_color || 'var(--muted)') : 'var(--muted)')"
                      x-text="win.person ? win.person.initials : ''"></span>
                <div style="min-width:0;flex:1;">
                    <h2 id="tb-win-name" class="wd-title" style="margin:0;font-size:16px;" x-text="win.person ? win.person.name : ''"></h2>
                    <p class="wd-sub" style="margin:2px 0 0;" x-text="winPersonSub"></p>
                </div>
                <button type="button" class="wd-ico" @click="closeWindow()" :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="wd-body">
                <p class="tb-win-summary" x-text="winSummary"></p>

                {{-- Task-level filters — these narrow only the tasks shown below,
                     for this one person. They never touch the person table's own
                     search/toggles/sort behind this window. --}}
                <div class="tb-win-filters">
                    <select class="tb-select" x-model="win.typeFilter" @change="applyWinFilter()">
                        <option value="" x-text="$store.ui.lang==='en' ? 'Any type' : 'Sebarang jenis'">Any type</option>
                        <option value="task">Task</option>
                        <option value="assignment">Assignment</option>
                        <option value="adhoc">Adhoc</option>
                    </select>
                    <select class="tb-select" x-model="win.priorityFilter" @change="applyWinFilter()">
                        <option value="" x-text="$store.ui.lang==='en' ? 'Any priority' : 'Sebarang keutamaan'">Any priority</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                    <select class="tb-select" x-model="win.projectFilter" @change="applyWinFilter()">
                        <option value="" x-text="$store.ui.lang==='en' ? 'Any project' : 'Sebarang projek'">Any project</option>
                        @foreach ($tbProjects as $proj)
                            <option value="{{ $proj->id }}">{{ $proj->name }}</option>
                        @endforeach
                    </select>
                    @foreach ($tbLabelDef as $lk => [$lname, $lcolor])
                        <button type="button" class="tb-chip" @click="setWinLabelFilter('{{ $lk }}')"
                                :style="win.labelFilter === '{{ $lk }}' ? { background: '{{ $lcolor }}', color: '#fff', borderColor: '{{ $lcolor }}' } : {}">{{ $lname }}</button>
                    @endforeach
                </div>

                @php
                    $tbWinCols = ['todo' => ['To Do', 'To Do'], 'prog' => ['In Progress', 'Sedang Jalan'], 'review' => ['In Review', 'Disemak'], 'done' => ['Done', 'Selesai']];
                    $tbRowsByStatus = $teamRows->groupBy(fn ($row) => $row['item']->status);
                @endphp
                <div class="tb-win-kanban" x-ref="winTaskBody">
                    @foreach ($tbWinCols as $sk => $sl)
                        <div class="tb-win-col">
                            <div class="tb-win-col-head">
                                <span x-text="$store.ui.lang==='en' ? @js($sl[0]) : @js($sl[1])">{{ $sl[0] }}</span>
                            </div>
                            <div class="tb-win-col-cards">
                                @foreach ($tbRowsByStatus->get($sk, collect()) as $row)
                                    @include('partials.work-card', ['c' => $row['item'], 'compact' => true, 'owner' => ['id' => $row['owner_id']]])
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                @if ($teamRows->isNotEmpty())
                    <div class="tb-empty" x-show="winVisibleCount === 0" x-cloak>
                        <div class="tb-empty-title" x-text="$store.ui.lang==='en' ? 'No matching tasks' : 'Tiada tugasan sepadan'">No matching tasks</div>
                        <div class="tb-empty-body" x-text="$store.ui.lang==='en' ? 'Try widening the filters.' : 'Cuba luaskan penapis.'">Try widening the filters.</div>
                    </div>
                @endif
            </div>
        </aside>
    </div>
    </template>
</div>
@endsection
