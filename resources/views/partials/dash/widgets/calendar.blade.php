@php
    /** Reuses CalendarController::screenData, so the grid here and the Calendar
        screen can never drift apart. Month navigation is deliberately absent for
        now — it needs a partial swap, which is a later phase.

        The month arrows in the header say which month is drawn, so the strip
        below is only ever today's date — the month badge that used to sit here
        would have repeated the arrows' own label.

        The tabs widen the circle rather than filter it: Personal is your own
        leave plus what applies to everyone, Team adds your reports, Company is
        everyone. Every entry is rendered once carrying the narrowest tab it
        belongs to, and the tab decides what shows — so switching tabs and picking
        a day are instant, with no trip to the server. */
    $today = now();
    $days = $w['days'] ?? [];
    $tabs = $w['calTabs'] ?? ['personal', 'company'];
    $tabLevels = ['personal' => 0, 'team' => 1, 'company' => 2];
    $tabLabels = ['personal' => ['Personal', 'Peribadi'], 'team' => ['Team', 'Pasukan'], 'company' => ['Company', 'Syarikat']];
    $kindLabels = ['leave' => ['Leave', 'Cuti'], 'awaiting' => ['Waiting for approval', 'Menunggu kelulusan'], 'pending' => ['Pending', 'Menunggu'], 'holiday' => ['Holiday', 'Cuti umum'], 'event' => ['Event', 'Acara'], 'task' => ['Task', 'Tugas'], 'note' => ['Note', 'Nota']];
    $noteRoutes = \Illuminate\Support\Facades\Route::has('calendar-notes.store');
    // Day label and the per-tab entry count, so the panel heading can be written
    // client-side without shipping every day's heading as markup.
    $meta = collect($days)->map(fn (array $d): array => [
        'label' => $d['label'],
        'counts' => collect($d['marks'])->map(fn (array $m): int => $m['count'])->all(),
    ])->all();
