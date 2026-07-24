@extends('layouts.app')

@php
    $canSeeCost = $canSeeCost ?? false;
    $md = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $rm = fn ($v) => 'RM '.number_format((float) $v, 2);
    $totals = $reportTotals ?? ['days' => 0, 'cost' => 0, 'uncostedDays' => 0];
@endphp

@section('screen')
{{-- Reciprocal of the "see all staff" icon on the personal timesheet screen: this
     report is reached by that one-way shortcut, so offer a one-tap way back to My
     timesheets rather than leaving the browser Back button as the only exit. --}}
<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
    <a href="{{ route('app.screen', 'timesheets') }}" class="uj-btn-ghost" style="font-size:12px;padding:7px 12px;text-decoration:none;">
        <span x-text="$store.ui.lang==='en' ? '← My timesheets' : '← Timesheet saya'">← My timesheets</span>
    </a>
</div>
@include('partials.guide', [
    'key' => 'timesheet-reports',
    'en'  => [
        'title' => 'Timesheet reports',
        'body'  => 'See where staff time and money went, drawn from submitted timesheets. "By category" answers spend questions like how much went to Study or Leave; "by project" shows cost and effort per project; "by staff" breaks one person down. Time is in person-days (a day at 100% = one person-day); cost is RM, derived from each person\'s salary band.',
        'who'   => 'Managers, Management & HR',
        'steps' => [
            'Set the period (defaults to this month). Optionally filter to one category or project, then press Apply.',
            'Read the summary strip for total person-days and total RM in the period.',
            'Switch tabs: By category, By project, or By staff. Bars show each slice as a share of its group.',
        ],
    ],
    'ms'  => [
        'title' => 'Laporan lembaran masa',
        'body'  => 'Lihat ke mana masa dan wang staf pergi, daripada timesheet yang dihantar. "Mengikut kategori" menjawab soalan perbelanjaan seperti berapa untuk Belajar atau Cuti; "mengikut projek" menunjukkan kos dan usaha setiap projek; "mengikut staf" memecahkan seorang. Masa dalam hari-orang (sehari pada 100% = satu hari-orang); kos dalam RM, diambil daripada band gaji setiap orang.',
        'who'   => 'Pengurus, Pengurusan & HR',
        'steps' => [
            'Tetapkan tempoh (lalai bulan ini). Pilihan: tapis kepada satu kategori atau projek, kemudian tekan Guna.',
            'Baca jalur ringkasan untuk jumlah hari-orang dan jumlah RM dalam tempoh.',
            'Tukar tab: Mengikut kategori, projek, atau staf. Bar menunjukkan setiap bahagian sebagai peratus kumpulannya.',
        ],
    ],
])

{{-- This-week compliance roster — who still owes a sheet. Access is the screen's own
     403 gate (management/HR/superiors, see AppController::canSeeAll), not a role check
     here. Always the current week, independent of the report period below. --}}
@php
    $tsRoster = collect($tsRoster ?? []);
    $tsDone = $tsRoster->where('status', 'done')->count();
    $tsTotal = $tsRoster->count();
    // Only chips for people who still owe a sheet — a full done/pending grid is a
    // wall of grey most of the week. Header keeps the done/total ratio for context.
    $tsPending = $tsRoster->where('status', '!=', 'done')->values();
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
        @forelse ($tsPending as $row)
            <span style="display:inline-flex;align-items:center;gap:7px;padding:4px 11px;border-radius:999px;background:var(--surface-2,#f3f4f6);font-size:12px;">
                <span style="width:8px;height:8px;border-radius:50%;background:{{ $tsPill[$row['status']] }};flex:none;"></span>
                <span>{{ $row['employee']->name }}</span>
                <span style="color:var(--muted);font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;">
                    @if ($row['status'] === 'late')
                        <span x-text="$store.ui.lang==='en' ? 'late' : 'lewat'">late</span>
                    @else
                        <span x-text="$store.ui.lang==='en' ? 'pending' : 'belum'">pending</span>
                    @endif
                </span>
            </span>
        @empty
            <span style="font-size:12px;color:var(--success);" x-text="$store.ui.lang==='en' ? 'Everyone is in for this week.' : 'Semua sudah hantar minggu ini.'">Everyone is in for this week.</span>
        @endforelse
    </div>
