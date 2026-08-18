@extends('layouts.app')

@php
    $sc = [
        'draft' => 'var(--muted)',
        'submitted' => 'var(--amber)',
        'approved' => 'var(--success)',
        'rejected' => 'var(--error)',
    ];

    // Manday costing — visible to money roles only (manager/management/HR), set by TimesheetController.
    $canSeeCost = $canSeeCost ?? false;
    $timesheetCosts = $timesheetCosts ?? [];
    $rm = fn ($id) => array_key_exists($id, $timesheetCosts) && $timesheetCosts[$id] !== null
        ? 'RM '.number_format($timesheetCosts[$id], 2) : null;

    $weekStatus = $weekStatus ?? null;
    $weekLocked = $weekStatus && $weekStatus !== 'draft';

    // Week-header nav for the day-first capture card: $weekStart arrives as a plain ISO
    // string (TimesheetController returns ->toDateString(), not a Carbon instance), so it
    // is parsed here rather than formatted directly.
    $weekStartC = \Illuminate\Support\Carbon::parse($weekStart);
    $prevWeekStart = $weekStartC->copy()->subWeek()->toDateString();
    $nextWeekStart = $weekStartC->copy()->addWeek()->toDateString();
    $prevWeekDisabled = $prevWeekStart < $tsEarliestWeek;
@endphp

@section('screen')
@include('partials.see-all-btn', ['target' => 'timesheet-reports', 'label' => 'See all staff timesheets', 'labelMs' => 'Lihat lembaran masa semua staf'])
@include('partials.guide', [
    'key' => 'timesheets',
    'en'  => [
        'title' => 'Weekly timesheets',
        'body'  => 'Pick the week, then work through it one day at a time. Tap a day in the strip to open it, add what you worked on, and set each entry\'s percentage — every working day must reach 100% before the week can be submitted.',
        'who'   => 'Staff fill & submit · Managers & HR approve',
        'steps' => [
            'Pick the week using the arrows at the top — the strip shows each weekday with a fill bar for how much of that day is planned.',
            'Tap a day in the strip to open it. Press "+ Add what you worked on", then answer one question at a time — category, then project, then which part of it — and set the line\'s percentage. Repeat until the day reads 100%.',
            'Locked days (approved leave, public holidays) are filled in for you and can\'t be edited.',
            'Touch a line to show its own amount buttons: 100%, 50%, 25%, or "Give it the rest". A line left without a percentage stays on screen and blocks the week until you fill it in or remove it.',
            'Use "Same as <day>" to copy the previous day. Save a draft any time; press "Submit week" once every day reads 100%.',
            'Doing the same work every week? On a line, tap "Save this line as a template" and name it — it becomes a one-tap shortcut at the top of the list in every future week.',
        ],
    ],
    'ms'  => [
        'title' => 'Timesheet mingguan',
        'body'  => 'Pilih minggu, kemudian kerjakan satu hari pada satu masa. Ketik satu hari dalam jalur untuk membukanya, tambah apa yang anda kerjakan, dan tetapkan peratus setiap entri — setiap hari bekerja mesti mencapai 100% sebelum minggu boleh dihantar.',
        'who'   => 'Staf isi & hantar · Pengurus & HR luluskan',
        'steps' => [
            'Pilih minggu menggunakan anak panah di atas — jalur memaparkan setiap hari minggu dengan bar pengisian menunjukkan berapa banyak hari itu telah dirancang.',
            'Ketik satu hari dalam jalur untuk membukanya. Tekan "+ Tambah apa yang anda kerjakan", kemudian jawab satu soalan pada satu masa — kategori, projek, dan bahagiannya — kemudian tetapkan peratus baris itu. Ulang sehingga hari itu membaca 100%.',
            'Hari yang dikunci (cuti diluluskan, cuti umum) sudah diisi untuk anda dan tidak boleh disunting.',
            'Sentuh satu baris untuk memaparkan butang amaunnya sendiri: 100%, 50%, 25%, atau "Beri baki". Baris tanpa peratus kekal di skrin dan menghalang minggu itu sehingga anda mengisinya atau membuangnya.',
            'Guna "Sama seperti <hari>" untuk menyalin hari sebelumnya. Simpan draf pada bila-bila masa; tekan "Hantar minggu" apabila setiap hari membaca 100%.',
            'Buat kerja sama setiap minggu? Pada satu baris, tekan "Simpan baris ini sebagai templat" dan namakannya — ia menjadi pintasan satu ketik di bahagian atas senarai pada setiap minggu akan datang.',
        ],
    ],
])

@if (($positionMissing ?? false))
    <div class="uj-card" style="margin-bottom:16px;padding:12px 18px;display:flex;align-items:flex-start;gap:11px;font-size:12.5px;color:var(--ink);line-height:1.5;">
        <span class="uj-stamp" data-tone="amber" style="margin-top:1px;" x-text="$store.ui.lang==='en' ? 'Needs setup' : 'Perlu tetapan'">Needs setup</span>
        <span x-text="$store.ui.lang==='en' ? 'You have no position band assigned, so your timesheet cost can\'t be computed. Set it in Administration → Position & Manday Rates.' : 'Anda belum ada band pangkat, jadi kos timesheet anda tidak dapat dikira. Tetapkan di Pentadbiran → Pangkat & Kadar Manday.'">You have no position band assigned, so your timesheet cost can't be computed.</span>
    </div>
@endif

