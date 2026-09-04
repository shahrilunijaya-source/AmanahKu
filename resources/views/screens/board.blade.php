@extends('layouts.app')

@php
    $priLabel = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
    $statusLabels = ['todo' => 'To Do', 'prog' => 'In Progress', 'review' => 'In Review', 'done' => 'Done'];
    $labelDef = \App\Models\WorkItem::LABELS;
    $boardType = $boardType ?? 'core';
@endphp

@section('screen')
<div class="uj-board-topbar">
@include('partials.guide', [
    'key' => 'board',
    'en'  => [
        'title' => 'Work board',
        'body'  => 'Plan and track work as cards that move across four columns — To Do, In Progress, In Review, then Done. Drag a card to move it, or click it to add detail and comments.',
        'who'   => 'Anyone adds work · Owner moves their cards',
        'steps' => [
            'Click "+ Add a card" at the bottom of a column — it opens right away so you can name it and fill in the details.',
            'Click any existing card to open it — set type, priority, a due label, and write a description.',
            'Drag the card across columns as the work progresses, or use the status menu inside it.',
            'Leave comments on a card to keep the back-and-forth in one place.',
        ],
    ],
    'ms'  => [
        'title' => 'Papan kerja',
        'body'  => 'Rancang dan jejak kerja sebagai kad yang bergerak melalui empat lajur — To Do, In Progress, In Review, kemudian Done. Seret kad untuk gerakkannya, atau klik untuk tambah butiran dan komen.',
        'who'   => 'Sesiapa boleh tambah kerja · Pemilik gerak kad sendiri',
        'steps' => [
            'Klik "+ Tambah kad" di bahagian bawah lajur — ia terus terbuka supaya anda boleh namakannya dan isi butiran.',
            'Klik mana-mana kad sedia ada untuk buka — tetapkan jenis, keutamaan, label tarikh akhir, dan tulis penerangan.',
            'Seret kad merentas lajur apabila kerja maju, atau guna menu status di dalamnya.',
            'Tinggalkan komen pada kad supaya perbualan kekal di satu tempat.',
        ],
    ],
])
@include('partials.see-all-btn', ['target' => 'team-board', 'label' => 'See all staff tasks', 'labelMs' => 'Lihat tugasan semua staf'])
</div>

