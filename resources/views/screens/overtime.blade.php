@extends('layouts.app')

@php
    $sc = ['submitted' => 'var(--amber)', 'verified' => 'var(--info)', 'approved' => 'var(--success)', 'rejected' => 'var(--error)'];
    $pendingHours = $myOvertime->where('status', 'submitted')->sum('hours');
    $approvedEquiv = $myOvertime->where('status', 'approved')->sum('equivalent_hours');
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'overtime',
    'en'  => [
        'title' => 'Overtime tracking',
        'body'  => 'Record extra hours staff work beyond their normal shift. Each request is checked against Malaysian Employment Act rates, then sent to a manager for approval. Once approved, the equivalent hours can be paid out or taken as time-off-in-lieu.',
        'who'   => 'Staff log their own · Managers & HR approve',
        'steps' => [
            'Staff fills the "Log overtime" form with the date, hours and reason.',
            'Pick the right rate: 1.5x for a normal working day, 2.0x for a rest day or public holiday.',
            'After submitting, the request shows as "Submitted" until a manager approves or rejects it.',
            'Approved overtime appears in the equivalent-hours total at the top.',
        ],
    ],
    'ms'  => [
        'title' => 'Rekod kerja lebih masa',
        'body'  => 'Rekod jam tambahan yang staf bekerja melebihi waktu biasa. Setiap permohonan disemak ikut kadar Akta Kerja Malaysia, kemudian dihantar kepada pengurus untuk kelulusan. Setelah diluluskan, jam setara boleh dibayar atau diambil sebagai cuti ganti.',
        'who'   => 'Staf rekod sendiri · Pengurus & HR luluskan',
        'steps' => [
            'Staf isi borang "Log overtime" dengan tarikh, jam dan sebab.',
            'Pilih kadar betul: 1.5x untuk hari kerja biasa, 2.0x untuk hari rehat atau cuti umum.',
            'Selepas hantar, permohonan akan kekal "Submitted" sehingga pengurus lulus atau tolak.',
            'Overtime yang diluluskan muncul dalam jumlah jam setara di bahagian atas.',
        ],
    ],
])
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'My requests' : 'Permohonan saya'">My requests</span></div><div class="uj-stat-value">{{ $myOvertime->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Pending hours' : 'Jam menunggu'">Pending hours</span></div><div class="uj-stat-value" style="color:var(--amber);">{{ number_format((float) $pendingHours, 2) }}h</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Approved (equivalent)' : 'Diluluskan (setara)'">Approved (equivalent)</span></div><div class="uj-stat-value" style="color:var(--success);">{{ number_format((float) $approvedEquiv, 2) }}h</div></div>
</div>

