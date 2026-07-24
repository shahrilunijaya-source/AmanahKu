@extends('layouts.app')

@php
    use Illuminate\Support\Str;

    // Flag badge labels (EN / MS) — mirrors the staff-facing attendance screen.
    $flagLabel = [
        'late' => ['Late', 'Lewat'],
        'out_of_radius_in' => ['Off-site in', 'Clock in luar'],
        'out_of_radius_out' => ['Off-site out', 'Clock out luar'],
        'early_out' => ['Left early', 'Balik awal'],
        'short_hours' => ['Short hours', 'Jam kurang'],
    ];
    $stColor = ['on_time' => 'var(--success)', 'late' => 'var(--amber)', 'pending' => 'var(--muted-soft)'];
    $stLabel = ['on_time' => ['On time', 'Tepat masa'], 'late' => ['Late', 'Lewat'], 'pending' => ['Pending', 'Menunggu']];

    // Punctuality → swatch. Shared by KPI tile and per-staff cells.
    $pcol = fn (int $p) => $p >= 90 ? 'var(--success)' : ($p >= 75 ? 'var(--amber)' : 'var(--error)');

    $periodLabel = ['week' => ['This week', 'Minggu ini'], 'month' => ['Last 30 days', '30 hari lepas'], 'quarter' => ['Last 90 days', '90 hari lepas']];
    $maxTotal = collect($trend)->max('total') ?: 1;
    $baseUrl = route('app.screen', 'attendance-report').'?period='.$period.($dept ? '&dept='.urlencode($dept) : '');
@endphp

@section('screen')
{{-- Reciprocal of the "see all staff" icon on the personal attendance screen: this
     report is reached by that one-way shortcut, so offer a one-tap way back to My
     attendance rather than leaving the browser Back button as the only exit. --}}
<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
    <a href="{{ route('app.screen', 'attendance') }}" class="uj-btn-ghost" style="font-size:12px;padding:7px 12px;text-decoration:none;">
        <span x-text="$store.ui.lang==='en' ? '← My attendance' : '← Kehadiran saya'">← My attendance</span>
    </a>
</div>
@include('partials.guide', [
    'key' => 'attendance-report',
    'en' => [
        'title' => 'Attendance Reports',
        'body' => 'See how punctual the workforce is over time, then click any staff member to drill into their daily record. Use the period buttons to switch between this week, the last 30 days, or the last 90 days, and filter by department.',
        'who' => 'Management and HR only',
        'steps' => [
            'Pick a period and (optionally) a department at the top.',
            'The tiles summarise punctuality, lateness, off-site clocks and average hours.',
            'The chart shows the on-time vs late trend across the period.',
            'Click a staff row to open their day-by-day attendance.',
        ],
    ],
    'ms' => [
        'title' => 'Laporan Kehadiran',
        'body' => 'Lihat ketepatan masa tenaga kerja dari masa ke masa, kemudian klik mana-mana staf untuk perincikan rekod harian mereka. Guna butang tempoh untuk tukar antara minggu ini, 30 hari lepas, atau 90 hari lepas, dan tapis mengikut jabatan.',
        'who' => 'Pengurusan dan HR sahaja',
        'steps' => [
            'Pilih tempoh dan (pilihan) jabatan di bahagian atas.',
            'Petak meringkaskan ketepatan masa, kelewatan, clock luar lokasi dan purata jam.',
            'Carta menunjukkan trend tepat masa berbanding lewat sepanjang tempoh.',
            'Klik baris staf untuk buka kehadiran harian mereka.',
        ],
    ],
])

