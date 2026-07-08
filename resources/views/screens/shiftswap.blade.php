@extends('layouts.app')

@php
    $sc = [
        'requested' => 'var(--amber)',
        'accepted'  => 'var(--info)',
        'approved'  => 'var(--success)',
        'rejected'  => 'var(--error)',
        'cancelled' => 'var(--muted-soft)',
    ];
    $fmtTime = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('g:ia') : '—';
    $fs = 'height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;background:#fff;color:var(--ink);outline:none;';
    $swStatusMs = ['requested' => 'Dipohon', 'accepted' => 'Diterima', 'approved' => 'Diluluskan', 'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan'];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'shiftswap',
    'en'  => [
        'title' => 'Shift swap & cover',
        'body'  => 'Ask a colleague to cover one of your rostered shifts, or give it away for anyone to pick up. If you name a colleague they accept first, then a manager or HR approves — the shift then moves to the new person.',
        'who'   => 'Staff request & accept · Managers & HR approve',
        'steps' => [
            'Pick one of your own upcoming shifts from the dropdown.',
            'Optionally name the colleague you want to take it — leave blank to offer it to anyone.',
            'Give a short reason and submit. A named colleague must accept before approval.',
            'A manager or HR approves; the shift is then reassigned to the colleague.',
        ],
    ],
    'ms'  => [
        'title' => 'Tukar & ganti shift',
        'body'  => 'Minta rakan sekerja ganti salah satu shift anda, atau serahkan untuk diambil sesiapa sahaja. Jika anda namakan rakan, mereka terima dahulu, kemudian pengurus atau HR luluskan — shift itu kemudian berpindah kepada orang baharu.',
        'who'   => 'Staf mohon & terima · Pengurus & HR luluskan',
        'steps' => [
            'Pilih salah satu shift akan datang anda daripada senarai.',
            'Pilihan: namakan rakan yang anda mahu ambil shift itu — biar kosong untuk tawar kepada sesiapa.',
            'Beri sebab ringkas dan hantar. Rakan yang dinamakan mesti terima sebelum kelulusan.',
            'Pengurus atau HR luluskan; shift kemudian ditetapkan semula kepada rakan tersebut.',
        ],
    ],
])

<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'My swap requests' : 'Permohonan tukar saya'">My swap requests</span></div><div class="uj-stat-value">{{ $mySwaps->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Awaiting my accept' : 'Menunggu penerimaan saya'">Awaiting my accept</span></div><div class="uj-stat-value" style="color:var(--info);">{{ $awaitingMyAcceptance->count() }}</div></div>
    @if ($privileged)
        <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Pending approval' : 'Menunggu kelulusan'">Pending approval</span></div><div class="uj-stat-value" style="color:var(--amber);">{{ $pendingSwaps->count() }}</div></div>
    @endif
</div>

@if ($privileged && $pendingSwaps->isNotEmpty())
    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Swaps to approve' : 'Tukar untuk diluluskan'">Swaps to approve</span></h3><span class="uj-pill" style="background:var(--red-tint);color:var(--red);">{{ $pendingSwaps->count() }}</span></div>
        @foreach ($pendingSwaps as $sw)
            <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="width:32px;height:32px;border-radius:50%;background:{{ $sw->requester?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $sw->requester?->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">
                        {{ $sw->shift?->date?->format('D, j M') }} · {{ $fmtTime($sw->shift?->start_time) }}–{{ $fmtTime($sw->shift?->end_time) }} · {{ $sw->shift?->location }}
                    </div>
                    <div style="font-size:12px;color:var(--muted);">
                        {{ $sw->requester?->name }}
                        @if ($sw->counterpart)
                            → <strong style="color:var(--ink);">{{ $sw->counterpart?->name }}</strong>
                        @else
                            → <em><span x-text="$store.ui.lang==='en' ? 'open to anyone' : 'terbuka kepada sesiapa'">open to anyone</span></em>
                        @endif
                        @if ($sw->reason) · {{ $sw->reason }} @endif
                    </div>
                </div>
                <span style="font-size:11px;font-weight:600;color:{{ $sc[$sw->status] }};" x-text="$store.ui.lang==='en' ? '{{ ucfirst($sw->status) }}' : '{{ $swStatusMs[$sw->status] ?? ucfirst($sw->status) }}'">{{ ucfirst($sw->status) }}</span>
                @if ($sw->counterpart)
                    <form method="post" action="{{ route('shiftswap.approve', $sw) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</span></button></form>
                @else
                    <span style="font-size:11.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Needs a taker' : 'Perlukan pengganti'">Needs a taker</span></span>
                @endif
                <form method="post" action="{{ route('shiftswap.reject', $sw) }}">@csrf<button class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</span></button></form>
            </div>
        @endforeach
    </div>
@endif

