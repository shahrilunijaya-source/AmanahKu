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
        'body'  => 'A read-only, company-wide view of every staff member\'s work: a summary strip ranking who is carrying what, and one filterable table underneath holding every task. Use it to spot who is overloaded, what is overdue or blocked, and where a project stands across everyone.',
        'who'   => 'Management · HR · Immediate superiors',
        'steps' => [
            'The strip at the top ranks people by open items. Click a name to narrow the table to just them; click it again to clear.',
            'Search a name or a task title, or flip the Overdue / Blocked switches for a fast read on trouble.',
            'Open "More filters" for type, priority, project, label or a due window — it shows a count whenever one of those is active.',
            'Click a sortable heading, on the strip or the table, to sort by it; click again to reverse the order.',
        ],
    ],
    'ms'  => [
        'title' => 'Papan pasukan — semua tugasan',
        'body'  => 'Paparan baca-sahaja seluruh syarikat bagi kerja setiap staf: satu jalur ringkasan menyusun siapa memikul apa, dan satu jadual boleh ditapis di bawahnya memegang semua tugasan. Guna untuk kesan siapa terlalu bebanan, apa yang lewat atau tersekat, dan kedudukan sesuatu projek merentas semua orang.',
        'who'   => 'Pengurusan · HR · Penyelia terdekat',
        'steps' => [
            'Jalur di atas menyusun orang mengikut item terbuka. Klik nama untuk sempitkan jadual kepada orang itu sahaja; klik lagi untuk kosongkan.',
            'Cari mengikut nama atau tajuk tugasan, atau tukar suis Lewat / Tersekat untuk bacaan pantas tentang masalah.',
            'Buka "Lagi penapis" untuk jenis, keutamaan, projek, label atau tetingkap tarikh akhir — ia menunjukkan kiraan apabila mana-mana daripadanya aktif.',
            'Klik kepala yang boleh disusun, sama ada di jalur atau jadual, untuk susun mengikutnya; klik lagi untuk terbalikkan susunan.',
        ],
    ],
])

@php
    // Summary strip: ranked by open count descending — see the design doc's
    // "Summary strip". teamPeople itself stays ordered by name (that contract
    // belongs to BuildsWorkData::teamBoardData()); this re-sort is
    // presentation-only, scoped to this view.
    $tbPeopleByOpen = $teamPeople->sortByDesc('open')->values();
    // A zero renders as an en dash rather than "0" so a non-zero count is
    // what draws the eye down the column — see the design doc.
    $tbZero = fn (int $n) => $n > 0 ? (string) $n : '—';

    // Distinct projects actually referenced among today's rows, for the
    // "More filters" project select — narrower than, and always in sync
    // with, loading every active tenant project.
    $tbProjects = $teamRows->pluck('item.projectRef')->filter()->unique('id')->sortBy('name')->values();
    $tbLabelDef = \App\Models\WorkItem::LABELS;
@endphp

