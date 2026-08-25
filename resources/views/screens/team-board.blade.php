@extends('layouts.app')

@section('screen')
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
<div x-data="teamBoard(@js($tbPeopleByOpen), @js($tbLabelDef), @js([
    'defaultId' => $assignableEmployees->first()['id'] ?? null,
    'show' => $errors->getBag('assign')->any(),
    'employeeId' => old('employee_id') ? (int) old('employee_id') : null,
]))">
{{-- Reciprocal of the "see all staff" icon on the personal board screen: this board
     is reached by that one-way shortcut, so offer a one-tap way back to My tasks
     rather than leaving the browser Back button as the only exit. --}}
<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:14px;">
    @if (($canAssign ?? false) && $assignableEmployees->isNotEmpty())
        <button type="button" class="uj-btn-primary" style="font-size:12px;padding:7px 14px;" @click="openAssign(null, $event.currentTarget)">
            <span x-text="$store.ui.lang==='en' ? '+ Assign task' : '+ Beri tugas'">+ Assign task</span>
        </button>
    @endif
    <a href="{{ route('app.screen', 'board') }}" class="uj-btn-ghost" style="font-size:12px;padding:7px 12px;text-decoration:none;">
        <span x-text="$store.ui.lang==='en' ? '← My tasks' : '← Tugasan saya'">← My tasks</span>
    </a>
