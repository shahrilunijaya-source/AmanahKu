@extends('layouts.app')

@php
    $sc = ['submitted' => 'var(--amber)', 'approved' => 'var(--success)', 'rejected' => 'var(--error)'];
    $statusMs = ['submitted' => 'Dihantar', 'approved' => 'Diluluskan', 'rejected' => 'Ditolak'];
    $typeMs = ['loan' => 'Pinjaman', 'advance' => 'Pendahuluan'];
    $pendingAmount = $myLoans->where('status', 'submitted')->sum('amount');
    $approvedAmount = $myLoans->where('status', 'approved')->sum('amount');
    $privileged = in_array($role, ['manager', 'management', 'hr'], true);
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'loans',
    'en'  => [
        'title' => 'Staff loans & salary advances',
        'body'  => 'Staff request a company loan or a salary advance to be repaid over time. Each request goes to a manager or HR for approval. Once approved, repayments are deducted from the employee\'s salary over the number of instalments chosen.',
        'who'   => 'Staff request · Managers & HR approve',
        'steps' => [
            'Choose the type: a Loan is repaid over several months; a Salary advance is usually repaid in one.',
            'Set how many monthly instalments to repay over, then enter the total amount in Ringgit (RM).',
            'Give a clear reason and submit — it shows as "Submitted" until a manager approves or rejects it.',
            'Approved amounts are recovered through payroll deductions over the instalments you chose.',
        ],
    ],
    'ms'  => [
        'title' => 'Pinjaman staf & pendahuluan gaji',
        'body'  => 'Staf mohon pinjaman syarikat atau pendahuluan gaji untuk dibayar balik secara berperingkat. Setiap permohonan dihantar kepada pengurus atau HR untuk kelulusan. Setelah diluluskan, bayaran balik ditolak daripada gaji pekerja mengikut bilangan ansuran yang dipilih.',
        'who'   => 'Staf mohon · Pengurus & HR luluskan',
        'steps' => [
            'Pilih jenis: Loan dibayar balik selama beberapa bulan; Salary advance biasanya dibayar balik sekali gus.',
            'Tetapkan berapa ansuran bulanan untuk bayar balik, kemudian masukkan jumlah penuh dalam Ringgit (RM).',
            'Beri sebab yang jelas dan hantar — ia akan kekal "Submitted" sehingga pengurus lulus atau tolak.',
            'Jumlah yang diluluskan dipotong melalui potongan payroll mengikut bilangan ansuran yang anda pilih.',
        ],
    ],
])
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'My requests' : 'Permohonan saya'">My requests</div><div class="uj-stat-value">{{ $myLoans->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Pending amount' : 'Jumlah belum selesai'">Pending amount</div><div class="uj-stat-value" style="color:var(--amber);">RM {{ number_format($pendingAmount, 2) }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Approved (outstanding)' : 'Diluluskan (tertunggak)'">Approved (outstanding)</div><div class="uj-stat-value" style="color:var(--success);">RM {{ number_format($approvedAmount, 2) }}</div></div>
</div>

@if ($privileged && $pendingLoans->isNotEmpty())
    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Requests to approve' : 'Permohonan untuk diluluskan'">Requests to approve</h3><span class="uj-pill" style="background:var(--red-tint);color:var(--red);">{{ $pendingLoans->count() }}</span></div>
        @foreach ($pendingLoans as $l)
            <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="width:32px;height:32px;border-radius:50%;background:{{ $l->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $l->employee?->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;"><span x-text="$store.ui.lang==='en' ? @js(ucfirst($l->type)) : @js($typeMs[$l->type] ?? ucfirst($l->type))">{{ ucfirst($l->type) }}</span> · {{ $l->installments }} <span x-text="$store.ui.lang==='en' ? @js($l->installments === 1 ? 'instalment' : 'instalments') : 'ansuran'">{{ $l->installments === 1 ? 'instalment' : 'instalments' }}</span></div>
                    <div style="font-size:12px;color:var(--muted);">{{ $l->employee?->name }} · {{ $l->reason }}</div>
                </div>
                <div style="font-size:14px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">RM {{ number_format($l->amount, 2) }}</div>
                <form method="post" action="{{ route('loans.approve', $l) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</button></form>
                <form method="post" action="{{ route('loans.reject', $l) }}">@csrf<button class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</button></form>
            </div>
        @endforeach
    </div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1.3;min-width:340px;padding:24px;">
        <h3 class="uj-card-title" style="margin-bottom:16px;" x-text="$store.ui.lang==='en' ? 'New request' : 'Permohonan baharu'">New request</h3>
        <form method="post" action="{{ route('loans.store') }}">
            @csrf
            <div style="display:flex;gap:16px;margin-bottom:16px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</label>
                    <select name="type" required style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;background:#fff;color:var(--ink);">
                        @php $typeOpts = ['loan' => 'Loan', 'advance' => 'Salary advance']; $typeOptsMs = ['loan' => 'Pinjaman', 'advance' => 'Pendahuluan gaji']; @endphp
                        @foreach ($typeOpts as $v => $l)
                            <option value="{{ $v }}" @selected(old('type') === $v) x-text="$store.ui.lang==='en' ? @js($l) : @js($typeOptsMs[$v])">{{ $l }}</option>
                        @endforeach
                    </select>
                    @include('partials.hint', ['en' => 'Loan = larger sum repaid over many months. Salary advance = part of your own pay early, repaid quickly.', 'ms' => 'Loan = jumlah besar dibayar balik selama beberapa bulan. Salary advance = sebahagian gaji anda lebih awal, dibayar balik cepat.'])
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Instalments' : 'Ansuran'">Instalments</label>
                    <input name="installments" type="number" min="1" max="120" value="{{ old('installments', 1) }}" required style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;font-family:var(--font-mono);outline:none;" />
                    @include('partials.hint', ['tone' => 'warn', 'en' => 'Number of months to repay over. Amount ÷ instalments is deducted from each month\'s pay — more instalments means a smaller monthly cut.', 'ms' => 'Bilangan bulan untuk bayar balik. Jumlah ÷ ansuran ditolak daripada gaji setiap bulan — lebih banyak ansuran bermaksud potongan bulanan lebih kecil.'])
                </div>
            </div>
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Amount (RM)' : 'Jumlah (RM)'">Amount (RM)</label>
            <input name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" required placeholder="0.00" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;font-family:var(--font-mono);margin-bottom:16px;outline:none;" />
            @include('partials.hint', ['tone' => 'warn', 'en' => 'Total amount being borrowed. This full sum is recovered from future salary — only request what can be comfortably repaid.', 'ms' => 'Jumlah penuh yang dipinjam. Keseluruhan jumlah ini dipotong daripada gaji akan datang — mohon hanya jumlah yang mampu dibayar balik dengan selesa.'])
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Reason' : 'Sebab'">Reason</label>
            <textarea name="reason" rows="2" required placeholder="e.g. Medical bill — repay over 6 months" :placeholder="$store.ui.lang==='en' ? 'e.g. Medical bill — repay over 6 months' : 'cth. Bil perubatan — bayar balik dalam 6 bulan'" style="width:100%;padding:12px 14px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:18px;outline:none;resize:vertical;">{{ old('reason') }}</textarea>
            @error('reason')<div style="font-size:12px;color:var(--error);margin-top:-10px;margin-bottom:14px;">{{ $message }}</div>@enderror
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Submit request' : 'Hantar permohonan'">Submit request</button>
        </form>
    </div>

    <div class="uj-card" style="flex:1;min-width:300px;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'My requests' : 'Permohonan saya'">My requests</h3></div>
        @forelse ($myLoans as $l)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;"><span x-text="$store.ui.lang==='en' ? @js(ucfirst($l->type)) : @js($typeMs[$l->type] ?? ucfirst($l->type))">{{ ucfirst($l->type) }}</span> · {{ $l->installments }}x</div>
                    <div style="font-size:11.5px;color:var(--muted);">{{ $l->reason }}</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">RM {{ number_format($l->amount, 2) }}</div>
                    <div style="font-size:11px;font-weight:600;color:{{ $sc[$l->status] }};" x-text="$store.ui.lang==='en' ? @js(ucfirst($l->status)) : @js($statusMs[$l->status] ?? ucfirst($l->status))">{{ ucfirst($l->status) }}</div>
                </div>
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No requests yet' : 'Belum ada permohonan'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Use the form on the left to request a loan or salary advance. Your requests and their approval status will appear here.' : 'Guna borang di sebelah kiri untuk mohon pinjaman atau pendahuluan gaji. Permohonan anda dan status kelulusannya akan muncul di sini.'"></span></div>
            </div>
        @endforelse
    </div>
</div>
@endsection
