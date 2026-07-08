@extends('layouts.app')

@php
    $factor = config('manday.loading_factor');
    $daysPerMonth = config('manday.days_per_month');
    $hoursPerDay = config('manday.hours_per_day');

    // Matrix axes from the real lookup rows — only the departments / levels a band actually uses.
    $usedDeptIds = $positions->pluck('department_id')->unique();
    $usedLvlIds = $positions->pluck('staff_level_id')->unique();
    $depts = $departments->whereIn('id', $usedDeptIds)->values();
    $lvls = $staffLevels->whereIn('id', $usedLvlIds)->values();
    $cell = fn ($d, $l) => $positions->where('department_id', $d->id)->where('staff_level_id', $l->id);

    $assigned = $staff->whereNotNull('position_id')->count();
    $unassigned = $staff->count() - $assigned;

    // Group bands by department so the assign dropdown can use scannable <optgroup>s.
    $positionsByDept = $positions->groupBy(fn ($p) => $p->department?->name ?? '—');

    // First-run: no bands yet → land on "Manage bands" with the add form already open.
    $emptyState = $positions->isEmpty();
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'position',
    'en'  => [
        'title' => 'Position & manday rates',
        'body'  => 'Each position (rank band) is a cell in your org chart — a department + level with a MAX salary. The charge-out rate used to cost timesheets is derived from that band, never from a person\'s real salary. Assign every staff a position so their timesheet hours can be priced.',
        'who'   => 'HR & Management only',
        'steps' => [
            'Add a position: pick the department and level, name the title, and enter its MAX salary.',
            'The manday and manhour rate are computed automatically from the formula below.',
            'Assign each staff member to a position in the table at the bottom.',
            'On Timesheets, HR & management see the RM cost = hours × that position\'s manhour rate.',
        ],
    ],
    'ms'  => [
        'title' => 'Pangkat & kadar manday',
        'body'  => 'Setiap pangkat ialah satu sel dalam carta organisasi — jabatan + peringkat dengan gaji MAKSIMUM. Kadar caj untuk mengira kos timesheet diambil dari band itu, bukan gaji sebenar seseorang. Tetapkan pangkat kepada setiap staf supaya jam timesheet mereka boleh dihargakan.',
        'who'   => 'HR & Pengurusan sahaja',
        'steps' => [
            'Tambah pangkat: pilih jabatan dan peringkat, namakan jawatan, dan masukkan gaji MAKSIMUM.',
            'Kadar manday dan manhour dikira automatik dari formula di bawah.',
            'Tetapkan setiap staf kepada satu pangkat dalam jadual di bawah.',
            'Pada Timesheet, HR & pengurusan melihat kos RM = jam × kadar manhour pangkat itu.',
        ],
    ],
])

@if (session('ok'))
    <div class="uj-card" style="margin-bottom:16px;padding:12px 18px;border:1px solid var(--hairline);border-radius:10px;background:color-mix(in oklch, var(--success) 8%, #fff);font-size:13px;color:var(--ink);">{{ session('ok') }}</div>
@endif
@if (session('error'))
    <div class="uj-card" style="margin-bottom:16px;padding:12px 18px;border:1px solid var(--hairline);border-radius:10px;background:color-mix(in oklch, var(--error) 8%, #fff);font-size:13px;color:var(--ink);">{{ session('error') }}</div>
@endif

{{-- ── Stat strip (global context, above the tabs) ──────────────────────── --}}
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Position bands' : 'Band pangkat'">Position bands</span></div><div class="uj-stat-value">{{ $positions->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Staff assigned' : 'Staf ditetapkan'">Staff assigned</span></div><div class="uj-stat-value" style="color:var(--success);">{{ $assigned }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Unassigned' : 'Belum ditetapkan'">Unassigned</span></div><div class="uj-stat-value" style="color:{{ $unassigned > 0 ? 'var(--amber)' : 'var(--muted)' }};">{{ $unassigned }}</div></div>
</div>