@if ($awaitingMyAcceptance->isNotEmpty())
    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Awaiting my acceptance' : 'Menunggu penerimaan saya'">Awaiting my acceptance</span></h3><span class="uj-pill" style="background:var(--info-tint,#e6effa);color:var(--info);">{{ $awaitingMyAcceptance->count() }}</span></div>
        @foreach ($awaitingMyAcceptance as $sw)
            <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">
                        {{ $sw->shift?->date?->format('D, j M') }} · {{ $fmtTime($sw->shift?->start_time) }}–{{ $fmtTime($sw->shift?->end_time) }} · {{ $sw->shift?->location }}
                    </div>
                    <div style="font-size:12px;color:var(--muted);">{{ $sw->requester?->name }} <span x-text="$store.ui.lang==='en' ? 'asked you to cover' : 'minta anda ganti'">asked you to cover</span>@if ($sw->reason) · {{ $sw->reason }} @endif</div>
                </div>
                <form method="post" action="{{ route('shiftswap.accept', $sw) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Accept' : 'Terima'">Accept</span></button></form>
            </div>
        @endforeach
    </div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1.3;min-width:340px;padding:24px;">
        <h3 class="uj-card-title" style="margin-bottom:16px;"><span x-text="$store.ui.lang==='en' ? 'Request a swap' : 'Mohon tukar shift'">Request a swap</span></h3>
        @if ($mySwappableShifts->isEmpty())
            <div style="padding:18px 0;font-size:13px;color:var(--muted);line-height:1.5;">
                <span x-text="$store.ui.lang==='en' ? 'You have no upcoming shifts to swap. Once a manager rosters you, your shifts will appear here to offer up.' : 'Anda tiada shift akan datang untuk ditukar. Setelah pengurus tetapkan shift, ia akan muncul di sini untuk ditawarkan.'"></span>
            </div>
        @else
            <form method="post" action="{{ route('shiftswap.store') }}">
                @csrf
                <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'My shift to swap' : 'Shift saya untuk ditukar'">My shift to swap</span></label>
                <select name="shift_id" required style="{{ $fs }}width:100%;margin-bottom:6px;">
                    <option value="" x-text="$store.ui.lang==='en' ? 'Select one of your shifts…' : 'Pilih salah satu shift anda…'">Select one of your shifts…</option>
                    @foreach ($mySwappableShifts as $s)
                        <option value="{{ $s->id }}" @selected((string) old('shift_id') === (string) $s->id)>{{ $s->date->format('D, j M') }} · {{ $fmtTime($s->start_time) }}–{{ $fmtTime($s->end_time) }} · {{ $s->location }}</option>
                    @endforeach
                </select>
                @include('partials.hint', ['en' => 'Only your own upcoming shifts can be swapped — pick the one you need covered.', 'ms' => 'Hanya shift akan datang anda sendiri boleh ditukar — pilih yang anda perlu diganti.'])
                @error('shift_id')<div style="font-size:12px;color:var(--error);margin-top:6px;margin-bottom:6px;">{{ $message }}</div>@enderror

                <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;"><span x-text="$store.ui.lang==='en' ? 'Give to (optional)' : 'Beri kepada (pilihan)'">Give to (optional)</span></label>
                <select name="counterpart_employee_id" style="{{ $fs }}width:100%;margin-bottom:6px;">
                    <option value="" x-text="$store.ui.lang==='en' ? 'Open to anyone' : 'Terbuka kepada sesiapa'">Open to anyone</option>
                    @foreach ($employees ?? [] as $e)
                        <option value="{{ $e->id }}" @selected((string) old('counterpart_employee_id') === (string) $e->id)>{{ $e->name }}</option>
                    @endforeach
                </select>
                @include('partials.hint', ['en' => 'Name a colleague to ask them directly — they must accept before approval. Leave blank to let a manager assign anyone.', 'ms' => 'Namakan rakan untuk minta terus — mereka mesti terima sebelum kelulusan. Biar kosong untuk biar pengurus tetapkan sesiapa.'])

                <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;"><span x-text="$store.ui.lang==='en' ? 'Reason' : 'Sebab'">Reason</span></label>
                <textarea name="reason" rows="2" :placeholder="$store.ui.lang==='en' ? 'e.g. Medical appointment that morning' : 'cth. Temujanji perubatan pagi itu'" style="width:100%;padding:12px 14px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:18px;outline:none;resize:vertical;">{{ old('reason') }}</textarea>
                @error('reason')<div style="font-size:12px;color:var(--error);margin-top:-10px;margin-bottom:14px;">{{ $message }}</div>@enderror

                <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Submit swap request' : 'Hantar permohonan tukar'">Submit swap request</span></button>
            </form>
        @endif
    </div>

    <div class="uj-card" style="flex:1;min-width:300px;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My swap requests' : 'Permohonan tukar saya'">My swap requests</span></h3></div>
        @forelse ($mySwaps as $sw)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $sw->shift?->date?->format('D, j M') }} · {{ $sw->shift?->location }}</div>
                    <div style="font-size:11.5px;color:var(--muted);">
                        @if ($sw->counterpart)
                            <span x-text="$store.ui.lang==='en' ? 'To' : 'Kepada'">To</span> {{ $sw->counterpart?->name }}
                        @else
                            <span x-text="$store.ui.lang==='en' ? 'Open to anyone' : 'Terbuka kepada sesiapa'">Open to anyone</span>
                        @endif
                        @if ($sw->reason) · {{ $sw->reason }} @endif
                    </div>
                </div>
                <div style="font-size:11px;font-weight:600;color:{{ $sc[$sw->status] }};" x-text="$store.ui.lang==='en' ? '{{ ucfirst($sw->status) }}' : '{{ $swStatusMs[$sw->status] ?? ucfirst($sw->status) }}'">{{ ucfirst($sw->status) }}</div>
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No swap requests yet' : 'Belum ada permohonan tukar'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Use the form to offer up one of your shifts. Your requests and their status will appear here.' : 'Guna borang untuk menawarkan salah satu shift anda. Permohonan dan statusnya akan muncul di sini.'"></span></div>
            </div>
        @endforelse
    </div>
</div>
@endsection
