{{--
    Card detail drawer — shared by the personal board (interactive) and the
    all-staff team board (view + comment only). Every property row already
    branches on `drawer.locked` for the personal board's own per-card lock
    (a tac's assignee, a shared card's participant); the team board simply
    forces `drawer.locked = true` on every card it opens, so those existing
    branches are what make it read-only there too — no duplicate markup for
    labels/people/links/description. Only the status control, the "..." menu,
    and the title need a genuinely different (non-editable) shape, gated by
    $interactive below.

    @param bool $interactive   true = personal board (full edit surface),
                                false = team board (view + comment only).
    @param array $priLabel     value => label, e.g. ['high' => 'High', ...]

    Note: the label palette itself (WorkItem::LABELS) is NOT a Blade param here —
    it's read from Alpine's `labels` data property, already bound on the host
    component's x-data (workBoard(..., labels, ...) / teamBoard(..., labels, ...)).
--}}
@php
    $interactive = $interactive ?? true;
    $statusLabels = ['todo' => 'To Do', 'prog' => 'In Progress', 'review' => 'In Review', 'done' => 'Done'];
    $typeLabels = ['assignment' => 'Assignment', 'task' => 'Task', 'adhoc' => 'Adhoc'];
    $priLabel = $priLabel ?? ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
@endphp
<template x-teleport="body">
<div>
    {{-- wd--over-modal (team board only): this drawer can open from a card
         inside .tb-win-modal, which shares this same .wd-scrim/.wd shell for
         its OWN backdrop — without the modifier, the two would tie at the
         same z-index and stacking would depend on DOM order. --}}
    <div class="wd-scrim @unless ($interactive) wd--over-modal @endunless" x-show="drawer.show" x-cloak :data-open="drawer.open ? '' : null" @click="drawer.ovOpen ? (drawer.ovOpen = false) : closeDrawer()">
        @include('partials.work-overview', ['interactive' => $interactive])
    </div>

    <aside class="wd @unless ($interactive) wd--over-modal @endunless" x-show="drawer.show" x-cloak :data-open="drawer.open ? '' : null" x-ref="drawerEl"
           tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="wd-title"
           @keydown.escape.window="drawer.show && closeDrawer()" @keydown.tab="trapFocus($event)">

        <div class="wd-head">
            @if ($interactive)
                <div class="wd-seg" role="group" aria-label="Status" x-show="!drawer.card.parent_id">
                    @foreach ($statusLabels as $sv => $sl)
                        <button type="button" :aria-pressed="drawer.card.status === '{{ $sv }}' ? 'true' : 'false'"
                                :disabled="drawer.locked" @click="setStatus('{{ $sv }}')">{{ $sl }}</button>
                    @endforeach
                </div>
                {{-- A subtask is open or done. Ticking is access-wide (a participant may), so it
                     is not disabled by drawer.locked: tickChild() goes through move, not update. --}}
                <div class="wd-seg" role="group" aria-label="Status" x-show="drawer.card.parent_id" x-cloak>
                    <button type="button" :aria-pressed="drawer.card.status !== 'done' ? 'true' : 'false'" @click="drawer.card.status === 'done' && tickChild(drawer.card)">
                        <span x-text="$store.ui.lang==='en' ? 'Open' : 'Buka'"></span>
                    </button>
                    <button type="button" :aria-pressed="drawer.card.status === 'done' ? 'true' : 'false'" @click="drawer.card.status !== 'done' && tickChild(drawer.card)">
                        <span x-text="$store.ui.lang==='en' ? 'Done' : 'Siap'"></span>
                    </button>
                </div>
                <span class="wd-saved" :data-on="drawer.saved ? '' : null">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                    <span x-text="$store.ui.lang==='en' ? 'Saved' : 'Disimpan'">Saved</span>
                </span>
                <div style="position:relative;">
                    <button type="button" class="wd-ico" @click="drawer.menuOpen = !drawer.menuOpen" aria-haspopup="menu" data-tip-below data-tip-end :data-tip="$store.ui.lang==='en' ? 'Archive, cancel or delete' : 'Arkib, batal atau padam'"
                            :aria-expanded="drawer.menuOpen ? 'true' : 'false'" :aria-label="$store.ui.lang==='en' ? 'More actions' : 'Tindakan lain'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                    </button>
                    <div class="wd-menu" x-show="drawer.menuOpen" x-cloak @click.outside="drawer.menuOpen = false" role="menu">
                        <button type="button" role="menuitem" x-show="!drawer.locked && drawer.card.status === 'done' && !drawer.card.parent_id" @click="drawer.menuOpen = false; archiveCard()">
                            <span x-text="$store.ui.lang==='en' ? 'Archive card' : 'Arkibkan kad'">Archive card</span>
                        </button>
                        <button type="button" role="menuitem" x-show="!drawer.locked && !drawer.card.parent_id && !drawer.card.cancelled_at" @click="drawer.menuOpen = false; cancelCard()">
                            <span x-text="$store.ui.lang==='en' ? 'Cancel card (with reason)' : 'Batalkan kad (dengan sebab)'">Cancel card (with reason)</span>
                        </button>
                        <button type="button" role="menuitem" class="is-danger" x-show="!drawer.locked" @click="drawer.menuOpen = false; deleteCard()">
                            <span x-text="$store.ui.lang==='en' ? 'Delete card' : 'Padam kad'">Delete card</span>
                        </button>
                    </div>
                </div>
            @else
                <span style="font-size:13px;font-weight:600;color:var(--ink);" x-text="drawer.card.parent_id ? (drawer.card.status === 'done' ? 'Done' : 'Open') : ((@js($statusLabels))[drawer.card.status] || '')"></span>
            @endif
            <button type="button" class="wd-ico" @click="closeDrawer()" data-tip-below data-tip-end :data-tip="$store.ui.lang==='en' ? 'Close (Esc)' : 'Tutup (Esc)'" :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
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

                    {{-- title: a click-to-edit heading on the personal board; plain text on the team board --}}
                    <button type="button" class="wd-back" x-show="drawer.card.parent_id" x-cloak @click="backToParent()">
                        <span aria-hidden="true">&larr;</span> <span x-text="drawer.family?.parent.title"></span>
                    </button>
                    @if ($interactive)
                        <h2 class="wd-title" id="wd-title" x-ref="titleEl" :contenteditable="!drawer.locked ? 'true' : 'false'" spellcheck="false"
                            role="textbox" :aria-label="$store.ui.lang==='en' ? 'Card title' : 'Tajuk kad'"
                            @input="onTitleInput($event)" @blur="commitFieldFromCard('title')" @keydown.enter.prevent="$event.target.blur()"></h2>
                    @else
                        <h2 class="wd-title" id="wd-title" x-text="drawer.card.title"></h2>
                    @endif
                    <p class="wd-sub">
                        <span x-text="drawer.sub"></span>
                        {{-- CR-19: " · ⚡ Closed automatically <date>" once the card carries the Auto marker. --}}
                        <template x-if="drawer.card.auto_closed_label">
                            <span class="wd-meta-auto">
                                &middot;
                                <svg viewBox="0 0 24 24" fill="currentColor" width="11" height="11"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
                                <span x-text="($store.ui.lang==='en' ? 'Closed automatically' : 'Ditutup automatik') + ' ' + drawer.card.auto_closed_label"></span>
                            </span>
                        </template>
                    </p>

                    <div class="wd-props">
                        <span class="wd-plabel" x-show="!drawer.card.parent_id" data-tip-below data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Task is yours, Assignment came from someone, Adhoc is a quick one-off' : 'Tugas milik anda, Tugasan datang dari orang lain, Adhoc kerja kecil sekali lalu'" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span>
                        <span class="wd-pval" x-show="!drawer.card.parent_id">
                            @if ($interactive)
                                <select class="wd-inline" x-model="drawer.card.type" :disabled="drawer.locked" @change="commitField('type', drawer.card.type)">
                                    @foreach ($typeLabels as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                </select>
                            @else
                                <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="(@js($typeLabels))[drawer.card.type] || ''"></span>
                            @endif
                        </span>

                        <span class="wd-plabel" data-tip-below data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Only High shows on the card. Medium is the default.' : 'Hanya Tinggi tertera di kad. Sederhana ialah lalai.'" x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</span>
                        <span class="wd-pval">
                            @if ($interactive)
                                <select class="wd-inline" x-model="drawer.card.priority" :disabled="drawer.locked" @change="commitField('priority', drawer.card.priority)">
                                    @foreach ($priLabel as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                </select>
                            @else
                                <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="(@js($priLabel))[drawer.card.priority] || ''"></span>
                            @endif
                        </span>

                        <span class="wd-plabel" data-tip-below data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Locks the moment it is saved. Ask HR if it must change.' : 'Dikunci sebaik disimpan. Minta HR jika perlu ubah.'" x-text="$store.ui.lang==='en' ? 'Due' : 'Tarikh akhir'">Due</span>
                        <span class="wd-pval" style="position:relative;display:inline-block;">
                            @if ($interactive)
                                {{-- Dates render as "30 Jul 2026" everywhere, matching the card face — a bare
                                     date input shows locale format (07/30/2026 here). The visible control is a
                                     formatted button; the real <input type="date"> sits on top of it full-size and
                                     transparent, so the tap itself lands on the native control — iOS only raises its
                                     date wheel from a direct tap, a synthetic .click()/.focus() on a hidden 1px
                                     input (the old approach) is silently ignored on iOS Safari without showPicker(). --}}
                                {{-- Once a work card has a due date it never changes (date-calendar-rules §1):
                                     the picker is disabled and the hint below says how to move the work. --}}
                                <button type="button" class="wd-inline" :class="{ 'wd-inline--empty': !drawer.card.due_at }" :disabled="drawer.locked || !!drawer.card.due_at"
                                        @click="openDuePicker()" x-text="drawer.card.due_label || ($store.ui.lang==='en' ? 'Set a due date' : 'Tetapkan tarikh akhir')"></button>
                                <input type="date" x-ref="dueInput" :value="drawer.card.due_at || ''" :disabled="drawer.locked || !!drawer.card.due_at"
                                       @click="openDuePicker()"
                                       @change="commitField('due_at', $event.target.value || null)"
                                       style="position:absolute;inset:0;opacity:0;width:100%;height:100%;pointer-events:auto;cursor:pointer;" />
                                <span class="wd-due-lock" x-show="!drawer.locked && !!drawer.card.due_at" x-cloak
                                      x-text="$store.ui.lang==='en' ? 'Locked. If the work has moved, cancel this card with a reason and create a new one.' : 'Dikunci. Jika kerja ini berubah tarikh, batalkan kad ini dengan sebab dan cipta kad baharu.'"></span>
                            @else
                                <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="drawer.card.due_label || ($store.ui.lang==='en' ? 'No due date' : 'Tiada tarikh akhir')"></span>
                            @endif
                        </span>

                        {{-- Category and Project are one decision with two halves, and the old
                             capture screen asked for both on every line — what kind of work, and
                             which job it is on. Kept on a single row, category first, in the order
                             the timesheet used to ask.

                             Category is always asked, because the board is the only way work
                             reaches a timesheet and a card with no category produces no row at
                             all. Project is asked only when the chosen category needs one:
                             Development and Maintenance are done on a job, HR and Admin, Charity
                             and Others are not. An empty project list is the server saying the
                             question does not arise — see WorkItem::projectOptions(). --}}
                        <span class="wd-plabel" x-show="!drawer.card.parent_id" data-tip-below data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Feeds the timesheet. A card with no category cannot be logged.' : 'Masuk ke timesheet. Kad tanpa kategori tidak boleh direkod.'" x-text="$store.ui.lang==='en' ? 'Category · Project' : 'Kategori · Projek'">Category · Project</span>
                        <span class="wd-pval wd-ppair" x-show="!drawer.card.parent_id">
                            @if ($interactive)
                                <select class="wd-inline" x-model="drawer.card.timesheet_category_id" :disabled="drawer.locked"
                                        :aria-label="$store.ui.lang==='en' ? 'Category' : 'Kategori'"
                                        @change="commitField('timesheet_category_id', drawer.card.timesheet_category_id === '' ? null : drawer.card.timesheet_category_id)">
                                    <option value="" x-text="$store.ui.lang==='en' ? 'No category' : 'Tiada kategori'"></option>
                                    <template x-for="c in (drawer.card.timesheet_category_options || [])" :key="c.id">
                                        <option :value="c.id" x-text="c.name"></option>
                                    </template>
                                </select>
                                {{-- x-show, not x-if wrapped in a span: .wd-ppair styles its
                                     direct children (see .wd-ppair > .wd-inline in app.css), so a
                                     wrapper element would double the negative margin. Neither of
                                     these carries an inline `display`, so x-show has nothing to
                                     wipe on reveal. --}}
                                <span class="wd-psep" aria-hidden="true" x-show="categoryNeedsProject()" x-cloak>·</span>
                                <select class="wd-inline" x-model="drawer.card.project_id" :disabled="drawer.locked"
                                        x-show="categoryNeedsProject()" x-cloak
                                        :aria-label="$store.ui.lang==='en' ? 'Project' : 'Projek'"
                                        @change="commitField('project_id', drawer.card.project_id === '' ? null : drawer.card.project_id)">
                                    <option value="" x-text="$store.ui.lang==='en' ? 'No project' : 'Tiada projek'"></option>
                                    <template x-for="p in (drawer.card.project_options || [])" :key="p.id">
                                        <option :value="p.id" x-text="p.name"></option>
                                    </template>
                                </select>
                            @else
                                <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="drawer.card.timesheet_category_name || ($store.ui.lang==='en' ? 'No category' : 'Tiada kategori')"></span>
                                <span class="wd-psep" aria-hidden="true" x-show="drawer.card.project" x-cloak>·</span>
                                <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;"
                                      x-show="drawer.card.project" x-cloak
                                      x-text="drawer.card.project ? drawer.card.project.name : ''"></span>
                            @endif
                        </span>

                        <span class="wd-plabel" data-tip-below data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Tags for filtering the board. Recurring cards come back on their own.' : 'Tag untuk tapis papan. Kad berulang muncul semula sendiri.'" x-text="$store.ui.lang==='en' ? 'Labels' : 'Label'">Labels</span>
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

                        <span class="wd-plabel" data-tip-below data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Helper does part of the work. FYI only watches.' : 'Pembantu buat sebahagian kerja. FYI hanya lihat.'" x-text="$store.ui.lang==='en' ? 'People' : 'Orang'">People</span>
                        <span class="wd-pval">
                            <span class="wd-chiprow">
                                <template x-if="!drawer.locked">
                                    <template x-for="p in drawer.card.participants" :key="p.id">
                                        <span style="display:inline-flex;align-items:center;gap:6px;padding:3px 8px 3px 4px;border:1px solid var(--hairline);border-radius:9999px;font-size:12px;font-weight:500;color:var(--ink);background:#fff;">
                                            <span class="wa" :style="'margin-left:0;width:22px;height:22px;font-size:9px;background:' + (p.color || 'var(--muted)')" x-text="p.initials"></span>
                                            <span x-text="p.name"></span>
                                            {{-- CR-04: Helper does part of the work, FYI only watches. Click to flip. --}}
                                            <button type="button" class="wd-role-toggle" @click="setPersonRole(p.id, p.role === 'fyi' ? 'helper' : 'fyi')"
                                                    :data-tip="$store.ui.lang==='en' ? 'Helper does part of the work, FYI only watches. Click to flip.' : 'Pembantu buat sebahagian kerja, FYI hanya lihat. Klik untuk tukar.'" data-tip-wrap data-tip-start
                                                    x-text="p.role === 'fyi' ? 'FYI' : ($store.ui.lang==='en' ? 'Helper' : 'Pembantu')"></button>
                                            <button type="button" @click="removePerson(p.id)" :aria-label="($store.ui.lang==='en' ? 'Remove ' : 'Buang ') + p.name"
                                                    style="border:0;background:none;color:var(--muted);font-size:14px;line-height:1;cursor:pointer;padding:0;">×</button>
                                        </span>
                                    </template>
                                </template>
                                <template x-if="drawer.locked && drawer.card.participants.length">
                                    <span class="wa-stack">
                                        <template x-for="p in drawer.card.participants" :key="'ro'+p.id">
                                            <span class="wa" :style="'background:' + (p.color || 'var(--muted)')" :title="p.name + (p.role === 'fyi' ? ' (FYI)' : ' (Helper)')" x-text="p.initials"></span>
                                        </template>
                                    </span>
                                </template>
                                <template x-if="!drawer.card.participants.length">
                                    <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="$store.ui.lang==='en' ? 'Just you' : 'Anda sahaja'"></span>
                                </template>
                                <span style="position:relative;display:inline-block;" x-show="!drawer.locked && availablePeople.length">
                                    <button type="button" class="wd-add" @click="drawer.peopleQuery = ''; drawer.peopleMenuOpen = !drawer.peopleMenuOpen; if (drawer.peopleMenuOpen) $nextTick(() => $refs.peopleSearch.focus())"
                                            aria-haspopup="menu" :aria-expanded="drawer.peopleMenuOpen ? 'true' : 'false'">
                                        <span x-text="$store.ui.lang==='en' ? '+ Add someone' : '+ Tambah orang'"></span>
                                    </button>
                                    <div class="wd-menu" x-show="drawer.peopleMenuOpen" x-cloak @click.outside="drawer.peopleMenuOpen = false" role="menu" style="top:28px;max-height:220px;overflow:auto;">
                                        <input type="search" class="wd-inline" style="margin:0 0 4px;width:100%;" x-ref="peopleSearch" x-model="drawer.peopleQuery"
                                               @keydown.escape.stop="drawer.peopleMenuOpen = false"
                                               :placeholder="$store.ui.lang==='en' ? 'Search name or nickname' : 'Cari nama atau gelaran'"
                                               :aria-label="$store.ui.lang==='en' ? 'Search people' : 'Cari orang'" autocomplete="off">
                                        <template x-for="p in filteredPeople" :key="p.id">
                                            <div class="wd-tag-row" role="none">
                                                <span x-text="p.name"></span>
                                                <button type="button" role="menuitem" class="wd-role-pick" @click="addPerson(p.id, 'helper'); drawer.peopleQuery = ''"
                                                        x-text="$store.ui.lang==='en' ? 'Helper' : 'Pembantu'"></button>
                                                <button type="button" role="menuitem" class="wd-role-pick" @click="addPerson(p.id, 'fyi'); drawer.peopleQuery = ''">FYI</button>
                                            </div>
                                        </template>
                                        <template x-if="!filteredPeople.length">
                                            <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="$store.ui.lang==='en' ? 'Nobody found' : 'Tiada sesiapa dijumpai'"></span>
                                        </template>
                                    </div>
                                </span>
                            </span>
                        </span>

                        {{-- CR-30 Request Help: the explicit escalation. Tags a Helper and sends one message.
                             Anyone but the owner may be asked; an FYI person becomes a Helper.
                             A reaction, Send Help included, never does this. --}}
                        <template x-if="!drawer.locked && reviewerOptions.length">
                            <span class="wd-plabel" data-tip-below data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Pings a teammate with your message. They can then join as Helper.' : 'Hantar mesej kepada rakan. Mereka boleh sertai sebagai Pembantu.'" x-text="$store.ui.lang==='en' ? 'Request help' : 'Minta bantuan'">Request help</span>
                        </template>
                        <template x-if="!drawer.locked && reviewerOptions.length">
                            <span class="wd-pval">
                                <span class="wd-help-row">
                                    <select class="wd-inline" x-model="drawer.helpId" :aria-label="$store.ui.lang==='en' ? 'Who to ask' : 'Siapa untuk diminta'">
                                        <option value="" x-text="$store.ui.lang==='en' ? 'Who?' : 'Siapa?'"></option>
                                        <template x-for="p in reviewerOptions" :key="'hp'+p.id">
                                            <option :value="p.id" x-text="p.name"></option>
                                        </template>
                                    </select>
                                    <input type="text" class="wd-inline" maxlength="200" x-model="drawer.helpMessage"
                                           :placeholder="$store.ui.lang==='en' ? 'What do you need?' : 'Apa yang anda perlukan?'"
                                           @keydown.enter.prevent="requestHelp()">
                                    <button type="button" class="wd-add" @click="requestHelp()" :disabled="!drawer.helpId || !drawer.helpMessage.trim()"
                                            x-text="$store.ui.lang==='en' ? 'Ask' : 'Minta'"></button>
                                </span>
                            </span>
                        </template>

                        {{-- CR-18 Linked event: a recurring card (the company social) names the Event its
                             "Create Event" step produced. Everyone must be on the event first; the server
                             ticks that subtask, and the card cannot reach Done until the event is closed out. --}}
                        <template x-if="drawer.card.company_event || (drawer.card.event_options || []).length">
                            <span class="wd-plabel" data-tip-below data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Ties this card to a company event so attendance closes it.' : 'Kaitkan kad dengan acara syarikat supaya kehadiran menutupnya.'" x-text="$store.ui.lang==='en' ? 'Linked event' : 'Acara berkaitan'">Linked event</span>
                        </template>
                        <template x-if="drawer.card.company_event">
                            <span class="wd-pval" data-linked-event>
                                <span x-text="drawer.card.company_event.title + (drawer.card.company_event.date ? ' · ' + drawer.card.company_event.date : '')"></span>
                            </span>
                        </template>
                        <template x-if="!drawer.card.company_event && (drawer.card.event_options || []).length">
                            <span class="wd-pval">
                                <span class="wd-help-row">
                                    <select class="wd-inline" x-model="drawer.eventId" :disabled="drawer.locked" :aria-label="$store.ui.lang==='en' ? 'Which event' : 'Acara mana'">
                                        <option value="" x-text="$store.ui.lang==='en' ? 'Which event?' : 'Acara mana?'"></option>
                                        <template x-for="e in drawer.card.event_options" :key="'ev'+e.id">
                                            <option :value="e.id" x-text="e.title + (e.date ? ' · ' + e.date : '')"></option>
                                        </template>
                                    </select>
                                    <button type="button" class="wd-add" @click="linkEvent()" :disabled="drawer.locked || !drawer.eventId"
                                            x-text="$store.ui.lang==='en' ? 'Link' : 'Pautkan'"></button>
                                </span>
                            </span>
                        </template>

                        {{-- CR-04 Reviewer: set by PM and above; alone moves the card from In Review to Done. --}}
                        <span class="wd-plabel" x-show="drawer.card.can_set_reviewer || drawer.card.reviewer" data-tip-below data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Signs the card off. It cannot reach Done without them.' : 'Mengesahkan kad. Tidak boleh Selesai tanpa mereka.'" x-text="$store.ui.lang==='en' ? 'Reviewer' : 'Penyemak'">Reviewer</span>
                        <span class="wd-pval" x-show="drawer.card.can_set_reviewer || drawer.card.reviewer">
                            {{-- The server says who may set it (PM and above, covering the owner), so the
                                 control follows can_set_reviewer rather than the drawer lock: the team board
                                 is otherwise read-only, yet it is where a PM meets a staff member's card. --}}
                            <template x-if="drawer.card.can_set_reviewer">
                                <select class="wd-inline" :value="drawer.card.reviewer_id || ''" @change="setReviewer($event.target.value)">
                                    <option value="" x-text="$store.ui.lang==='en' ? 'No reviewer' : 'Tiada penyemak'"></option>
                                    <template x-for="p in reviewerOptions" :key="'rv'+p.id">
                                        <option :value="p.id" :selected="p.id === drawer.card.reviewer_id" x-text="p.name"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="!drawer.card.can_set_reviewer">
                                <span class="wd-inline" :class="{ 'wd-inline--empty': !drawer.card.reviewer }" style="margin:0;padding-left:0;"
                                      x-text="drawer.card.reviewer ? drawer.card.reviewer.name : ($store.ui.lang==='en' ? 'None' : 'Tiada')"></span>
                            </template>
                        </span>

                        {{-- CR-28: Milestone — set by PM and above; only a Milestone card can ring the Victory Bell. --}}
                        <span class="wd-plabel" x-show="drawer.card.can_set_milestone || drawer.card.is_milestone" data-tip-below data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Big deal card. PM and above set it, and only a Milestone can ring the bell.' : 'Kad besar. PM ke atas tetapkan, dan hanya Pencapaian boleh bunyikan loceng.'" x-text="$store.ui.lang==='en' ? 'Milestone' : 'Pencapaian'">Milestone</span>
                        <span class="wd-pval" x-show="drawer.card.can_set_milestone || drawer.card.is_milestone">
                            <template x-if="drawer.card.can_set_milestone">
                                <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
                                    <input type="checkbox" :checked="drawer.card.is_milestone" :disabled="drawer.locked"
                                           @change="setMilestone($event.target.checked)">
                                    <span x-text="$store.ui.lang==='en' ? 'Flag as Milestone' : 'Tanda sebagai Pencapaian'"></span>
                                </label>
                            </template>
                            <template x-if="!drawer.card.can_set_milestone">
                                <span class="wd-inline" :class="{ 'wd-inline--empty': !drawer.card.is_milestone }" style="margin:0;padding-left:0;"
                                      x-text="drawer.card.is_milestone ? ($store.ui.lang==='en' ? 'Milestone' : 'Pencapaian') : ($store.ui.lang==='en' ? 'None' : 'Tiada')"></span>
                            </template>
                        </span>
                    </div>

                    {{-- CR-28: persistent "Ring the bell" action for a Done, unrung Milestone
                         card — the toast (work-board.js ringPrompt) offers it right after the
                         move; this stays for later. --}}
                    <template x-if="drawer.card.can_ring_bell">
                        <button type="button" class="uj-btn-ghost" style="align-self:flex-start;" @click="ringBell()" data-tip-start data-tip-wrap :data-tip="$store.ui.lang==='en' ? 'Tells the whole company this milestone landed. Once per card.' : 'Beritahu seluruh syarikat pencapaian ini tercapai. Sekali setiap kad.'"
                                x-text="$store.ui.lang==='en' ? '🔔 Ring the bell' : '🔔 Bunyikan loceng'"></button>
                    </template>

                    <h3 class="wd-sech" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</h3>
                    <textarea class="wd-desc" x-model="drawer.card.description" :readonly="drawer.locked" maxlength="5000"
                              :placeholder="$store.ui.lang==='en' ? 'Add more detail…' : 'Tambah butiran…'"
                              @input="scheduleCommit('description')" @blur="commitFieldFromCard('description')"></textarea>

                    {{-- Phone only (.wd-subpill): the overview cannot sit beside a full-width
                         drawer, so this pill opens it as a bottom sheet. Desktop hides it. --}}
                    <template x-if="drawer.family && (drawer.family.children.length || drawer.card.parent_id)">
                        <div class="wd-subpill-wrap" x-data="{ get done() { return drawer.family.children.filter(c => c.status === 'done').length; }, get total() { return drawer.family.children.length; }, get next() { return drawer.family.children.find(c => c.status !== 'done'); } }">
                            <h3 class="wd-sech" x-text="$store.ui.lang==='en' ? 'Subtasks' : 'Subtugas'">Subtasks</h3>
                            <button type="button" class="wd-subpill" @click="drawer.ovOpen = true" :style="'--p:' + Math.round(100 * done / Math.max(1, total)) + '%'">
                                <span class="wd-subpill-ring"><i x-text="done + '/' + total"></i></span>
                                <span class="wd-subpill-txt">
                                    <b x-text="$store.ui.lang==='en' ? (done + ' of ' + total + ' done') : (done + ' daripada ' + total + ' selesai')"></b>
                                    <small x-text="next ? (($store.ui.lang==='en' ? 'Next: ' : 'Seterusnya: ') + next.title) : ($store.ui.lang==='en' ? 'All done' : 'Semua selesai')"></small>
                                </span>
                                <span class="wd-subpill-chev" aria-hidden="true">&rsaquo;</span>
                            </button>
                        </div>
                    </template>

                    @if ($interactive)
                        {{-- The first subtask is added from here; once one exists the overview beside
                             the drawer carries the list and the add row. --}}
                        <template x-if="!drawer.card.parent_id && drawer.canAddChild && drawer.family && !drawer.family.children.length">
                            <div>
                                <h3 class="wd-sech" x-text="$store.ui.lang==='en' ? 'Subtasks' : 'Subtugas'">Subtasks</h3>
                                <input class="wd-inline" style="margin:0 0 6px;" x-model="drawer.newChildTitle" maxlength="160" :disabled="drawer.addingChild"
                                       :placeholder="$store.ui.lang==='en' ? '+ Add a subtask' : '+ Tambah subtugas'"
                                       @keydown.enter.prevent="addChild()">
                                {{-- Due date first: a subtask's date locks on first save. --}}
                                <input type="date" class="wd-inline" style="margin:0 0 12px;" x-model="drawer.newChildDueAt" :disabled="drawer.addingChild"
                                       :aria-label="$store.ui.lang==='en' ? 'Subtask due date' : 'Tarikh akhir subtugas'"
                                       @keydown.enter.prevent="addChild()">
                            </div>
                        </template>
                    @endif

                    <h3 class="wd-sech" x-text="$store.ui.lang==='en' ? 'Links' : 'Pautan'">Links</h3>
                    <template x-if="!drawer.locked">
                        <div>
                            <template x-for="(link, idx) in drawer.card.links" :key="idx">
                                <div>
                                    {{-- A saved row (label + url both filled) shows as its clickable
                                         button instead, unless editLink() forced it back open. --}}
                                    <div class="wd-link-row" x-show="drawer.editingLinkIdx === idx || !link.label.trim() || !link.url.trim()">
                                        <input class="wd-inline" style="margin:0;" x-model="link.label" @input="onLinkInput()" @blur="finishLinkEdit(idx)" placeholder="Label" maxlength="60">
                                        <input class="wd-inline" style="margin:0;" x-model="link.url" @input="onLinkInput()" @blur="finishLinkEdit(idx)" placeholder="https://...">
                                        <button type="button" @click="removeLink(idx)" style="border:0;background:none;color:var(--muted);font-size:14px;cursor:pointer;">&times;</button>
                                    </div>
                                    <div class="wd-chiprow" x-show="!(drawer.editingLinkIdx === idx || !link.label.trim() || !link.url.trim())" style="margin-bottom:8px;">
                                        <a :href="link.url" target="_blank" rel="noopener noreferrer" class="wd-inline" style="margin:0;" x-text="link.label"></a>
                                        <button type="button" @click="editLink(idx)" :aria-label="$store.ui.lang==='en' ? 'Edit link' : 'Sunting pautan'" style="border:0;background:none;color:var(--muted);font-size:13px;cursor:pointer;padding:2px 4px;">&#9998;</button>
                                        <button type="button" @click="removeLink(idx)" :aria-label="$store.ui.lang==='en' ? 'Remove link' : 'Buang pautan'" style="border:0;background:none;color:var(--muted);font-size:14px;cursor:pointer;padding:2px 4px;">&times;</button>
                                    </div>
                                </div>
                            </template>
                            <button type="button" class="wd-add" @click="addLink()">
                                <span x-text="$store.ui.lang==='en' ? '+ Add a link' : '+ Tambah pautan'"></span>
                            </button>
                        </div>
                    </template>
                    <template x-if="drawer.locked">
                        <div class="wd-chiprow">
                            <template x-for="link in drawer.card.links" :key="link.url">
                                <a :href="link.url" target="_blank" rel="noopener noreferrer" class="wd-inline" x-text="link.label"></a>
                            </template>
                            <template x-if="!drawer.card.links.length">
                                <span class="wd-inline wd-inline--empty" style="margin:0;padding-left:0;" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'"></span>
                            </template>
                        </div>
                    </template>

                    <hr class="wd-rule">

                    <h3 class="wd-sech" x-text="drawer.comments.length ? (($store.ui.lang==='en' ? 'Comments' : 'Komen') + ' (' + drawer.comments.length + ')') : ($store.ui.lang==='en' ? 'Comments' : 'Komen')">Comments</h3>
                    <div class="wd-cmts">
                        <template x-for="c in drawer.comments" :key="c.id">
                            <div class="wd-cmt" :class="{ 'wd-cmt--system': c.is_system }">
                                {{-- CR-19: an auto-close activity line carries no employee_id — a system
                                     mark renders instead of an avatar. --}}
                                <span class="wd-cmt-mark" x-show="c.is_system"><svg viewBox="0 0 24 24" fill="currentColor" width="13" height="13"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg></span>
                                <span class="wa" :style="'background:' + c.color" x-text="c.initials" x-show="!c.is_system"></span>
                                <div style="flex:1;min-width:0;">
                                    <div class="wd-cmt-who">
                                        <span class="wd-cmt-name" x-text="c.author"></span>
                                        <span class="wd-cmt-at" x-text="c.when"></span>
                                        {{-- CR-08: an official record in Track, with its version; greyed once withdrawn. --}}
                                        <span class="wd-cmt-track" x-show="c.pushed && !c.withdrawn_reason"
                                              :title="$store.ui.lang==='en' ? 'Official record in Track' : 'Rekod rasmi dalam Track'"
                                              x-text="($store.ui.lang==='en' ? 'In Track' : 'Dalam Track') + (c.track_version > 1 ? ' · v' + c.track_version : '')"></span>
                                        <span class="wd-cmt-track wd-cmt-track--off" x-show="c.withdrawn_reason"
                                              :title="c.withdrawn_reason"
                                              x-text="$store.ui.lang==='en' ? 'Withdrawn' : 'Ditarik balik'"></span>
                                        <span class="wd-cmt-acts" x-show="c.mine && !c.is_system && drawer.editing.id !== c.id">
                                            <button type="button" @click="startEditComment(c)" x-show="!c.withdrawn_reason" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'"></button>
                                            <button type="button" @click="withdrawComment(c)" x-show="c.pushed && !c.withdrawn_reason" x-text="$store.ui.lang==='en' ? 'Withdraw' : 'Tarik balik'"></button>
                                            <button type="button" @click="deleteComment(c.id)" x-show="!c.pushed" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'"></button>
                                        </span>
                                    </div>
                                    {{-- Escaped first, then tinted: c.body is user input, and renderCommentBody()
                                         only ever wraps exact-match substrings of the card's own mentionable
                                         names inside the ALREADY-escaped string — see work-board.js/team-board.js. --}}
                                    <div class="wd-cmt-body" :class="{ 'wd-cmt-body--off': c.withdrawn_reason }" x-show="drawer.editing.id !== c.id" x-html="renderCommentBody(c.body)"></div>
                                    <template x-if="drawer.editing.id === c.id">
                                        <div class="wd-cmt-edit">
                                            <textarea x-model="drawer.editing.body" rows="2" maxlength="2000" @keydown.enter.meta.prevent="saveEditComment()" @keydown.escape.stop="cancelEditComment()"></textarea>
                                            <p x-show="c.pushed" x-text="$store.ui.lang==='en' ? 'Saving makes a new version in Track; the old one stays in its history.' : 'Simpan akan buat versi baharu dalam Track; yang lama kekal dalam sejarah.'"></p>
                                            <div>
                                                <button type="button" class="uj-btn-primary" @click="saveEditComment()" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'"></button>
                                                <button type="button" @click="cancelEditComment()" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'"></button>
                                            </div>
                                        </div>
                                    </template>
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
             work-board.js (and its team-board.js counterpart). --}}
        <div class="wd-foot wd-foot--reveal" :class="{ 'has-text': drawer.newComment.trim().length }">
            {{-- CR-08: Push to Track. Only PM and above, or the project's PE/PM, see it. Off
                 on every open, never remembered. Disabled with the reason when the project
                 is not linked to Track or the card is Internal. Ticking shows the exact
                 text Track will display. --}}
            <template x-if="drawer.card.can_push_to_track">
                <div class="wd-push" data-testid="push-to-track">
                    <label :class="{ 'is-off': drawer.card.push_to_track_disabled }" :title="drawer.card.push_to_track_disabled || ''">
                        <input type="checkbox" :checked="drawer.pushToTrack" :disabled="!!drawer.card.push_to_track_disabled" @change="togglePushToTrack()">
                        <span x-text="$store.ui.lang==='en' ? 'Push to Track' : 'Hantar ke Track'"></span>
                        <span class="wd-push-why" x-show="drawer.card.push_to_track_disabled" x-text="drawer.card.push_to_track_disabled"></span>
                    </label>
                    <div class="wd-push-prev" x-show="drawer.pushToTrack" x-cloak>
                        <div class="wd-push-prev-h" x-text="$store.ui.lang==='en' ? 'Will appear in Track as' : 'Akan dipaparkan dalam Track sebagai'"></div>
                        <div class="wd-push-prev-b" x-text="drawer.pushPreview || (drawer.newComment.trim() ? '…' : ($store.ui.lang==='en' ? 'Type the comment first.' : 'Tulis komen dahulu.'))"></div>
                        <div class="wd-push-prev-n" x-text="$store.ui.lang==='en' ? 'Mentions become plain names. Nobody in Track is notified. Once posted this is an official project record.' : 'Sebutan jadi nama biasa. Tiada sesiapa dalam Track dimaklumkan. Selepas dihantar ini menjadi rekod rasmi projek.'"></div>
                    </div>
                </div>
            </template>
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
            <textarea x-ref="newCommentEl" x-model="drawer.newComment" @input="onCommentInput($event)" @input.debounce.400ms="refreshPushPreview()" @keydown="onCommentKeydown($event)"
                      @blur="setTimeout(() => closeMention(), 120)" @keydown.enter.meta.prevent="addComment()" rows="1" maxlength="2000"
                      :placeholder="$store.ui.lang==='en' ? 'Write a comment, or type @ to notify someone…' : 'Tulis komen, atau taip @ untuk maklumkan seseorang…'"></textarea>
            <button type="button" class="uj-btn-primary wd-post" @click="addComment()">
                <span x-text="$store.ui.lang==='en' ? 'Post' : 'Hantar'">Post</span>
            </button>
        </div>
    </aside>
</div>
</template>