@php $tab = request()->query('tab') === 'review' ? 'review' : 'record'; @endphp
<div x-data="{
    tab: @js($tab),
    setTab(t) {
        this.tab = t
        const url = new URL(location)
        url.searchParams.set('tab', t)
        history.replaceState(null, '', url)
    },
}">
    {{-- Two tabs: Record writes the week, Review reads it back. An underline bar mirroring
         the all-staff report screen. Record opens by default — the nav names this screen
         "Timesheet" and the daily job is filling it in. --}}
    <div class="uj-tr-tabs" role="tablist">
        <button type="button" class="uj-tr-tab" role="tab" :data-on="tab==='record'"
            :aria-selected="tab==='record'" @click="setTab('record')">
            <span x-text="$store.ui.lang==='en' ? 'Record' : 'Rekod'">Record</span>
        </button>
        <button type="button" class="uj-tr-tab" role="tab" :data-on="tab==='review'"
            :aria-selected="tab==='review'" @click="setTab('review')">
            <span x-text="$store.ui.lang==='en' ? 'Review' : 'Semak'">Review</span>
        </button>
    </div>

    <div x-show="tab==='record'" x-cloak role="tabpanel">
{{-- ===================== CAPTURE (per-day cards) ===================== --}}
    <div class="uj-card uj-ts-card" style="width:100%;position:relative;"
         x-data="timesheetCapture({
            weekStart: @js($weekStart),
            days: 5,
            today: @js($tsToday),
            earliestWeek: @js($tsEarliestWeek),
            locked: @js($tsLocked),
            items: @js($tsItems),
            categories: @js($tsCategories),
            projects: @js($tsProjects),
            templates: @js($tsTemplates),
            existing: @js($existingGrid),
            readonly: @js($weekLocked),
            weekLabel: @js($weekLabel ?? null),
         })"
         @popstate.window="if (reviewing) closeReview()"
         @keydown.escape.window="if (reviewing) history.back()">

        <div x-show="!reviewing">
        {{-- ---- Week shelf: live week-percent allocated, read straight from the capture
             scope so it moves as the day is edited. Locked days count as 100%. ---- --}}
        <div class="uj-ts-shelf" style="background:var(--shelf,#ece9e1);border:1px solid var(--shelf-line,#ddd9cf);border-radius:14px;margin:-4px -4px 18px;">
            <div style="display:flex;align-items:baseline;gap:13px;flex-wrap:wrap;">
                <span class="uj-ts-shelf-figure" style="color:var(--ink);letter-spacing:-.03em;font-variant-numeric:tabular-nums;"
                    x-text="Math.round(dayDates().filter(d=>!isOffDay(d)).reduce((s,d)=>s+Math.min(dayTotal(d),100),0) / Math.max(1, dayDates().filter(d=>!isOffDay(d)).length*100) * 100) + '%'">0%</span>
                <span style="font-size:13.5px;color:var(--body);" x-text="$store.ui.lang==='en' ? 'of the week allocated' : 'daripada minggu diperuntukkan'">of the week allocated</span>
            </div>
            <div class="uj-ts-shelf-hint" style="font-size:12.5px;color:var(--muted);margin-top:7px;" x-text="$store.ui.lang==='en' ? 'Every working day must reach 100% before the week can be submitted.' : 'Setiap hari bekerja mesti mencapai 100% sebelum minggu boleh dihantar.'">Every working day must reach 100% before the week can be submitted.</div>
            <div class="uj-ts-shelf-stats" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <div class="uj-ts-shelf-stat" style="border:1px solid var(--shelf-line,#ddd9cf);border-radius:9px;display:flex;align-items:baseline;gap:7px;">
                    <b style="font:600 17px/1 var(--font-mono);color:var(--success-ink,#1f8a65);"
                       x-text="dayDates().filter(d=>!isOffDay(d) && ['done','locked'].includes(dayState(d))).length"></b>
                    <span style="font-size:11px;color:var(--body);" x-text="$store.ui.lang==='en' ? 'days at 100%' : 'hari pada 100%'">days at 100%</span>
                </div>
                <div class="uj-ts-shelf-stat" style="border:1px solid var(--shelf-line,#ddd9cf);border-radius:9px;display:flex;align-items:baseline;gap:7px;">
                    <b style="font:600 17px/1 var(--font-mono);color:var(--amber-ink,#c08532);"
                       x-text="dayDates().filter(d=>!isOffDay(d) && !['done','locked'].includes(dayState(d))).length"></b>
                    <span style="font-size:11px;color:var(--body);" x-text="$store.ui.lang==='en' ? 'left to fill' : 'lagi untuk diisi'">left to fill</span>
                </div>
            </div>
        </div>

        {{-- ---- Today header: the date opens the jump sheet; total + progress on the right ---- --}}
        <div class="uj-ts-day-head" style="display:flex;align-items:flex-start;gap:12px;">
            <div class="uj-ts-day-nav-col" style="display:flex;flex-direction:column;min-width:0;">
                <span x-show="selected === today" x-cloak style="font-size:11px;font-weight:600;color:var(--red);background:var(--red-tint);padding:3px 10px;border-radius:999px;margin-bottom:9px;"><span x-text="$store.ui.lang==='en' ? 'Today' : 'Hari ini'">Today</span></span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <button type="button" @click="stepDay(-1)" :disabled="dayDates().indexOf(selected) <= 0"
                        :style="dayDates().indexOf(selected) <= 0 ? { opacity:.3, cursor:'not-allowed' } : {}"
                        class="uj-btn-ghost" style="height:30px;width:30px;padding:0;font-size:13px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"
                        :aria-label="$store.ui.lang==='en' ? 'Previous day' : 'Hari sebelumnya'">&larr;</button>
                    <button type="button" @click="sheetOpen = true" style="display:inline-flex;align-items:center;gap:7px;border:0;background:none;padding:0;cursor:pointer;color:var(--ink);text-align:left;min-width:0;">
                        <strong class="uj-ts-day-name" style="font-weight:600;white-space:nowrap;" x-text="dayLong(selected)"></strong>
                        <span style="font-size:15px;color:var(--muted);flex-shrink:0;">&#9662;</span>
                    </button>
                    <button type="button" @click="stepDay(1)" :disabled="dayDates().indexOf(selected) >= lastSelectableIndex()"
                        :style="dayDates().indexOf(selected) >= lastSelectableIndex() ? { opacity:.3, cursor:'not-allowed' } : {}"
                        class="uj-btn-ghost" style="height:30px;width:30px;padding:0;font-size:13px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;"
                        :aria-label="$store.ui.lang==='en' ? 'Next day' : 'Hari seterusnya'">&rarr;</button>
                </div>
                <div style="font-size:12px;color:var(--muted);margin-top:3px;">{{ $weekStartC->format('j M') }} &ndash; {{ $weekStartC->copy()->addDays(4)->format('j M') }} · <span style="color:{{ $sc[$weekStatus ?? 'draft'] }};">{{ ucfirst($weekStatus ?? 'draft') }}</span> · <span x-text="$store.ui.lang==='en' ? 'tap the date to jump' : 'ketik tarikh untuk lompat'"></span></div>
            </div>
            {{-- One number for the day, with its unit, and one word for what that number
                 means. It used to read "100 / 100" with no unit, forty pixels from the
                 week's "20% of the week allocated" — two bare numbers measuring different
                 things. ---- --}}
            <div class="uj-ts-day-total">
                <div style="font-size:22px;font-weight:600;font-family:var(--font-mono);white-space:nowrap;line-height:1;font-variant-numeric:tabular-nums;"
                    :style="{ color: { empty:'var(--muted)', partial:'var(--ink)', done:'var(--success-ink)', over:'var(--error)', locked:'var(--muted)', future:'var(--muted)' }[dayState(selected)] }"
                    x-text="dayTotal(selected) + '%'"></div>
                <div style="font-size:11px;margin-top:5px;white-space:nowrap;"
                    :style="{ color: dayState(selected) === 'over' ? 'var(--error)'
                        : hasBlankRows(selected) ? 'var(--amber-ink)'
                        : dayState(selected) === 'done' ? 'var(--success-ink)' : 'var(--muted)' }"
                    x-text="dayState(selected) === 'over'
                        ? ($store.ui.lang==='en' ? 'over by ' : 'lebih ') + Math.round((dayTotal(selected) - 100) * 100) / 100 + '%'
                        : hasBlankRows(selected)
                            ? ($store.ui.lang==='en' ? 'a line has no percentage' : 'satu baris tiada peratus')
                            : dayState(selected) === 'done'
                                ? ($store.ui.lang==='en' ? 'this day is done' : 'hari ini selesai')
                                : ($store.ui.lang==='en' ? 'of this day' : 'daripada hari ini')"></div>
            </div>
        </div>

        {{-- The bar is segmented, one piece per line in the day, so it shows the split and
             not only the total. Segment colours match the dot on each row below. ---- --}}
        <div style="display:flex;gap:2px;height:7px;background:var(--hairline-soft);border-radius:999px;overflow:hidden;margin:14px 0 4px;">
            <template x-if="isFullyLocked(selected)">
                <div style="height:100%;width:100%;background:var(--muted);"></div>
            </template>
            <template x-if="!isFullyLocked(selected) && lockedPct(selected) > 0">
                <div style="height:100%;background:var(--muted);"
                    :style="{ width: (lockedPct(selected) / Math.max(100, dayTotal(selected)) * 100) + '%' }"></div>
            </template>
            <template x-for="(r, i) in (isFullyLocked(selected) ? [] : (rows[selected] || []))" :key="i">
                <div style="height:100%;transition:width .3s var(--ease);"
                    :style="{
                        width: ((parseFloat(r.percentage) || 0) / Math.max(100, dayTotal(selected)) * 100) + '%',
                        background: dayState(selected) === 'over' ? 'var(--error)' : rowColour(i),
                    }"></div>
            </template>
        </div>

        @if ($weekLocked)
            <div style="margin-top:6px;font-size:11.5px;color:var(--muted);text-align:center;">
                @if ($weekStatus === 'submitted' && $weekTimesheet)
                    <span x-text="$store.ui.lang==='en' ? 'This week is submitted. Reopen it to make changes.' : 'Minggu ini telah dihantar. Buka semula untuk membuat perubahan.'"></span>
                    <form method="post" action="{{ route('timesheets.recall', $weekTimesheet) }}" style="margin-top:8px;">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12px;">
                            <span x-text="$store.ui.lang==='en' ? 'Reopen this week' : 'Buka semula minggu ini'"></span>
                        </button>
                    </form>
                @else
                    <span x-text="$store.ui.lang==='en' ? 'This week is locked. Pick another week to edit.' : 'Minggu ini dikunci. Pilih minggu lain untuk menyunting.'"></span>
                @endif
            </div>
        @endif

        {{-- ---- Day: locked banners + editable rows (date/total/bar hoisted into the header above) ---- --}}
        <div style="border:1px solid var(--hairline);border-radius:12px;padding:16px;margin-top:16px;">

            {{-- Fully locked: an approved whole-day leave or public holiday. Read-only. --}}
            <template x-if="isFullyLocked(selected)">
                <div style="display:flex;align-items:center;gap:10px;padding:12px;border-radius:8px;background:var(--canvas);">
                    <span>&#128274;</span>
                    <div style="flex:1;">
                        <div style="font-size:12.5px;" x-text="locked[selected].label"></div>
                        <div style="font-size:11px;color:var(--muted);"
                            x-text="$store.ui.lang==='en' ? 'Nothing to do here.' : 'Tiada apa-apa untuk dibuat.'"></div>
                    </div>
                    <span style="font-family:var(--font-mono);font-size:13px;">100%</span>
                </div>
            </template>

            {{-- Half-day leave: 50% is locked to "On Leave", the staffer fills the rest below. --}}
            <template x-if="isPartlyLocked(selected)">
                <div style="display:flex;align-items:center;gap:10px;padding:12px;border-radius:8px;background:var(--canvas);margin-bottom:8px;">
                    <span>&#189;</span>
                    <div style="flex:1;">
                        <div style="font-size:12.5px;">
                            <span x-text="locked[selected].label"></span>
                            <span style="color:var(--muted);" x-show="locked[selected].period"
                                x-text="'(' + (locked[selected].period === 'am' ? ($store.ui.lang==='en' ? 'morning' : 'pagi') : ($store.ui.lang==='en' ? 'afternoon' : 'petang')) + ')'"></span>
                        </div>
                        <div style="font-size:11px;color:var(--muted);"
                            x-text="$store.ui.lang==='en' ? 'Fill the other half of the day below.' : 'Isi separuh hari yang lagi satu di bawah.'"></div>
                    </div>
                    <span style="font-family:var(--font-mono);font-size:13px;" x-text="lockedPct(selected) + '%'"></span>
                </div>
            </template>

            {{-- Future day: reachable for viewing (a shown weekend, or arrowing forward) but
                 not yet fillable — the server rejects entries for days that haven't happened. --}}
            <template x-if="isFuture(selected) && !isOffDay(selected)">
                <div style="padding:12px;border-radius:8px;background:var(--canvas);font-size:12px;color:var(--muted);">
                    <span x-text="$store.ui.lang==='en' ? 'This day hasn\'t happened yet — you can fill it in on the day or after.' : 'Hari ini belum berlaku — anda boleh mengisinya pada hari itu atau selepasnya.'"></span>
                </div>
            </template>

            {{-- Sunday rest day (Unijaya six-day week): not a recordable work day. --}}
            <template x-if="isOffDay(selected)">
                <div style="padding:12px;border-radius:8px;background:var(--canvas);font-size:12px;color:var(--muted);">
                    <span x-text="$store.ui.lang==='en' ? 'Sunday is a rest day — nothing to record.' : 'Ahad ialah hari rehat — tiada apa untuk direkod.'"></span>
                </div>
            </template>

            <template x-if="!isFullyLocked(selected) && !isFuture(selected) && !isOffDay(selected)">
                <div>
                    <template x-for="(r, i) in (rows[selected] || [])" :key="i">
                        <div class="uj-ts-row" :data-blank="isBlank(r) ? '1' : '0'"
                            style="padding:10px 0 8px;border-top:1px solid var(--hairline-soft);">
                            <div class="uj-ts-row-head" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <span class="uj-ts-row-label" style="min-width:0;display:flex;align-items:center;gap:10px;">
                                    <span style="width:8px;height:8px;border-radius:999px;flex:none;"
                                        :style="{ background: rowColour(i) }"></span>
                                    <span style="font-size:12.5px;color:var(--ink);line-height:1.35;" x-text="rowLabel(r)"></span>
                                </span>
                                {{-- The unit lives IN the box. A bare number field next to a bare
                                     "100 / 100" left testers unsure whether it wanted percent or
                                     hours, and the aria-label names the line it belongs to. --}}
                                <span style="position:relative;flex:none;">
                                    <input type="text" inputmode="decimal"
                                        x-model="r.percentage" @input="clampPct(r)" @blur="save()" :disabled="!isEditable(selected)"
                                        :aria-label="($store.ui.lang==='en' ? 'Percent of the day for ' : 'Peratus hari untuk ') + rowLabel(r)"
                                        style="width:86px;height:36px;padding:0 26px 0 10px;text-align:right;border:1px solid var(--hairline);border-radius:8px;font-family:var(--font-mono);font-size:12.5px;font-variant-numeric:tabular-nums;color:var(--ink);background:#fff;outline:none;"
                                        :style="isBlank(r) && isEditable(selected) ? { borderColor:'var(--amber)', background:'#fffaf0' } : {}" />
                                    <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-family:var(--font-mono);font-size:12.5px;color:var(--muted);pointer-events:none;">%</span>
                                </span>
                                <button type="button" @click="openEditRow(i)" :disabled="!isEditable(selected)"
                                    class="uj-btn-ghost" style="height:36px;padding:0 10px;"
                                    :aria-label="($store.ui.lang==='en' ? 'Edit ' : 'Sunting ') + rowLabel(r)">&#9998;</button>
                                <button type="button" @click="removeRow(i); save()" :disabled="!isEditable(selected)"
                                    class="uj-btn-ghost" style="height:36px;padding:0 10px;color:var(--error);"
                                    :aria-label="($store.ui.lang==='en' ? 'Remove ' : 'Buang ') + rowLabel(r)">&times;</button>
                            </div>
                            {{-- The note this line carries is rich HTML written through the
                                 popup's Quill editor (see openEditRow()/confirmEntry()) — shown
                                 read-only here so its own list/bold markup renders instead of
                                 raw tags leaking into a plain input. Tap "Edit" to change it. --}}
                            <div class="uj-markdown" x-show="r.description" x-cloak x-html="r.description"
                                style="margin-top:8px;font-size:12px;padding:8px 10px;background:var(--canvas);border-radius:7px;"></div>

                            {{-- A line with no percentage says so, and blocks the week, instead of
                                 being dropped on the next save with nothing said. --}}
                            <div x-show="isBlank(r) && isEditable(selected)" x-cloak
                                style="margin-top:8px;font-size:11px;color:var(--amber-ink);"
                                x-text="$store.ui.lang==='en' ? 'Needs a percentage — how much of this day went here?' : 'Perlukan peratus — berapa banyak hari ini pergi ke sini?'"></div>

                            {{-- The amount controls sit INSIDE the line they change, revealed by
                                 hovering or focusing that line. They used to be one strip pinned
                                 under the last row: it looked like it belonged to whichever row it
                                 sat under, and always acted on the last one. --}}
                            <div class="uj-ts-tools" x-show="isEditable(selected)" x-cloak
                                style="display:flex;align-items:center;gap:6px;margin-top:8px;flex-wrap:wrap;">
                                <span style="font-size:11px;color:var(--muted);margin-right:2px;"
                                    x-text="$store.ui.lang==='en' ? 'Set to' : 'Tetapkan'"></span>
                                <template x-for="pct in [100, 50, 25]" :key="pct">
                                    <button type="button" @click="r.percentage = pct; save()" class="uj-ts-pill"
                                        style="min-height:30px;padding:0 12px;border-radius:999px;border:1px solid var(--hairline);background:#fff;color:var(--body);font-family:var(--font-mono);font-size:11px;cursor:pointer;"
                                        x-text="pct + '%'"></button>
                                </template>
                                {{-- Names the amount and the line it lands on, and is absent when
                                     there is nothing left — the old day-level version set the last
                                     line to 0 whenever the day had already gone over 100%. --}}
                                <button type="button" x-show="remainder(selected) > 0" @click="giveRemainder(r)" class="uj-ts-pill"
                                    style="min-height:30px;padding:0 12px;border-radius:999px;border:1px solid var(--success);background:color-mix(in srgb, var(--success) 8%, #fff);color:var(--success-ink);font-size:11px;cursor:pointer;"
                                    x-text="($store.ui.lang==='en' ? 'Give it the ' : 'Beri baki ') + remainder(selected) + ($store.ui.lang==='en' ? '% left' : '%')"></button>
                                <button type="button" @click="startSaveTemplate(r)" class="uj-ts-pill"
                                    style="min-height:30px;padding:0 12px;margin-left:auto;border:1px dashed var(--hairline);border-radius:999px;background:none;color:var(--muted);font-size:11px;cursor:pointer;">
                                    <span x-text="$store.ui.lang==='en' ? 'Save this line as a template' : 'Simpan baris ini sebagai templat'"></span>
                                </button>
                            </div>
                        </div>
                    </template>

                    {{-- Task 8: save-as-template. @submit.prevent awaits save() (autosaving the
                         day) before submitting for real via $event.target.submit(), so the
                         redirect + page reload from the store route never drops in-progress
                         work. project_id/sub_pillar_id are OMITTED (via x-if) rather than
                         posted at all when a template has no project, so a template with no
                         project cleanly satisfies storeTemplate()'s nullable|integer rules
                         for those two fields — no "" ever hits the validator. ---- --}}
                    <div x-show="savingTemplate" x-cloak style="display:flex;gap:6px;align-items:flex-start;margin-top:10px;padding:10px;border:1px solid var(--hairline);border-radius:8px;background:var(--canvas);flex-wrap:wrap;">
                        <form method="post" action="{{ route('timesheets.templates.store') }}"
                            @submit.prevent="await save(); $event.target.submit()"
                            style="display:flex;gap:6px;flex:1;min-width:200px;flex-wrap:wrap;">
                            @csrf
                            <input type="text" name="name" x-model="templateDraft.name" required maxlength="80"
                                :placeholder="$store.ui.lang==='en' ? 'Name this template' : 'Namakan templat ini'"
                                style="flex:1;min-width:140px;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;outline:none;" />
                            <input type="hidden" name="category_id" :value="templateDraft.category_id" />
                            <template x-if="templateDraft.project_id">
                                <input type="hidden" name="project_id" :value="templateDraft.project_id" />
                            </template>
                            <template x-if="templateDraft.sub_pillar_id">
                                <input type="hidden" name="sub_pillar_id" :value="templateDraft.sub_pillar_id" />
                            </template>
                            <input type="hidden" name="percentage" :value="templateDraft.percentage" />
                            <button type="submit" :disabled="saving" class="uj-btn-primary" style="height:32px;padding:0 14px;font-size:12px;">
                                <span x-text="$store.ui.lang==='en' ? 'Save template' : 'Simpan templat'"></span>
                            </button>
                        </form>
                        <button type="button" @click="savingTemplate = false" class="uj-btn-ghost" style="height:32px;padding:0 10px;font-size:11px;">
                            <span x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'"></span>
                        </button>
                    </div>

                    <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
                        <button type="button" @click="copyPreviousDay()" x-show="previousWorkday(selected)"
                            :disabled="!isEditable(selected)" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;">
                            <span x-text="($store.ui.lang==='en' ? 'Same as ' : 'Sama seperti ') + dayName(previousWorkday(selected) || selected)"></span>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        {{-- ---- Add affordance: opens the picker as a popup, one question at a time.

             Category, then project, then sub-pillar. A flat list of all ~31 combinations was
             tried in between and rejected: it is too much to read when the staffer already
             knows the category they want. A step the data does not need is skipped, so a
             standalone category is one tap and a project with no sub-pillar is two. Work the
             day already carries is greyed rather than hidden, so the staffer can see it is
             there instead of adding an indistinguishable twin.

             The picker itself lives in a popup (below), not inline here, so opening it never
             reflows the day's rows underneath — the #1 complaint on the old inline-expanding
             panel, worst on a phone where the expansion pushed "Submit week" off screen. ---- --}}
        <div x-show="isEditable(selected)" x-cloak style="margin-top:12px;">
            <button type="button" x-ref="addEntryBtn" @click="openPicker(); $nextTick(() => $refs.pickerCloseBtn?.focus())"
                style="width:100%;padding:10px;border:1px dashed var(--hairline);border-radius:10px;background:none;cursor:pointer;font-size:12.5px;color:var(--muted);">
                <span x-text="$store.ui.lang==='en' ? '+ Add what you worked on' : '+ Tambah apa yang anda kerjakan'"></span>
            </button>
        </div>

        {{-- ---- Add-entry popup: same header/back arrow/trail per Alpine picker step, but the
             list of options now sits over the page instead of inside it. Same shell (fixed
             backdrop, centred card, .uj-slide entrance) as partials/ticket-raise.blade.php, so
             every popup in the app opens the same way. Padding around the backdrop plus an
             internally-scrolling body keeps it usable on a phone without a separate bottom-sheet
             variant — a step's option list is what grows, not the dialog's position. ---- --}}
        <div x-show="picker.open" x-cloak class="uj-dialog-overlay"
             @click.self="closePicker()"
             @keydown.escape.window="if (picker.open) closePicker()"
             style="position:fixed;inset:0;z-index:200;padding:16px;background:rgba(31,30,26,.55);backdrop-filter:blur(2px);">
            <div role="dialog" aria-modal="true" aria-labelledby="ts-picker-title"
                 x-transition:enter="uj-overlay-enter" x-transition:enter-start="uj-overlay-from" x-transition:enter-end="uj-overlay-to"
                 x-transition:leave="uj-overlay-leave" x-transition:leave-start="uj-overlay-to" x-transition:leave-end="uj-overlay-from"
                 style="width:100%;max-width:clamp(420px,55vw,900px);margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 24px 70px rgba(31,30,26,.30);display:flex;flex-direction:column;max-height:min(720px,88vh);">

                {{-- Header: back arrow, the question this step asks, and the trail of what is
                     already chosen so the staffer knows where they are. --}}
                <div style="display:flex;align-items:center;gap:8px;padding:16px 18px;border-bottom:1px solid var(--hairline);flex-shrink:0;">
                    <button type="button" x-ref="pickerCloseBtn" @click="pickerBack()" class="uj-btn-ghost"
                        :aria-label="picker.step === 'category'
                            ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal')
                            : ($store.ui.lang==='en' ? 'Back a step' : 'Undur satu langkah')"
                        style="height:30px;width:30px;padding:0;flex-shrink:0;font-size:15px;line-height:1;"
                        x-text="picker.step === 'category' ? '×' : '←'"></button>
                    <div style="flex:1;min-width:0;">
                        {{-- tabindex=-1 + outline:none: a script-focus target only, so a screen
                             reader/keyboard user's position moves with each step instead of
                             silently dropping to <body> when x-if swaps out the focused option
                             row — see chooseStep()/chooseItem()/pickerBack() in
                             timesheet-capture.js, which all focus this after changing step. --}}
                        <strong id="ts-picker-title" tabindex="-1" style="display:block;outline:none;font-size:10.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;"
                            x-text="picker.step === 'category'
                                ? ($store.ui.lang==='en' ? 'Which category?' : 'Kategori yang mana?')
                                : (picker.step === 'project'
                                    ? ($store.ui.lang==='en' ? 'Which project?' : 'Projek yang mana?')
                                    : (picker.step === 'sub'
                                        ? ($store.ui.lang==='en' ? 'Which part of it?' : 'Bahagian yang mana?')
                                        : ($store.ui.lang==='en' ? 'How much, and any notes?' : 'Berapa banyak, dan sebarang nota?')))"></strong>
                        <span x-show="picker.step !== 'details' && pickerTrail()" x-cloak
                            style="display:block;font-size:11px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            x-text="pickerTrail()"></span>
                        <span x-show="picker.step === 'details'" x-cloak class="uj-pill"
                            style="display:inline-block;margin-top:2px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:var(--hairline-soft);color:var(--muted);"
                            x-text="picker.pendingItem?.label"></span>
                    </div>
                </div>

                <div style="flex:1;min-height:0;overflow-y:auto;padding:14px 18px 18px;display:flex;flex-direction:column;gap:5px;">

                    {{-- Saved templates and recent work, pinned above the first step only:
                         a one-tap shortcut straight past the drill-down. --}}
                    <template x-if="picker.step === 'category' && pinnedItems().length">
                        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);padding:2px;">
                            <span x-text="$store.ui.lang==='en' ? 'Saved and recent' : 'Disimpan dan terkini'"></span>
                        </div>
                    </template>
                    <template x-if="picker.step === 'category'">
                        <div style="display:flex;flex-direction:column;gap:5px;">
                            <template x-for="item in pinnedItems()" :key="item.key">
                                <div @click="chooseItem(item)" role="button" :tabindex="isOnDay(item) ? -1 : 0"
                                    @keydown.enter="chooseItem(item)" @keydown.space.prevent="chooseItem(item)"
                                    class="uj-ts-opt" :data-disabled="isOnDay(item) ? '1' : '0'"
                                    x-transition:enter="uj-ts-step-enter" x-transition:enter-start="uj-ts-step-enter-start" x-transition:enter-end="uj-ts-step-enter-end"
                                    style="display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:8px;border:1px solid var(--hairline-soft);"
                                    :style="isOnDay(item) ? { background:'var(--hairline-soft)', color:'var(--muted)', cursor:'default' } : {}">
                                    <span style="width:8px;height:8px;border-radius:999px;flex:none;" :style="{ background: categoryColour(item.category_id) }"></span>
                                    <span style="flex:1;font-size:12.5px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" x-text="item.label"></span>
                                    <span x-show="isOnDay(item)" x-cloak style="font-size:10px;color:var(--muted);flex-shrink:0;"
                                        x-text="$store.ui.lang==='en' ? 'already on this day' : 'sudah ada pada hari ini'"></span>
                                    <span x-show="item.isTemplate && !isOnDay(item)" x-cloak style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--success);background:var(--canvas);padding:2px 8px;border-radius:999px;flex-shrink:0;">
                                        <span x-text="$store.ui.lang==='en' ? 'saved' : 'disimpan'"></span>
                                    </span>
                                    {{-- Delete a template: DELETE /app/timesheets/templates/{template}
                                         (timesheets.templates.delete). @click.stop keeps this from
                                         also triggering the row's chooseItem(); @keydown.enter.stop
                                         does the same for a keyboard user tabbing to the delete
                                         button and pressing Enter — without it, Enter both submits
                                         the delete AND bubbles up to fire the row's chooseItem(),
                                         adding a spurious row. The button's native Enter-to-submit
                                         is unaffected, since .stop only stops propagation. The
                                         confirm() guard uses $event.preventDefault() (not "return
                                         false", which Alpine's expression evaluator cannot parse
                                         as a statement). --}}
                                    <template x-if="item.isTemplate">
                                        <form method="post" :action="'/app/timesheets/templates/' + item.template_id" @click.stop @keydown.enter.stop
                                            @submit="if (!confirm($store.ui.lang==='en' ? 'Delete this template?' : 'Padam templat ini?')) $event.preventDefault()"
                                            style="flex-shrink:0;line-height:0;">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" :aria-label="$store.ui.lang==='en' ? 'Delete template' : 'Padam templat'"
                                                style="height:22px;width:22px;padding:0;border:0;background:none;color:var(--error);cursor:pointer;font-size:14px;line-height:1;">&times;</button>
                                        </form>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- The current step's options. An option that completes a line is taken
                         straight away; one that still needs a project or a sub-pillar shows a
                         chevron and opens the next step. --}}
                    <template x-if="picker.step === 'category' && pinnedItems().length">
                        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);padding:10px 2px 0;">
                            <span x-text="$store.ui.lang==='en' ? 'All categories' : 'Semua kategori'"></span>
                        </div>
                    </template>
                    <template x-for="opt in pickerOptions()" :key="opt.label">
                        <div @click="chooseStep(opt)" role="button" :tabindex="opt.item && isOnDay(opt.item) ? -1 : 0"
                            @keydown.enter="chooseStep(opt)" @keydown.space.prevent="chooseStep(opt)"
                            class="uj-ts-opt" :data-disabled="opt.item && isOnDay(opt.item) ? '1' : '0'"
                            x-transition:enter="uj-ts-step-enter" x-transition:enter-start="uj-ts-step-enter-start" x-transition:enter-end="uj-ts-step-enter-end"
                            style="display:flex;align-items:center;gap:8px;padding:11px 10px;border-radius:8px;border:1px solid var(--hairline-soft);"
                            :style="opt.item && isOnDay(opt.item) ? { background:'var(--hairline-soft)', color:'var(--muted)', cursor:'default' } : {}">
                            <span x-show="picker.step === 'category'" x-cloak style="width:8px;height:8px;border-radius:999px;flex:none;" :style="{ background: categoryColour(opt.c?.id) }"></span>
                            <span style="flex:1;font-size:12.5px;min-width:0;" x-text="opt.label"></span>
                            <span x-show="opt.item && isOnDay(opt.item)" x-cloak style="font-size:10px;color:var(--muted);flex-shrink:0;"
                                x-text="$store.ui.lang==='en' ? 'already on this day' : 'sudah ada pada hari ini'"></span>
                            <span x-show="!opt.item" x-cloak style="font-size:13px;color:var(--muted);flex-shrink:0;line-height:1;">&rsaquo;</span>
                        </div>
                    </template>

                    {{-- Final step: percentage + a rich-text note, then Submit adds (or
                         saves, when editing an existing row) the line. Everything the add
                         flow needs now happens inside this one popup. ---- --}}
                    <template x-if="picker.step === 'details'">
                        <div x-transition:enter="uj-ts-step-enter" x-transition:enter-start="uj-ts-step-enter-start" x-transition:enter-end="uj-ts-step-enter-end"
                            style="display:flex;flex-direction:column;gap:14px;">
                            {{-- The percentage is this step's one figure — the same 26px tabular
                                 mono weight the rest of the app reserves for "the one number a
                                 screen exists to report" (docs/DESIGN.md), not the small 13px
                                 row-editing size, so it reads as the decision this step asks for
                                 rather than a form field lost among buttons. --}}
                            <div style="background:var(--canvas);border-radius:10px;padding:14px 16px;">
                                <label style="display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:9px;"
                                    x-text="$store.ui.lang==='en' ? 'Percentage of the day' : 'Peratus hari'"></label>
                                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                    <span style="position:relative;display:inline-block;flex-shrink:0;">
                                        <input type="text" inputmode="decimal" x-model="picker.pendingPct" @input="clampPickerPct()"
                                            :aria-label="$store.ui.lang==='en' ? 'Percentage of the day' : 'Peratus hari'"
                                            style="width:118px;height:52px;padding:0 32px 0 14px;text-align:right;border:1px solid var(--hairline);border-radius:10px;font:600 26px/1 var(--font-mono);font-variant-numeric:tabular-nums;color:var(--ink);background:#fff;outline:none;" />
                                        <span style="position:absolute;right:14px;top:50%;transform:translateY(-50%);font:600 18px/1 var(--font-mono);color:var(--muted);pointer-events:none;">%</span>
                                    </span>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <template x-for="pct in [100, 50, 25]" :key="pct">
                                            <button type="button" @click="picker.pendingPct = pct" class="uj-ts-pill"
                                                style="min-height:30px;padding:0 12px;border-radius:999px;font-family:var(--font-mono);font-size:11px;cursor:pointer;"
                                                :style="picker.pendingPct == pct
                                                    ? { border:'1px solid var(--success)', background:'color-mix(in srgb, var(--success) 8%, #fff)', color:'var(--success-ink)' }
                                                    : { border:'1px solid var(--hairline)', background:'#fff', color:'var(--body)' }"
                                                x-text="pct + '%'"></button>
                                        </template>
                                        <button type="button" x-show="remainder(selected) > 0" @click="picker.pendingPct = remainder(selected)" class="uj-ts-pill"
                                            style="min-height:30px;padding:0 12px;border-radius:999px;font-size:11px;cursor:pointer;"
                                            :style="picker.pendingPct == remainder(selected)
                                                ? { border:'1px solid var(--success)', background:'color-mix(in srgb, var(--success) 8%, #fff)', color:'var(--success-ink)' }
                                                : { border:'1px solid var(--hairline)', background:'#fff', color:'var(--body)' }"
                                            x-text="($store.ui.lang==='en' ? 'Give it the ' : 'Beri baki ') + remainder(selected) + ($store.ui.lang==='en' ? '% left' : '%')"></button>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label style="display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:7px;"
                                    x-text="$store.ui.lang==='en' ? 'Notes (optional)' : 'Nota (pilihan)'"></label>
                                <div class="uj-ql" x-init="mountQuillEditor($el)"></div>
                            </div>

                            <button type="button" @click="confirmEntry()" class="uj-btn-primary" style="height:42px;font-size:13px;">
                                <span x-text="picker.editingIndex != null
                                    ? ($store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan')
                                    : ($store.ui.lang==='en' ? 'Add entry' : 'Tambah entri')"></span>
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- ---- Week at a glance: dots + weekend toggle. Day-to-day nav lives in the date sheet. ---- --}}
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
            <div style="display:flex;gap:14px;align-items:flex-end;">
                <template x-for="d in dayDates()" :key="d">
                    <button type="button" @click="select(d)" :disabled="isOffDay(d)"
                        style="background:none;border:0;padding:0;text-align:center;min-width:20px;"
                        :style="isOffDay(d) ? { cursor:'default', opacity:.3 } : (isFuture(d) ? { cursor:'pointer', opacity:.5 } : { cursor:'pointer' })">
                        <div style="height:14px;display:flex;align-items:center;justify-content:center;">
                            <template x-if="isFullyLocked(d)"><span style="font-size:12px;color:var(--muted);">&#128274;</span></template>
                            <template x-if="!isFullyLocked(d)">
                                <span :style="{
                                    width: d === selected ? '13px' : '11px', height: d === selected ? '13px' : '11px',
                                    borderRadius:'50%', display:'inline-block',
                                    background: { empty:'var(--hairline)', partial:'var(--amber)', done:'var(--success)', over:'var(--error)', locked:'var(--muted)', future:'var(--hairline-soft)' }[dayState(d)],
                                    boxShadow: d === selected ? '0 0 0 3px var(--red-tint)' : 'none',
                                }"></span>
                            </template>
                        </div>
                        <div style="font-size:11px;margin-top:5px;" :style="d === selected ? { color:'var(--ink)', fontWeight:600 } : { color:'var(--muted)' }">
                            <span x-show="isPartlyLocked(d)" x-cloak style="font-size:9px;">&#189;</span><span x-text="dayName(d)"></span>
                        </div>
                    </button>
                </template>
            </div>
            <button type="button" @click="days = (days === 5 ? 7 : 5)" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:12px;">
                <span x-text="days === 5 ? ($store.ui.lang==='en' ? 'Show weekend' : 'Papar hujung minggu') : ($store.ui.lang==='en' ? 'Hide weekend' : 'Sembunyi hujung minggu')"></span>
            </button>
        </div>
        <div style="display:flex;gap:14px;align-items:center;margin-top:11px;font-size:11px;color:var(--muted);flex-wrap:wrap;">
            <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--success);"></span> <span x-text="$store.ui.lang==='en' ? '100% done' : '100% siap'">100% done</span></span>
            <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:var(--amber);"></span> <span x-text="$store.ui.lang==='en' ? 'in progress' : 'sedang jalan'">in progress</span></span>
            <span>&#128274; <span x-text="$store.ui.lang==='en' ? 'on leave / holiday' : 'cuti / kelepasan'">on leave / holiday</span></span>
        </div>

        {{-- ---- Footer: save / submit ---- --}}
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px;flex-wrap:wrap;">
            <div style="font-size:12px;flex:1;min-width:200px;">
                {{-- blockingMessage() picks its own sentence: a day over 100% is told it went
                     over, a day holding an uncosted line is told that, and only a genuinely
                     unfinished day reads "not at 100% yet". The one message used to say
                     "not at 100% yet" for all three, including a day sitting at 140%. --}}
                <span x-show="!weekComplete()"
                    :style="{ color: overDays().length ? 'var(--error)' : 'var(--amber-ink)' }"
                    x-text="blockingMessage()"></span>
                <span x-show="weekComplete() && savedAt" style="color:var(--muted);" x-text="($store.ui.lang==='en' ? 'Saved ' : 'Disimpan ') + savedAt"></span>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="button" @click="save(false, true)" :disabled="readonly || saving" class="uj-btn-ghost" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save draft' : 'Simpan draf'">Save draft</span></button>
                <button type="button" id="ts-submit-btn" @click="openReview()" :disabled="!weekComplete() || readonly || saving"
                    :style="(!weekComplete() || readonly) ? { opacity:'.5', cursor:'not-allowed' } : {}"
                    class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Submit week' : 'Hantar minggu'">Submit week</span></button>
            </div>
        </div>
        <div x-show="error" x-cloak style="margin-top:8px;font-size:12px;color:var(--error);" x-text="error"></div>

        {{-- ---- Date sheet: jump to any day in the week, or to another week ---- --}}
        <div x-show="sheetOpen" x-cloak @click.self="sheetOpen = false"
             style="position:absolute;inset:0;z-index:60;background:transparent;display:flex;align-items:center;justify-content:center;padding:16px;">
            <div style="background:#fff;border:1px solid var(--hairline);border-radius:14px;width:100%;max-width:420px;padding:18px;box-shadow:0 14px 40px rgba(0,0,0,.18);">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                    <strong style="font-size:14px;"><span x-text="$store.ui.lang==='en' ? 'Go to a day' : 'Pergi ke hari'">Go to a day</span></strong>
                    <button type="button" @click="sheetOpen = false" class="uj-btn-ghost" style="height:28px;width:28px;padding:0;font-size:15px;" :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">&times;</button>
                </div>

                <div style="display:flex;gap:7px;flex-wrap:wrap;">
                    <template x-for="d in dayDates()" :key="d">
                        <button type="button" @click="if (! isOffDay(d)) { select(d); sheetOpen = false; }" :disabled="isOffDay(d)"
                            style="flex:1;min-width:52px;border-radius:9px;padding:8px 4px;text-align:center;background:#fff;"
                            :style="{
                                border: d === selected ? '2px solid var(--red)' : '1px solid var(--hairline)',
                                color: d === selected ? 'var(--red)' : (isOffDay(d) ? 'var(--muted)' : (isFuture(d) ? 'var(--muted)' : 'var(--ink)')),
                                cursor: isOffDay(d) ? 'default' : 'pointer',
                                opacity: isOffDay(d) ? .4 : (isFuture(d) ? .7 : 1),
                            }">
                            <div style="font-size:11px;color:var(--muted);" x-text="dayName(d)"></div>
                            <template x-if="isFullyLocked(d)"><span style="font-size:12px;">&#128274;</span></template>
                            <template x-if="!isFullyLocked(d)"><span style="font-size:13px;font-family:var(--font-mono);" x-text="Number(d.slice(8, 10))"></span></template>
                        </button>
                    </template>
                </div>

                <div style="border-top:1px solid var(--hairline);margin:16px 0 12px;"></div>

                <div style="font-size:12px;color:var(--muted);margin-bottom:8px;"><span x-text="$store.ui.lang==='en' ? 'Or jump to a whole week' : 'Atau lompat ke satu minggu'">Or jump to a whole week</span></div>
                <div style="display:flex;gap:8px;align-items:center;">
                    @if ($prevWeekDisabled)
                        <span aria-disabled="true" class="uj-btn-ghost" style="height:38px;width:38px;padding:0;display:inline-flex;align-items:center;justify-content:center;opacity:.4;cursor:not-allowed;">&larr;</span>
                    @else
                        <a href="{{ route('app.screen', ['screen' => 'timesheets', 'week' => $prevWeekStart]) }}" class="uj-btn-ghost" style="height:38px;width:38px;padding:0;display:inline-flex;align-items:center;justify-content:center;">&larr;</a>
                    @endif
                    <form method="get" action="{{ route('app.screen', 'timesheets') }}" style="flex:1;">
                        <input type="date" name="week" value="{{ $weekStart }}" onchange="this.form.submit()" style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:9px;font-size:13px;outline:none;" />
                    </form>
                    <a href="{{ route('app.screen', ['screen' => 'timesheets', 'week' => $nextWeekStart]) }}" class="uj-btn-ghost" style="height:38px;width:38px;padding:0;display:inline-flex;align-items:center;justify-content:center;">&rarr;</a>
                </div>

                <div style="margin-top:14px;font-size:11.5px;color:var(--muted);line-height:1.5;">
                    <span x-text="$store.ui.lang==='en' ? 'Locked days (on leave, public holiday) are filled in for you and can\'t be edited.' : 'Hari dikunci (cuti, hari kelepasan) sudah diisi untuk anda dan tidak boleh disunting.'"></span>
                </div>
            </div>
        </div>
        </div>

        {{-- ===================== REVIEW: full-page pre-submit check =====================
             Not a dialog: no backdrop, no aria-modal, no focus trap. Replaces the whole
             Record pane (tab bar and old Submit button included) so there is only ever one
             submit path on screen. ---- --}}
        <div x-show="reviewing" x-cloak
             x-transition:enter="uj-overlay-enter" x-transition:enter-start="uj-overlay-from" x-transition:enter-end="uj-overlay-to"
             x-transition:leave="uj-overlay-leave" x-transition:leave-start="uj-overlay-to" x-transition:leave-end="uj-overlay-from">
            <button type="button" @click="history.back()" class="uj-btn-ghost" style="height:32px;padding:0 10px;font-size:13px;margin-bottom:10px;">
                <span x-text="$store.ui.lang==='en' ? '← Back to editing' : '← Kembali sunting'">&larr; Back to editing</span>
            </button>
            <h2 id="ts-review-title" tabindex="-1" style="outline:none;font-size:18px;font-weight:600;margin:0 0 3px;">
                <span x-text="$store.ui.lang==='en' ? 'Review before you submit' : 'Semak sebelum hantar'">Review before you submit</span>
            </h2>
            <div style="font-size:12.5px;color:var(--muted);margin-bottom:18px;">{{ $weekStartC->format('j M') }} &ndash; {{ $weekStartC->copy()->addDays(4)->format('j M Y') }} &middot; <span x-text="$store.ui.lang==='en' ? 'every working day at 100%' : 'setiap hari bekerja pada 100%'"></span></div>

            <div id="ts-review-summary"></div>
            <div id="ts-review-days"></div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:22px;padding-top:16px;border-top:1px solid var(--hairline);flex-wrap:wrap;">
                <div style="font-size:12px;flex:1;min-width:200px;">
                    <span x-show="!weekComplete()" :style="{ color: overDays().length ? 'var(--error)' : 'var(--amber-ink)' }" x-text="blockingMessage()"></span>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="button" @click="history.back()" :disabled="saving" class="uj-btn-ghost" style="height:40px;padding:0 18px;font-size:13px;">
                        <span x-text="$store.ui.lang==='en' ? 'Back to editing' : 'Kembali sunting'">Back to editing</span>
                    </button>
                    <button type="button" id="ts-confirm-submit-btn" @click="save(true)" :disabled="!weekComplete() || readonly || saving"
                        :style="(!weekComplete() || readonly || saving) ? { opacity:'.5', cursor:'not-allowed' } : {}"
                        class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;">
                        <span x-text="saving ? ($store.ui.lang==='en' ? 'Submitting…' : 'Menghantar…') : ($store.ui.lang==='en' ? 'Confirm & submit' : 'Sahkan & hantar')">Confirm &amp; submit</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    </div>

    <div x-show="tab==='review'" x-cloak role="tabpanel">
    @if ($canSeeCost && $myTimesheets->isNotEmpty())
    {{-- ===================== REFERENCE PANEL: My weeks (RM, money roles only) ===================== --}}
    {{-- Read-only — editing and submitting a week both live on the Record tab now, this
         is only where a money role can see what their own weeks cost. --}}
    <div class="uj-card" style="margin-bottom:16px;">
        <div style="padding:14px 20px 4px;font-size:13px;font-weight:600;color:var(--ink);">
            <span x-text="$store.ui.lang==='en' ? 'My weeks' : 'Minggu saya'">My weeks</span>
        </div>
        <div>
        @foreach ($myTimesheets as $t)
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 20px;border-top:1px solid var(--hairline-soft);">
                <div style="min-width:0;">
                    <div style="font-size:13px;color:var(--ink);">{{ $t->week_label ?: $t->week_start->format('j M Y') }}</div>
                    <div style="font-size:11px;font-weight:600;color:{{ $sc[$t->status] }};">{{ ucfirst($t->status) }}</div>
                </div>
                @if ($rm($t->id))
                    <div style="font-size:12px;font-family:var(--font-mono);color:var(--success);flex-shrink:0;">{{ $rm($t->id) }}</div>
                @endif
            </div>
        @endforeach
        </div>
    </div>
    @endif
    {{-- ===================== REFERENCE PANEL: My time spent ===================== --}}
    <div class="uj-card">
        {{-- Section: My time spent (personal, person-days only — never RM) --}}
        @if (! empty($myBreakdown))
            @php $totalMd = rtrim(rtrim(number_format($myBreakdown['totalDays'], 2), '0'), '.'); @endphp
            <div style="padding:16px 20px 4px;font-size:13px;font-weight:600;color:var(--ink);">
                <span x-text="$store.ui.lang==='en' ? 'My time spent' : 'Masa saya'">My time spent</span>
            </div>
            <div style="padding:16px 22px 20px;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:12px;">
                    <div>
                <div style="font-size:12px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Where your recorded time went — by category and project. Submitted weeks only.' : 'Ke mana masa anda direkod — mengikut kategori dan projek. Minggu dihantar sahaja.'"></span></div>
            </div>
            <form method="get" action="{{ route('app.screen', 'timesheets') }}" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="week" value="{{ $weekStart }}" />
                <div>
                    <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'From' : 'Dari'">From</span></label>
                    <input type="date" name="from" value="{{ $breakdownFrom }}" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" />
                </div>
                <div>
                    <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'To' : 'Hingga'">To</span></label>
                    <input type="date" name="to" value="{{ $breakdownTo }}" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" />
                </div>
                <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Apply' : 'Guna'">Apply</span></button>
            </form>
        </div>

        @if ($myBreakdown['empty'])
            <div style="padding:22px;text-align:center;font-size:12.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No recorded time in this period. Submit a week to see your breakdown.' : 'Tiada masa direkod dalam tempoh ini. Hantar satu minggu untuk melihat pecahan anda.'"></span></div>
        @else
            <div style="font-size:12.5px;color:var(--muted);margin-bottom:16px;">
                <span style="font-family:var(--font-mono);color:var(--ink);font-weight:600;">{{ $totalMd }}</span>
                <span x-text="$store.ui.lang==='en' ? 'person-days total' : 'hari-orang jumlah'">person-days total</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:28px;">
                <div>
                    <div style="font-size:10.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:11px;"><span x-text="$store.ui.lang==='en' ? 'By category' : 'Mengikut kategori'">By category</span></div>
                    @foreach ($myBreakdown['byCategory'] as $row)
                        <div style="margin-bottom:9px;">
                            <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;color:var(--ink);margin-bottom:3px;">
                                <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $row['label'] }}</span>
                                <span style="font-family:var(--font-mono);color:var(--muted);flex-shrink:0;">{{ rtrim(rtrim(number_format($row['days'], 2), '0'), '.') }} md · {{ $row['pct'] }}%</span>
                            </div>
                            <div style="height:7px;border-radius:9999px;background:var(--canvas);overflow:hidden;"><div style="height:100%;width:{{ $row['pct'] }}%;background:var(--info);border-radius:9999px;"></div></div>
                        </div>
                    @endforeach
                </div>
                <div>
                    <div style="font-size:10.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-bottom:11px;"><span x-text="$store.ui.lang==='en' ? 'By project' : 'Mengikut projek'">By project</span></div>
                    @forelse ($myBreakdown['byProject'] as $row)
                        <div style="margin-bottom:9px;">
                            <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;color:var(--ink);margin-bottom:3px;">
                                <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $row['label'] }}</span>
                                <span style="font-family:var(--font-mono);color:var(--muted);flex-shrink:0;">{{ rtrim(rtrim(number_format($row['days'], 2), '0'), '.') }} md · {{ $row['pct'] }}%</span>
                            </div>
                            <div style="height:7px;border-radius:9999px;background:var(--canvas);overflow:hidden;"><div style="height:100%;width:{{ $row['pct'] }}%;background:var(--success);border-radius:9999px;"></div></div>
                        </div>
                    @empty
                        <div style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No project-linked time in this period.' : 'Tiada masa berkaitan projek dalam tempoh ini.'">No project-linked time.</span></div>
                    @endforelse
                </div>
            </div>
        @endif
    </div>
@endif
    </div>
    </div>{{-- /tab=review panel --}}
</div>{{-- /tab root --}}
@endsection
