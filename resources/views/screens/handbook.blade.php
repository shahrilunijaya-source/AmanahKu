@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'handbook',
    'en'  => [
        'title' => 'Company handbook',
        'body'  => 'The company policies and SOPs everyone should follow. Some policies need a formal acknowledgement so there is a record that staff have read and accepted them.',
        'who'   => 'Everyone reads · HR tracks acknowledgements',
        'steps' => [
            'Open and read each policy. The version tag (v1, v2) shows the latest one.',
            'Policies marked "Acknowledgement required" are not optional — read them fully first.',
            'Click "Acknowledge" to confirm you have read and accepted that policy.',
            'A green tick means it is done. Managers and HR can see the company-wide acknowledgement rate at the top.',
        ],
    ],
    'ms'  => [
        'title' => 'Handbook syarikat',
        'body'  => 'Polisi dan SOP syarikat yang semua orang patut ikut. Sesetengah polisi perlu acknowledge secara rasmi supaya ada rekod staf sudah baca dan terima.',
        'who'   => 'Semua orang baca · HR pantau acknowledgement',
        'steps' => [
            'Buka dan baca setiap polisi. Tag versi (v1, v2) menunjukkan yang terkini.',
            'Polisi bertanda "Acknowledgement required" bukan pilihan — baca habis dahulu.',
            'Klik "Acknowledge" untuk sahkan anda sudah baca dan terima polisi itu.',
            'Tanda hijau bermakna selesai. Pengurus dan HR boleh lihat kadar acknowledgement seluruh syarikat di bahagian atas.',
        ],
    ],
])
@if (in_array($role, ['manager', 'management', 'hr'], true))
    <div class="uj-card uj-stat" style="margin-bottom:16px;max-width:280px;">
        <div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Policy acknowledgement rate' : 'Kadar pengesahan polisi'">Policy acknowledgement rate</span></div>
        <div class="uj-stat-value" style="color:{{ $ackRate >= 90 ? 'var(--success)' : 'var(--amber)' }};">{{ $ackRate }}%</div>
    </div>
@endif

@foreach ($sections as $category => $items)
    <div style="margin-bottom:8px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{{ $category }}</div>
    <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px;">
        @foreach ($items as $s)
            @php $acked = in_array($s->id, $ackedIds); @endphp
            <div class="uj-card" style="padding:18px 20px;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:9px;margin-bottom:6px;">
                            <h3 style="font-size:15px;font-weight:600;color:var(--ink);margin:0;">{{ $s->title }}</h3>
                            <span style="font-size:10.5px;font-weight:600;color:var(--muted);background:var(--hairline-soft);padding:2px 8px;border-radius:9999px;font-family:var(--font-mono);">v{{ $s->version }}</span>
                            @if ($s->requires_ack)<span style="font-size:10px;font-weight:600;color:var(--red);"><span x-text="$store.ui.lang==='en' ? 'Acknowledgement required' : 'Pengesahan diperlukan'">Acknowledgement required</span></span>@endif
                        </div>
                        <p style="font-size:13px;color:var(--body);line-height:1.55;margin:0;">{{ $s->body }}</p>
                    </div>
                    <div style="flex-shrink:0;text-align:right;">
                        @if (! $s->requires_ack)
                            <span style="font-size:12px;color:var(--muted-soft);"><span x-text="$store.ui.lang==='en' ? 'Reference' : 'Rujukan'">Reference</span></span>
                        @elseif ($acked)
                            <span style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;color:var(--success);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg><span x-text="$store.ui.lang==='en' ? 'Acknowledged' : 'Telah disahkan'">Acknowledged</span></span>
                        @else
                            <form method="post" action="{{ route('handbook.acknowledge', $s) }}">@csrf<button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Acknowledge' : 'Sahkan'">Acknowledge</span></button></form>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endforeach
@endsection
