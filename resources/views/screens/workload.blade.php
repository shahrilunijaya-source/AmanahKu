@extends('layouts.app')

@php use App\Support\Amanahku; @endphp

@section('screen')
@include('partials.guide', [
    'key' => 'workload',
    'en'  => [
        'title' => 'Team workload & capacity',
        'body'  => 'An at-a-glance view of how loaded each person is against their weekly capacity. A red bar means someone is overloaded. Use the recommended actions on the right to rebalance work before people burn out.',
    ],
    'ms'  => [
        'title' => 'Beban kerja & kapasiti pasukan',
        'body'  => 'Pandangan sepintas lalu tentang sejauh mana setiap orang dibebani berbanding kapasiti mingguan mereka. Bar merah bermakna seseorang itu terlebih beban. Guna tindakan disyorkan di sebelah kanan untuk seimbangkan semula kerja sebelum staf keletihan.',
    ],
])
<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:2;min-width:380px;padding:20px;">
        <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Team capacity this week' : 'Kapasiti pasukan minggu ini'">Team capacity this week</h3>
        <p style="font-size:12.5px;color:var(--muted);margin:0 0 18px;" x-text="$store.ui.lang==='en' ? 'Assigned load against weekly capacity. Red = overloaded.' : 'Beban diberi berbanding kapasiti mingguan. Merah = terlebih beban.'">Assigned load against weekly capacity. Red = overloaded.</p>
        @foreach ($bars as $b)
            <div style="margin-bottom:14px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                    <div style="width:26px;height:26px;border-radius:50%;background:{{ $b['avatar'] }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;">{{ $b['initials'] }}</div>
                    <span style="flex:1;font-size:13px;color:var(--ink);font-weight:500;">{{ $b['name'] }}</span>
                    <span style="font-size:12.5px;font-weight:600;color:{{ Amanahku::SWATCH[$b['color']] }};font-family:var(--font-mono);">{{ $b['pct'] }}%</span>
                </div>
                <div style="height:8px;background:var(--hairline);border-radius:9999px;overflow:hidden;"><div style="height:100%;width:{{ $b['capped'] }}%;background:{{ Amanahku::SWATCH[$b['color']] }};"></div></div>
            </div>
        @endforeach
    </div>
    <div style="flex:1;min-width:280px;background:var(--sidebar);border-radius:12px;padding:20px;color:#fff;">
        <div style="display:flex;align-items:center;gap:9px;margin-bottom:14px;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 4.6L18.5 9.5 13.9 11.4 12 16l-1.9-4.6L5.5 9.5l4.6-1.9z"></path></svg><h3 style="font-size:15px;font-weight:600;color:#fff;margin:0;" x-text="$store.ui.lang==='en' ? 'Recommended actions' : 'Tindakan disyorkan'">Recommended actions</h3></div>
        @include('partials.recs')
    </div>
</div>
@endsection
