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
@endphp

@section('screen')
@include('partials.see-all-btn', ['target' => 'timesheet-reports', 'label' => 'See all staff timesheets', 'labelMs' => 'Lihat lembaran masa semua staf'])
@include('partials.guide', [
    'key' => 'timesheets',
    'en'  => [
        'title' => 'Weekly timesheets',
        'body'  => 'Allocate your week by percentage. Add each thing you worked on once as a line (category → project → sub-pillar), then set its share of each day in the grid — every working day column must add up to 100%. Pick a week, fill the grid, then submit the week.',
        'who'   => 'Staff fill & submit · Managers & HR approve',
        'steps' => [
            'Choose the week at the top. The grid shows your lines down the side and the days across the top.',
            'Press "+ Add line" and tap a category pill (and a project pill if it needs one). Then type each day\'s percentage in that line\'s row.',
            'Use "Copy across weekdays" to repeat a line Mon–Fri, or click a day total to fill it to 100%. Save a line as a template, or add one from a saved template.',
            'When every filled day reads 100%, press "Submit week". You can "Save draft" any time before that.',
        ],
    ],
    'ms'  => [
        'title' => 'Timesheet mingguan',
        'body'  => 'Peruntukkan minggu anda mengikut peratus. Tambah setiap perkara yang anda kerjakan sekali sahaja sebagai satu baris (kategori → projek → sub-tiang), kemudian tetapkan bahagiannya bagi setiap hari dalam grid — setiap lajur hari bekerja mesti berjumlah 100%. Pilih minggu, isi grid, kemudian hantar minggu untuk kelulusan.',
        'who'   => 'Staf isi & hantar · Pengurus & HR luluskan',
        'steps' => [
            'Pilih minggu di atas. Grid memaparkan baris anda di sisi dan hari di bahagian atas.',
            'Tekan "+ Tambah baris" dan ketik pil kategori (dan pil projek jika perlu). Kemudian taip peratus setiap hari dalam baris itu.',
            'Guna "Salin ke hari minggu" untuk mengulang baris Isnin–Jumaat, atau klik jumlah hari untuk mengisinya ke 100%. Simpan baris sebagai templat, atau tambah satu daripada templat tersimpan.',
            'Apabila setiap hari yang diisi membaca 100%, tekan "Hantar minggu". Anda boleh "Simpan draf" pada bila-bila masa sebelum itu.',
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
            categories: @js($tsCategories),
            projects: @js($tsProjects),
            templates: @js($tsTemplates),
            existing: @js($existingGrid),
         })">

        {{-- Week picker (GET — reloads the grid for the chosen week) --}}
        <form method="get" action="{{ route('app.screen', 'timesheets') }}" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:6px;">
            <div>
                <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Week starting (Mon)' : 'Minggu bermula (Isnin)'">Week starting (Mon)</span></label>
                <input type="date" name="week" value="{{ $weekStart }}" onchange="this.form.submit()" style="height:40px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
            </div>
            <div style="flex:1;"></div>
            <button type="button" @click="days = (days === 5 ? 7 : 5)" class="uj-btn-ghost" style="height:36px;padding:0 13px;font-size:12px;"><span x-text="days === 5 ? ($store.ui.lang==='en' ? 'Show weekend' : 'Papar hujung minggu') : ($store.ui.lang==='en' ? 'Hide weekend' : 'Sembunyi hujung minggu')"></span></button>
        </form>
        @include('partials.hint', ['en' => 'Pick any Monday to load or build that week. Each day must total 100% before the week can be submitted.', 'ms' => 'Pilih mana-mana Isnin untuk memuatkan atau membina minggu itu. Setiap hari mesti berjumlah 100% sebelum minggu boleh dihantar.'])

        @if ($weekLocked)
            {{-- Locked: this week is already submitted/approved/rejected. Read-only. --}}
            <div style="margin-top:8px;padding:12px 16px;border-radius:10px;background:var(--canvas);border:1px solid var(--hairline);font-size:12.5px;color:var(--ink);">
                <span style="font-weight:600;color:{{ $sc[$weekStatus] }};">{{ ucfirst($weekStatus) }}</span> —
                <span x-text="$store.ui.lang==='en' ? 'this week is locked. Pick another week to edit.' : 'minggu ini dikunci. Pilih minggu lain untuk menyunting.'">this week is locked. Pick another week to edit.</span>
            </div>
            @if ($weekTimesheet)
                <div style="margin-top:12px;">
                    @foreach ($weekTimesheet->entries->sortBy('entry_date') as $entry)
                        <div style="display:flex;justify-content:space-between;gap:12px;font-size:12.5px;color:var(--muted);padding:6px 0;border-top:1px solid var(--hairline-soft);">
                            <span>{{ $entry->entry_date->format('D j M') }} · {{ $entryLabel($entry) }}</span>
                            <span style="font-family:var(--font-mono);color:var(--ink);">{{ rtrim(rtrim(number_format($entry->percentage, 2), '0'), '.') }}%</span>
                        </div>
                    @endforeach
                </div>
            @endif
        @else
            {{-- Editable line-item grid: lines = what you worked on, columns = days --}}
            <form method="post" action="{{ route('timesheets.store') }}" style="margin-top:6px;">
                @csrf
                <input type="hidden" name="week_start" :value="weekStart" />
                <div style="margin-bottom:14px;">
                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Week label (optional)' : 'Label minggu (pilihan)'">Week label (optional)</span></label>
                    <input name="week_label" value="{{ old('week_label', $weekLabel ?? '') }}" :placeholder="$store.ui.lang==='en' ? 'e.g. Week 26 · 16–22 Jun' : 'cth. Minggu 26 · 16–22 Jun'" style="width:100%;height:40px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>

                {{-- The grid scrolls horizontally on narrow cards; all rows share one min-width so columns stay aligned. --}}
                <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                    <div style="min-width:480px;">
                        {{-- Header: day columns --}}
                        <div style="display:grid;gap:6px;align-items:end;padding:0 0 6px;" :style="gridCols()">
                            <div style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;"><span x-text="$store.ui.lang==='en' ? 'What I worked on' : 'Apa saya kerjakan'"></span></div>
                            <template x-for="d in dayDates()" :key="d">
                                <div style="text-align:center;line-height:1.15;">
                                    <div style="font-size:11.5px;font-weight:600;" :style="isToday(d) ? { color:'var(--red)' } : { color:'var(--ink)' }" x-text="dayName(d)"></div>
                                    <div style="font-size:9.5px;color:var(--muted);" x-text="dayShort(d)"></div>
                                </div>
                            </template>
                            <div></div>
                        </div>

                        {{-- One row per line, plus its inline pill picker --}}
                        <template x-for="(line, li) in lines" :key="li">
                            <div>
                                <div style="display:grid;gap:6px;align-items:center;padding:5px 0;border-top:1px solid var(--hairline-soft);" :style="gridCols()">
                                    <button type="button" @click="line._open = ! line._open"
                                        style="text-align:left;min-height:36px;padding:6px 10px;border:1px solid var(--hairline);border-radius:8px;background:#fff;font-size:12px;color:var(--ink);cursor:pointer;line-height:1.25;overflow:hidden;"
                                        :style="lineIncomplete(line) ? { borderColor:'var(--amber)' } : {}">
                                        <template x-if="line.category_id"><span x-text="lineLabel(line)"></span></template>
                                        <template x-if="!line.category_id"><span style="color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Pick category…' : 'Pilih kategori…'"></span></template>
                                    </button>
                                    <template x-for="d in dayDates()" :key="d">
                                        <input type="number" min="0" max="100" step="0.01" inputmode="decimal" x-model="line.cells[d]" placeholder="·"
                                            style="width:100%;height:36px;padding:0 4px;text-align:center;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;background:#fff;" />
                                    </template>
                                    <div style="display:flex;gap:2px;justify-content:flex-end;align-items:center;">
                                        <button type="button" @click="openNote(li)" class="uj-btn-ghost" style="height:34px;padding:0 8px;font-size:12px;" :title="$store.ui.lang==='en' ? 'Add note' : 'Tambah nota'"><span x-text="noteSummary(line) ? '📝' : '✎'"></span></button>
                                        <button type="button" @click="removeLine(li)" class="uj-btn-ghost" style="height:34px;padding:0 8px;font-size:14px;color:var(--error);" :title="$store.ui.lang==='en' ? 'Remove line' : 'Buang baris'">&times;</button>
                                    </div>
                                </div>

                                {{-- Inline pill picker for this line --}}
                                <div x-show="line._open" x-cloak style="padding:12px 14px;margin:4px 0 8px;border:1px solid var(--hairline);border-radius:12px;background:var(--canvas);display:flex;flex-direction:column;gap:10px;">
                                    <div>
                                        <div style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'"></span></div>
                                        <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                            <template x-for="c in categories" :key="c.id">
                                                <button type="button" @click="setCategory(li, c.id)" :style="String(line.category_id)===String(c.id) ? pillOn : pillOff" x-text="catLabel(c)"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <div x-show="requiresProject(line.category_id)">
                                        <div style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'"></span></div>
                                        <input x-show="projects.length > 12" x-model="projFilter" :placeholder="$store.ui.lang==='en' ? 'Search project…' : 'Cari projek…'" style="width:100%;max-width:260px;height:32px;padding:0 10px;margin-bottom:8px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;outline:none;" />
                                        <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                            <template x-for="p in filteredProjects()" :key="p.id">
                                                <button type="button" @click="setProject(li, p.id)" :style="String(line.project_id)===String(p.id) ? pillOn : pillOff" x-text="p.name"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <div x-show="requiresProject(line.category_id) && line.project_id && subPillarsFor(line.project_id).length">
                                        <div style="font-size:10.5px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Sub-pillar (optional)' : 'Sub-tiang (pilihan)'"></span></div>
                                        <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                            <button type="button" @click="setSub(li, '')" :style="!line.sub_pillar_id ? pillOn : pillOff" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'"></button>
                                            <template x-for="s in subPillarsFor(line.project_id)" :key="s.id">
                                                <button type="button" @click="setSub(li, s.id)" :style="String(line.sub_pillar_id)===String(s.id) ? pillOn : pillOff" x-text="s.name"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding-top:2px;">
                                        <button type="button" @click="copyAcross(li)" style="background:none;border:0;color:var(--info);font-size:11.5px;cursor:pointer;padding:0;"><span x-text="$store.ui.lang==='en' ? '↳ Copy across weekdays' : '↳ Salin ke hari minggu'"></span></button>
                                        <button type="button" @click="saveAsTemplate(li)" style="background:none;border:0;color:var(--info);font-size:11.5px;cursor:pointer;padding:0;"><span x-text="$store.ui.lang==='en' ? '★ Save as template' : '★ Simpan templat'"></span></button>
                                        <div style="flex:1;"></div>
                                        <button type="button" @click="line._open = false" class="uj-btn-ghost" style="height:30px;padding:0 14px;font-size:12px;"><span x-text="$store.ui.lang==='en' ? 'Done' : 'Selesai'"></span></button>
                                    </div>
                                </div>
                            </div>
                        </template>

                        {{-- Empty state --}}
                        <template x-if="!lines.length">
                            <div style="padding:18px;text-align:center;border:1px dashed var(--hairline);border-radius:12px;margin-top:10px;font-size:12.5px;color:var(--muted);">
                                <span x-text="$store.ui.lang==='en' ? 'Nothing here yet. Add a line below, pick what you worked on, then fill each day.' : 'Tiada apa-apa lagi. Tambah baris di bawah, pilih apa anda kerjakan, kemudian isi setiap hari.'"></span>
                            </div>
                        </template>

                        {{-- Footer: per-day totals (click a column to fill it to 100%) --}}
                        <div style="display:grid;gap:6px;align-items:center;padding:9px 0 2px;border-top:2px solid var(--hairline);margin-top:2px;" :style="gridCols()">
                            <div style="font-size:10.5px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.04em;"><span x-text="$store.ui.lang==='en' ? 'Day total' : 'Jumlah hari'"></span></div>
                            <template x-for="d in dayDates()" :key="d">
                                <button type="button" @click="fillDay(d)" :title="$store.ui.lang==='en' ? 'Fill to 100% (into last line)' : 'Isi ke 100% (baris akhir)'"
                                    style="height:30px;border:0;background:none;cursor:pointer;font-size:12px;font-weight:700;font-family:var(--font-mono);" :style="`color:${dayColor(d)}`"
                                    x-text="dayEmpty(d) ? '—' : fmtPct(dayTotal(d))"></button>
                            </template>
                            <div></div>
                        </div>
                    </div>
                </div>

                {{-- Add a line + apply a saved template --}}
                <div style="display:flex;gap:8px;align-items:center;margin-top:14px;flex-wrap:wrap;">
                    <button type="button" @click="addLine()" class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? '+ Add line' : '+ Tambah baris'">+ Add line</span></button>
                    <template x-if="templates.length">
                        <select @change="if($event.target.value){ applyTemplate($event.target.value); $event.target.value=''; }" style="height:34px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;background:#fff;outline:none;color:var(--muted);">
                            <option value="" x-text="$store.ui.lang==='en' ? 'Add from template…' : 'Tambah dari templat…'"></option>
                            <template x-for="tpl in templates" :key="tpl.id">
                                <option :value="tpl.id" x-text="tpl.name"></option>
                            </template>
                        </select>
                    </template>
                </div>

                {{-- Manage saved templates: name + delete (the only delete affordance for
                     timesheets.templates.delete — templates could be saved but never removed). --}}
                @if (! empty($tsTemplates))
                    <div style="margin-top:10px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                        <span style="font-size:11px;color:var(--muted-soft);font-weight:600;text-transform:uppercase;letter-spacing:.04em;" x-text="$store.ui.lang==='en' ? 'Saved templates' : 'Templat disimpan'">Saved templates</span>
                        @foreach ($tsTemplates as $tpl)
                            <span style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--body);background:var(--hairline-soft);border-radius:9999px;padding:3px 7px 3px 11px;">
                                {{ $tpl['name'] }}
                                <form method="post" action="{{ route('timesheets.templates.delete', $tpl['id']) }}" style="display:inline;line-height:0;">
                                    @csrf @method('DELETE')
                                    <button type="submit" :title="$store.ui.lang==='en' ? 'Delete template' : 'Padam templat'" style="display:flex;align-items:center;justify-content:center;width:17px;height:17px;border-radius:50%;background:none;border:0;color:var(--muted);cursor:pointer;font-size:14px;line-height:1;">×</button>
                                </form>
                            </span>
                        @endforeach
                    </div>
                @endif

                {{-- Hidden inputs flattened from the grid (the real submitted payload) --}}
                <template x-for="(row, idx) in flatRows()" :key="idx">
                    <span>
                        <input type="hidden" :name="`entries[${idx}][entry_date]`" :value="row.entry_date" />
                        <input type="hidden" :name="`entries[${idx}][category_id]`" :value="row.category_id" />
                        <input type="hidden" :name="`entries[${idx}][project_id]`" :value="row.project_id || ''" />
                        <input type="hidden" :name="`entries[${idx}][sub_pillar_id]`" :value="row.sub_pillar_id || ''" />
                        <input type="hidden" :name="`entries[${idx}][percentage]`" :value="row.percentage" />
                        <input type="hidden" :name="`entries[${idx}][description]`" :value="row.description || ''" />
                    </span>
                </template>

                @error('submit')<div style="font-size:12px;color:var(--error);margin:10px 0 0;">{{ $message }}</div>@enderror
                @if ($errors->has('entries') || $errors->hasAny(['entries.*.project_id', 'entries.*.category_id', 'entries.*.percentage', 'entries.*.sub_pillar_id']))
                    <div style="font-size:12px;color:var(--error);margin:10px 0 0;"><span x-text="$store.ui.lang==='en' ? 'Please check each line has a category and a valid percentage (and a project where required).' : 'Sila pastikan setiap baris ada kategori dan peratus yang sah (dan projek jika perlu).'">Please check each line.</span></div>
                @endif
                <div x-show="anyIncomplete()" x-cloak style="font-size:12px;color:var(--amber);margin:10px 0 0;"><span x-text="$store.ui.lang==='en' ? 'Some lines have a percentage but no category or project — finish or clear them before submitting.' : 'Ada baris ada peratus tetapi tiada kategori atau projek — lengkapkan atau kosongkan sebelum hantar.'"></span></div>

                {{-- Week meter + actions --}}
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px;flex-wrap:wrap;">
                    <div style="font-size:12px;color:var(--muted);">
                        <span x-text="filledDays()"></span> <span x-text="$store.ui.lang==='en' ? 'days filled' : 'hari diisi'">days filled</span> ·
                        <span :style="weekOk() ? 'color:var(--success)' : 'color:var(--amber)'" x-text="weekOk() ? ($store.ui.lang==='en' ? 'all days at 100%' : 'semua hari 100%') : ($store.ui.lang==='en' ? 'some days not 100%' : 'ada hari belum 100%')"></span>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" name="submit_now" value="0" class="uj-btn-ghost" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save draft' : 'Simpan draf'">Save draft</span></button>
                        <button type="submit" name="submit_now" value="1" :disabled="!canSubmit()" :style="!canSubmit() ? { opacity:'.5', cursor:'not-allowed' } : {}" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Submit week' : 'Hantar minggu'">Submit week</span></button>
                    </div>
                </div>
            </form>

            {{-- Note (rich-text) modal — sibling of the form, same Alpine scope --}}
            <template x-teleport="body">
            <div x-show="note.open" x-cloak style="position:fixed;inset:0;z-index:60;display:flex;align-items:center;justify-content:center;background:rgba(20,18,14,.45);padding:20px;" @click.self="closeNote()">
                <div class="uj-card" style="width:100%;max-width:560px;margin:auto;padding:20px;">
                    <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</span></h3>
                    <div x-ref="noteEditor" style="min-height:160px;background:#fff;"></div>
                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
                        <button type="button" @click="closeNote()" class="uj-btn-ghost" style="height:36px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</span></button>
                        <button type="button" @click="saveNote()" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save note' : 'Simpan nota'">Save note</span></button>
                    </div>
                </div>
            </div>
            </template>

            {{-- Hidden transient form used by "Save as template" --}}
            <form method="post" action="{{ route('timesheets.templates.store') }}" x-ref="tplForm" style="display:none;">
                @csrf
                <input type="hidden" name="name" />
                <input type="hidden" name="category_id" />
                <input type="hidden" name="project_id" />
                <input type="hidden" name="sub_pillar_id" />
                <input type="hidden" name="percentage" />
                <input type="hidden" name="description" />
            </form>
        @endif
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