{{-- Two-step gate: superior verifies their team's overtime, then management approves. --}}
@if ($overtimeToVerify->isNotEmpty())
    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'To verify (your team)' : 'Untuk disahkan (pasukan anda)'">To verify (your team)</span></h3><span class="uj-pill" style="background:var(--amber-tint,#fbf3e6);color:#7a5418;">{{ $overtimeToVerify->count() }}</span></div>
        @foreach ($overtimeToVerify as $o)
            <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="width:32px;height:32px;border-radius:50%;background:{{ $o->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $o->employee?->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ number_format((float) $o->hours, 2) }}h @ {{ $o->rate_multiplier }}x · {{ $o->ot_date->format('d M Y') }}</div>
                    <div style="font-size:12px;color:var(--muted);">{{ $o->employee?->name }} · {{ $o->reason }}</div>
                </div>
                <div style="font-size:14px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ number_format((float) $o->equivalent_hours, 2) }}h</div>
                <form method="post" action="{{ route('overtime.verify', $o) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Verify' : 'Sahkan'">Verify</span></button></form>
                <form method="post" action="{{ route('overtime.reject', $o) }}">@csrf<button class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</span></button></form>
            </div>
        @endforeach
    </div>
@endif

@if ($overtimeToApprove->isNotEmpty())
    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'To approve (verified)' : 'Untuk diluluskan (disahkan)'">To approve (verified)</span></h3><span class="uj-pill" style="background:var(--red-tint);color:var(--red);">{{ $overtimeToApprove->count() }}</span></div>
        @foreach ($overtimeToApprove as $o)
            <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="width:32px;height:32px;border-radius:50%;background:{{ $o->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $o->employee?->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ number_format((float) $o->hours, 2) }}h @ {{ $o->rate_multiplier }}x · {{ $o->ot_date->format('d M Y') }}</div>
                    <div style="font-size:12px;color:var(--muted);">{{ $o->employee?->name }} · {{ $o->reason }}</div>
                    @if ($o->verifiedBy)<div style="font-size:11px;color:var(--success);">{{ $o->verifiedBy->name }} <span x-text="$store.ui.lang==='en' ? 'verified' : 'sahkan'">verified</span></div>@endif
                </div>
                <div style="font-size:14px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ number_format((float) $o->equivalent_hours, 2) }}h</div>
                <form method="post" action="{{ route('overtime.approve', $o) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</span></button></form>
                <form method="post" action="{{ route('overtime.reject', $o) }}">@csrf<button class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</span></button></form>
            </div>
        @endforeach
    </div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1.3;min-width:340px;padding:24px;">
        <h3 class="uj-card-title" style="margin-bottom:16px;"><span x-text="$store.ui.lang==='en' ? 'Log overtime' : 'Rekod kerja lebih masa'">Log overtime</span></h3>
        <form method="post" action="{{ route('overtime.store') }}">
            @csrf
            <div style="display:flex;gap:16px;margin-bottom:16px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span></label>
                    <input name="ot_date" type="date" value="{{ old('ot_date') }}" required style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;background:#fff;color:var(--ink);outline:none;margin-bottom:6px;" />
                    @include('partials.hint', ['en' => 'The day the overtime actually happened — not today.', 'ms' => 'Hari overtime sebenarnya berlaku — bukan hari ini.'])
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Hours' : 'Jam'">Hours</span></label>
                    <input name="hours" type="number" step="0.5" min="0.5" max="24" value="{{ old('hours') }}" required placeholder="0.00" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;font-family:var(--font-mono);outline:none;margin-bottom:6px;" />
                    @include('partials.hint', ['en' => 'In half-hour steps. Example: 2.5 = two and a half hours.', 'ms' => 'Dalam langkah setengah jam. Contoh: 2.5 = dua jam setengah.'])
                </div>
            </div>
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Rate multiplier' : 'Pendarab kadar'">Rate multiplier</span></label>
            <select name="rate_multiplier" required style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;background:#fff;color:var(--ink);margin-bottom:16px;">
                @php $rateLabelsMs = ['1.50' => '1.5x (kerja lebih masa biasa)', '2.00' => '2.0x (hari rehat / cuti umum)']; @endphp
                @foreach (['1.50' => '1.5x (normal overtime)', '2.00' => '2.0x (rest day / public holiday)'] as $v => $l)
                    <option value="{{ $v }}" @selected(old('rate_multiplier') === $v) x-text="$store.ui.lang==='en' ? '{{ $l }}' : '{{ $rateLabelsMs[$v] }}'">{{ $l }}</option>
                @endforeach
            </select>
            @include('partials.hint', ['en' => '1.5x is the normal weekday overtime rate. Use 2.0x only when the work fell on the employee\'s rest day or a gazetted public holiday.', 'ms' => '1.5x ialah kadar overtime hari kerja biasa. Guna 2.0x hanya jika kerja jatuh pada hari rehat pekerja atau cuti umum yang diwartakan.'])
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Reason' : 'Sebab'">Reason</span></label>
            <textarea name="reason" rows="2" required :placeholder="$store.ui.lang==='en' ? 'e.g. Month-end closing — stayed back to clear backlog' : 'cth. Tutup akaun hujung bulan — tinggal lewat untuk selesaikan kerja tertunggak'" style="width:100%;padding:12px 14px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:18px;outline:none;resize:vertical;">{{ old('reason') }}</textarea>
            @error('reason')<div style="font-size:12px;color:var(--error);margin-top:-10px;margin-bottom:14px;">{{ $message }}</div>@enderror
            @error('hours')<div style="font-size:12px;color:var(--error);margin-top:-10px;margin-bottom:14px;">{{ $message }}</div>@enderror
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Submit request' : 'Hantar permohonan'">Submit request</span></button>
        </form>
    </div>

    <div class="uj-card" style="flex:1;min-width:300px;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My overtime' : 'Kerja lebih masa saya'">My overtime</span></h3></div>
        @forelse ($myOvertime as $o)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ number_format((float) $o->hours, 2) }}h @ {{ $o->rate_multiplier }}x</div>
                    <div style="font-size:11.5px;color:var(--muted);">{{ $o->ot_date->format('d M Y') }} · {{ $o->reason }}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ number_format((float) $o->equivalent_hours, 2) }}h</div>
                    @php $otStatusMs = ['submitted' => 'Dihantar', 'verified' => 'Disahkan', 'approved' => 'Diluluskan', 'rejected' => 'Ditolak']; @endphp
                    <div style="font-size:11px;font-weight:600;color:{{ $sc[$o->status] }};" x-text="$store.ui.lang==='en' ? '{{ ucfirst($o->status) }}' : '{{ $otStatusMs[$o->status] ?? ucfirst($o->status) }}'">{{ ucfirst($o->status) }}</div>
                </div>
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No overtime logged yet' : 'Belum ada kerja lebih masa direkod'">No overtime logged yet</span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Fill the form on the left to submit your first request. It will appear here with its approval status.' : 'Isi borang di sebelah kiri untuk hantar permohonan pertama anda. Ia akan muncul di sini dengan status kelulusannya.'">Fill the form on the left to submit your first request. It will appear here with its approval status.</span></div>
            </div>
        @endforelse
    </div>
</div>
@endsection
