@extends('layouts.app')

@php use App\Support\Amanahku; @endphp

@section('screen')
@include('partials.guide', [
    'key' => 'reports',
    'en'  => [
        'title' => 'Workforce reports',
        'body'  => 'A read-only summary of your people: headcount, department capacity, employment status and workload spread. Use it to spot which departments are stretched and to export the employee list for payroll or audits.',
    ],
    'ms'  => [
        'title' => 'Laporan tenaga kerja',
        'body'  => 'Ringkasan baca-sahaja tentang pekerja anda: headcount, kapasiti jabatan, status pekerjaan dan taburan beban kerja. Guna untuk kesan jabatan mana yang tertekan dan untuk eksport senarai pekerja bagi tujuan payroll atau audit.',
    ],
])
@if (in_array($role, ['manager', 'management', 'hr'], true))
    <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
        <a href="{{ route('reports.export.employees') }}" class="uj-btn-ghost" style="display:inline-flex;align-items:center;gap:8px;height:38px;padding:0 15px;font-size:13px;text-decoration:none;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"></path></svg>
            <span x-text="$store.ui.lang==='en' ? 'Export employees (CSV)' : 'Eksport pekerja (CSV)'">Export employees (CSV)</span>
        </a>
    </div>
@endif
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Headcount' : 'Bilangan staf'">Headcount</div><div class="uj-stat-value">{{ $headcount }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Departments' : 'Jabatan'">Departments</div><div class="uj-stat-value">{{ $byDept->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Leave approved' : 'Cuti diluluskan'">Leave approved</div><div class="uj-stat-value" style="color:var(--success);">{{ $leaveApproved }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Leave pending' : 'Cuti menunggu'">Leave pending</div><div class="uj-stat-value" style="color:var(--amber);">{{ $leavePending }}</div></div>
</div>

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1.6;min-width:380px;padding:20px;">
        <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Department capacity' : 'Kapasiti jabatan'">Department capacity</h3>
        <p style="font-size:12.5px;color:var(--muted);margin:0 0 18px;" x-text="$store.ui.lang==='en' ? 'Estimated load vs. capacity by department.' : 'Anggaran beban berbanding kapasiti mengikut jabatan.'">Estimated load vs. capacity by department.</p>
        @foreach ($byDept as $d)
            <div style="margin-bottom:14px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="font-size:13px;color:var(--ink);font-weight:500;">{{ $d['name'] }} <span style="color:var(--muted-soft);font-weight:400;">· {{ $d['head'] }} <span x-text="$store.ui.lang==='en' ? 'staff' : 'staf'">staff</span></span></span><span style="font-size:12.5px;font-weight:600;color:{{ Amanahku::SWATCH[$d['color']] }};font-family:var(--font-mono);">{{ $d['cap'] }}%</span></div>
                <div style="height:8px;background:var(--hairline);border-radius:9999px;overflow:hidden;"><div style="height:100%;width:{{ $d['cap'] }}%;background:{{ Amanahku::SWATCH[$d['color']] }};"></div></div>
            </div>
        @endforeach
    </div>

    <div style="flex:1;min-width:300px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Employment status' : 'Status pekerjaan'">Employment status</h3>
            @foreach ($byStatus as $s)
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="font-size:13px;color:var(--ink);">{{ $s['k'] }}</span><span style="font-size:12.5px;color:var(--muted);font-family:var(--font-mono);">{{ $s['v'] }} · {{ $pct($s['v']) }}%</span></div>
                    <div style="height:7px;background:var(--hairline);border-radius:9999px;overflow:hidden;"><div style="height:100%;width:{{ $pct($s['v']) }}%;background:{{ Amanahku::SWATCH[$s['c']] }};"></div></div>
                </div>
            @endforeach
        </div>
        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Workload distribution' : 'Taburan beban kerja'">Workload distribution</h3>
            @foreach ($workload as $w)
                <div style="margin-bottom:12px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="font-size:13px;color:var(--ink);">{{ $w['k'] }}</span><span style="font-size:12.5px;color:var(--muted);font-family:var(--font-mono);">{{ $w['v'] }} · {{ $pct($w['v']) }}%</span></div>
                    <div style="height:7px;background:var(--hairline);border-radius:9999px;overflow:hidden;"><div style="height:100%;width:{{ $pct($w['v']) }}%;background:{{ Amanahku::SWATCH[$w['c']] }};"></div></div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
