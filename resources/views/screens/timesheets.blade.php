@extends('layouts.app')

@php
    $sc = [
        'draft' => 'var(--muted)',
        'submitted' => 'var(--amber)',
        'approved' => 'var(--success)',
        'rejected' => 'var(--error)',
    ];
    $submittedCount = $myTimesheets->whereIn('status', ['submitted', 'approved'])->count();
    $draftCount = $myTimesheets->where('status', 'draft')->count();

    // Manday costing — visible to money roles only (manager/management/HR), set by TimesheetController.
    $canSeeCost = $canSeeCost ?? false;
    $timesheetCosts = $timesheetCosts ?? [];
    $rm = fn ($id) => array_key_exists($id, $timesheetCosts) && $timesheetCosts[$id] !== null
        ? 'RM '.number_format($timesheetCosts[$id], 2) : null;

    // One-line summary of an entry for the read-only lists (category · project · sub-pillar).
    $entryLabel = function ($e) {
        $parts = array_filter([
            $e->category?->name ?: $e->project,
            $e->projectRef?->name,
            $e->subPillar?->name,
        ]);

        return implode(' · ', $parts);
    };

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
            'Tap a day in the strip to open it. Press "+ Add what you worked on", choose what you did, and set its percentage — repeat until the day reads 100%.',
            'Locked days (approved leave, public holidays) are filled in for you and can\'t be edited.',
            'Use "Same as <day>" to copy the previous day, or "Give the rest to the last line" to top a day up to 100%. Save a draft any time; press "Submit week" once every day reads 100%.',
            'Doing the same work every week? On the line you just added, tap "Save as template" and name it — it becomes a one-tap shortcut at the top of the list in every future week.',
        ],
    ],
    'ms'  => [
        'title' => 'Timesheet mingguan',
        'body'  => 'Pilih minggu, kemudian kerjakan satu hari pada satu masa. Ketik satu hari dalam jalur untuk membukanya, tambah apa yang anda kerjakan, dan tetapkan peratus setiap entri — setiap hari bekerja mesti mencapai 100% sebelum minggu boleh dihantar.',
        'who'   => 'Staf isi & hantar · Pengurus & HR luluskan',
        'steps' => [
            'Pilih minggu menggunakan anak panah di atas — jalur memaparkan setiap hari minggu dengan bar pengisian menunjukkan berapa banyak hari itu telah dirancang.',
            'Ketik satu hari dalam jalur untuk membukanya. Tekan "+ Tambah apa yang anda kerjakan", pilih apa yang dilakukan, dan tetapkan peratusnya — ulang sehingga hari itu membaca 100%.',
            'Hari yang dikunci (cuti diluluskan, cuti umum) sudah diisi untuk anda dan tidak boleh disunting.',
            'Guna "Sama seperti <hari>" untuk menyalin hari sebelumnya, atau "Beri bakinya kepada baris akhir" untuk melengkapkan hari itu ke 100%. Simpan draf pada bila-bila masa; tekan "Hantar minggu" apabila setiap hari membaca 100%.',
            'Buat kerja sama setiap minggu? Pada baris yang baru anda tambah, tekan "Simpan sebagai templat" dan namakannya — ia menjadi pintasan satu ketik di bahagian atas senarai pada setiap minggu akan datang.',
        ],
    ],
])