</div>
@include('partials.guide', [
    'key' => 'team-board',
    'en'  => [
        'title' => 'Team board — all tasks',
        'body'  => 'A company-wide view of every staff member\'s work: one row per person, showing what they are carrying. Click a person to see their tasks in a window, without leaving this screen. Click a task there to open it and comment — the details themselves stay read-only.',
        'who'   => 'Management · HR · Immediate superiors',
        'steps' => [
            'Each row is one person, ranked by open items. Click a row (or press Enter) to open that person\'s tasks.',
            'Search a name or a task title, or switch on Overdue / Blocked to see only people carrying that trouble.',
            'Inside a person\'s window, filter by type, priority, project, label or status to narrow their own list.',
            'Click a task card to open it — read its details and leave a comment. Only its owner can edit it.',
            'Click a sortable column heading to sort by it; click again to reverse the order.',
            'Press Escape, or the close button, to leave a person\'s window — the table underneath keeps your search, toggles and sort exactly as you left them.',
        ],
    ],
    'ms'  => [
        'title' => 'Papan pasukan — semua tugasan',
        'body'  => 'Paparan seluruh syarikat bagi kerja setiap staf: satu baris bagi setiap orang, menunjukkan apa yang mereka pikul. Klik seseorang untuk lihat tugasan mereka dalam satu tetingkap, tanpa perlu tinggalkan skrin ini. Klik satu tugasan di situ untuk buka dan beri komen — butirannya sendiri kekal baca-sahaja.',
        'who'   => 'Pengurusan · HR · Penyelia terdekat',
        'steps' => [
            'Setiap baris mewakili seorang staf, disusun mengikut item terbuka. Klik baris itu (atau tekan Enter) untuk buka tugasan orang berkenaan.',
            'Cari mengikut nama atau tajuk tugasan, atau hidupkan suis Lewat / Tersekat untuk lihat sesiapa sahaja yang menghadapi masalah itu.',
            'Di dalam tetingkap seseorang, tapis mengikut jenis, keutamaan, projek, label atau status untuk sempitkan senarai mereka sendiri.',
            'Klik kad tugasan untuk buka — baca butirannya dan tinggalkan komen. Hanya pemiliknya boleh menyuntingnya.',
            'Klik kepala lajur yang boleh disusun untuk susun mengikutnya; klik lagi untuk terbalikkan susunan.',
            'Tekan Escape, atau butang tutup, untuk keluar dari tetingkap seseorang — jadual di bawah kekal dengan carian, suis dan susunan seperti sebelumnya.',
        ],
    ],
])

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
         A centered popup (.tb-win-modal), not the personal board's right-anchored
         slide-over — wide enough to hold the same 4-column kanban side-by-side, the
         way board.blade.php lays its own columns out. Still reuses .wd-scrim/.wd-head/
         .wd-ico/.wd-body wholesale (those are already shell-agnostic); only the outer
         .wd shell itself is swapped for .tb-win-modal (see resources/css/app.css).
         Every card below is already rendered server-side from $teamRows; opening a
         person only toggles which cards are visible (resources/js/team-board.js's
         openWindow()/applyWinFilter()) — no fetch, nothing here writes. --}}
    <template x-teleport="body">
    <div>
        <div class="wd-scrim" x-show="win.show" x-cloak :data-open="win.open ? '' : null" @click="closeWindow()"></div>

        <aside class="tb-win-modal" x-show="win.show" x-cloak :data-open="win.open ? '' : null" x-ref="winEl"
               tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="tb-win-name"
               @keydown.escape.window="win.show && !assign.show && !drawer.show && closeWindow()" @keydown.tab="trapFocusWindow($event)">

            <div class="wd-head" style="gap:12px;">
                <span class="tb-av" :style="'background:' + (win.person ? (win.person.avatar_color || 'var(--muted)') : 'var(--muted)')"
                      x-text="win.person ? win.person.initials : ''"></span>
                <div style="min-width:0;flex:1;">
                    <h2 id="tb-win-name" class="wd-title" style="margin:0;font-size:16px;" x-text="win.person ? win.person.name : ''"></h2>
                    <p class="wd-sub" style="margin:2px 0 0;" x-text="winPersonSub"></p>
                </div>
                @if (($canAssign ?? false) && $assignableEmployees->isNotEmpty())
                    <button type="button" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;flex-shrink:0;"
                            @click="openAssign(win.person.id, $event.currentTarget)">
                        <span x-text="$store.ui.lang==='en' ? 'Assign task' : 'Beri tugas'">Assign task</span>
                    </button>
                @endif
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
                        <div class="tb-win-col" data-status="{{ $sk }}">
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

    {{-- Card detail drawer, view + comment only — no edit, no move (see
         partials.work-drawer and resources/js/team-board.js's drawer.* code). --}}
    @include('partials.work-drawer', ['interactive' => false])

    {{-- ═══════ Assign-task modal — teleported to body, its own scrim/shell
         (see .tb-assign-scrim/.tb-assign-modal in app.css) so it can layer
         above the person window when opened from that window's own button.
         A plain form POST to the existing work.assign route/controller —
         submitting reloads the page, same as the profile screen's own
         assign form does today. Gated the same as the two trigger buttons —
         not just hidden client-side, since $assignableEmployees is the full
         tenant-wide active roster and has no business reaching a browser
         that has no way to act on it. ═══════ --}}
    @if (($canAssign ?? false) && $assignableEmployees->isNotEmpty())
    <template x-teleport="body">
    <div>
        <div class="tb-assign-scrim" x-show="assign.show" x-cloak :data-open="assign.open ? '' : null" @click="closeAssign()"></div>

        <div class="tb-assign-modal" x-show="assign.show" x-cloak :data-open="assign.open ? '' : null"
             role="dialog" aria-modal="true" aria-labelledby="tb-assign-title"
             @keydown.escape.window="assign.show && closeAssign()">
            <form method="post" :action="'/app/board/assign/' + assign.employeeId">
                @csrf
                {{-- Never read by the controller (the URL path segment above is what it
                     acts on) — this is purely so a validation error's back()-redirect
                     can reopen the modal already pointed at the right person, via
                     old('employee_id') feeding assignInit above. --}}
                <input type="hidden" name="employee_id" :value="assign.employeeId" />

                <div class="wd-head">
                    <span id="tb-assign-title" style="font-size:13px;font-weight:600;color:var(--ink);flex:1;"
                          x-text="$store.ui.lang==='en' ? 'Assign a task' : 'Beri tugas'">Assign a task</span>
                    <button type="button" class="wd-ico" @click="closeAssign()" :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="wd-body">
                    @if ($errors->getBag('assign')->any())
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;margin-bottom:14px;">{{ $errors->getBag('assign')->first() }}</div>
                    @endif

                    <input type="text" name="title" maxlength="160" required value="{{ old('title') }}" x-ref="assignTitleEl"
                           class="wd-title" style="width:100%;border-color:var(--hairline);"
                           x-bind:placeholder="$store.ui.lang==='en' ? 'Task title' : 'Tajuk tugas'" />

                    <div class="wd-props" style="margin-top:14px;">
                        <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Assign to' : 'Beri kepada'">Assign to</span>
                        <span class="wd-pval">
                            <select class="wd-inline" x-model.number="assign.employeeId" required>
                                @foreach ($assignableEmployees as $e)
                                    <option value="{{ $e['id'] }}">{{ $e['name'] }}</option>
                                @endforeach
                            </select>
                        </span>

                        <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span>
                        <span class="wd-pval">
                            <select name="type" class="wd-inline">
                                @foreach (['adhoc' => 'Adhoc', 'task' => 'Task', 'assignment' => 'Assignment'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('type', 'adhoc') === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </span>

                        <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</span>
                        <span class="wd-pval">
                            <select name="priority" class="wd-inline">
                                @foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('priority', 'medium') === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </span>

                        <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Due' : 'Tarikh akhir'">Due</span>
                        <span class="wd-pval">
                            <input type="date" name="due_at" value="{{ old('due_at') }}" class="wd-inline" required />
                        </span>
                    </div>

                    <textarea name="description" rows="3" maxlength="5000" class="wd-desc"
                              x-bind:placeholder="$store.ui.lang==='en' ? 'Description (optional)' : 'Penerangan (pilihan)'">{{ old('description') }}</textarea>

                    {{-- Own small x-data, same technique tot-edit-form.blade.php uses for its
                         classic-form link rows: bracket-indexed :name bindings so a plain POST
                         carries them, no autosave/JS submission needed. Visual style (wd-inline
                         inputs, wd-add button) matches the card drawer's own link editor
                         instead — this is a one-shot create form, not that drawer. --}}
                    <div style="margin-top:16px;" x-data="{ links: @js(old('links', [])) }">
                        <h3 class="wd-sech" x-text="$store.ui.lang==='en' ? 'Links' : 'Pautan'">Links</h3>
                        <template x-for="(link, idx) in links" :key="idx">
                            <div style="display:grid;grid-template-columns:140px 1fr 30px;gap:8px;margin-bottom:8px;">
                                <input class="wd-inline" style="margin:0;" :name="`links[${idx}][label]`" x-model="link.label" placeholder="Label" maxlength="60">
                                <input class="wd-inline" style="margin:0;" :name="`links[${idx}][url]`" x-model="link.url" placeholder="https://...">
                                <button type="button" @click="links.splice(idx, 1)" style="border:0;background:none;color:var(--muted);font-size:14px;cursor:pointer;">&times;</button>
                            </div>
                        </template>
                        <button type="button" class="wd-add" @click="links.push({ label: '', url: '' })">
                            <span x-text="$store.ui.lang==='en' ? '+ Add a link' : '+ Tambah pautan'"></span>
                        </button>
                    </div>

                    <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;width:100%;margin-top:16px;">
                        <span x-text="$store.ui.lang==='en' ? 'Assign task' : 'Beri tugas'">Assign task</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    </template>
    @endif
</div>
@endsection
