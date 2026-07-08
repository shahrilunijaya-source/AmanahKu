@extends('layouts.app')

@php
    $periodLabel = ['ytd' => ['This year', 'Tahun ini'], '12m' => ['Last 12 months', '12 bulan lepas'], 'quarter' => ['Last 90 days', '90 hari lepas']];
    $baseUrl = route('app.screen', 'leave-report').'?period='.$period.($dept ? '&dept='.urlencode($dept) : '');
    $maxTypeDays = collect($byType)->max('days') ?: 1;
    // Unplanned share → tile colour. Rising emergency leave is the signal to surface.
    $upcol = $kpis['unplannedPct'] >= 25 ? 'var(--error)' : ($kpis['unplannedPct'] >= 10 ? 'var(--amber)' : 'var(--success)');
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'leave-report',
    'en' => [
        'title' => 'Leave Reports',
        'body' => 'See how much leave the company takes — split by type and by person. Emergency (unplanned) leave is tracked separately: it is not extra entitlement, it deducts from Annual, and frequent use is a sign someone is not planning ahead. Click any staff member to drill into their leave.',
        'who' => 'Management, HR and managers (their own team)',
        'steps' => [
            'Pick a period and (optionally) a department at the top.',
            'The tiles summarise total leave, unplanned leave and pending requests.',
            'The "By type" panel shows where leave is going; "By staff" surfaces frequent emergency takers first.',
            'Click a staff row to open their leave history and per-type breakdown.',
        ],
    ],
    'ms' => [
        'title' => 'Laporan Cuti',
        'body' => 'Lihat berapa banyak cuti diambil syarikat — mengikut jenis dan individu. Cuti kecemasan (tidak dirancang) dijejak berasingan: ia bukan kelayakan tambahan, ia ditolak daripada Cuti Tahunan, dan penggunaan kerap menandakan seseorang tidak merancang. Klik mana-mana staf untuk perincikan cuti mereka.',
        'who' => 'Pengurusan, HR dan pengurus (pasukan sendiri)',
        'steps' => [
            'Pilih tempoh dan (pilihan) jabatan di bahagian atas.',
            'Petak meringkaskan jumlah cuti, cuti tidak dirancang dan permohonan tertunggak.',
            'Panel "Mengikut jenis" menunjukkan ke mana cuti pergi; "Mengikut staf" mengutamakan pengambil kecemasan kerap.',
            'Klik baris staf untuk buka sejarah cuti dan pecahan mengikut jenis mereka.',
        ],
    ],
])

<style>
    .lr-filter{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:18px;}
    .lr-pills{display:inline-flex;background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:10px;padding:3px;gap:2px;}
    .lr-pill{padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--muted);text-decoration:none;white-space:nowrap;}
    .lr-pill.lr-on{background:var(--surface,#fff);color:var(--ink);box-shadow:0 1px 2px rgba(0,0,0,.06);}
    .lr-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px;}
    .lr-kpi{padding:16px 18px;}
    .lr-kpi-v{font-size:26px;font-weight:700;line-height:1.1;letter-spacing:-.5px;}
    .lr-kpi-k{font-size:11.5px;color:var(--muted);margin-top:4px;}
    .lr-type-row{display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--hairline-soft);}
    .lr-type-name{width:120px;font-size:13px;color:var(--ink);flex-shrink:0;}
    .lr-bar-track{flex:1;height:10px;background:var(--canvas);border-radius:6px;overflow:hidden;}
    .lr-bar-fill{height:100%;border-radius:6px;}
    .lr-type-days{width:64px;text-align:right;font-size:12.5px;font-weight:600;font-family:var(--font-mono);}
    .lr-staff-row{display:grid;grid-template-columns:1.7fr .8fr .7fr .7fr .8fr .8fr;align-items:center;gap:10px;padding:11px 16px;border-bottom:1px solid var(--hairline-soft);text-decoration:none;}
    .lr-staff-row:hover{background:var(--canvas);}
    .lr-head{font-size:10.5px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;}
    .lr-num{font-size:13px;font-weight:600;font-family:var(--font-mono);text-align:right;}
</style>

