@extends('layouts.app')

@php
    $priLabel = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
    $statusLabels = ['todo' => 'To Do', 'prog' => 'In Progress', 'review' => 'In Review', 'done' => 'Done'];
    $labelDef = \App\Models\WorkItem::LABELS;
    $boardType = $boardType ?? 'core';
@endphp

@section('screen')
@include('partials.see-all-btn', ['target' => 'team-board', 'label' => 'See all staff tasks', 'labelMs' => 'Lihat tugasan semua staf'])
@include('partials.guide', [
    'key' => 'board',
    'en'  => [
        'title' => 'Work board',
        'body'  => 'Plan and track work as cards that move across four columns — To Do, In Progress, In Review, then Done. Drag a card to move it, or click it to add detail and comments.',
        'who'   => 'Anyone adds work · Owner moves their cards',
        'steps' => [
            'Click "+ Add a card" at the bottom of a column and type what needs doing.',
            'Click a card to open it — set type, priority, a due label, and write a description.',
            'Drag the card across columns as the work progresses, or use the status menu inside it.',
            'Leave comments on a card to keep the back-and-forth in one place.',
        ],
    ],
    'ms'  => [
        'title' => 'Papan kerja',
        'body'  => 'Rancang dan jejak kerja sebagai kad yang bergerak melalui empat lajur — To Do, In Progress, In Review, kemudian Done. Seret kad untuk gerakkannya, atau klik untuk tambah butiran dan komen.',
        'who'   => 'Sesiapa boleh tambah kerja · Pemilik gerak kad sendiri',
        'steps' => [
            'Klik "+ Tambah kad" di bahagian bawah lajur dan taip apa yang perlu dibuat.',
            'Klik kad untuk buka — tetapkan jenis, keutamaan, label tarikh akhir, dan tulis penerangan.',
            'Seret kad merentas lajur apabila kerja maju, atau guna menu status di dalamnya.',
            'Tinggalkan komen pada kad supaya perbualan kekal di satu tempat.',
        ],
    ],
])