</div>
@endif

{{-- Period + slice filters --}}
<form method="get" action="{{ route('app.screen', 'timesheet-reports') }}" class="uj-card" style="padding:16px 20px;margin-bottom:16px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;">
    <div>
        <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'From' : 'Dari'">From</span></label>
        <input type="date" name="from" value="{{ $from }}" style="height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
    </div>
    <div>
        <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'To' : 'Hingga'">To</span></label>
        <input type="date" name="to" value="{{ $to }}" style="height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
    </div>
    <div>
        <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</span></label>
        <select name="category" style="height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;outline:none;min-width:150px;">
            <option value="" x-text="$store.ui.lang==='en' ? 'All categories' : 'Semua kategori'">All categories</option>
            @foreach ($filterCategories as $c)
                <option value="{{ $c->id }}" @selected((string) $selCategory === (string) $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'">Project</span></label>
        <select name="project" style="height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;outline:none;min-width:150px;">
            <option value="" x-text="$store.ui.lang==='en' ? 'All projects' : 'Semua projek'">All projects</option>
            @foreach ($filterProjects as $p)
                <option value="{{ $p->id }}" @selected((string) $selProject === (string) $p->id)>{{ $p->name }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Apply' : 'Guna'">Apply</span></button>
</form>

@unless ($reportEmpty)
    {{-- Summary strip: total person-days + total RM for the period --}}
    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
        <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Total person-days' : 'Jumlah hari-orang'">Total person-days</span></div><div class="uj-stat-value">{{ $md($totals['days']) }}</div></div>
        @if ($canSeeCost)
            <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Total cost' : 'Jumlah kos'">Total cost</span></div><div class="uj-stat-value" style="color:var(--success);">{{ $rm($totals['cost']) }}</div></div>
        @endif
        @if ($canSeeCost && (float) $totals['uncostedDays'] > 0)
            <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Uncosted (no salary band)' : 'Tanpa kos (tiada band gaji)'">Uncosted</span></div><div class="uj-stat-value" style="color:var(--amber);">{{ $md($totals['uncostedDays']) }} <span style="font-size:13px;font-weight:400;color:var(--muted);">md</span></div></div>
        @endif
    </div>
@endunless

@if ($reportEmpty)
    <div class="uj-card" style="padding:36px;text-align:center;font-size:13px;color:var(--muted);">
        <span x-text="$store.ui.lang==='en' ? 'No submitted timesheet entries match this period and filter.' : 'Tiada entri timesheet dihantar yang sepadan dengan tempoh dan tapisan ini.'">No timesheet entries in this period.</span>
    </div>
@else
    <div x-data="{ tab: 'category' }">
        {{-- Tabs --}}
        <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
            <button type="button" @click="tab='category'" :class="tab==='category' ? 'uj-btn-primary' : 'uj-btn-ghost'" class="uj-btn-ghost" style="height:36px;padding:0 16px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'By category' : 'Mengikut kategori'">By category</span></button>
            <button type="button" @click="tab='project'" :class="tab==='project' ? 'uj-btn-primary' : 'uj-btn-ghost'" class="uj-btn-ghost" style="height:36px;padding:0 16px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'By project' : 'Mengikut projek'">By project</span></button>
            <button type="button" @click="tab='staff'" :class="tab==='staff' ? 'uj-btn-primary' : 'uj-btn-ghost'" class="uj-btn-ghost" style="height:36px;padding:0 16px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'By staff' : 'Mengikut staf'">By staff</span></button>
        </div>

        {{-- ===================== BY CATEGORY ===================== --}}
        <div x-show="tab==='category'">
            <div class="uj-card" style="padding:8px 0;">
                @foreach ($byCategory as $row)
                    <div style="padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:7px;">
                            <div style="min-width:0;">
                                <span style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $row['label'] }}</span>
                                <span style="font-size:11.5px;color:var(--muted);margin-left:8px;">{{ $row['people'] }} <span x-text="$store.ui.lang==='en' ? (({{ $row['people'] }})===1 ? 'person' : 'people') : 'orang'">people</span></span>
                            </div>
                            <div style="text-align:right;flex-shrink:0;font-family:var(--font-mono);">
                                @if ($canSeeCost)<span style="font-size:13px;font-weight:600;color:var(--success);">{{ $rm($row['cost']) }}</span>@endif
                                <span style="font-size:11.5px;color:var(--muted);margin-left:8px;">{{ $md($row['days']) }} md · {{ $row['pct'] }}%</span>
                            </div>
                        </div>
                        <div style="height:8px;border-radius:9999px;background:var(--canvas);overflow:hidden;"><div style="height:100%;width:{{ $row['pct'] }}%;background:var(--info);border-radius:9999px;"></div></div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ===================== BY PROJECT ===================== --}}
        <div x-show="tab==='project'" x-cloak>
            @forelse ($byProject as $p)
                <div class="uj-card" style="padding:16px 20px;margin-bottom:12px;">
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:12px;">
                        <h3 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;">{{ $p['project'] }}</h3>
                        <span style="font-size:12.5px;font-family:var(--font-mono);">
                            @if ($canSeeCost)<span style="color:var(--success);font-weight:600;">{{ $rm($p['cost']) }}</span> · @endif
                            <span style="color:var(--muted);">{{ $md($p['days']) }} <span x-text="$store.ui.lang==='en' ? 'person-days' : 'hari-orang'">person-days</span></span>
                        </span>
                    </div>
                    @foreach ($p['employees'] as $emp)
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                            <div style="width:26px;height:26px;border-radius:50%;background:{{ $emp['color'] }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex-shrink:0;">{{ $emp['initials'] }}</div>
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;color:var(--ink);margin-bottom:3px;">
                                    <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $emp['name'] }}</span>
                                    <span style="font-family:var(--font-mono);color:var(--muted);flex-shrink:0;">@if ($canSeeCost){{ $rm($emp['cost']) }} · @endif{{ $md($emp['days']) }} md · {{ $emp['pct'] }}%</span>
                                </div>
                                <div style="height:7px;border-radius:9999px;background:var(--canvas);overflow:hidden;">
                                    <div style="height:100%;width:{{ $emp['pct'] }}%;background:{{ $emp['color'] }};border-radius:9999px;"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @empty
                <div class="uj-card" style="padding:28px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No project-linked time in this period.' : 'Tiada masa berkaitan projek dalam tempoh ini.'">No project-linked time in this period.</span></div>
            @endforelse
        </div>

        {{-- ===================== BY STAFF ===================== --}}
        <div x-show="tab==='staff'" x-cloak>
            @foreach ($byStaff as $s)
                <div class="uj-card" style="padding:16px 20px;margin-bottom:12px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                        <div style="width:30px;height:30px;border-radius:50%;background:{{ $s['color'] }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $s['initials'] }}</div>
                        <h3 style="flex:1;font-size:14px;font-weight:600;color:var(--ink);margin:0;">{{ $s['name'] }}</h3>
                        <span style="font-size:12.5px;font-family:var(--font-mono);">
                            @if ($canSeeCost)<span style="color:var(--success);font-weight:600;">{{ $rm($s['cost']) }}</span> · @endif
                            <span style="color:var(--muted);">{{ $md($s['days']) }} <span x-text="$store.ui.lang==='en' ? 'person-days' : 'hari-orang'">person-days</span></span>
                        </span>
                    </div>
                    @foreach ($s['rows'] as $row)
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;color:var(--ink);margin-bottom:3px;">
                                    <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $row['label'] }}</span>
                                    <span style="font-family:var(--font-mono);color:var(--muted);flex-shrink:0;">@if ($canSeeCost){{ $rm($row['cost']) }} · @endif{{ $md($row['days']) }} md · {{ $row['pct'] }}%</span>
                                </div>
                                <div style="height:7px;border-radius:9999px;background:var(--canvas);overflow:hidden;">
                                    <div style="height:100%;width:{{ $row['pct'] }}%;background:var(--info);border-radius:9999px;"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
@endif
@endsection