<div x-data="teamBoard()">
    {{-- ═══════ Summary strip: one row per person, sortable, click to filter ═══════ --}}
    <div class="tb-strip">
        <div class="tb-strip-head tb-strip-cols">
            <span>
                <button type="button" class="tb-sortbtn" @click="sortStrip('person')">
                    <span x-text="$store.ui.lang==='en' ? 'Person' : 'Orang'">Person</span>
                    <span class="tb-sort-arrow" x-show="stripSort.key==='person'" x-cloak x-text="stripSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span>
                <button type="button" class="tb-sortbtn" @click="sortStrip('open')">
                    <span x-text="$store.ui.lang==='en' ? 'Open' : 'Terbuka'">Open</span>
                    <span class="tb-sort-arrow" x-show="stripSort.key==='open'" x-cloak x-text="stripSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span>
                <button type="button" class="tb-sortbtn" @click="sortStrip('overdue')">
                    <span x-text="$store.ui.lang==='en' ? 'Overdue' : 'Lewat'">Overdue</span>
                    <span class="tb-sort-arrow" x-show="stripSort.key==='overdue'" x-cloak x-text="stripSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span>
                <button type="button" class="tb-sortbtn" @click="sortStrip('blocked')">
                    <span x-text="$store.ui.lang==='en' ? 'Blocked' : 'Tersekat'">Blocked</span>
                    <span class="tb-sort-arrow" x-show="stripSort.key==='blocked'" x-cloak x-text="stripSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span>
                <button type="button" class="tb-sortbtn" @click="sortStrip('review')">
                    <span x-text="$store.ui.lang==='en' ? 'In review' : 'Disemak'">In review</span>
                    <span class="tb-sort-arrow" x-show="stripSort.key==='review'" x-cloak x-text="stripSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
        </div>

        <div x-ref="stripBody">
            @foreach ($tbPeopleByOpen as $p)
                <button type="button" class="tb-strip-row tb-strip-cols"
                        @click="filterByPerson({{ $p['id'] }})"
                        :data-active="personFilter === {{ $p['id'] }} ? '' : null"
                        data-person-id="{{ $p['id'] }}"
                        data-person-name="{{ mb_strtolower($p['name']) }}"
                        data-open="{{ $p['open'] }}"
                        data-overdue="{{ $p['overdue'] }}"
                        data-blocked="{{ $p['blocked'] }}"
                        data-review="{{ $p['in_review'] }}">
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
                </button>
            @endforeach
        </div>
    </div>

    {{-- ═══════ Filter bar — always-visible controls ═══════ --}}
    <div class="tb-filters">
        <input x-model="search" @input="applyFilter()" type="search"
               class="tb-search"
               :placeholder="$store.ui.lang==='en' ? 'Search a name or task…' : 'Cari nama atau tugasan…'" />

        @foreach (['todo' => ['To Do', 'To Do'], 'prog' => ['In Progress', 'Sedang Jalan'], 'review' => ['In Review', 'Disemak'], 'done' => ['Done', 'Selesai']] as $sk => $sl)
            <button type="button" class="tb-chip" @click="toggleStatus('{{ $sk }}')" :data-on="statusOn('{{ $sk }}') ? '' : null">
                <span x-text="$store.ui.lang==='en' ? @js($sl[0]) : @js($sl[1])">{{ $sl[0] }}</span>
            </button>
        @endforeach

        <button type="button" class="tb-chip" @click="toggleOverdue()" :data-on="overdueOnly ? '' : null">
            <span x-text="$store.ui.lang==='en' ? 'Overdue' : 'Lewat'">Overdue</span>
        </button>
        <button type="button" class="tb-chip" @click="toggleBlocked()" :data-on="blockedOnly ? '' : null">
            <span x-text="$store.ui.lang==='en' ? 'Blocked' : 'Tersekat'">Blocked</span>
        </button>

        <span style="flex:1;"></span>

        <button type="button" class="tb-chip" @click="moreOpen = !moreOpen" :aria-expanded="moreOpen ? 'true' : 'false'" :data-on="moreOpen ? '' : null">
            <span x-text="$store.ui.lang==='en' ? 'More filters' : 'Lagi penapis'">More filters</span>
            <span class="tb-chip-badge" x-show="moreFilterCount > 0" x-cloak x-text="moreFilterCount"></span>
        </button>
    </div>

    {{-- ═══════ "More filters" disclosure: type, priority, project, label, due window ═══════ --}}
    <div class="tb-more-panel" x-show="moreOpen" x-cloak>
        <div class="tb-more-field">
            <label x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</label>
            <select class="tb-select" x-model="typeFilter" @change="applyFilter()">
                <option value="" x-text="$store.ui.lang==='en' ? 'Any' : 'Mana-mana'">Any</option>
                <option value="task">Task</option>
                <option value="assignment">Assignment</option>
                <option value="adhoc">Adhoc</option>
            </select>
        </div>

        <div class="tb-more-field">
            <label x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</label>
            <select class="tb-select" x-model="priorityFilter" @change="applyFilter()">
                <option value="" x-text="$store.ui.lang==='en' ? 'Any' : 'Mana-mana'">Any</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
            </select>
        </div>

        <div class="tb-more-field">
            <label x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'">Project</label>
            <select class="tb-select" x-model="projectFilter" @change="applyFilter()">
                <option value="" x-text="$store.ui.lang==='en' ? 'Any' : 'Mana-mana'">Any</option>
                @foreach ($tbProjects as $proj)
                    <option value="{{ $proj->id }}">{{ $proj->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="tb-more-field">
            <label x-text="$store.ui.lang==='en' ? 'Label' : 'Label'">Label</label>
            @foreach ($tbLabelDef as $lk => [$lname, $lcolor])
                <button type="button" class="tb-chip" @click="setLabelFilter('{{ $lk }}')"
                        :style="labelFilter === '{{ $lk }}' ? { background: '{{ $lcolor }}', color: '#fff', borderColor: '{{ $lcolor }}' } : {}">{{ $lname }}</button>
            @endforeach
        </div>

        <div class="tb-more-field">
            <label x-text="$store.ui.lang==='en' ? 'Due' : 'Tarikh akhir'">Due</label>
            <select class="tb-select" x-model="dueWindow" @change="applyFilter()">
                <option value="" x-text="$store.ui.lang==='en' ? 'Any' : 'Mana-mana'">Any</option>
                <option value="overdue" x-text="$store.ui.lang==='en' ? 'Overdue' : 'Lewat'">Overdue</option>
                <option value="week" x-text="$store.ui.lang==='en' ? 'This week' : 'Minggu ini'">This week</option>
                <option value="none" x-text="$store.ui.lang==='en' ? 'No date' : 'Tiada tarikh'">No date</option>
            </select>
        </div>

        <button type="button" class="tb-clear" @click="clearAll()" x-text="$store.ui.lang==='en' ? 'Clear all filters' : 'Kosongkan semua penapis'">Clear all filters</button>
    </div>

    {{-- ═══════ Table: one row per work item, sortable by Person / Status / Priority / Due ═══════ --}}
    <div class="tb-table">
        <div class="tb-thead tb-cols">
            <span class="tb-cell tb-cell--person">
                <button type="button" class="tb-sortbtn" @click="sortTable('person')">
                    <span x-text="$store.ui.lang==='en' ? 'Person' : 'Orang'">Person</span>
                    <span class="tb-sort-arrow" x-show="tableSort.key==='person'" x-cloak x-text="tableSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span class="tb-cell tb-cell--task" x-text="$store.ui.lang==='en' ? 'Task' : 'Tugasan'">Task</span>
            <span class="tb-cell tb-cell--type" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span>
            <span class="tb-cell tb-cell--status">
                <button type="button" class="tb-sortbtn" @click="sortTable('status')">
                    <span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span>
                    <span class="tb-sort-arrow" x-show="tableSort.key==='status'" x-cloak x-text="tableSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span class="tb-cell tb-cell--priority">
                <button type="button" class="tb-sortbtn" @click="sortTable('priority')">
                    <span x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</span>
                    <span class="tb-sort-arrow" x-show="tableSort.key==='priority'" x-cloak x-text="tableSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span class="tb-cell tb-cell--project" x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'">Project</span>
            <span class="tb-cell tb-cell--due">
                <button type="button" class="tb-sortbtn" @click="sortTable('due')">
                    <span x-text="$store.ui.lang==='en' ? 'Due' : 'Tarikh akhir'">Due</span>
                    <span class="tb-sort-arrow" x-show="tableSort.key==='due'" x-cloak x-text="tableSort.dir==='asc' ? '▲' : '▼'"></span>
                </button>
            </span>
            <span class="tb-cell tb-cell--labels" x-text="$store.ui.lang==='en' ? 'Labels' : 'Label'">Labels</span>
        </div>

        <div x-ref="tableBody">
            @forelse ($teamRows as $row)
                @include('partials.team-board-row', ['row' => $row])
            @empty
                <div class="tb-empty">
                    <div class="tb-empty-title" x-text="$store.ui.lang==='en' ? 'No tasks yet' : 'Belum ada tugasan'">No tasks yet</div>
                    <div class="tb-empty-body" x-text="$store.ui.lang==='en' ? 'Nobody has any work items on their board.' : 'Tiada sesiapa mempunyai item kerja pada papan mereka.'">Nobody has any work items on their board.</div>
                </div>
            @endforelse
        </div>

        {{-- Filtered-to-zero state. Only reachable when rows exist server-side
             but the active filters match none of them. --}}
        @if ($teamRows->isNotEmpty())
            <div class="tb-empty" x-show="visibleCount === 0" x-cloak>
                <div class="tb-empty-title" x-text="$store.ui.lang==='en' ? 'No matches' : 'Tiada padanan'">No matches</div>
                <div class="tb-empty-body" x-text="$store.ui.lang==='en' ? 'Try widening the filters.' : 'Cuba luaskan penapis.'">Try widening the filters.</div>
            </div>
        @endif
    </div>
</div>
@endsection