<div x-data="{
        tab: '{{ $emptyState ? 'bands' : 'rates' }}',
        showAdd: {{ $emptyState ? 'true' : 'false' }},
        bandFilter: '',
        staffFilter: ''
     }">

    {{-- ── Tab switcher (segmented control) ─────────────────────────────── --}}
    @php
        $tabBase = 'border:none;border-radius:8px;padding:9px 18px;font-size:13px;cursor:pointer;transition:color .15s,background .15s,box-shadow .15s;display:inline-flex;align-items:center;gap:8px;white-space:nowrap;';
    @endphp
    <div style="display:flex;gap:4px;margin-bottom:18px;background:var(--canvas);border:1px solid var(--hairline);border-radius:11px;padding:4px;width:fit-content;max-width:100%;overflow-x:auto;">
        <button type="button" @click="tab='rates'" style="{{ $tabBase }}"
            :style="tab==='rates' ? { background:'#fff', color:'var(--ink)', fontWeight:'600', boxShadow:'0 1px 2px rgba(0,0,0,.07)' } : { background:'transparent', color:'var(--muted)', fontWeight:'500' }">
            <span x-text="$store.ui.lang==='en' ? 'Rate card' : 'Jadual kadar'">Rate card</span>
        </button>
        <button type="button" @click="tab='bands'" style="{{ $tabBase }}"
            :style="tab==='bands' ? { background:'#fff', color:'var(--ink)', fontWeight:'600', boxShadow:'0 1px 2px rgba(0,0,0,.07)' } : { background:'transparent', color:'var(--muted)', fontWeight:'500' }">
            <span x-text="$store.ui.lang==='en' ? 'Manage bands' : 'Urus band'">Manage bands</span>
            <span style="font-size:11px;font-weight:600;background:var(--canvas);border:1px solid var(--hairline);border-radius:20px;padding:1px 8px;color:var(--muted);">{{ $positions->count() }}</span>
        </button>
        <button type="button" @click="tab='assign'" style="{{ $tabBase }}"
            :style="tab==='assign' ? { background:'#fff', color:'var(--ink)', fontWeight:'600', boxShadow:'0 1px 2px rgba(0,0,0,.07)' } : { background:'transparent', color:'var(--muted)', fontWeight:'500' }">
            <span x-text="$store.ui.lang==='en' ? 'Assign staff' : 'Tetapkan staf'">Assign staff</span>
            @if ($unassigned > 0)
                <span style="font-size:11px;font-weight:600;background:color-mix(in oklch, var(--amber) 16%, #fff);border:1px solid color-mix(in oklch, var(--amber) 35%, var(--hairline));border-radius:20px;padding:1px 8px;color:var(--ink);">{{ $unassigned }}</span>
            @else
                <span style="font-size:11px;font-weight:600;color:var(--success);">✓</span>
            @endif
        </button>
    </div>

    {{-- ══ TAB 1 · RATE CARD ════════════════════════════════════════════ --}}
    <div x-show="tab==='rates'" x-cloak>
        {{-- Formula reference --}}
        <div class="uj-card" style="margin-bottom:16px;padding:16px 20px;display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
            <div style="font-family:var(--font-mono);font-size:13px;color:var(--ink);background:var(--canvas);border:1px solid var(--hairline);border-radius:8px;padding:10px 14px;">
                manday = (max&nbsp;salary × {{ $factor }}) ÷ {{ $daysPerMonth }} &nbsp;·&nbsp; manhour = manday ÷ {{ $hoursPerDay }}
            </div>
            <div style="font-size:12px;color:var(--muted);">
                <span x-text="$store.ui.lang==='en' ? 'Loading factor' : 'Faktor beban'">Loading factor</span> <b style="color:var(--ink);">{{ $factor }}</b> ·
                <span x-text="$store.ui.lang==='en' ? 'days/month' : 'hari/bulan'">days/month</span> <b style="color:var(--ink);">{{ $daysPerMonth }}</b> ·
                <span x-text="$store.ui.lang==='en' ? 'hours/day' : 'jam/hari'">hours/day</span> <b style="color:var(--ink);">{{ $hoursPerDay }}</b>
            </div>
        </div>

        <div class="uj-card" style="padding:0;overflow:hidden;">
            <div class="uj-card-head" style="padding:16px 20px;"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Rate card (department × level)' : 'Jadual kadar (jabatan × peringkat)'">Rate card</span></h3></div>
            @if ($emptyState)
                <div style="padding:36px 20px;text-align:center;font-size:13px;color:var(--muted);">
                    <span x-text="$store.ui.lang==='en' ? 'No position bands yet.' : 'Tiada band pangkat lagi.'">No position bands yet.</span>
                    <button type="button" @click="tab='bands'; showAdd=true" class="uj-btn-primary" style="margin-left:8px;height:32px;padding:0 14px;font-size:12px;"><span x-text="$store.ui.lang==='en' ? 'Add your first band' : 'Tambah band pertama'">Add your first band</span></button>
                </div>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:680px;">
                        <thead>
                            <tr style="background:var(--canvas);">
                                <th style="text-align:left;padding:9px 14px;color:var(--muted);font-weight:600;position:sticky;left:0;background:var(--canvas);"><span x-text="$store.ui.lang==='en' ? 'Level' : 'Peringkat'">Level</span></th>
                                @foreach ($depts as $d)
                                    <th style="text-align:left;padding:9px 14px;color:var(--muted);font-weight:600;white-space:nowrap;">{{ $d->name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lvls as $l)
                                @php $rowCells = $depts->map(fn ($d) => $cell($d, $l)); @endphp
                                @continue($rowCells->every(fn ($c) => $c->isEmpty()))
                                <tr style="border-top:1px solid var(--hairline-soft);">
                                    <td style="padding:10px 14px;font-weight:600;color:var(--ink);white-space:nowrap;position:sticky;left:0;background:#fff;">{{ $l->name }}</td>
                                    @foreach ($depts as $d)
                                        <td style="padding:8px 14px;vertical-align:top;">
                                            @foreach ($cell($d, $l) as $p)
                                                <div style="margin-bottom:6px;">
                                                    <div style="color:var(--ink);font-weight:500;">{{ $p->title }}</div>
                                                    <div style="color:var(--muted);font-family:var(--font-mono);font-size:11px;">
                                                        RM {{ number_format((float) $p->max_salary, 0) }} ·
                                                        <span title="manday">RM {{ number_format($p->mandayRate(), 2) }}/d</span> ·
                                                        <span title="manhour">RM {{ number_format($p->manhourRate(), 2) }}/h</span>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ══ TAB 2 · MANAGE BANDS ═════════════════════════════════════════ --}}
    <div x-show="tab==='bands'" x-cloak>

        {{-- Add / import — collapsed by default, toggled inline (no modal) --}}
        <div class="uj-card" style="margin-bottom:16px;padding:0;overflow:hidden;">
            <div class="uj-card-head" style="padding:14px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Add a position band' : 'Tambah band pangkat'">Add a position band</span></h3>
                <button type="button" @click="showAdd = ! showAdd" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;">
                    <span x-show="! showAdd"><span x-text="$store.ui.lang==='en' ? '+ New band' : '+ Band baru'">+ New band</span></span>
                    <span x-show="showAdd" x-cloak><span x-text="$store.ui.lang==='en' ? 'Close' : 'Tutup'">Close</span></span>
                </button>
            </div>

            <div x-show="showAdd" x-cloak style="padding:4px 20px 20px;border-top:1px solid var(--hairline-soft);">
                <form method="post" action="{{ route('position.store') }}" style="padding-top:14px;">
                    @csrf
                    <div style="display:flex;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
                        <div style="flex:1;min-width:160px;">
                            <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Department' : 'Jabatan'">Department</span></label>
                            <select name="department_id" required style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;">
                                <option value="">—</option>
                                @foreach ($departments as $d)<option value="{{ $d->id }}" @selected(old('department_id') == $d->id)>{{ $d->name }}</option>@endforeach
                            </select>
                        </div>
                        <div style="flex:1;min-width:160px;">
                            <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Level' : 'Peringkat'">Level</span></label>
                            <select name="staff_level_id" required style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;">
                                <option value="">—</option>
                                @foreach ($staffLevels as $l)<option value="{{ $l->id }}" @selected(old('staff_level_id') == $l->id)>{{ $l->name }}</option>@endforeach
                            </select>
                        </div>
                        <div style="flex:1.6;min-width:200px;">
                            <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Position title' : 'Jawatan'">Position title</span></label>
                            <input name="title" required value="{{ old('title') }}" :placeholder="$store.ui.lang==='en' ? 'e.g. Project Manager' : 'cth. Project Manager'" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:flex-end;">
                        <div style="flex:1;min-width:110px;">
                            <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Code' : 'Kod'">Code</span></label>
                            <input name="code" value="{{ old('code') }}" placeholder="PM-01" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                        </div>
                        <div style="flex:1.2;min-width:140px;">
                            <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Default role' : 'Peranan lalai'">Default role</span></label>
                            <select name="default_role" style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;">
                                <option value="">—</option>
                                @foreach (['employee' => 'Employee', 'manager' => 'Manager', 'management' => 'Management', 'hr' => 'HR'] as $v => $l)<option value="{{ $v }}" @selected(old('default_role') === $v)>{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        <div style="flex:1.4;min-width:150px;">
                            <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Max salary (RM)' : 'Gaji maks (RM)'">Max salary (RM)</span></label>
                            <input type="number" step="0.01" min="0" name="max_salary" required value="{{ old('max_salary') }}" placeholder="0.00" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;font-family:var(--font-mono);outline:none;" />
                        </div>
                        <div style="width:84px;">
                            <label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Sort' : 'Susun'">Sort</span></label>
                            <input type="number" min="0" name="sort" value="{{ old('sort', 0) }}" style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                        </div>
                        <label style="display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--ink);height:38px;white-space:nowrap;">
                            <input type="checkbox" name="is_managerial" value="1" @checked(old('is_managerial')) style="accent-color:var(--red);width:15px;height:15px;" /> <span x-text="$store.ui.lang==='en' ? 'Managerial' : 'Pengurusan'">Managerial</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--ink);height:38px;white-space:nowrap;" :title="$store.ui.lang==='en' ? 'Pins staff on this band to the org chart\'s Directors band — no login needed.' : 'Menyematkan staf band ini ke jalur Pengarah dalam carta organisasi — tanpa log masuk.'">
                            <input type="checkbox" name="is_director" value="1" @checked(old('is_director')) style="accent-color:#8a6d00;width:15px;height:15px;" /> <span x-text="$store.ui.lang==='en' ? 'Director band' : 'Band pengarah'">Director band</span>
                        </label>
                    </div>
                    @foreach (['department_id', 'staff_level_id', 'title', 'max_salary'] as $f)
                        @error($f)<div style="font-size:12px;color:var(--error);margin-bottom:8px;">{{ $message }}</div>@enderror
                    @endforeach
                    <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Add position' : 'Tambah pangkat'">Add position</span></button>
                </form>

                {{-- Bulk import via spreadsheet --}}
                <div style="margin-top:18px;padding-top:16px;border-top:1px dashed var(--hairline);">
                    <div style="font-size:12px;font-weight:600;color:var(--ink);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Bulk import' : 'Import pukal'">Bulk import</span></div>
                    <div style="font-size:11.5px;color:var(--muted);line-height:1.5;margin-bottom:10px;">
                        <span x-text="$store.ui.lang==='en' ? 'Download the template, fill it in Excel, then upload to add many bands at once. Unknown departments / levels are created automatically.' : 'Muat turun templat, isi dalam Excel, kemudian muat naik untuk tambah banyak band sekali gus. Jabatan / peringkat yang tiada dicipta automatik.'"></span>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                        <a href="{{ route('position.import.template') }}" class="uj-btn-ghost" style="height:34px;display:inline-flex;align-items:center;padding:0 12px;font-size:12px;text-decoration:none;">
                            <span x-text="$store.ui.lang==='en' ? '↓ Download template' : '↓ Muat turun templat'">↓ Download template</span>
                        </a>
                        <form method="post" action="{{ route('position.import') }}" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            @csrf
                            <input type="file" name="file" accept=".csv,text/csv" required style="font-size:12px;max-width:190px;" />
                            <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12px;"><span x-text="$store.ui.lang==='en' ? 'Upload' : 'Muat naik'">Upload</span></button>
                        </form>
                    </div>
                    @error('file')<div style="font-size:12px;color:var(--error);margin-top:8px;">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        {{-- Band list — searchable + height-capped (internal scroll) --}}
        <div class="uj-card" style="padding:0;overflow:hidden;">
            <div class="uj-card-head" style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'All position bands' : 'Semua band pangkat'">All position bands</span></h3>
                @unless ($emptyState)
                    <input type="search" x-model="bandFilter" :placeholder="$store.ui.lang==='en' ? 'Filter bands…' : 'Tapis band…'" style="height:32px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;min-width:200px;" />
                @endunless
            </div>
            <div style="max-height:560px;overflow-y:auto;">
                @forelse ($positions as $p)
                    <div x-data="{ edit: false }"
                         x-show="bandFilter === '' || @js(\Illuminate\Support\Str::lower($p->title.' '.($p->department?->name ?? '').' '.($p->staffLevel?->name ?? '').' '.$p->code)).includes(bandFilter.toLowerCase().trim())"
                         style="border-top:1px solid var(--hairline-soft);">
                        <div style="display:flex;align-items:center;gap:12px;padding:11px 20px;">
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                                    {{ $p->title }}
                                    @if ($p->is_director)
                                        <span style="font-size:9.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#8a6d00;background:#fdf1c4;border:1px solid #f2d675;border-radius:9999px;padding:1px 7px;line-height:1.4;" x-text="$store.ui.lang==='en' ? 'Director' : 'Pengarah'">Director</span>
                                    @endif
                                </div>
                                <div style="font-size:11.5px;color:var(--muted);">{{ $p->department?->name }} · {{ $p->staffLevel?->name }}</div>
                            </div>
                            <div style="text-align:right;flex-shrink:0;font-family:var(--font-mono);">
                                <div style="font-size:13px;color:var(--ink);font-weight:600;">RM {{ number_format((float) $p->max_salary, 2) }}</div>
                                <div style="font-size:11px;color:var(--muted);">RM {{ number_format($p->mandayRate(), 2) }}/d · RM {{ number_format($p->manhourRate(), 2) }}/h</div>
                            </div>
                            <button type="button" @click="edit = ! edit" class="uj-btn-ghost" style="height:30px;padding:0 10px;font-size:12px;flex-shrink:0;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
                        </div>
                        <div x-show="edit" x-cloak style="padding:0 20px 14px;">
                            <form method="post" action="{{ route('position.update', $p) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                                @csrf
                                <select name="department_id" required style="flex:1;min-width:110px;height:34px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;background:#fff;color:var(--ink);outline:none;">
                                    @foreach ($departments as $d)<option value="{{ $d->id }}" @selected($p->department_id == $d->id)>{{ $d->name }}</option>@endforeach
                                </select>
                                <select name="staff_level_id" required style="flex:1;min-width:100px;height:34px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;background:#fff;color:var(--ink);outline:none;">
                                    @foreach ($staffLevels as $l)<option value="{{ $l->id }}" @selected($p->staff_level_id == $l->id)>{{ $l->name }}</option>@endforeach
                                </select>
                                <input name="title" required value="{{ $p->title }}" style="flex:1.4;min-width:130px;height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;outline:none;" />
                                <input type="number" step="0.01" min="0" name="max_salary" required value="{{ (float) $p->max_salary }}" style="width:110px;height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;font-family:var(--font-mono);outline:none;" />
                                <input type="number" min="0" name="sort" value="{{ $p->sort }}" style="width:64px;height:34px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;outline:none;" />
                                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ink);height:34px;white-space:nowrap;">
                                    <input type="checkbox" name="is_managerial" value="1" @checked($p->is_managerial) style="accent-color:var(--red);width:14px;height:14px;" /> <span x-text="$store.ui.lang==='en' ? 'Managerial' : 'Pengurusan'">Managerial</span>
                                </label>
                                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--ink);height:34px;white-space:nowrap;" :title="$store.ui.lang==='en' ? 'Pins staff on this band to the org chart\'s Directors band.' : 'Menyematkan staf band ini ke jalur Pengarah.'">
                                    <input type="checkbox" name="is_director" value="1" @checked($p->is_director) style="accent-color:#8a6d00;width:14px;height:14px;" /> <span x-text="$store.ui.lang==='en' ? 'Director' : 'Pengarah'">Director</span>
                                </label>
                                <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12px;"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
                            </form>
                            <form method="post" action="{{ route('position.destroy', $p) }}" @submit="if (! confirm($store.ui.lang==='en' ? 'Delete this position? Staff on it become unassigned.' : 'Padam pangkat ini? Staf padanya menjadi belum ditetapkan.')) $event.preventDefault()" style="margin-top:8px;">
                                @csrf
                                <button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
                            </form>
                        </div>
                    </div>
                @empty
                    <div style="padding:24px 20px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No bands yet.' : 'Tiada band lagi.'">No bands yet.</span></div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ══ TAB 3 · ASSIGN STAFF ═════════════════════════════════════════ --}}
    <div x-show="tab==='assign'" x-cloak>
        <div class="uj-card" style="padding:0;overflow:hidden;">
            <div class="uj-card-head" style="padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div>
                    <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Assign position to staff' : 'Tetapkan pangkat kepada staf'">Assign position to staff</span></h3>
                    @unless ($emptyState)
                        <div style="font-size:11.5px;color:var(--muted);margin-top:3px;"><span x-text="$store.ui.lang==='en' ? 'Pick a band — it saves automatically.' : 'Pilih band — ia simpan automatik.'">Pick a band — it saves automatically.</span></div>
                    @endunless
                </div>
                @unless ($emptyState)
                    <input type="search" x-model="staffFilter" :placeholder="$store.ui.lang==='en' ? 'Filter staff…' : 'Tapis staf…'" style="height:32px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;min-width:200px;" />
                @endunless
            </div>
            @if ($emptyState)
                <div style="padding:28px 20px;text-align:center;font-size:13px;color:var(--muted);">
                    <span x-text="$store.ui.lang==='en' ? 'Add at least one position band before assigning staff.' : 'Tambah sekurang-kurangnya satu band pangkat sebelum menetapkan staf.'">Add at least one position band before assigning staff.</span>
                    <button type="button" @click="tab='bands'; showAdd=true" class="uj-btn-primary" style="margin-left:8px;height:32px;padding:0 14px;font-size:12px;"><span x-text="$store.ui.lang==='en' ? 'Add a band' : 'Tambah band'">Add a band</span></button>
                </div>
            @else
                <div style="max-height:620px;overflow-y:auto;">
                    @foreach ($staff as $emp)
                        <div x-show="staffFilter === '' || @js(\Illuminate\Support\Str::lower($emp->name)).includes(staffFilter.toLowerCase().trim())"
                             x-data="{
                                positionId: '{{ $emp->position_id }}',
                                saving: false, saved: false, error: false,
                                save() {
                                    this.saving = true; this.saved = false; this.error = false;
                                    fetch('{{ route('position.assign', $emp) }}', {
                                        method: 'POST',
                                        headers: {
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                            'Content-Type': 'application/json',
                                            'Accept': 'application/json',
                                        },
                                        body: JSON.stringify({ position_id: this.positionId ? Number(this.positionId) : null }),
                                    })
                                    .then(r => { if (! r.ok) throw 0; this.saved = true; setTimeout(() => this.saved = false, 1800); })
                                    .catch(() => { this.error = true; setTimeout(() => this.error = false, 3000); })
                                    .finally(() => { this.saving = false; });
                                }
                             }"
                             style="display:flex;align-items:center;gap:12px;padding:9px 20px;border-top:1px solid var(--hairline-soft);">
                            <div style="width:30px;height:30px;border-radius:50%;background:{{ $emp->avatar_color }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $emp->initials }}</div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:var(--ink);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $emp->name }}</div>
                                <div style="font-size:11.5px;color:var(--muted);">{{ $emp->department?->name ?? '—' }}@if ($emp->positionBand) · {{ $emp->positionBand->title }}@endif</div>
                            </div>
                            <select x-model="positionId" @change="save()" :disabled="saving" style="width:280px;max-width:42vw;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:#fff;">
                                <option value="">— {{ 'unassigned' }} —</option>
                                @foreach ($positionsByDept as $deptName => $group)
                                    <optgroup label="{{ $deptName }}">
                                        @foreach ($group as $p)
                                            <option value="{{ $p->id }}">{{ $p->title }} · {{ $p->staffLevel?->name }} · RM {{ number_format((float) $p->max_salary, 0) }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            <span style="width:18px;flex-shrink:0;text-align:center;font-size:13px;">
                                <span x-show="saving" x-cloak style="color:var(--muted);">·</span>
                                <span x-show="saved" x-cloak x-transition style="color:var(--success);font-weight:700;">✓</span>
                                <span x-show="error" x-cloak x-transition style="color:var(--error);font-weight:700;" title="Failed — try again">!</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

</div>
@endsection
