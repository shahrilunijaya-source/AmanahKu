@extends('layouts.app')

@section('screen')

<div class="uj-lv">
    <h1 style="font-size:19px;font-weight:600;color:var(--ink);margin:0 0 14px;"
        x-text="$store.ui.lang==='en' ? 'Office Requests — Insights' : 'Permintaan Pejabat — Wawasan'">Office Requests — Insights</h1>

    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px;">
        <div class="uj-card" style="padding:18px 22px;min-width:150px;">
            <p style="font-size:11.5px;color:var(--muted);margin:0 0 4px;" x-text="$store.ui.lang==='en' ? 'Requests' : 'Permintaan'">Requests</p>
            <p style="font-size:26px;font-weight:700;color:var(--ink);margin:0;">{{ $requests }}</p>
        </div>
        <div class="uj-card" style="padding:18px 22px;min-width:150px;">
            <p style="font-size:11.5px;color:var(--muted);margin:0 0 4px;" x-text="$store.ui.lang==='en' ? 'Avg days to close' : 'Purata hari selesai'">Avg days to close</p>
            <p style="font-size:26px;font-weight:700;color:var(--ink);margin:0;">{{ $avg_days_to_close }}</p>
        </div>
    </div>

    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'By category' : 'Mengikut kategori'">By category</h3>
        </div>
        @foreach ($by_category as $category => $count)
            <div style="display:flex;justify-content:space-between;padding:8px 20px;border-top:1px solid var(--hairline-soft);font-size:13px;">
                <span>{{ ucfirst($category) }}</span>
                <span style="color:var(--muted);">{{ $count }}</span>
            </div>
        @endforeach
    </div>

    <div class="uj-card">
        <div class="uj-card-head">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Top voted' : 'Paling banyak undian'">Top voted</h3>
        </div>
        @forelse ($top_voted as $t)
            <div style="display:flex;justify-content:space-between;padding:8px 20px;border-top:1px solid var(--hairline-soft);font-size:13px;">
                <span>{{ $t['title'] }}</span>
                <span style="color:var(--muted);">{{ $t['votes'] }}</span>
            </div>
        @empty
            <div class="uj-lv-empty"><b x-text="$store.ui.lang==='en' ? 'Nothing this month' : 'Tiada apa-apa bulan ini'"></b></div>
        @endforelse
    </div>
</div>

@endsection
