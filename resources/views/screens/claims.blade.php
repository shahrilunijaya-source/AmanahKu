@extends('layouts.app')

@php
    $sc = ['submitted' => 'var(--amber)', 'verified' => 'var(--info)', 'approved' => 'var(--success)', 'rejected' => 'var(--error)', 'paid' => 'var(--info)'];
    // Waiting on approval: still moving through the two-step gate (submitted or verified).
    $waitingClaims = $myClaims->whereIn('status', ['submitted', 'verified']);
    $waitingTotal = $waitingClaims->sum('amount');
    $waitingCount = $waitingClaims->count();
    // Approved but not yet paid: approved money hasn't landed yet, so it's kept separate
    // from `paid` — the thing the employee actually wants to know is when it arrives.
    $approvedNotPaidTotal = $myClaims->where('status', 'approved')->sum('amount');
    $medicalCap = $medicalCap ?? 0;
    $medicalRemaining = max(0, $medicalCap - ($medicalUsedYtd ?? 0));
@endphp

@section('screen')
<div x-data="{ newClaimOpen: {{ $errors->any() ? 'true' : 'false' }} }">
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
        <div class="uj-card uj-stat" style="flex:1;min-width:200px;">
            <div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Waiting on approval' : 'Menunggu kelulusan'">Waiting on approval</div>
            <div class="uj-stat-value" style="color:var(--amber);">RM {{ number_format($waitingTotal, 2) }}</div>
            <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">
                {{ $waitingCount }} <span x-text="$store.ui.lang==='en' ? @js(\Illuminate\Support\Str::plural('claim', $waitingCount)) : 'claim'">{{ \Illuminate\Support\Str::plural('claim', $waitingCount) }}</span>
            </div>
        </div>
        <div class="uj-card uj-stat" style="flex:1;min-width:200px;">
            <div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Approved, not yet paid' : 'Diluluskan, belum dibayar'">Approved, not yet paid</div>
            <div class="uj-stat-value" style="color:var(--success);">RM {{ number_format($approvedNotPaidTotal, 2) }}</div>
            <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">
                <span x-text="$store.ui.lang==='en' ? 'pays out next payroll run' : 'dibayar dalam gaji berikutnya'">pays out next payroll run</span>
            </div>
        </div>
</div>

<div class="uj-card">
    @forelse ($myClaims as $c)
        <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
            <div style="min-width:0;"><div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $c->title }}@if ($c->receipt_path) <a href="{{ route('claims.receipt', $c) }}" style="text-decoration:none;" title="{{ $c->receipt_name }}">📎</a>@endif</div><div style="font-size:11.5px;color:var(--muted);">{{ ucfirst($c->type) }} · {{ $c->date->format('j M') }}</div></div>
            <div style="text-align:right;"><div style="font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $c->currency }} {{ number_format($c->amount, 2) }}</div><div style="font-size:11px;font-weight:600;color:{{ $sc[$c->status] }};">{{ ucfirst($c->status) }}</div></div>
        </div>
    @empty
        <div style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No claims yet' : 'Belum ada claim'"></span></div>
            <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Use the New claim button below to submit your first claim. It will appear here with its approval status and amount.' : 'Guna butang Claim baharu di bawah untuk hantar claim pertama anda. Ia akan muncul di sini dengan status kelulusan dan jumlahnya.'"></span></div>
        </div>
    @endforelse
    <div style="background:var(--canvas);border-top:1px solid var(--hairline);padding:14px 20px;">
        @include('partials.approval-chain', ['compact' => true])
    </div>
    <div style="padding:14px 20px;">
        <button type="button" @click="newClaimOpen = true" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;display:inline-flex;align-items:center;gap:7px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
            <span x-text="$store.ui.lang==='en' ? 'New claim' : 'Claim baharu'">New claim</span>
        </button>
    </div>
</div>

{{-- ───────── New claim modal ───────── --}}
<template x-teleport="body">
<div x-show="newClaimOpen" x-cloak @click.self="newClaimOpen = false" @keydown.escape.window="newClaimOpen = false"
     x-transition:enter="uj-overlay-enter" x-transition:enter-start="uj-overlay-from" x-transition:enter-end="uj-overlay-to"
     x-transition:leave="uj-overlay-leave" x-transition:leave-start="uj-overlay-to" x-transition:leave-end="uj-overlay-from"
     style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
    <div class="uj-card" style="width:100%;max-width:560px;margin:auto;padding:0;overflow:hidden;">

        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--hairline);">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'New claim' : 'Claim baharu'">New claim</h3>
            <button type="button" @click="newClaimOpen = false" style="font-size:20px;line-height:1;color:var(--muted);background:transparent;cursor:pointer;">&times;</button>
        </div>

        <div style="padding:20px;max-height:75vh;overflow-y:auto;">
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
    </div>
</div>
</template>
</div>
@endsection