{{-- Period + department filter --}}
<div class="lr-filter">
    <div class="lr-pills">
        @foreach ($periods as $p)
            <a href="{{ route('app.screen', 'leave-report') }}?period={{ $p }}{{ $dept ? '&dept='.urlencode($dept) : '' }}" class="lr-pill {{ $period === $p ? 'lr-on' : '' }}">
                <span x-text="$store.ui.lang==='en' ? '{{ $periodLabel[$p][0] }}' : '{{ $periodLabel[$p][1] }}'">{{ $periodLabel[$p][0] }}</span>
            </a>
        @endforeach
    </div>
    <select onchange="window.location=this.value" style="height:36px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);">
        <option value="{{ route('app.screen', 'leave-report') }}?period={{ $period }}">{{ __('All departments') }}</option>
        @foreach ($departments as $d)
            <option value="{{ route('app.screen', 'leave-report') }}?period={{ $period }}&dept={{ urlencode($d) }}" {{ $dept === $d ? 'selected' : '' }}>{{ $d }}</option>
        @endforeach
    </select>
    <span style="font-size:12px;color:var(--muted-soft);">{{ $rangeLabel }}</span>
</div>

{{-- Company-wide KPI tiles --}}
<div class="lr-kpis">
    <div class="uj-card lr-kpi"><div class="lr-kpi-v">{{ rtrim(rtrim(number_format($kpis['totalDays'], 1), '0'), '.') }}</div><div class="lr-kpi-k"><span x-text="$store.ui.lang==='en' ? 'Leave days taken' : 'Hari cuti diambil'">Leave days taken</span></div></div>
    <div class="uj-card lr-kpi"><div class="lr-kpi-v" style="color:{{ $upcol }};">{{ rtrim(rtrim(number_format($kpis['unplannedDays'], 1), '0'), '.') }} <span style="font-size:15px;">({{ $kpis['unplannedPct'] }}%)</span></div><div class="lr-kpi-k"><span x-text="$store.ui.lang==='en' ? 'Emergency (unplanned)' : 'Kecemasan (tak dirancang)'">Emergency (unplanned)</span></div></div>
    <div class="uj-card lr-kpi"><div class="lr-kpi-v">{{ $kpis['pending'] }}</div><div class="lr-kpi-k"><span x-text="$store.ui.lang==='en' ? 'Pending requests' : 'Permohonan tertunggak'">Pending requests</span></div></div>
    <div class="uj-card lr-kpi"><div class="lr-kpi-v">{{ $kpis['avgPerHead'] }}</div><div class="lr-kpi-k"><span x-text="$store.ui.lang==='en' ? 'Avg days / head' : 'Purata hari / orang'">Avg days / head</span></div></div>
    <div class="uj-card lr-kpi"><div class="lr-kpi-v">{{ $kpis['staffTaken'] }}<span style="font-size:15px;color:var(--muted-soft);"> / {{ $kpis['headcount'] }}</span></div><div class="lr-kpi-k"><span x-text="$store.ui.lang==='en' ? 'Staff who took leave' : 'Staf yang bercuti'">Staff who took leave</span></div></div>
</div>

@if ($kpis['unplannedPct'] >= 25 && $kpis['unplannedDays'] > 0)
    <div class="uj-card" style="border-left:3px solid var(--error);padding:12px 16px;margin-bottom:18px;">
        <span style="font-size:13px;color:var(--error);font-weight:600;">⚠ <span x-text="$store.ui.lang==='en' ? 'High emergency-leave rate' : 'Kadar cuti kecemasan tinggi'">High emergency-leave rate</span></span>
        <span style="font-size:12.5px;color:var(--muted);"> — <span x-text="$store.ui.lang==='en' ? '{{ $kpis['unplannedPct'] }}% of leave was unplanned across {{ $kpis['unplannedStaff'] }} staff. Review the top of the staff table.' : '{{ $kpis['unplannedPct'] }}% cuti tidak dirancang merentas {{ $kpis['unplannedStaff'] }} staf. Semak bahagian atas jadual staf.'"></span></span>
    </div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- By type --}}
    <div class="uj-card" style="flex:1;min-width:300px;padding:20px;">
        <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'By leave type' : 'Mengikut jenis cuti'">By leave type</span></h3>
        @forelse ($byType as $t)
            <div class="lr-type-row">
                <span class="lr-type-name">{{ $t['name'] }}@if ($t['unplanned'])<span style="color:var(--error);font-size:11px;"> ⚑</span>@endif</span>
                <div class="lr-bar-track"><div class="lr-bar-fill" style="width:{{ max(3, (int) round($t['days'] / $maxTypeDays * 100)) }}%;background:{{ $t['unplanned'] ? 'var(--error)' : 'var(--accent, #3a6ea5)' }};"></div></div>
                <span class="lr-type-days">{{ rtrim(rtrim(number_format($t['days'], 1), '0'), '.') }}d</span>
            </div>
        @empty
            <div style="font-size:13px;color:var(--muted);padding:8px 0;"><span x-text="$store.ui.lang==='en' ? 'No approved leave in this period.' : 'Tiada cuti diluluskan dalam tempoh ini.'">No approved leave in this period.</span></div>
        @endforelse
    </div>

    {{-- Per-staff drill (only when a staff member is selected) --}}
    @if ($drill)
        <div class="uj-card" style="flex:1;min-width:300px;padding:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 class="uj-card-title">{{ $drill->name }}</h3>
                <a href="{{ $baseUrl }}" style="font-size:12px;color:var(--info);text-decoration:none;">✕ <span x-text="$store.ui.lang==='en' ? 'Close' : 'Tutup'">Close</span></a>
            </div>
            @foreach ($drillByType as $t)
                <div class="lr-type-row"><span class="lr-type-name">{{ $t['name'] }}@if ($t['unplanned'])<span style="color:var(--error);"> ⚑</span>@endif</span><span class="lr-type-days" style="margin-left:auto;">{{ rtrim(rtrim(number_format($t['days'], 1), '0'), '.') }}d</span></div>
            @endforeach
            <h4 style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin:16px 0 6px;"><span x-text="$store.ui.lang==='en' ? 'History' : 'Sejarah'">History</span></h4>
            @foreach ($drillRequests as $r)
                <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--hairline-soft);font-size:12.5px;">
                    <span style="color:var(--ink);">{{ $r->leaveType?->name }}@if ($r->leaveType?->is_unplanned)<span style="color:var(--error);"> ⚑</span>@endif</span>
                    <span style="color:var(--muted);">{{ $r->date_from->format('j M') }}–{{ $r->date_to->format('j M') }} · {{ $r->days }}d</span>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- By staff — worst unplanned first --}}
