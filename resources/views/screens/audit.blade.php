@extends('layouts.app')

@php
    $icon = [
        'Approved' => ['var(--success)', 'M20 6L9 17l-5-5'],
        'Rejected' => ['var(--error)', 'M18 6L6 18M6 6l12 12'],
        'Changed' => ['var(--info)', 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z'],
        'Updated' => ['var(--info)', 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z'],
        'Acknowledged' => ['var(--muted)', 'M20 6L9 17l-5-5'],
    ];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'audit',
    'en'  => [
        'title' => 'Activity log',
        'body'  => 'A read-only record of recent admin and approval actions in the workspace — approvals, rejections, edits and policy acknowledgements, with who did it and when. Use it to check what happened; you can\'t change entries here.',
    ],
    'ms'  => [
        'title' => 'Log aktiviti',
        'body'  => 'Rekod baca-sahaja bagi tindakan admin dan kelulusan terkini dalam workspace — kelulusan, penolakan, suntingan dan acknowledgement polisi, dengan siapa buat dan bila. Guna untuk semak apa yang berlaku; anda tidak boleh ubah entri di sini.',
    ],
])
<div class="uj-card">
    <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Activity log' : 'Log aktiviti'">Activity log</h3><span style="font-size:12.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Last' : 'Terkini'">Last</span> {{ $logs->count() }} <span x-text="$store.ui.lang==='en' ? 'events' : 'acara'">events</span></span></div>
    @forelse ($logs as $log)
        @php $verb = explode(' ', $log->action)[0]; [$col, $path] = $icon[$verb] ?? ['var(--muted)', 'M12 8v4l3 2']; @endphp
        <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
            <div style="width:32px;height:32px;border-radius:8px;background:var(--canvas);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="{{ $col }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"></path></svg></div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:13.5px;color:var(--ink);"><span style="font-weight:600;">{{ $log->action }}</span>@if ($log->target) — {{ $log->target }}@endif</div>
                <div style="font-size:11.5px;color:var(--muted);">{{ $log->actor_name }}</div>
            </div>
            <span style="font-size:12px;color:var(--muted-soft);font-family:var(--font-mono);white-space:nowrap;">{{ $log->created_at->format('j M, H:i') }}</span>
        </div>
    @empty
        <div style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No activity recorded yet' : 'Belum ada aktiviti direkodkan'"></span></div>
            <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'As people approve requests, edit records or acknowledge policies, those actions will be listed here automatically.' : 'Apabila orang meluluskan permohonan, menyunting rekod atau acknowledge polisi, tindakan tersebut akan disenaraikan di sini secara automatik.'"></span></div>
        </div>
    @endforelse
</div>
@endsection
