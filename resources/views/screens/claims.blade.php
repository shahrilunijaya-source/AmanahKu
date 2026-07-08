@extends('layouts.app')

@php
    $sc = ['submitted' => 'var(--amber)', 'verified' => 'var(--info)', 'approved' => 'var(--success)', 'rejected' => 'var(--error)', 'paid' => 'var(--info)'];
    $pendingTotal = $myClaims->where('status', 'submitted')->sum('amount');
    $approvedTotal = $myClaims->whereIn('status', ['approved', 'paid'])->sum('amount');
    $medicalCap = $medicalCap ?? 0;
    $medicalRemaining = max(0, $medicalCap - ($medicalUsedYtd ?? 0));
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'claims',
    'en'  => [
        'title' => 'Expense claims',
        'body'  => 'Staff claim back money they spent for work — mileage, medical, travel and other expenses. Each claim goes to a manager to approve or reject. Approved claims are paid back, usually through the next payroll run.',
        'who'   => 'Staff claim · Managers & HR approve',
        'steps' => [
            'Pick the claim type, then the date the expense actually happened.',
            'Describe what it was for and enter the exact amount in Ringgit (RM).',
            'Submit — the claim shows as "Submitted" until a manager approves or rejects it.',
            'Once approved, it counts toward your "Approved (YTD)" total and gets reimbursed in payroll.',
        ],
    ],
    'ms'  => [
        'title' => 'Tuntutan perbelanjaan',
        'body'  => 'Staf tuntut balik wang yang dibelanja untuk kerja — mileage, perubatan, perjalanan dan perbelanjaan lain. Setiap claim dihantar kepada pengurus untuk lulus atau tolak. Claim yang diluluskan dibayar balik, biasanya melalui payroll bulan berikutnya.',
        'who'   => 'Staf tuntut · Pengurus & HR luluskan',
        'steps' => [
            'Pilih jenis claim, kemudian tarikh perbelanjaan sebenarnya berlaku.',
            'Terangkan untuk apa dan masukkan jumlah tepat dalam Ringgit (RM).',
            'Hantar — claim akan kekal "Submitted" sehingga pengurus lulus atau tolak.',
            'Setelah diluluskan, ia dikira dalam jumlah "Approved (YTD)" anda dan dibayar balik melalui payroll.',
        ],
    ],
])
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'My claims' : 'Claim saya'">My claims</div><div class="uj-stat-value">{{ $myClaims->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Pending amount' : 'Jumlah menunggu'">Pending amount</div><div class="uj-stat-value" style="color:var(--amber);">RM {{ number_format($pendingTotal, 2) }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Approved (YTD)' : 'Diluluskan (YTD)'">Approved (YTD)</div><div class="uj-stat-value" style="color:var(--success);">RM {{ number_format($approvedTotal, 2) }}</div></div>
</div>

{{-- Two-step gate: superior verifies their team's claims, then management approves. --}}
@if ($claimsToVerify->isNotEmpty())
    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'To verify (your team)' : 'Untuk disahkan (pasukan anda)'">To verify (your team)</h3><span class="uj-pill" style="background:var(--amber-tint,#fbf3e6);color:#7a5418;">{{ $claimsToVerify->count() }}</span></div>
        @foreach ($claimsToVerify as $c)
            <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="width:32px;height:32px;border-radius:50%;background:{{ $c->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $c->employee?->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $c->title }}</div>
                    <div style="font-size:12px;color:var(--muted);">{{ $c->employee?->name }} · {{ ucfirst($c->type) }} · {{ $c->date->format('j M Y') }}</div>
                    @if ($c->receipt_path)<a href="{{ route('claims.receipt', $c) }}" style="font-size:11.5px;color:var(--info);text-decoration:none;">📎 <span x-text="$store.ui.lang==='en' ? 'View receipt' : 'Lihat resit'">View receipt</span></a>@endif
                </div>
                <div style="font-size:14px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $c->currency }} {{ number_format($c->amount, 2) }}</div>
                <form method="post" action="{{ route('claims.verify', $c) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Verify' : 'Sahkan'">Verify</button></form>
                <form method="post" action="{{ route('claims.reject', $c) }}">@csrf<button class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</button></form>
            </div>
        @endforeach
    </div>
@endif

@if ($claimsToApprove->isNotEmpty())
    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'To approve (verified)' : 'Untuk diluluskan (disahkan)'">To approve (verified)</h3><span class="uj-pill" style="background:var(--red-tint);color:var(--red);">{{ $claimsToApprove->count() }}</span></div>
        @foreach ($claimsToApprove as $c)
            <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="width:32px;height:32px;border-radius:50%;background:{{ $c->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $c->employee?->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $c->title }}</div>
                    <div style="font-size:12px;color:var(--muted);">{{ $c->employee?->name }} · {{ ucfirst($c->type) }} · {{ $c->date->format('j M Y') }}</div>
                    @if ($c->receipt_path)<a href="{{ route('claims.receipt', $c) }}" style="font-size:11.5px;color:var(--info);text-decoration:none;">📎 <span x-text="$store.ui.lang==='en' ? 'View receipt' : 'Lihat resit'">View receipt</span></a>@endif
                    @if ($c->verifiedBy)<div style="font-size:11px;color:var(--success);">{{ $c->verifiedBy->name }} <span x-text="$store.ui.lang==='en' ? 'verified' : 'sahkan'">verified</span></div>@endif
                </div>
                <div style="font-size:14px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $c->currency }} {{ number_format($c->amount, 2) }}</div>
                <form method="post" action="{{ route('claims.approve', $c) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</button></form>
                <form method="post" action="{{ route('claims.reject', $c) }}">@csrf<button class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</button></form>
            </div>
        @endforeach
    </div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1.3;min-width:340px;padding:24px;">
        <h3 class="uj-card-title" style="margin-bottom:16px;" x-text="$store.ui.lang==='en' ? 'New claim' : 'Claim baharu'">New claim</h3>
        @if ($errors->any())
            <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('claims.store') }}" enctype="multipart/form-data" x-data="{ busy: false, type: '{{ old('type', 'expense') }}', requiresReceipt: { expense: true, medical: true, travel: true, mileage: false, other: false } }" @submit="busy = true">
            @csrf
            <div style="display:flex;gap:16px;margin-bottom:16px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</label>
                    <select name="type" x-model="type" required style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;background:#fff;color:var(--ink);">
                        @foreach (['expense' => ['Expense', 'Perbelanjaan'], 'mileage' => ['Mileage', 'Mileage'], 'medical' => ['Medical', 'Perubatan'], 'travel' => ['Travel', 'Perjalanan'], 'other' => ['Other', 'Lain-lain']] as $v => $l)<option value="{{ $v }}" @selected(old('type') === $v) x-text="$store.ui.lang==='en' ? @js($l[0]) : @js($l[1])">{{ $l[0] }}</option>@endforeach
                    </select>
                    @include('partials.hint', ['en' => 'Pick the category the spend falls under — it helps finance code the reimbursement correctly.', 'ms' => 'Pilih kategori yang sesuai dengan perbelanjaan — ia bantu finance kod bayaran balik dengan betul.'])
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</label>
                    <input name="date" type="date" value="{{ old('date', now()->toDateString()) }}" required style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                    @include('partials.hint', ['en' => 'The day the expense was incurred — not today\'s date.', 'ms' => 'Hari perbelanjaan itu berlaku — bukan tarikh hari ini.'])
                </div>
            </div>
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label>
            <input name="title" value="{{ old('title') }}" required placeholder="e.g. Client visit — mileage" :placeholder="$store.ui.lang==='en' ? 'e.g. Client visit — mileage' : 'cth. Lawatan client — mileage'" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;margin-bottom:16px;outline:none;" />
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Amount (RM)' : 'Jumlah (RM)'">Amount (RM)</label>
            <input name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" required placeholder="0.00" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;font-family:var(--font-mono);margin-bottom:16px;outline:none;" />
            @include('partials.hint', ['tone' => 'warn', 'en' => 'Enter the exact amount on the receipt — this is the figure that gets reimbursed. Keep the receipt; finance may ask for it.', 'ms' => 'Masukkan jumlah tepat pada resit — inilah angka yang akan dibayar balik. Simpan resit; finance mungkin minta nanti.'])
            <div x-show="type === 'medical'" x-cloak style="background:var(--info-tint,#eef4fb);border:1px solid var(--info);color:#1f4e79;font-size:12px;line-height:1.5;border-radius:8px;padding:9px 12px;margin-bottom:16px;">
                <span x-text="$store.ui.lang==='en'
                    ? 'Medical claims are capped at RM {{ number_format($medicalCap, 2) }} per year. You have RM {{ number_format($medicalRemaining, 2) }} left for {{ now()->year }}.'
                    : 'Claim perubatan dihadkan RM {{ number_format($medicalCap, 2) }} setahun. Baki anda RM {{ number_format($medicalRemaining, 2) }} untuk {{ now()->year }}.'"></span>
            </div>
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;">
                <span x-text="$store.ui.lang==='en' ? 'Receipt / supporting document' : 'Resit / dokumen sokongan'">Receipt / supporting document</span>
                <span x-show="requiresReceipt[type]" style="color:var(--red);">*</span>
                <span x-show="!requiresReceipt[type]" style="color:var(--muted-soft);font-weight:400;" x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'"></span>
            </label>
            <input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png" :required="requiresReceipt[type]" style="width:100%;font-size:13px;color:var(--ink);margin-bottom:8px;" />
            @include('partials.hint', ['en' => 'Attach a photo or PDF of the receipt. Expense, medical and travel claims require it; mileage and other are optional. Up to 8 MB.', 'ms' => 'Lampirkan foto atau PDF resit. Claim perbelanjaan, perubatan dan perjalanan wajib; mileage dan lain-lain pilihan. Sehingga 8 MB.'])
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Notes (optional)' : 'Nota (pilihan)'">Notes (optional)</label>
            <textarea name="reason" rows="2" style="width:100%;padding:12px 14px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:18px;outline:none;resize:vertical;">{{ old('reason') }}</textarea>
            <button type="submit" class="uj-btn-primary" :disabled="busy" style="height:42px;padding:0 20px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Submit claim' : 'Hantar claim'">Submit claim</button>
        </form>
    </div>

    <div class="uj-card" style="flex:1;min-width:300px;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'My claims' : 'Claim saya'">My claims</h3></div>
        @forelse ($myClaims as $c)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="min-width:0;"><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $c->title }}@if ($c->receipt_path) <a href="{{ route('claims.receipt', $c) }}" style="text-decoration:none;" title="{{ $c->receipt_name }}">📎</a>@endif</div><div style="font-size:11.5px;color:var(--muted);">{{ ucfirst($c->type) }} · {{ $c->date->format('j M') }}</div></div>
                <div style="text-align:right;"><div style="font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $c->currency }} {{ number_format($c->amount, 2) }}</div><div style="font-size:11px;font-weight:600;color:{{ $sc[$c->status] }};">{{ ucfirst($c->status) }}</div></div>
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No claims yet' : 'Belum ada claim'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Use the form on the left to submit your first claim. It will appear here with its approval status and amount.' : 'Guna borang di sebelah kiri untuk hantar claim pertama anda. Ia akan muncul di sini dengan status kelulusan dan jumlahnya.'"></span></div>
            </div>
        @endforelse
    </div>
</div>
@endsection