<style>
    .ar-filter{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:18px;}
    .ar-pills{display:inline-flex;background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:10px;padding:3px;gap:2px;}
    .ar-pill{padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--muted);text-decoration:none;white-space:nowrap;transition:background .15s ease,color .15s ease;}
    .ar-pill.ar-on{background:var(--surface,#fff);color:var(--ink);box-shadow:0 1px 2px rgba(0,0,0,.06);}
    .ar-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px;}
    .ar-kpi{padding:16px 18px;}
    .ar-kpi-v{font-size:26px;font-weight:700;line-height:1.1;letter-spacing:-.5px;}
    .ar-kpi-k{font-size:11.5px;color:var(--muted);margin-top:4px;}
    .ar-chart{display:flex;align-items:flex-end;gap:6px;height:150px;padding:6px 2px 0;overflow-x:auto;}
    .ar-col{flex:1;min-width:14px;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end;}
    .ar-bar{width:100%;max-width:34px;display:flex;flex-direction:column;justify-content:flex-end;border-radius:5px 5px 0 0;overflow:hidden;background:var(--canvas);min-height:3px;}
    .ar-seg-late{background:var(--amber);}
    .ar-seg-ontime{background:var(--success);}
    .ar-xlab{font-size:10px;color:var(--muted);font-family:var(--font-mono);text-align:center;line-height:1.2;}
    .ar-staff-row{display:grid;grid-template-columns:1.6fr .8fr .7fr .7fr .7fr .9fr;align-items:center;gap:10px;padding:11px 16px;border-bottom:1px solid var(--hairline-soft);text-decoration:none;transition:background .12s ease;}
    .ar-staff-row:hover{background:var(--canvas);}
    .ar-staff-head{font-size:10.5px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
    .ar-num{font-size:13px;font-weight:600;font-family:var(--font-mono);text-align:right;}
    .ar-av{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0;}
    @media (max-width:720px){
        .ar-staff-row{grid-template-columns:1.4fr .7fr .7fr;}
        .ar-hide-sm{display:none;}
    }
</style>

{{-- ── Filter bar: period pills + department select ─────────────────────────── --}}
<div class="ar-filter">
    <div class="ar-pills">
        @foreach ($periods as $p)
            <a href="{{ route('app.screen', 'attendance-report').'?period='.$p.($dept ? '&dept='.urlencode($dept) : '') }}"
               class="ar-pill {{ $p === $period ? 'ar-on' : '' }}">
                <span x-text="$store.ui.lang==='en' ? @js($periodLabel[$p][0]) : @js($periodLabel[$p][1])">{{ $periodLabel[$p][0] }}</span>
            </a>
        @endforeach
    </div>

    <form method="get" action="{{ route('app.screen', 'attendance-report') }}" style="display:inline-flex;align-items:center;gap:8px;">
        <input type="hidden" name="period" value="{{ $period }}" />
        <select name="dept" onchange="this.form.submit()"
                style="padding:8px 12px;border:1px solid var(--hairline);border-radius:9px;font-size:12.5px;color:var(--ink);background:var(--surface,#fff);outline:none;">
            <option value="" {{ $dept ? '' : 'selected' }} x-text="$store.ui.lang==='en' ? 'All departments' : 'Semua jabatan'">All departments</option>
            @foreach ($departments as $d)
                <option value="{{ $d }}" {{ $dept === $d ? 'selected' : '' }}>{{ $d }}</option>
            @endforeach
        </select>
    </form>

    <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);margin-left:auto;">{{ $rangeLabel }}</span>
</div>

{{-- ── KPI tiles ─────────────────────────────────────────────────────────────── --}}
<div class="ar-kpis">
    <div class="uj-card ar-kpi">
        <div class="ar-kpi-v" style="color:{{ $pcol($kpis['punctuality']) }};">{{ $kpis['punctuality'] }}%</div>
        <div class="ar-kpi-k"><span x-text="$store.ui.lang==='en' ? 'On-time rate' : 'Kadar tepat masa'">On-time rate</span></div>
    </div>
    <div class="uj-card ar-kpi">
        <div class="ar-kpi-v" style="color:var(--ink);">{{ $kpis['onTime'] }}</div>
        <div class="ar-kpi-k"><span x-text="$store.ui.lang==='en' ? 'On-time clock-ins' : 'Clock in tepat masa'">On-time clock-ins</span></div>
    </div>
    <div class="uj-card ar-kpi">
        <div class="ar-kpi-v" style="color:{{ $kpis['late'] ? 'var(--amber)' : 'var(--ink)' }};">{{ $kpis['late'] }}</div>
        <div class="ar-kpi-k"><span x-text="$store.ui.lang==='en' ? 'Late arrivals' : 'Kehadiran lewat'">Late arrivals</span></div>
    </div>
    <div class="uj-card ar-kpi">
        <div class="ar-kpi-v" style="color:{{ $kpis['offsite'] ? 'var(--error)' : 'var(--ink)' }};">{{ $kpis['offsite'] }}</div>
        <div class="ar-kpi-k"><span x-text="$store.ui.lang==='en' ? 'Off-site clocks' : 'Clock luar lokasi'">Off-site clocks</span></div>
    </div>
    <div class="uj-card ar-kpi">
        <div class="ar-kpi-v" style="color:var(--ink);">{{ $kpis['avgHours'] }}</div>
        <div class="ar-kpi-k"><span x-text="$store.ui.lang==='en' ? 'Avg hours / day' : 'Purata jam / hari'">Avg hours / day</span></div>
    </div>
    <div class="uj-card ar-kpi">
        <div class="ar-kpi-v" style="color:var(--ink);">{{ $kpis['coverage'] }}%</div>
        <div class="ar-kpi-k"><span x-text="$store.ui.lang==='en' ? '{{ $kpis['reported'] }}/{{ $kpis['headcount'] }} staff reported' : '{{ $kpis['reported'] }}/{{ $kpis['headcount'] }} staf melapor'">{{ $kpis['reported'] }}/{{ $kpis['headcount'] }} staff reported</span></div>
    </div>
</div>

{{-- ── Punctuality trend ─────────────────────────────────────────────────────── --}}
<div class="uj-card" style="margin-bottom:18px;">
    <div class="uj-card-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Punctuality trend' : 'Trend ketepatan masa'">Punctuality trend</span></h3>
        <div style="display:flex;align-items:center;gap:14px;font-size:11.5px;color:var(--muted);">
            <span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:9px;height:9px;border-radius:2px;background:var(--success);"></span><span x-text="$store.ui.lang==='en' ? 'On time' : 'Tepat masa'">On time</span></span>
            <span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:9px;height:9px;border-radius:2px;background:var(--amber);"></span><span x-text="$store.ui.lang==='en' ? 'Late' : 'Lewat'">Late</span></span>
        </div>
    </div>
    <div style="padding:14px 18px 18px;">
        @if (collect($trend)->sum('total') === 0)
            <div style="padding:26px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No clock-ins in this period.' : 'Tiada clock in dalam tempoh ini.'">No clock-ins in this period.</span></div>
        @else
            <div class="ar-chart">
                @foreach ($trend as $b)
                    @php
                        $oh = (int) round($b['onTime'] / $maxTotal * 120);
                        $lh = (int) round($b['late'] / $maxTotal * 120);
                    @endphp
                    <div class="ar-col">
                        <div class="ar-bar" style="height:{{ max($oh + $lh, $b['total'] ? 3 : 0) }}px;{{ $b['weekend'] ? 'opacity:.5;' : '' }}"
                             title="{{ $b['label'] }} · {{ $b['onTime'] }} on time · {{ $b['late'] }} late · {{ $b['pct'] }}%">
                            @if ($b['late'])<div class="ar-seg-late" style="height:{{ $lh }}px;"></div>@endif
                            @if ($b['onTime'])<div class="ar-seg-ontime" style="height:{{ $oh }}px;"></div>@endif
                        </div>
                        <div class="ar-xlab">{{ $b['label'] }}<br><span style="opacity:.6;">{{ $b['sub'] }}</span></div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@if ($drill)
    {{-- ── Single-staff drill-down ───────────────────────────────────────────── --}}
    <div class="uj-card">
        <div class="uj-card-head" style="display:flex;align-items:center;gap:12px;">
            <div class="ar-av" style="background:{{ $drill->avatar_color }};">{{ $drill->initials }}</div>
            <div style="min-width:0;">
                <h3 class="uj-card-title" style="margin:0;">{{ $drill->name }}</h3>
                <div style="font-size:11.5px;color:var(--muted);">{{ trim(($drill->position ?? '').' · '.($drill->department?->name ?? ''), ' ·') }}</div>
            </div>
            <a href="{{ $baseUrl }}" class="uj-btn-ghost" style="margin-left:auto;font-size:12px;padding:7px 12px;text-decoration:none;">
                <span x-text="$store.ui.lang==='en' ? '← All staff' : '← Semua staf'">← All staff</span>
            </a>
        </div>

        <div class="ar-staff-row ar-staff-head" style="background:none;">
            <span x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span>
            <span style="text-align:right;">In</span>
            <span style="text-align:right;" class="ar-hide-sm">Out</span>
            <span style="text-align:right;" class="ar-hide-sm"><span x-text="$store.ui.lang==='en' ? 'Worked' : 'Bekerja'">Worked</span></span>
            <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span></span>
            <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Flags' : 'Tanda'">Flags</span></span>
        </div>
        @forelse ($drillRecords as $r)
            @php
                $rin = $r->clock_in ? Str::of($r->clock_in)->limit(5, '') : '—';
                $rout = $r->clock_out ? Str::of($r->clock_out)->limit(5, '') : '—';
                $wm = (int) ($r->worked_minutes ?? 0);
                $worked = $wm > 0 ? intdiv($wm, 60).'h'.($wm % 60 ? ($wm % 60).'m' : '') : '—';
                $sl = $stLabel[$r->status] ?? [$r->status, $r->status];
            @endphp
            <div class="ar-staff-row" style="cursor:default;">
                <span style="font-size:13px;color:var(--ink);font-weight:500;">{{ $r->date->format('D, j M') }}</span>
                <span class="ar-num">{{ $rin }}</span>
                <span class="ar-num ar-hide-sm">{{ $rout }}</span>
                <span class="ar-num ar-hide-sm" style="font-weight:500;color:var(--muted);">{{ $worked }}</span>
                <span style="text-align:right;font-size:12px;font-weight:600;color:{{ $stColor[$r->status] ?? 'var(--muted)' }};">
                    <span x-text="$store.ui.lang==='en' ? @js($sl[0]) : @js($sl[1])">{{ $sl[0] }}</span>
                </span>
                <span style="display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;">
                    @foreach (($r->flags ?? []) as $f)
                        @php $fl = $flagLabel[$f] ?? [$f, $f]; @endphp
                        <span style="font-size:9px;font-weight:600;color:var(--error);background:var(--red-tint,rgba(214,35,43,.1));padding:2px 5px;border-radius:9999px;white-space:nowrap;" x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</span>
                    @endforeach
                </span>
            </div>
        @empty
            <div style="padding:24px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No attendance in this period.' : 'Tiada kehadiran dalam tempoh ini.'">No attendance in this period.</span></div>
        @endforelse
    </div>
@else
    {{-- ── By-staff roll-up (worst punctuality first) ────────────────────────── --}}
    <div class="uj-card">
        <div class="uj-card-head">
            <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'By staff' : 'Mengikut staf'">By staff</span></h3>
        </div>
        <div class="ar-staff-row ar-staff-head" style="background:none;">
            <span x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</span>
            <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'On-time' : 'Tepat masa'">On-time</span></span>
            <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Late' : 'Lewat'">Late</span></span>
            <span style="text-align:right;" class="ar-hide-sm"><span x-text="$store.ui.lang==='en' ? 'Off-site' : 'Luar'">Off-site</span></span>
            <span style="text-align:right;" class="ar-hide-sm"><span x-text="$store.ui.lang==='en' ? 'Avg hrs' : 'Purata jam'">Avg hrs</span></span>
            <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Punctual' : 'Tepat'">Punctual</span></span>
        </div>
        @forelse ($byStaff as $s)
            <a href="{{ $baseUrl.'&emp='.$s['id'] }}" class="ar-staff-row">
                <span style="display:flex;align-items:center;gap:10px;min-width:0;">
                    <span class="ar-av" style="background:{{ $s['color'] }};">{{ $s['initials'] }}</span>
                    <span style="min-width:0;">
                        <span style="display:block;font-size:13px;color:var(--ink);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $s['name'] }}</span>
                        <span style="display:block;font-size:11px;color:var(--muted);">{{ $s['dept'] ?? '—' }} · {{ $s['days'] }}<span x-text="$store.ui.lang==='en' ? 'd' : 'h'">d</span></span>
                    </span>
                </span>
                <span class="ar-num" style="color:var(--success);">{{ $s['onTime'] }}</span>
                <span class="ar-num" style="color:{{ $s['late'] ? 'var(--amber)' : 'var(--muted)' }};">{{ $s['late'] }}</span>
                <span class="ar-num ar-hide-sm" style="color:{{ $s['offsite'] ? 'var(--error)' : 'var(--muted)' }};">{{ $s['offsite'] }}</span>
                <span class="ar-num ar-hide-sm" style="color:var(--muted);">{{ $s['avgHours'] }}</span>
                <span class="ar-num" style="color:{{ $pcol($s['punctuality']) }};">{{ $s['punctuality'] }}%</span>
            </a>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No attendance recorded' : 'Tiada kehadiran direkod'">No attendance recorded</span></div>
                <div style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No staff clocked in for this period or filter.' : 'Tiada staf clock in untuk tempoh atau penapis ini.'"></span></div>
            </div>
        @endforelse
    </div>
@endif
@endsection