<div x-data="workBoard(@js($boardType), @js($canAssignPeople ?? false), @js($people ?? []), @js($labelDef), @js($deepLinkCardId ?? null))"
     @if ($deepLinkCardId ?? null) data-deep-link-card="{{ $deepLinkCardId }}" @endif>
    {{-- One board, all work types. Chips filter the cards live — no page reload. --}}
    <div style="display:flex;align-items:center;gap:7px;margin-bottom:16px;flex-wrap:wrap;">
        @foreach (['all' => ['All work', 'Semua kerja'], 'task' => ['Tasks', 'Tugas'], 'assignment' => ['Assignments', 'Tugasan'], 'adhoc' => ['Adhoc', 'Adhoc']] as $fk => $fl)
            <button type="button" @click="setFilter('{{ $fk }}')"
                    :style="filter === '{{ $fk }}'
                        ? { background: 'var(--red)', color: '#fff', borderColor: 'var(--red)' }
                        : { background: '#fff', color: 'var(--body)', borderColor: 'var(--hairline)' }"
                    style="padding:7px 14px;font-size:12.5px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
                <span x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</span>
                <span x-text="counts['{{ $fk }}']" style="font-size:11px;opacity:.7;font-family:var(--font-mono);"></span>
            </button>
        @endforeach
            <span style="width:1px;height:20px;background:var(--hairline);margin:0 3px;"></span>
            <button type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen"
                    :style="filtersOpen || activeFilterCount > 0
                        ? { background: 'var(--red)', color: '#fff', borderColor: 'var(--red)' }
                        : { background: '#fff', color: 'var(--body)', borderColor: 'var(--hairline)' }"
                    style="padding:7px 13px;font-size:12.5px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
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
                        style="padding:5px 12px;font-size:12px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;">{{ $lname }}</button>
            @endforeach
        </div>

        {{-- Third filter row: narrow the board to one project. "All" clears it. --}}
        <div style="display:flex;align-items:center;gap:7px;margin:-6px 0 16px;flex-wrap:wrap;">
            <span style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-right:2px;" x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'">Project</span>
            <select x-model="projectFilter" @change="applyFilter()"
                    style="padding:6px 12px;font-size:12.5px;font-weight:600;border:1px solid var(--hairline);border-radius:9999px;cursor:pointer;background:#fff;color:var(--body);">
                <option value="" x-text="$store.ui.lang==='en' ? 'All projects' : 'Semua projek'"></option>
                @foreach ($projects ?? [] as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
        </div>
    </div>

    <div style="display:flex;gap:14px;align-items:flex-start;overflow-x:auto;padding-bottom:8px;">
        @foreach ($columns as $key => $col)
            <div style="flex:1;min-width:272px;">
                <div style="display:flex;align-items:center;gap:8px;padding:0 4px 12px;">
                    <span style="font-size:13px;font-weight:600;color:var(--ink);">{{ $col['title'] }}</span>
                    <span data-count="{{ $key }}" style="font-size:11px;font-weight:600;color:var(--muted);background:var(--hairline-soft);padding:1px 8px;border-radius:9999px;">{{ $col['cards']->count() }}</span>
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
                        <button type="button" x-show="!open['{{ $key }}']" @click="toggleComposer('{{ $key }}')"
                                style="width:100%;text-align:left;padding:9px 12px;border:1px dashed var(--hairline);border-radius:10px;background:transparent;font-size:12.5px;font-weight:500;color:var(--muted);cursor:pointer;">
                            <span x-text="$store.ui.lang==='en' ? '+ Add a card' : '+ Tambah kad'"></span>
                        </button>
                        <div x-show="open['{{ $key }}']" x-cloak class="uj-card" style="padding:10px;">
                            <textarea x-ref="draft_{{ $key }}" x-model="draft['{{ $key }}']"
                                      @keydown.enter.prevent="submitAdd('{{ $key }}')"
                                      @keydown.escape="toggleComposer('{{ $key }}')"
                                      rows="2" maxlength="160"
                                      :placeholder="$store.ui.lang==='en' ? 'What needs doing?' : 'Apa yang perlu dibuat?'"
                                      style="width:100%;border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;font-size:13px;color:var(--ink);outline:none;resize:vertical;font-family:inherit;"></textarea>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <button type="button" @click="submitAdd('{{ $key }}')" :disabled="busy" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;">
                                    <span x-text="$store.ui.lang==='en' ? 'Add card' : 'Tambah'"></span>
                                </button>
                                <button type="button" @click="toggleComposer('{{ $key }}')" style="height:34px;padding:0 10px;font-size:12.5px;color:var(--muted);background:transparent;cursor:pointer;">×</button>
                            </div>
                        </div>
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
         control instead of a "Column" select; Delete lives in the ⋯ menu. --}}
    @if ($employee)
    <template x-teleport="body">
    <div>
        <div class="wd-scrim" x-show="drawer.show" x-cloak :data-open="drawer.open ? '' : null" @click="closeDrawer()"></div>

        <aside class="wd" x-show="drawer.show" x-cloak :data-open="drawer.open ? '' : null" x-ref="drawerEl"
               tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="wd-title"
               @keydown.escape.window="drawer.show && closeDrawer()" @keydown.tab="trapFocus($event)">

            <div class="wd-head">
                <div class="wd-seg" role="group" aria-label="Status">
                    @foreach ($statusLabels as $sv => $sl)
                        <button type="button" :aria-pressed="drawer.card.status === '{{ $sv }}' ? 'true' : 'false'"
                                :disabled="drawer.locked" @click="setStatus('{{ $sv }}')">{{ $sl }}</button>
                    @endforeach
                </div>
                <span class="wd-saved" :data-on="drawer.saved ? '' : null">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    <span x-text="$store.ui.lang==='en' ? 'Saved' : 'Disimpan'">Saved</span>
                </span>
                <div style="position:relative;">
                    <button type="button" class="wd-ico" @click="drawer.menuOpen = !drawer.menuOpen" aria-haspopup="menu"
                            :aria-expanded="drawer.menuOpen ? 'true' : 'false'" :aria-label="$store.ui.lang==='en' ? 'More actions' : 'Tindakan lain'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                    </button>
                    <div class="wd-menu" x-show="drawer.menuOpen" x-cloak @click.outside="drawer.menuOpen = false" role="menu">
                        <button type="button" role="menuitem" class="is-danger" x-show="!drawer.locked" @click="drawer.menuOpen = false; deleteCard()">
                            <span x-text="$store.ui.lang==='en' ? 'Delete card' : 'Padam kad'">Delete card</span>
                        </button>
                    </div>
                </div>
                <button type="button" class="wd-ico" @click="closeDrawer()" :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="wd-body">
                <template x-if="drawer.loading">
                    <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'Loading…' : 'Memuatkan…'"></div>
                </template>

                <template x-if="!drawer.loading">
                    <div>
                        <div x-show="drawer.error" x-cloak style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;" x-text="drawer.error"></div>

                        <div class="wd-locked" x-show="drawer.locked" x-cloak>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <span x-text="drawer.lockedReason"></span>
                        </div>

                        {{-- title: a click-to-edit heading, not a bordered input sharing weight with the property rows --}}
                        <h2 class="wd-title" id="wd-title" x-ref="titleEl" :contenteditable="!drawer.locked ? 'true' : 'false'" spellcheck="false"
                            role="textbox" :aria-label="$store.ui.lang==='en' ? 'Card title' : 'Tajuk kad'"
                            @input="onTitleInput($event)" @blur="commitFieldFromCard('title')" @keydown.enter.prevent="$event.target.blur()"></h2>
                        <p class="wd-sub" x-text="drawer.sub"></p>

                        <div class="wd-props">
                            <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span>
                            <span class="wd-pval">
                                <select class="wd-inline" x-model="drawer.card.type" :disabled="drawer.locked" @change="commitField('type', drawer.card.type)">
                                    @foreach (['assignment' => 'Assignment', 'task' => 'Task', 'adhoc' => 'Adhoc'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                </select>
                            </span>

                            <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</span>
                            <span class="wd-pval">
                                <select class="wd-inline" x-model="drawer.card.priority" :disabled="drawer.locked" @change="commitField('priority', drawer.card.priority)">
                                    @foreach ($priLabel as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                </select>
                            </span>

                            <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Due' : 'Tarikh akhir'">Due</span>
                            <span class="wd-pval" style="position:relative;display:inline-block;">
                                {{-- Dates render as "30 Jul 2026" everywhere, matching the card face — a bare
                                     date input shows locale format (07/30/2026 here). The visible control is a
                                     formatted button; the real <input type="date"> underneath collects the value. --}}
                                <button type="button" class="wd-inline" :class="{ 'wd-inline--empty': !drawer.card.due_at }" :disabled="drawer.locked"
                                        @click="openDuePicker()" x-text="drawer.card.due_label || ($store.ui.lang==='en' ? 'Set a due date' : 'Tetapkan tarikh akhir')"></button>
                                <input type="date" x-ref="dueInput" :value="drawer.card.due_at || ''" :disabled="drawer.locked"
                                       @change="commitField('due_at', $event.target.value || null)" tabindex="-1" aria-hidden="true"
                                       style="position:absolute;inset:0;opacity:0;width:1px;height:1px;pointer-events:none;" />
                            </span>

                            <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'">Project</span>
                            <span class="wd-pval">
                                <select class="wd-inline" x-model="drawer.card.project_id" :disabled="drawer.locked"
                                        @change="commitField('project_id', drawer.card.project_id === '' ? null : drawer.card.project_id)">
                                    <option value="" x-text="$store.ui.lang==='en' ? 'No project' : 'Tiada projek'"></option>
                                    @foreach ($projects ?? [] as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                                </select>
                            </span>

                            <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'Labels' : 'Label'">Labels</span>
                            <span class="wd-pval">
                                <span class="wd-chiprow">
                                    <template x-for="lk in drawer.card.labels" :key="lk">
                                        <span class="wc-label" :style="'--wc-l:' + (labels[lk] ? labels[lk][1] : 'var(--muted)')" x-text="labels[lk] ? labels[lk][0] : lk"></span>
                                    </template>
                                    <template x-if="!drawer.card.labels.length && drawer.locked">
                                        <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'"></span>
                                    </template>
                                    <span style="position:relative;display:inline-block;" x-show="!drawer.locked">
                                        <button type="button" class="wd-add" @click="drawer.labelMenuOpen = !drawer.labelMenuOpen" aria-haspopup="menu" :aria-expanded="drawer.labelMenuOpen ? 'true' : 'false'">
                                            <span x-text="drawer.card.labels.length ? ($store.ui.lang==='en' ? 'Label' : 'Label') : ($store.ui.lang==='en' ? '+ Add label' : '+ Tambah label')"></span>
                                        </button>
                                        <div class="wd-menu" x-show="drawer.labelMenuOpen" x-cloak @click.outside="drawer.labelMenuOpen = false" role="menu" style="top:28px;">
                                            <template x-for="(def, lk) in labels" :key="lk">
                                                <button type="button" role="menuitemcheckbox" :aria-checked="drawer.card.labels.includes(lk) ? 'true' : 'false'" @click="toggleLabel(lk)">
                                                    <span x-text="(drawer.card.labels.includes(lk) ? '✓ ' : '') + def[0]"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </span>
                                </span>
                            </span>

                            <span class="wd-plabel" x-text="$store.ui.lang==='en' ? 'People' : 'Orang'">People</span>
                            <span class="wd-pval">
                                <span class="wd-chiprow">
                                    <template x-if="canAssign && !drawer.locked">
                                        <template x-for="p in drawer.card.participants" :key="p.id">
                                            <span style="display:inline-flex;align-items:center;gap:6px;padding:3px 8px 3px 4px;border:1px solid var(--hairline);border-radius:9999px;font-size:12px;font-weight:500;color:var(--ink);background:#fff;">
                                                <span class="wa" :style="'margin-left:0;width:22px;height:22px;font-size:9px;background:' + (p.color || 'var(--muted)')" x-text="p.initials"></span>
                                                <span x-text="p.name"></span>
                                                <button type="button" @click="removePerson(p.id)" :aria-label="($store.ui.lang==='en' ? 'Remove ' : 'Buang ') + p.name"
                                                        style="border:0;background:none;color:var(--muted);font-size:14px;line-height:1;cursor:pointer;padding:0;">×</button>
                                            </span>
                                        </template>
                                    </template>
                                    <template x-if="!(canAssign && !drawer.locked) && drawer.card.participants.length">
                                        <span class="wa-stack">
                                            <template x-for="p in drawer.card.participants" :key="'ro'+p.id">
                                                <span class="wa" :style="'background:' + (p.color || 'var(--muted)')" :title="p.name" x-text="p.initials"></span>
                                            </template>
                                        </span>
                                    </template>
                                    <template x-if="!drawer.card.participants.length">
                                        <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="$store.ui.lang==='en' ? 'Just you' : 'Anda sahaja'"></span>
                                    </template>
                                    <span style="position:relative;display:inline-block;" x-show="canAssign && !drawer.locked && availablePeople.length">
                                        <select class="wd-inline" style="margin-left:0;" @change="addPerson($event.target.value); $event.target.value=''">
                                            <option value="" x-text="$store.ui.lang==='en' ? '+ Add someone' : '+ Tambah orang'"></option>
                                            <template x-for="p in availablePeople" :key="p.id">
                                                <option :value="p.id" x-text="p.name"></option>
                                            </template>
                                        </select>
                                    </span>
                                </span>
                            </span>
                        </div>

                        <h3 class="wd-sech" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</h3>
                        <textarea class="wd-desc" x-model="drawer.card.description" :readonly="drawer.locked" maxlength="5000"
                                  :placeholder="$store.ui.lang==='en' ? 'Add more detail…' : 'Tambah butiran…'"
                                  @input="scheduleCommit('description')" @blur="commitFieldFromCard('description')"></textarea>

                        <hr class="wd-rule">

                        <h3 class="wd-sech" x-text="drawer.comments.length ? (($store.ui.lang==='en' ? 'Comments' : 'Komen') + ' (' + drawer.comments.length + ')') : ($store.ui.lang==='en' ? 'Comments' : 'Komen')">Comments</h3>
                        <div class="wd-cmts">
                            <template x-for="c in drawer.comments" :key="c.id">
                                <div class="wd-cmt">
                                    <span class="wa" :style="'background:' + c.color" x-text="c.initials"></span>
                                    <div style="flex:1;min-width:0;">
                                        <div class="wd-cmt-who">
                                            <span class="wd-cmt-name" x-text="c.author"></span>
                                            <span class="wd-cmt-at" x-text="c.when"></span>
                                            <button type="button" x-show="c.mine" @click="deleteComment(c.id)" style="margin-left:auto;font-size:11px;color:var(--muted);background:transparent;cursor:pointer;" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'"></button>
                                        </div>
                                        {{-- Escaped first, then tinted: c.body is user input, and renderCommentBody()
                                             only ever wraps exact-match substrings of the card's own mentionable
                                             names inside the ALREADY-escaped string — see work-board.js. --}}
                                        <div class="wd-cmt-body" x-html="renderCommentBody(c.body)"></div>
                                    </div>
                                </div>
                            </template>
                            <template x-if="!drawer.comments.length">
                                <p style="font-size:13px;color:var(--muted-soft);margin:0;" x-text="$store.ui.lang==='en' ? 'No comments yet.' : 'Tiada komen lagi.'"></p>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Composer docks to the panel floor so a long thread never hides it.
                 Typing "@" at a word boundary opens the mention picker above it —
                 see mentionActiveQuery()/paintMention()/insertMention() in
                 work-board.js, lifted from public/_proto-board.html's reference
                 implementation. Escape closes the picker only: onCommentKeydown()
                 stops propagation before the drawer's own window-level Escape
                 handler ever sees the key. --}}
            <div class="wd-foot">
                <div class="wd-ment" role="listbox" :data-open="drawer.mention.open ? '' : null"
                     :aria-label="$store.ui.lang==='en' ? 'Mention someone on this card' : 'Sebut seseorang pada kad ini'">
                    <template x-if="drawer.mention.open && !mentionPool.length">
                        <p class="wd-ment-none" x-text="$store.ui.lang==='en'
                            ? 'Only people on this card can be mentioned, and nobody else is on it yet. Add someone under People first.'
                            : 'Hanya orang pada kad ini boleh disebut, dan belum ada sesiapa lagi. Tambah seseorang di bawah Orang dahulu.'"></p>
                    </template>
                    <template x-if="drawer.mention.open && mentionPool.length && !drawer.mention.hits.length">
                        <p class="wd-ment-none" x-text="$store.ui.lang==='en' ? 'Nobody on this card matches that name.' : 'Tiada sesiapa pada kad ini sepadan dengan nama itu.'"></p>
                    </template>
                    <template x-if="drawer.mention.open && drawer.mention.hits.length">
                        <template x-for="(p, i) in drawer.mention.hits" :key="p.id">
                            <button type="button" role="option" :aria-selected="i === drawer.mention.idx ? 'true' : 'false'"
                                    :data-active="i === drawer.mention.idx ? '' : null" @mousedown.prevent="insertMention(p)">
                                <span class="wa" :style="'background:' + (p.color || 'var(--muted)')" x-text="p.initials"></span>
                                <span x-text="p.name"></span>
                            </button>
                        </template>
                    </template>
                </div>
                <textarea x-ref="newCommentEl" x-model="drawer.newComment" @input="onCommentInput($event)" @keydown="onCommentKeydown($event)"
                          @blur="setTimeout(() => closeMention(), 120)" @keydown.enter.meta.prevent="addComment()" rows="1" maxlength="2000"
                          :placeholder="$store.ui.lang==='en' ? 'Write a comment, or type @ to notify someone…' : 'Tulis komen, atau taip @ untuk maklumkan seseorang…'"></textarea>
                <button type="button" class="uj-btn-primary wd-post" @click="addComment()">
                    <span x-text="$store.ui.lang==='en' ? 'Post' : 'Hantar'">Post</span>
                </button>
            </div>
        </aside>
    </div>
    </template>
    @endif
</div>
@endsection