<div x-data="workBoard(@js($boardType), @js($people ?? []), @js($labelDef), @js($deepLinkCardId ?? null), @js($archivedCount ?? 0))"
     @if ($deepLinkCardId ?? null) data-deep-link-card="{{ $deepLinkCardId }}" @endif>
    {{-- One board, all work types. Chips filter the cards live — no page reload. --}}
    <div style="display:flex;align-items:center;gap:7px;margin-bottom:16px;flex-wrap:wrap;">
        @foreach (['all' => ['All work', 'Semua kerja'], 'task' => ['Tasks', 'Tugas'], 'assignment' => ['Assignments', 'Tugasan'], 'adhoc' => ['Adhoc', 'Adhoc']] as $fk => $fl)
            <button type="button" @click="setFilter('{{ $fk }}')"
                    :style="filter === '{{ $fk }}'
                        ? { background: 'var(--red)', color: '#fff', borderColor: 'var(--red)' }
                        : { background: '#fff', color: 'var(--body)', borderColor: 'var(--hairline)' }"
                    style="padding:7px 14px;font-size:12.5px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:background-color .14s var(--ease),color .14s var(--ease),border-color .14s var(--ease);">
                <span x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</span>
                <span x-text="counts['{{ $fk }}']" style="font-size:11px;opacity:.7;font-family:var(--font-mono);"></span>
            </button>
        @endforeach
            <span style="width:1px;height:20px;background:var(--hairline);margin:0 3px;"></span>
            <button type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen"
                    :style="filtersOpen || activeFilterCount > 0
                        ? { background: 'var(--red)', color: '#fff', borderColor: 'var(--red)' }
                        : { background: '#fff', color: 'var(--body)', borderColor: 'var(--hairline)' }"
                    style="padding:7px 13px;font-size:12.5px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:background-color .14s var(--ease),color .14s var(--ease),border-color .14s var(--ease);">
                <span x-text="$store.ui.lang==='en' ? 'Filters' : 'Penapis'">Filters</span>
                <span x-show="activeFilterCount > 0" x-cloak x-text="activeFilterCount"
                      style="min-width:16px;height:16px;line-height:16px;text-align:center;font-size:10.5px;font-family:var(--font-mono);background:rgba(255,255,255,.28);border-radius:9999px;padding:0 4px;"></span>
            </button>
    </div>

    <div x-show="filtersOpen" x-cloak>
        <div x-show="activeFilterCount > 0" x-cloak style="margin:-2px 0 8px;">
            <button type="button" @click="clearFilters()" style="font-size:11.5px;font-weight:600;color:var(--muted);background:transparent;cursor:pointer;text-decoration:underline;padding:0;"
                    x-text="$store.ui.lang==='en' ? 'Clear filters' : 'Kosongkan penapis'"></button>
        </div>

        {{-- Second filter row: narrow the board to one label. Click again to clear. --}}
        <div style="display:flex;align-items:center;gap:7px;margin:-6px 0 16px;flex-wrap:wrap;">
            <span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-right:2px;" x-text="$store.ui.lang==='en' ? 'Label' : 'Label'">Label</span>
            @foreach ($labelDef as $lk => [$lname, $lcolor])
                <button type="button" @click="setLabelFilter('{{ $lk }}')"
                        :style="labelFilter === '{{ $lk }}'
                            ? { background: '{{ $lcolor }}', color: '#fff', borderColor: '{{ $lcolor }}' }
                            : { background: '#fff', color: 'var(--body)', borderColor: 'var(--hairline)' }"
                        style="padding:5px 12px;font-size:12px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;transition:background-color .14s var(--ease),color .14s var(--ease),border-color .14s var(--ease);">{{ $lname }}</button>
            @endforeach
        </div>

        {{-- Third filter row: narrow the board to a due-date bucket. Click again to clear. --}}
        <div style="display:flex;align-items:center;gap:7px;margin:-6px 0 16px;flex-wrap:wrap;">
            <span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-right:2px;" x-text="$store.ui.lang==='en' ? 'Due' : 'Tarikh akhir'">Due</span>
            @foreach (['overdue' => ['Overdue', 'Tertunggak'], 'today' => ['Today', 'Hari ini'], 'week' => ['This week', 'Minggu ini'], 'none' => ['No due date', 'Tiada tarikh akhir'], 'range' => ['Custom range', 'Julat tersuai']] as $dk => $dl)
                <button type="button" @click="setDueFilter('{{ $dk }}')"
                        :style="dueFilter === '{{ $dk }}'
                            ? { background: 'var(--red)', color: '#fff', borderColor: 'var(--red)' }
                            : { background: '#fff', color: 'var(--body)', borderColor: 'var(--hairline)' }"
                        style="padding:5px 12px;font-size:12px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;transition:background-color .14s var(--ease),color .14s var(--ease),border-color .14s var(--ease);">
                    <span x-text="$store.ui.lang==='en' ? @js($dl[0]) : @js($dl[1])">{{ $dl[0] }}</span>
                </button>
            @endforeach
            <template x-if="dueFilter === 'range'">
                <span style="display:inline-flex;align-items:center;gap:6px;">
                    <input type="date" x-model="dueRangeFrom" @change="applyFilter()"
                           style="padding:5px 8px;font-size:12px;font-weight:600;border:1px solid var(--hairline);border-radius:8px;background:#fff;color:var(--body);">
                    <span style="font-size:11px;color:var(--muted);">–</span>
                    <input type="date" x-model="dueRangeTo" @change="applyFilter()"
                           style="padding:5px 8px;font-size:12px;font-weight:600;border:1px solid var(--hairline);border-radius:8px;background:#fff;color:var(--body);">
                </span>
            </template>
        </div>

        {{-- Sort row: reorders cards within each column. Manual is the drag order;
             switching to Due date/Priority disables drag until back to Manual. --}}
        <div style="display:flex;align-items:center;gap:7px;margin:-6px 0 16px;flex-wrap:wrap;">
            <span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-right:2px;" x-text="$store.ui.lang==='en' ? 'Sort' : 'Susun'">Sort</span>
            <select x-model="sortMode" @change="setSortMode(sortMode)"
                    style="padding:6px 12px;font-size:12.5px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;background:#fff;color:var(--body);">
                <option value="manual" x-text="$store.ui.lang==='en' ? 'Manual' : 'Manual'"></option>
                <option value="due_at" x-text="$store.ui.lang==='en' ? 'Due date' : 'Tarikh akhir'"></option>
                <option value="priority" x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'"></option>
            </select>
        </div>

        {{-- Fourth filter row: narrow the board to one project. "All" clears it. --}}
        <div style="display:flex;align-items:center;gap:7px;margin:-6px 0 16px;flex-wrap:wrap;">
            <span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-right:2px;" x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'">Project</span>
            <select x-model="projectFilter" @change="applyFilter()"
                    style="padding:6px 12px;font-size:12.5px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;background:#fff;color:var(--body);">
                <option value="" x-text="$store.ui.lang==='en' ? 'All projects' : 'Semua projek'"></option>
                @foreach ($projects ?? [] as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
        </div>
    </div>

    {{-- Phone only (see .wb-strip): one pill per column, follows the snapped column
         and jumps to it on tap. The columns themselves snap one per swipe. --}}
    <div class="wb-strip" x-data="{ idx: 0 }" x-init="$nextTick(() => { const c = $refs.cols; c.addEventListener('scroll', () => { idx = Math.round(c.scrollLeft / (c.firstElementChild.offsetWidth + 12)); }, { passive: true }); })"
         x-effect="$el.children[idx]?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' })">
        @foreach ($columns as $key => $col)
            <button type="button" class="wb-pill" :class="{ 'is-on': idx === {{ $loop->index }} }"
                    @click="$refs.cols.children[{{ $loop->index }}].scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' })">
                {{ $col['title'] }} <i data-count="{{ $key }}">{{ $col['cards']->count() }}</i>
            </button>
        @endforeach
    </div>

    <div class="wb-cols" x-ref="cols">
        @foreach ($columns as $key => $col)
            <div class="wb-col">
                <div class="wb-colh">
                    <span style="font-size:13px;font-weight:600;color:var(--ink);">{{ $col['title'] }}</span>
                    <span data-count="{{ $key }}" style="font-size:11px;font-weight:600;color:var(--muted);background:var(--hairline-soft);padding:1px 8px;border-radius:9999px;">{{ $col['cards']->count() }}</span>
                    @if ($key === 'done' && $employee)
                        <button type="button" @click="openArchived()" x-show="archivedCount > 0" x-cloak
                                style="margin-left:auto;font-size:11px;font-weight:600;color:var(--muted);background:transparent;cursor:pointer;text-decoration:underline;padding:0;">
                            <span x-text="($store.ui.lang==='en' ? 'Archived' : 'Diarkibkan') + ' (' + archivedCount + ')'"></span>
                        </button>
                    @endif
                </div>

                <div data-list="{{ $key }}" style="display:flex;flex-direction:column;gap:10px;min-height:24px;">
                    @forelse ($col['cards'] as $c)
                        @include('partials.work-card', ['c' => $c])
                    @empty
                        <div data-empty class="wc-empty">
                            <span x-text="$store.ui.lang==='en' ? 'Nothing here yet.' : 'Belum ada apa-apa.'"></span>
                        </div>
                    @endforelse
                </div>

                @if ($employee)
                    <div style="margin-top:10px;">
                        <button type="button" :disabled="busy" @click="addCard('{{ $key }}')"
                                style="width:100%;text-align:left;padding:9px 12px;border:1px dashed var(--hairline);border-radius:10px;background:transparent;font-size:12.5px;font-weight:500;color:var(--muted);cursor:pointer;">
                            <span x-text="$store.ui.lang==='en' ? '+ Add a card' : '+ Tambah kad'"></span>
                        </button>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ───────── Card detail drawer ─────────
         Right slide-over replacing the old centre modal (see docs/superpowers/specs/
         2026-07-29-taa-board-redesign-design.md, "Drawer"). Every property row
         autosaves per field on change/blur/600ms-idle — see commitField() and
         friends in work-board.js. Status moves through the header segmented
         control instead of a "Column" select; Delete lives in the ⋯ menu.
         Markup lives in partials.work-drawer, shared with the team board's
         view-only copy (see resources/views/screens/team-board.blade.php). --}}
    @if ($employee)
    @include('partials.work-drawer', ['interactive' => true, 'priLabel' => $priLabel])

    {{-- Archived-cards panel: reached only from the Done column's "Archived (N)"
         link. A card lands here via archiveCard() (shake + fade, see work-board.js)
         and leaves only through reopenCard(), which puts it back at To Do — the
         one way back onto the board for something archived. --}}
    <template x-teleport="body">
    <div>
        <div class="wd-scrim" x-show="archivedOpen" x-cloak :data-open="archivedOpen ? '' : null" @click="archivedOpen = false"></div>
        <aside class="wd" x-show="archivedOpen" x-cloak :data-open="archivedOpen ? '' : null" style="max-width:420px;">
            <div class="wd-head">
                <span style="font-size:14px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Archived cards' : 'Kad diarkibkan'"></span>
                <button type="button" class="wd-ico" style="margin-left:auto;" @click="archivedOpen = false" :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="wd-body">
                <template x-if="!archivedItems.length">
                    <p style="font-size:13px;color:var(--muted-soft);" x-text="$store.ui.lang==='en' ? 'Nothing archived.' : 'Tiada yang diarkibkan.'"></p>
                </template>
                <template x-for="a in archivedItems" :key="a.id">
                    <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--hairline-soft);">
                        <div style="flex:1;min-width:0;">
                            <p style="font-size:13px;font-weight:600;color:var(--ink);margin:0 0 2px;text-wrap:pretty;" x-text="a.title"></p>
                            <p style="font-size:11px;color:var(--muted);margin:0;" x-text="($store.ui.lang==='en' ? 'Archived ' : 'Diarkibkan ') + a.archived_at"></p>
                        </div>
                        <button type="button" class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:12px;flex-shrink:0;" @click="reopenCard(a.id)">
                            <span x-text="$store.ui.lang==='en' ? 'Reopen' : 'Buka semula'"></span>
                        </button>
                    </div>
                </template>
            </div>
        </aside>
    </div>
    </template>
    @endif
</div>
@endsection