@endphp
<div x-data="{
        tab: @js($tabs[0]),
        sel: @js($w['selected'] ?? $today->toDateString()),
        meta: @js($meta),
        level() { return ({ personal: 0, team: 1, company: 2 })[this.tab]; },
        count() { return this.meta[this.sel]?.counts[this.tab] ?? 0; },
        // Personal-tab editing. `form` is null (closed), 'note' or 'pin'; `edit`
        // holds the note being changed. Every save answers with this card's fresh
        // markup, swapped in place — nothing else on the page moves.
        form: null, edit: null, busy: false, err: '',
        note: { title: '', starts_at: '', ends_at: '', body: '' },
        pin: '',
        // Note time pickers: half-hour slots in our own list rather than the
        // browser's time box. `timeOpen` is which list is open, if any.
        timeOpen: null,
        times(which) {
            const out = [];
            for (let m = 0; m < 1440; m += 30) { out.push(String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0')); }
            const cur = this.note[which];
            if (cur && ! out.includes(cur)) { out.push(cur); out.sort(); }
            return which === 'ends_at' ? out.filter(t => t > this.note.starts_at) : out;
        },
        toggleTime(which) {
            if (which === 'ends_at' && ! this.note.starts_at) { return; }
            this.timeOpen = this.timeOpen === which ? null : which;
            if (! this.timeOpen) { return; }
            this.$nextTick(() => {
                const list = this.$refs[which === 'starts_at' ? 'startList' : 'endList'];
                const at = list?.querySelector('[data-t][aria-selected=true]') ?? list?.querySelector('[data-t=\'08:00\']') ?? list?.querySelector('[data-t]');
                if (list && at) { list.scrollTop = at.offsetTop - 4; }
            });
        },
        setTime(which, t) {
            this.note[which] = t;
            if (which === 'starts_at' && (t === '' || (this.note.ends_at && this.note.ends_at <= t))) { this.note.ends_at = ''; }
            this.timeOpen = null;
        },
        // The pin picker: a searchable list of your open cards, filtered by title
        // as you type. `pinQ` is the search text, `pinIdx` the highlighted row.
        cards: @js($w['pinnable'] ?? []), pinQ: '', pinOpen: false, pinIdx: 0,
        pinHits() {
            const q = this.pinQ.trim().toLowerCase();
            return q === '' ? this.cards : this.cards.filter(c => c.title.toLowerCase().includes(q));
        },
        pinPick(c) { this.pin = c.id; this.pinQ = c.title; this.pinOpen = false; },
        pinKey(e) {
            const hits = this.pinHits();
            if (e.key === 'ArrowDown') { e.preventDefault(); this.pinOpen = true; this.pinIdx = Math.min(this.pinIdx + 1, hits.length - 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.pinIdx = Math.max(this.pinIdx - 1, 0); }
            else if (e.key === 'Enter' && this.pinOpen && hits[this.pinIdx]) { e.preventDefault(); this.pinPick(hits[this.pinIdx]); }
            else if (e.key === 'Escape') { this.pinOpen = false; }
        },
        openNote(e) {
            this.edit = e ?? null;
            this.note = { title: e?.title ?? '', starts_at: e?.starts_at ?? '', ends_at: e?.ends_at ?? '', body: e?.body ?? '' };
            this.err = ''; this.timeOpen = null; this.form = 'note';
            this.$nextTick(() => this.$refs.noteTitle?.focus());
        },
        openPin() {
            this.pin = ''; this.pinQ = ''; this.pinIdx = 0; this.err = ''; this.form = 'pin'; this.pinOpen = true;
            this.$nextTick(() => this.$refs.pinSearch?.focus());
        },
        async send(url, method, data) {
            const card = this.$root.closest('.uj-dw');
            this.busy = true; this.err = '';
            try {
                const res = await fetch(url, { method: 'POST', headers: {
                        'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html, application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'X-HTTP-Method-Override': method, 'Content-Type': 'application/json' },
                    body: JSON.stringify(data) });
                if (res.status === 422) {
                    const j = await res.json(); this.err = Object.values(j.errors ?? {})[0]?.[0] ?? j.message; return;
                }
                if (!res.ok) { this.err = 'Could not save. Try again.'; return; }
                const html = await res.text();
                if (card) { card.innerHTML = html; } else { this.form = null; }
            } catch { this.err = 'Could not save. Try again.'; }
            finally { this.busy = false; }
        },
        saveNote() {
            const d = Object.assign({}, this.note, { date: this.sel });
            for (const k of ['starts_at', 'ends_at', 'body']) { if (!d[k]) { d[k] = null; } }
            return this.edit
                ? this.send(@js($noteRoutes ? route('calendar-notes.update', '__id__') : '').replace('__id__', this.edit.id), 'PATCH', d)
                : this.send(@js($noteRoutes ? route('calendar-notes.store') : ''), 'POST', d);
        },
        savePin() {
            if (!this.pin) { return; }
            return this.send(@js($noteRoutes ? route('calendar-notes.store') : ''), 'POST', { date: this.sel, work_item_id: this.pin });
        },
        remove(id) {
            return this.send(@js($noteRoutes ? route('calendar-notes.destroy', '__id__') : '').replace('__id__', id), 'DELETE', {});
        },
    }">
    <div class="uj-dw-cal-today">
        <span>
            <span class="dow">{{ $today->format('l') }}</span>
            <span class="big">{{ $today->format('j F') }}</span>
        </span>
        <a class="uj-dw-btn uj-dw-btn-red" style="margin-left:auto" href="{{ route('app.screen', 'leave') }}"
           x-text="$store.ui.lang==='en' ? 'New request' : 'Permohonan baru'">New request</a>
    </div>
    <div class="uj-dw-cal-grid">
        @foreach ($w['weekdays'] ?? [] as $d)
            <div class="uj-dw-cal-dow">{{ $d }}</div>
        @endforeach
    </div>
    <div class="uj-dw-cal-grid">
        @foreach ($w['weeks'] ?? [] as $week)
            @foreach ($week as $day)
                @php $key = $day['date']->toDateString(); @endphp
                @if (! $day['inMonth'])
                    <div class="uj-dw-cal-day" data-out><span class="n">{{ $day['date']->format('j') }}</span></div>
                @else
                    <button type="button" class="uj-dw-cal-day" @click="sel = @js($key)"
                            @if ($day['isToday']) data-today @endif
                            :data-sel="sel === @js($key) ? '' : null">
                        <span class="n">{{ $day['date']->format('j') }}</span>
                        @foreach ($tabs as $tab)
                            @php $mark = $days[$key]['marks'][$tab] ?? ['pills' => [], 'more' => 0]; @endphp
                            @continue (! $mark['pills'])
                            <span class="uj-dw-cal-marks" x-show="tab === @js($tab)" x-cloak>
                                @foreach ($mark['pills'] as $pill)
                                    <span class="uj-dw-cal-pill" data-k="{{ $pill['kind'] }}" title="{{ $pill['label'] }}">{{ $pill['label'] }}</span>
                                @endforeach
                                @if ($mark['more'])
                                    <span class="uj-dw-cal-more">+{{ $mark['more'] }}</span>
                                @endif
                            </span>
                        @endforeach
                    </button>
                @endif
            @endforeach
        @endforeach
    </div>

    {{-- Whose days you are looking at. Nobody reporting to you means no Team tab:
         it would say exactly what Personal already says. --}}
    <div class="uj-dw-cal-tabs">
        @foreach ($tabs as $tab)
            <button type="button" @click="tab = @js($tab)" :data-on="tab === @js($tab) ? '' : null"
                    x-data="{ en: @js($tabLabels[$tab][0]), ms: @js($tabLabels[$tab][1]) }"
                    x-text="$store.ui.lang==='en' ? en : ms">{{ $tabLabels[$tab][0] }}</button>
        @endforeach
    </div>
    <div class="uj-dw-cal-panel">
        <p class="uj-dw-cal-ph" x-text="(meta[sel]?.label ?? '') + (count()
            ? ' · ' + count() + ' ' + ($store.ui.lang==='en' ? (count() === 1 ? 'entry' : 'entries') : 'perkara')
            : '')"></p>
        @foreach ($days as $key => $day)
            @foreach ($day['entries'] as $entry)
                <div class="uj-dw-cal-item" x-cloak
                     x-show="sel === @js($key) && level() >= @js($entry['level'])">
                    <span class="av">{{ $entry['who'] }}</span>
                    <span class="txt">
                        <span class="t">{{ $entry['title'] }}</span>
                        <span class="s">{{ $entry['sub'] }}</span>
                    </span>
                    <span class="uj-dw-cal-tag" data-k="{{ $entry['kind'] }}"
                          x-data="{ en: @js($kindLabels[$entry['kind']][0]), ms: @js($kindLabels[$entry['kind']][1]) }"
                          x-text="$store.ui.lang==='en' ? en : ms">{{ $kindLabels[$entry['kind']][0] }}</span>
                    @if (isset($entry['id']) && $noteRoutes)
                        @if ($entry['kind'] === 'note')
                            <button type="button" class="uj-dw-cal-x" :disabled="busy"
                                    @click="openNote(@js(['id' => $entry['id'], 'title' => $entry['title'], 'starts_at' => $entry['starts_at'], 'ends_at' => $entry['ends_at'], 'body' => $entry['body']]))"
                                    :aria-label="$store.ui.lang==='en' ? 'Edit note' : 'Sunting nota'" title="Edit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            </button>
                        @endif
                        <button type="button" class="uj-dw-cal-x" :disabled="busy" @click="remove(@js($entry['id']))"
                                :aria-label="$store.ui.lang==='en' ? 'Remove' : 'Buang'" title="Remove">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    @endif
                </div>
            @endforeach
        @endforeach
        <p class="uj-dw-empty" x-show="! count()" x-cloak
           x-text="$store.ui.lang==='en' ? 'Nothing on this day.' : 'Tiada apa-apa pada hari ini.'">Nothing on this day.</p>

        @if ($noteRoutes && ($w['pinnable'] ?? null) !== null)
            {{-- Your own additions to a day, Personal tab only: a note, or one of your
                 open board cards (yours, or one you review or are tagged on) pinned here. Both private to you. --}}
            <div class="uj-dw-cal-add" x-show="tab === 'personal' && form === null" x-cloak>
                <button type="button" class="uj-dw-btn uj-dw-btn-ghost" @click="openNote()"
                        x-text="$store.ui.lang==='en' ? '+ Note' : '+ Nota'">+ Note</button>
                @if ($w['pinnable'])
                    <button type="button" class="uj-dw-btn uj-dw-btn-ghost" @click="openPin()"
                            x-text="$store.ui.lang==='en' ? '+ Pin a card' : '+ Sematkan kad'">+ Pin a card</button>
                @endif
                <span class="uj-dw-cal-add-h" x-text="$store.ui.lang==='en' ? 'Add to this day. Only you can see it.' : 'Tambah pada hari ini. Hanya anda boleh melihatnya.'">Add to this day. Only you can see it.</span>
            </div>
            <form class="uj-dw-cal-form" x-show="form === 'note'" x-cloak @submit.prevent="saveNote()" @keydown.escape="timeOpen = null">
                <div class="uj-dw-cal-fh">
                    <p class="t" x-text="($store.ui.lang==='en' ? (edit ? 'Edit note' : 'New note') : (edit ? 'Sunting nota' : 'Nota baharu')) + ' · ' + (meta[sel]?.label ?? '')"></p>
                    <p class="s" x-text="$store.ui.lang==='en' ? 'A private reminder on this day. Only you can see it.' : 'Peringatan peribadi pada hari ini. Hanya anda boleh melihatnya.'"></p>
                </div>
                <label class="uj-dw-cal-lbl">
                    <span x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</span>
                    <input type="text" class="uj-dw-cal-in" x-model="note.title" maxlength="120" required
                           :placeholder="$store.ui.lang==='en' ? 'e.g. Dentist' : 'cth. Doktor gigi'" x-ref="noteTitle">
                </label>
                <div class="uj-dw-cal-lbl" @click.outside="timeOpen = null">
                    <span x-text="$store.ui.lang==='en' ? 'Time' : 'Masa'">Time</span>
                    <div class="uj-dw-cal-time">
                        <button type="button" class="uj-dw-cal-in uj-dw-cal-sel" @click="toggleTime('starts_at')"
                                :aria-expanded="timeOpen === 'starts_at' ? 'true' : 'false'" :aria-label="$store.ui.lang==='en' ? 'Starts' : 'Mula'">
                            <span x-text="note.starts_at || ($store.ui.lang==='en' ? 'All day' : 'Sepanjang hari')"></span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <span>–</span>
                        <button type="button" class="uj-dw-cal-in uj-dw-cal-sel" @click="toggleTime('ends_at')" :disabled="! note.starts_at"
                                :aria-expanded="timeOpen === 'ends_at' ? 'true' : 'false'" :aria-label="$store.ui.lang==='en' ? 'Ends' : 'Tamat'">
                            <span x-text="note.ends_at || ($store.ui.lang==='en' ? 'No end time' : 'Tiada masa tamat')"></span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                    </div>
                    <div class="uj-dw-cal-pick-list uj-dw-cal-times" x-ref="startList" role="listbox" x-show="timeOpen === 'starts_at'" x-cloak>
                        <button type="button" role="option" class="wide" :aria-selected="note.starts_at === '' ? 'true' : 'false'" @click="setTime('starts_at', '')">
                            <span class="t" x-text="$store.ui.lang==='en' ? 'All day' : 'Sepanjang hari'"></span>
                        </button>
                        <template x-for="t in times('starts_at')" :key="t">
                            <button type="button" role="option" :data-t="t" :aria-selected="note.starts_at === t ? 'true' : 'false'" @click="setTime('starts_at', t)">
                                <span class="t" x-text="t"></span>
                            </button>
                        </template>
                    </div>
                    <div class="uj-dw-cal-pick-list uj-dw-cal-times" x-ref="endList" role="listbox" x-show="timeOpen === 'ends_at'" x-cloak>
                        <button type="button" role="option" class="wide" :aria-selected="note.ends_at === '' ? 'true' : 'false'" @click="setTime('ends_at', '')">
                            <span class="t" x-text="$store.ui.lang==='en' ? 'No end time' : 'Tiada masa tamat'"></span>
                        </button>
                        <template x-for="t in times('ends_at')" :key="t">
                            <button type="button" role="option" :data-t="t" :aria-selected="note.ends_at === t ? 'true' : 'false'" @click="setTime('ends_at', t)">
                                <span class="t" x-text="t"></span>
                            </button>
                        </template>
                    </div>
                </div>
                <label class="uj-dw-cal-lbl">
                    <span><span x-text="$store.ui.lang==='en' ? 'Details' : 'Butiran'">Details</span> <em x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'">(optional)</em></span>
                    <textarea class="uj-dw-cal-in" rows="2" x-model="note.body" maxlength="2000"
                              :placeholder="$store.ui.lang==='en' ? 'Anything to remember' : 'Apa-apa untuk diingat'"></textarea>
                </label>
                <p class="uj-dw-cal-err" x-show="err" x-text="err"></p>
                <div class="uj-dw-cal-acts">
                    <button type="button" class="uj-dw-btn uj-dw-btn-ghost" @click="form = null"
                            x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                    <button type="submit" class="uj-dw-btn uj-dw-btn-red" :disabled="busy || ! note.title.trim()"
                            x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                </div>
            </form>
            <form class="uj-dw-cal-form" x-show="form === 'pin'" x-cloak @submit.prevent="savePin()">
                <div class="uj-dw-cal-fh">
                    <p class="t" x-text="($store.ui.lang==='en' ? 'Pin a card' : 'Sematkan kad') + ' · ' + (meta[sel]?.label ?? '')"></p>
                    <p class="s" x-text="$store.ui.lang==='en' ? 'Shows one of your open board cards on this day. Only you can see it.' : 'Paparkan satu kad papan terbuka anda pada hari ini. Hanya anda boleh melihatnya.'"></p>
                </div>
                <div class="uj-dw-cal-pick uj-dw-cal-lbl" @click.outside="pinOpen = false">
                    <span x-text="$store.ui.lang==='en' ? 'Card' : 'Kad'">Card</span>
                    <input type="text" class="uj-dw-cal-in" x-ref="pinSearch" x-model="pinQ" autocomplete="off"
                           role="combobox" aria-controls="uj-dw-cal-pick-list" :aria-expanded="pinOpen ? 'true' : 'false'"
                           :placeholder="$store.ui.lang==='en' ? 'Search your open cards' : 'Cari kad terbuka anda'"
                           @focus="pinOpen = true" @input="pin = ''; pinIdx = 0; pinOpen = true" @keydown="pinKey($event)">
                    <div id="uj-dw-cal-pick-list" class="uj-dw-cal-pick-list" role="listbox" x-show="pinOpen" x-cloak>
                        <template x-for="(c, i) in pinHits()" :key="c.id">
                            <button type="button" role="option" :aria-selected="c.id === pin ? 'true' : 'false'"
                                    :data-active="i === pinIdx ? '' : null" @mouseenter="pinIdx = i" @mousedown.prevent="pinPick(c)">
                                <span class="t" x-text="c.title"></span>
                                <span class="m" x-text="[c.role, c.due].filter(Boolean).join(' · ')"></span>
                            </button>
                        </template>
                        <p class="uj-dw-cal-pick-none" x-show="! pinHits().length"
                           x-text="$store.ui.lang==='en' ? 'No open card matches that title.' : 'Tiada kad terbuka sepadan dengan tajuk itu.'"></p>
                    </div>
                </div>
                <p class="uj-dw-cal-err" x-show="err" x-text="err"></p>
                <div class="uj-dw-cal-acts">
                    <button type="button" class="uj-dw-btn uj-dw-btn-ghost" @click="form = null"
                            x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                    <button type="submit" class="uj-dw-btn uj-dw-btn-red" :disabled="busy || ! pin"
                            x-text="$store.ui.lang==='en' ? 'Pin' : 'Sematkan'">Pin</button>
                </div>
            </form>
        @endif
    </div>
</div>
<div class="uj-dw-foot">
    <span>{{ ($w['outThisMonth'] ?? collect())->count() }}
        <span x-text="$store.ui.lang==='en' ? 'away this month' : 'bercuti bulan ini'">away this month</span>
    </span>
    <a class="uj-dw-link" style="margin-left:auto" href="{{ route('app.screen', 'calendar') }}"
       x-text="$store.ui.lang==='en' ? 'Open calendar' : 'Buka kalendar'">Open calendar</a>
</div>