<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'My timesheets' : 'Timesheet saya'">My timesheets</span></div><div class="uj-stat-value">{{ $myTimesheets->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Submitted' : 'Dihantar'">Submitted</span></div><div class="uj-stat-value" style="color:var(--success);">{{ $submittedCount }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Drafts' : 'Draf'">Drafts</span></div><div class="uj-stat-value" style="color:var(--amber);">{{ $draftCount }}</div></div>
</div>

@php
    $tsRoster = collect($tsRoster ?? []);
    $tsDone = $tsRoster->where('status', 'done')->count();
    $tsTotal = $tsRoster->count();
    $tsPill = ['done' => 'var(--success)', 'pending' => 'var(--muted)', 'late' => 'var(--red)'];
@endphp
@if ($tsTotal)
<div class="uj-card" style="margin-bottom:16px;padding:14px 18px;" x-data="{ open: true }">
    <div style="display:flex;align-items:center;gap:10px;cursor:pointer;" @click="open = !open">
        <strong style="flex:1;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'This week — team status' : 'Minggu ini — status pasukan'">This week — team status</strong>
        <span style="font-size:12.5px;color:var(--muted);">{{ $tsDone }} / {{ $tsTotal }} <span x-text="$store.ui.lang==='en' ? 'done' : 'selesai'">done</span></span>
        <span x-text="open ? '▾' : '▸'" style="color:var(--muted);"></span>
    </div>
    <div x-show="open" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;">
        @foreach ($tsRoster as $row)
            <span style="display:inline-flex;align-items:center;gap:7px;padding:4px 11px;border-radius:999px;background:var(--surface-2,#f3f4f6);font-size:12px;">
                <span style="width:8px;height:8px;border-radius:50%;background:{{ $tsPill[$row['status']] }};flex:none;"></span>
                <span>{{ $row['employee']->name }}</span>
                <span style="color:var(--muted);font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;">
                    @if ($row['status'] === 'done')
                        <span x-text="$store.ui.lang==='en' ? 'done' : 'selesai'">done</span>
                    @elseif ($row['status'] === 'late')
                        <span x-text="$store.ui.lang==='en' ? 'late' : 'lewat'">late</span>
                    @else
                        <span x-text="$store.ui.lang==='en' ? 'pending' : 'belum'">pending</span>
                    @endif
                </span>
            </span>
        @endforeach
    </div>
</div>
@endif

@if (($positionMissing ?? false))
    <div class="uj-card" style="margin-bottom:16px;padding:12px 18px;border-left:3px solid var(--amber);font-size:12.5px;color:var(--ink);">
        <span x-text="$store.ui.lang==='en' ? 'You have no position band assigned, so your timesheet cost can\'t be computed. Set it in Administration → Position & Manday Rates.' : 'Anda belum ada band pangkat, jadi kos timesheet anda tidak dapat dikira. Tetapkan di Pentadbiran → Pangkat & Kadar Manday.'">You have no position band assigned, so your timesheet cost can't be computed.</span>
    </div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- ===================== CAPTURE (per-day cards) ===================== --}}
    <div class="uj-card" style="flex:1.4;min-width:380px;padding:22px;"
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
         })">

        {{-- Week picker (GET — reloads the card for the chosen week) --}}
        <form method="get" action="{{ route('app.screen', 'timesheets') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:6px;">
            <div>
                <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Week starting (Mon)' : 'Minggu bermula (Isnin)'">Week starting (Mon)</span></label>
                <input type="date" name="week" value="{{ $weekStart }}" onchange="this.form.submit()" style="height:40px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
            </div>
            <div style="flex:1;"></div>
            <button type="button" @click="days = (days === 5 ? 7 : 5)" class="uj-btn-ghost" style="height:36px;padding:0 13px;font-size:12px;"><span x-text="days === 5 ? ($store.ui.lang==='en' ? 'Show weekend' : 'Papar hujung minggu') : ($store.ui.lang==='en' ? 'Hide weekend' : 'Sembunyi hujung minggu')"></span></button>
        </form>
        @include('partials.hint', ['en' => 'Pick any Monday to load or build that week. Each day must total 100% before the week can be submitted.', 'ms' => 'Pilih mana-mana Isnin untuk memuatkan atau membina minggu itu. Setiap hari mesti berjumlah 100% sebelum minggu boleh dihantar.'])

        {{-- ---- Week header: prev/next nav, date range, status chip ---- --}}
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px;">
            @if ($prevWeekDisabled)
                <span aria-disabled="true" style="height:32px;width:32px;border-radius:8px;border:1px solid var(--hairline);display:inline-flex;align-items:center;justify-content:center;font-size:13px;color:var(--muted);opacity:.4;cursor:not-allowed;">&larr;</span>
            @else
                <a href="{{ route('app.screen', ['screen' => 'timesheets', 'week' => $prevWeekStart]) }}" class="uj-btn-ghost" style="height:32px;width:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;font-size:13px;">&larr;</a>
            @endif

            <div style="text-align:center;">
                <div style="font-size:14px;font-weight:600;color:var(--ink);">{{ $weekStartC->format('j M') }} &ndash; {{ $weekStartC->copy()->addDays(4)->format('j M') }}</div>
                <span style="display:inline-block;margin-top:3px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:2px 9px;border-radius:999px;background:var(--canvas);color:{{ $sc[$weekStatus ?? 'draft'] }};">{{ ucfirst($weekStatus ?? 'draft') }}</span>
            </div>

            <a href="{{ route('app.screen', ['screen' => 'timesheets', 'week' => $nextWeekStart]) }}" class="uj-btn-ghost" style="height:32px;width:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;font-size:13px;">&rarr;</a>
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

        {{-- ---- Week strip: navigation + progress, one bar per day ---- --}}
        <div style="display:flex;gap:6px;margin:14px 0 16px;">
            <template x-for="d in dayDates()" :key="d">
                <button type="button" @click="select(d)" :disabled="isFuture(d)"
                    style="flex:1;background:none;border:0;padding:0;cursor:pointer;text-align:center;"
                    :style="isFuture(d) ? { cursor:'not-allowed', opacity:.45 } : {}">
                    <div style="height:6px;border-radius:3px;margin-bottom:5px;"
                        :style="{ background: {
                            empty:   'var(--hairline)',
                            partial: 'var(--amber)',
                            done:    'var(--success)',
                            over:    'var(--error)',
                            locked:  'var(--muted)',
                            future:  'var(--hairline-soft)',
                        }[dayState(d)] }"></div>
                    <div style="font-size:11px;"
                        :style="d === selected ? { color:'var(--ink)', fontWeight:600 } : { color:'var(--muted)' }">
                        <span x-show="isFullyLocked(d)" x-cloak>&#128274;</span>
                        <span x-show="isPartlyLocked(d)" x-cloak>&#189;</span>
                        <span x-text="dayName(d)"></span>
                    </div>
                </button>
            </template>
        </div>

        {{-- ---- Day card: the one editable (or locked) day ---- --}}
        <div style="border:1px solid var(--hairline);border-radius:12px;padding:16px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <strong style="font-size:14px;" x-text="dayLong(selected)"></strong>
                <span style="font-size:12.5px;font-family:var(--font-mono);"
                    :style="dayState(selected) === 'over' ? { color:'var(--error)' } : { color:'var(--muted)' }"
                    x-text="dayTotal(selected) + ' / 100'"></span>
            </div>

            <div style="height:6px;background:var(--hairline-soft);border-radius:3px;overflow:hidden;margin-bottom:14px;">
                <div style="height:100%;transition:width .15s;"
                    :style="{
                        width: Math.min(100, dayTotal(selected)) + '%',
                        background: dayState(selected) === 'done' ? 'var(--success)'
                                  : dayState(selected) === 'over' ? 'var(--error)'
                                  : dayState(selected) === 'locked' ? 'var(--muted)' : 'var(--amber)',
                    }"></div>
            </div>

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

            <template x-if="!isFullyLocked(selected)">
                <div>
                    <template x-for="(r, i) in (rows[selected] || [])" :key="i">
                        <div style="padding:8px 0;border-top:1px solid var(--hairline-soft);">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <span style="flex:1;font-size:12.5px;" x-text="rowLabel(r)"></span>
                                <input type="number" min="0" max="100" step="0.01" inputmode="decimal"
                                    x-model="r.percentage" @blur="save()" :disabled="!isEditable(selected)"
                                    style="width:72px;height:34px;padding:0 8px;text-align:center;border:1px solid var(--hairline);border-radius:7px;font-family:var(--font-mono);font-size:12.5px;outline:none;" />
                                <button type="button" @click="removeRow(i)" :disabled="!isEditable(selected)"
                                    class="uj-btn-ghost" style="height:34px;padding:0 9px;color:var(--error);"
                                    :aria-label="$store.ui.lang==='en' ? 'Remove' : 'Buang'">&times;</button>
                            </div>
                            {{-- Optional free-text note: "what you actually did" for this line.
                                 The column, validator and save/seed plumbing already round-trip
                                 `description`; this is the only place it is exposed. --}}
                            <input type="text" x-model="r.description" @blur="save()" maxlength="500"
                                :disabled="!isEditable(selected)"
                                :placeholder="$store.ui.lang==='en' ? 'Add a note (optional)' : 'Tambah nota (pilihan)'"
                                style="width:100%;height:32px;margin-top:6px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;outline:none;background:#fff;" />
                            {{-- Task 8: quick amount chips + save-as-template, shown only on the
                                 last (most recently added) row so the affordances appear once per
                                 day instead of repeating on every line. Save any combo by adding
                                 it last. --}}
                            <template x-if="isEditable(selected) && i === (rows[selected] || []).length - 1">
                                <div style="display:flex;align-items:center;gap:6px;margin-top:5px;flex-wrap:wrap;">
                                    <div style="display:flex;gap:5px;">
                                        <template x-for="pct in [100, 50, 25]" :key="pct">
                                            <button type="button" @click="r.percentage = pct; save()"
                                                style="padding:2px 9px;border-radius:999px;border:1px solid var(--hairline);background:#fff;color:var(--muted);font-size:10.5px;cursor:pointer;"
                                                x-text="pct + '%'"></button>
                                        </template>
                                    </div>
                                    <button type="button" @click="startSaveTemplate(r)"
                                        style="border:0;background:none;color:var(--muted);font-size:10.5px;text-decoration:underline;cursor:pointer;padding:2px 0;margin-left:auto;">
                                        <span x-text="$store.ui.lang==='en' ? 'Save as template' : 'Simpan sebagai templat'"></span>
                                    </button>
                                </div>
                            </template>
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
                        <button type="button" @click="fillRemainder()" x-show="(rows[selected] || []).length"
                            :disabled="!isEditable(selected)" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;">
                            <span x-text="$store.ui.lang==='en' ? 'Give the rest to the last line' : 'Beri bakinya kepada baris akhir'"></span>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        {{-- ---- Add affordance: flat, search-first picker (Task 8). Saved templates appear
             first (named, with a "saved" badge and a delete control), then recent
             Category · Project · Sub-pillar combinations, most recent first. "Something
             else" reveals the original three-step pill drill-down from Task 7 (moved here
             unchanged), so no combination is unreachable. ---- --}}
        <div x-data="{ add: { open: false, step: 1, cat: null, proj: null, filter: '' } }" x-show="isEditable(selected)" x-cloak style="margin-top:12px;">
            <button type="button" x-show="!picker.open" @click="openPicker(); add = { open: false, step: 1, cat: null, proj: null, filter: '' }"
                style="width:100%;padding:10px;border:1px dashed var(--hairline);border-radius:10px;background:none;cursor:pointer;font-size:12.5px;color:var(--muted);">
                <span x-text="$store.ui.lang==='en' ? '+ Add what you worked on' : '+ Tambah apa yang anda kerjakan'"></span>
            </button>

            <div x-show="picker.open" x-cloak style="padding:12px 14px;border:1px solid var(--hairline);border-radius:12px;background:var(--canvas);display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <strong style="flex:1;font-size:10.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;">
                        <span x-text="$store.ui.lang==='en' ? 'Add what you worked on' : 'Tambah apa yang anda kerjakan'"></span>
                    </strong>
                    <button type="button" @click="picker.open = false; add = { open: false, step: 1, cat: null, proj: null, filter: '' }"
                        class="uj-btn-ghost" style="height:26px;padding:0 9px;font-size:11px;">
                        <span x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'"></span>
                    </button>
                </div>

                {{-- Flat list: saved templates first, then recents. Hidden while "Something else" is open. --}}
                <template x-if="!add.open">
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <input x-show="pickerItems().length > 8" x-model="picker.search"
                            :placeholder="$store.ui.lang==='en' ? 'Search…' : 'Cari…'"
                            style="width:100%;height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" />

                        <div style="display:flex;flex-direction:column;gap:5px;max-height:260px;overflow-y:auto;">
                            <template x-for="item in filteredItems()" :key="item.key">
                                <div @click="chooseItem(item)" role="button" tabindex="0" @keydown.enter="chooseItem(item)"
                                    style="display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:8px;background:#fff;border:1px solid var(--hairline-soft);cursor:pointer;">
                                    <span style="flex:1;font-size:12.5px;color:var(--ink);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" x-text="item.label"></span>
                                    <span x-show="item.isTemplate" x-cloak style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--success);background:var(--canvas);padding:2px 8px;border-radius:999px;flex-shrink:0;">
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

                            <div x-show="pickerItems().length === 0" style="padding:16px 10px;text-align:center;font-size:12px;color:var(--muted);">
                                <span x-text="$store.ui.lang==='en' ? 'Nothing saved yet.' : 'Belum ada simpanan.'"></span>
                                <a href="#" @click.prevent="add = { open: true, step: 1, cat: null, proj: null, filter: '' }" style="color:var(--info);text-decoration:underline;">
                                    <span x-text="$store.ui.lang==='en' ? 'Use Something else instead.' : 'Guna Lain-lain sebagai gantinya.'"></span>
                                </a>
                            </div>

                            {{-- A search with zero matches is otherwise just a blank gap below the
                                 input; distinguish it from "nothing saved at all" above. --}}
                            <div x-show="picker.search.trim() !== '' && filteredItems().length === 0" style="padding:16px 10px;text-align:center;font-size:12px;color:var(--muted);">
                                <span x-text="$store.ui.lang==='en' ? 'No matches. Try \'Something else\' below.' : 'Tiada padanan. Cuba \'Lain-lain\' di bawah.'"></span>
                            </div>
                        </div>

                        <button type="button" @click="add = { open: true, step: 1, cat: null, proj: null, filter: '' }" class="uj-btn-ghost" style="width:100%;height:32px;font-size:12px;">
                            <span x-text="$store.ui.lang==='en' ? 'Something else' : 'Lain-lain'"></span>
                        </button>
                    </div>
                </template>

                {{-- Something else: the original three-step pill drill-down (category -> project
                     -> sub-pillar) from Task 7, moved here unchanged so every combination —
                     including ones with no saved template or recent use — stays reachable. --}}
                <template x-if="add.open">
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <button type="button" x-show="add.step > 1" @click="add.step = add.step - 1" class="uj-btn-ghost" style="height:26px;padding:0 9px;font-size:11px;">&larr;</button>
                            <strong style="flex:1;font-size:10.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;">
                                <span x-show="add.step===1" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'"></span>
                                <span x-show="add.step===2" x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'"></span>
                                <span x-show="add.step===3" x-text="$store.ui.lang==='en' ? 'Sub-pillar (optional)' : 'Sub-tiang (pilihan)'"></span>
                            </strong>
                            <button type="button" @click="add = { open: false, step: 1, cat: null, proj: null, filter: '' }" class="uj-btn-ghost" style="height:26px;padding:0 9px;font-size:11px;"><span x-text="$store.ui.lang==='en' ? 'Back to list' : 'Kembali ke senarai'"></span></button>
                        </div>

                        <div x-show="add.step===1" style="display:flex;flex-wrap:wrap;gap:6px;">
                            <template x-for="c in categories" :key="c.id">
                                <button type="button"
                                    @click="add.cat = c; if (! c.requires_project) { addRow({ category_id: c.id, project_id: '', sub_pillar_id: '' }); picker.open = false; add.open = false; } else { add.step = 2; }"
                                    style="padding:6px 13px;border-radius:999px;border:1px solid var(--hairline);background:#fff;color:var(--ink);font-size:12px;cursor:pointer;white-space:nowrap;"
                                    x-text="$store.ui.lang==='en' ? c.name : (c.name_ms || c.name)"></button>
                            </template>
                        </div>

                        <div x-show="add.step===2">
                            <input x-show="projects.length > 12" x-model="add.filter" :placeholder="$store.ui.lang==='en' ? 'Search project…' : 'Cari projek…'" style="width:100%;height:32px;padding:0 10px;margin-bottom:8px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;outline:none;" />
                            <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                <template x-for="p in projects.filter((p) => !add.filter || p.name.toLowerCase().includes(add.filter.toLowerCase()))" :key="p.id">
                                    <button type="button"
                                        @click="add.proj = p; if ((p.sub_pillars || []).length) { add.step = 3; } else { addRow({ category_id: add.cat.id, project_id: p.id, sub_pillar_id: '' }); picker.open = false; add.open = false; }"
                                        style="padding:6px 13px;border-radius:999px;border:1px solid var(--hairline);background:#fff;color:var(--ink);font-size:12px;cursor:pointer;white-space:nowrap;"
                                        x-text="p.name"></button>
                                </template>
                            </div>
                        </div>

                        <div x-show="add.step===3" style="display:flex;flex-wrap:wrap;gap:6px;">
                            <button type="button" @click="addRow({ category_id: add.cat.id, project_id: add.proj.id, sub_pillar_id: '' }); picker.open = false; add.open = false;"
                                style="padding:6px 13px;border-radius:999px;border:1px solid var(--hairline);background:#fff;color:var(--ink);font-size:12px;cursor:pointer;white-space:nowrap;"
                                x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'"></button>
                            <template x-for="s in (add.proj ? (add.proj.sub_pillars || []) : [])" :key="s.id">
                                <button type="button" @click="addRow({ category_id: add.cat.id, project_id: add.proj.id, sub_pillar_id: s.id }); picker.open = false; add.open = false;"
                                    style="padding:6px 13px;border-radius:999px;border:1px solid var(--hairline);background:#fff;color:var(--ink);font-size:12px;cursor:pointer;white-space:nowrap;"
                                    x-text="s.name"></button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- ---- Footer: save / submit ---- --}}
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px;flex-wrap:wrap;">
            <div style="font-size:12px;flex:1;min-width:200px;">
                <span x-show="!weekComplete()" style="color:var(--amber);" x-text="blockingDaysText() + ($store.ui.lang==='en' ? ' not at 100% yet' : ' belum 100%')"></span>
                <span x-show="weekComplete() && savedAt" style="color:var(--muted);" x-text="($store.ui.lang==='en' ? 'Saved ' : 'Disimpan ') + savedAt"></span>
            </div>
            <div style="display:flex;gap:8px;">
                <button type="button" @click="save(false, true)" :disabled="readonly || saving" class="uj-btn-ghost" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save draft' : 'Simpan draf'">Save draft</span></button>
                <button type="button" @click="save(true)" :disabled="!weekComplete() || readonly || saving"
                    :style="(!weekComplete() || readonly) ? { opacity:'.5', cursor:'not-allowed' } : {}"
                    class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Submit week' : 'Hantar minggu'">Submit week</span></button>
            </div>
        </div>
        <div x-show="error" x-cloak style="margin-top:8px;font-size:12px;color:var(--error);" x-text="error"></div>
    </div>

    {{-- ===================== MY TIMESHEETS ===================== --}}
    <div class="uj-card" style="flex:1;min-width:320px;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My timesheets' : 'Timesheet saya'">My timesheets</span></h3></div>
        @forelse ($myTimesheets as $t)
            <div x-data="{ open: false }" style="border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 20px;">
                    <div style="min-width:0;">
                        <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $t->week_label ?: $t->week_start->format('j M Y') }}</div>
                        <div style="font-size:11.5px;color:var(--muted);">{{ $t->entries->count() }} <span x-text="$store.ui.lang==='en' ? 'entries' : 'entri'">entries</span></div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:11px;font-weight:600;color:{{ $sc[$t->status] }};">{{ ucfirst($t->status) }}</div>
                        @if ($canSeeCost && $rm($t->id))
                            <div style="font-size:11px;font-family:var(--font-mono);color:var(--success);">{{ $rm($t->id) }}</div>
                        @endif
                    </div>
                </div>
                <div style="display:flex;gap:8px;padding:0 20px 12px;">
                    <button type="button" @click="open = ! open" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;"><span x-text="open ? ($store.ui.lang==='en' ? 'Hide entries' : 'Sembunyi entri') : ($store.ui.lang==='en' ? 'View entries' : 'Lihat entri')"></span></button>
                    @if ($t->status === 'draft')
                        <a href="{{ route('app.screen', ['screen' => 'timesheets', 'week' => $t->week_start->toDateString()]) }}" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;"><span x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</span></a>
                        <form method="post" action="{{ route('timesheets.submit', $t) }}">@csrf<button class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:12px;"><span x-text="$store.ui.lang==='en' ? 'Submit' : 'Hantar'">Submit</span></button></form>
                    @endif
                </div>
                <div x-show="open" x-cloak style="padding:0 20px 14px;">
                    @foreach ($t->entries->sortBy('entry_date') as $entry)
                        <div style="font-size:12px;color:var(--muted);padding:5px 0;border-top:1px solid var(--hairline-soft);">
                            <div style="display:flex;justify-content:space-between;gap:12px;">
                                <span>{{ $entry->entry_date->format('D j M') }} · {{ $entryLabel($entry) }}</span>
                                <span style="font-family:var(--font-mono);color:var(--ink);">{{ rtrim(rtrim(number_format($entry->percentage, 2), '0'), '.') }}%</span>
                            </div>
                            @if ($entry->description)
                                <div style="font-size:11.5px;color:var(--muted-soft);margin-top:2px;">{!! $entry->description !!}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No timesheets yet' : 'Tiada timesheet lagi'">No timesheets yet</span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Build this week on the left: add lines and fill each day until it reads 100%, then submit it.' : 'Bina minggu ini di sebelah kiri: tambah baris dan isi setiap hari sehingga membaca 100%, kemudian hantar.'">Build this week on the left.</span></div>
            </div>
        @endforelse
    </div>
</div>

{{-- ===================== MY TIME SPENT (personal, person-days only — never RM) ===================== --}}
@if (! empty($myBreakdown))
    @php $totalMd = rtrim(rtrim(number_format($myBreakdown['totalDays'], 2), '0'), '.'); @endphp
    <div class="uj-card" style="margin-top:16px;padding:20px 22px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:12px;">
            <div>
                <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My time spent' : 'Masa saya'">My time spent</span></h3>
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
@endsection
