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
    $kindLabels = ['leave' => ['Leave', 'Cuti'], 'pending' => ['Pending', 'Menunggu'], 'holiday' => ['Holiday', 'Cuti umum'], 'event' => ['Event', 'Acara'], 'task' => ['Task', 'Tugas'], 'note' => ['Note', 'Nota']];
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
        openNote(e) {
            this.edit = e ?? null;
            this.note = { title: e?.title ?? '', starts_at: e?.starts_at ?? '', ends_at: e?.ends_at ?? '', body: e?.body ?? '' };
            this.err = ''; this.form = 'note';
        },
        openPin() { this.pin = ''; this.err = ''; this.form = 'pin'; },
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
                 open board cards pinned here. Both private to you. --}}
            <div class="uj-dw-cal-add" x-show="tab === 'personal' && form === null" x-cloak>
                <button type="button" class="uj-dw-btn uj-dw-btn-ghost" @click="openNote()"
                        x-text="$store.ui.lang==='en' ? '+ Note' : '+ Nota'">+ Note</button>
                @if ($w['pinnable'])
                    <button type="button" class="uj-dw-btn uj-dw-btn-ghost" @click="openPin()"
                            x-text="$store.ui.lang==='en' ? '+ Pin a card' : '+ Sematkan kad'">+ Pin a card</button>
                @endif
            </div>
            <form class="uj-dw-cal-form" x-show="form === 'note'" x-cloak @submit.prevent="saveNote()">
                <input type="text" class="uj-dw-cal-in" x-model="note.title" maxlength="120" required
                       :placeholder="$store.ui.lang==='en' ? 'Add title' : 'Tambah tajuk'" x-ref="noteTitle">
                <div class="uj-dw-cal-time">
                    <input type="time" class="uj-dw-cal-in" x-model="note.starts_at" :aria-label="$store.ui.lang==='en' ? 'Starts' : 'Mula'">
                    <span>–</span>
                    <input type="time" class="uj-dw-cal-in" x-model="note.ends_at" :disabled="! note.starts_at" :aria-label="$store.ui.lang==='en' ? 'Ends' : 'Tamat'">
                </div>
                <textarea class="uj-dw-cal-in" rows="2" x-model="note.body" maxlength="2000"
                          :placeholder="$store.ui.lang==='en' ? 'Notes (optional)' : 'Catatan (pilihan)'"></textarea>
                <p class="uj-dw-cal-err" x-show="err" x-text="err"></p>
                <div class="uj-dw-cal-acts">
                    <button type="button" class="uj-dw-btn uj-dw-btn-ghost" @click="form = null"
                            x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                    <button type="submit" class="uj-dw-btn uj-dw-btn-red" :disabled="busy || ! note.title.trim()"
                            x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                </div>
            </form>
            <form class="uj-dw-cal-form" x-show="form === 'pin'" x-cloak @submit.prevent="savePin()">
                <select class="uj-dw-cal-in" x-model="pin" required>
                    <option value="" x-text="$store.ui.lang==='en' ? 'Choose one of your open cards' : 'Pilih kad terbuka anda'">Choose one of your open cards</option>
                    @foreach ($w['pinnable'] as $card)
                        <option value="{{ $card['id'] }}">{{ $card['title'] }}@if ($card['due']) · {{ $card['due'] }}@endif</option>
                    @endforeach
                </select>
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