<div class="uj-card" style="margin-top:16px;padding:0;overflow:hidden;">
    <div style="padding:16px 16px 10px;"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'By staff' : 'Mengikut staf'">By staff</span> <span style="font-size:12px;color:var(--muted-soft);font-weight:400;">— <span x-text="$store.ui.lang==='en' ? 'most unplanned leave first' : 'cuti tak dirancang dahulu'">most unplanned leave first</span></span></h3></div>
    <div class="lr-staff-row lr-head" style="border-bottom:1px solid var(--hairline);">
        <span><span x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</span></span>
        <span><span x-text="$store.ui.lang==='en' ? 'Dept' : 'Jabatan'">Dept</span></span>
        <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Total' : 'Jumlah'">Total</span></span>
        <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Planned' : 'Dirancang'">Planned</span></span>
        <span style="text-align:right;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Emergency' : 'Kecemasan'">Emergency</span></span>
        <span style="text-align:right;"><span x-text="$store.ui.lang==='en' ? 'Annual left' : 'Baki tahunan'">Annual left</span></span>
    </div>
    @forelse ($byStaff as $s)
        <a href="{{ $baseUrl }}&emp={{ $s['id'] }}" class="lr-staff-row">
            <span style="display:flex;align-items:center;gap:9px;min-width:0;">
                <span style="width:28px;height:28px;border-radius:50%;background:{{ $s['color'] ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $s['initials'] }}</span>
                <span style="font-size:13px;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $s['name'] }}</span>
            </span>
            <span style="font-size:12px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $s['dept'] ?? '—' }}</span>
            <span class="lr-num">{{ rtrim(rtrim(number_format($s['totalDays'], 1), '0'), '.') }}</span>
            <span class="lr-num" style="color:var(--muted);">{{ rtrim(rtrim(number_format($s['plannedDays'], 1), '0'), '.') }}</span>
            <span class="lr-num" style="color:{{ $s['unplannedDays'] > 0 ? 'var(--error)' : 'var(--muted-soft)' }};">{{ $s['unplannedDays'] > 0 ? rtrim(rtrim(number_format($s['unplannedDays'], 1), '0'), '.').' ('.$s['unplannedCount'].')' : '—' }}</span>
            <span class="lr-num" style="color:{{ $s['annualRemaining'] !== null && $s['annualRemaining'] <= 3 ? 'var(--amber)' : 'var(--ink)' }};">{{ $s['annualRemaining'] !== null ? rtrim(rtrim(number_format($s['annualRemaining'], 1), '0'), '.') : '—' }}</span>
        </a>
    @empty
        <div style="padding:16px;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No approved leave in this period.' : 'Tiada cuti diluluskan dalam tempoh ini.'">No approved leave in this period.</span></div>
    @endforelse
</div>
@endsection
