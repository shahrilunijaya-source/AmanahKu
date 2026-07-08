@extends('layouts.app')

@php
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $totalOnHand = $floats->sum('balance');
    $activeCount = $floats->where('is_active', true)->count();
@endphp

@section('screen')
@include('partials.guide', [
    'key'   => 'pettycash',
    'en'  => [
        'title' => 'Petty cash floats',
        'body'  => 'Physical cash kept at a branch for small day-to-day spending — pantry items, postage, a Grab for documents. Each float starts with an opening amount; every cash payout (disbursement) lowers the balance, and a top-up (replenishment) raises it back. This is not staff reimbursement — it is the cash actually held at the branch.',
        'who'   => 'HR & management manage · Everyone can view',
        'steps' => [
            'Open a float for a branch with its starting cash (e.g. RM 2000) — that becomes the opening balance.',
            'Record a disbursement each time cash leaves the box: who was paid and what for. The balance drops.',
            'When the cash runs low, record a replenishment to top the float back up. The balance rises.',
            'The current balance on each card is always the opening amount plus top-ups minus payouts.',
        ],
    ],
    'ms'  => [
        'title' => 'Wang runcit (petty cash)',
        'body'  => 'Wang tunai fizikal disimpan di cawangan untuk perbelanjaan kecil harian — barang pantri, pos, Grab untuk dokumen. Setiap float bermula dengan jumlah pembukaan; setiap bayaran tunai (disbursement) menurunkan baki, dan tambah nilai (replenishment) menaikkannya semula. Ini bukan bayaran balik staf — ini wang tunai yang benar-benar dipegang di cawangan.',
        'who'   => 'HR & pengurusan urus · Semua orang boleh lihat',
        'steps' => [
            'Buka float untuk satu cawangan dengan wang permulaannya (cth. RM 2000) — itu menjadi baki pembukaan.',
            'Rekod disbursement setiap kali tunai keluar: siapa dibayar dan untuk apa. Baki turun.',
            'Bila tunai dah rendah, rekod replenishment untuk tambah semula float. Baki naik.',
            'Baki semasa pada setiap kad sentiasa jumlah pembukaan campur tambah nilai tolak bayaran.',
        ],
    ],
])
<div x-data="{ add: {{ $errors->any() && $errors->has('name') ? 'true' : 'false' }} }">
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Active floats' : 'Float aktif'">Active floats</div><div class="uj-stat-value">{{ $activeCount }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Cash on hand' : 'Tunai di tangan'">Cash on hand</div><div class="uj-stat-value" style="color:var(--success);">RM {{ number_format($totalOnHand, 2) }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Total floats' : 'Jumlah float'">Total floats</div><div class="uj-stat-value">{{ $floats->count() }}</div></div>
</div>

@if ($privileged)
    <div x-show="add" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
        <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Open a new float' : 'Buka float baharu'">Open a new float</h3>
        <form method="post" action="{{ route('pettycash.floats') }}">
            @csrf
            @if ($errors->has('name') || $errors->has('branch_id') || $errors->has('opening_balance'))
                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
            @endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start;">
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Float name' : 'Nama float'">Float name</span> *</label>
                    <input name="name" value="{{ old('name') }}" required maxlength="120" placeholder="e.g. PJ HQ Petty Cash" :placeholder="$store.ui.lang==='en' ? 'e.g. PJ HQ Petty Cash' : 'cth. Petty Cash PJ HQ'" style="{{ $fs }}width:100%;" />
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Branch' : 'Cawangan'">Branch</span> *</label>
                    <select name="branch_id" required style="{{ $fs }}width:100%;">
                        <option value="" x-text="$store.ui.lang==='en' ? 'Select…' : 'Pilih…'">Select…</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((string) old('branch_id') === (string) $b->id)>{{ $b->name }}{{ $b->state ? ' · '.$b->state : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Opening balance (RM)' : 'Baki pembukaan (RM)'">Opening balance (RM)</span> *</label>
                    <input type="number" step="0.01" min="0" name="opening_balance" value="{{ old('opening_balance', '2000.00') }}" required style="{{ $fs }}width:100%;font-family:var(--font-mono);margin-bottom:6px;" />
                    @include('partials.hint', ['en' => 'The starting cash placed in the box. The running balance begins here.', 'ms' => 'Wang permulaan dalam kotak. Baki semasa bermula di sini.'])
                </div>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;" x-text="$store.ui.lang==='en' ? 'Open float' : 'Buka float'">Open float</button>
        </form>
    </div>
@endif

<div class="uj-card-head" style="border:none;padding:0 2px 12px;">
    <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Branch floats' : 'Float cawangan'">Branch floats</h3>
    @if ($privileged)<button @click="add = ! add" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Open float' : '+ Buka float')"></span></button>@endif
</div>

@forelse ($floats as $f)
    <div x-data="{ tab: 'log' }" class="uj-card" style="margin-bottom:16px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px 20px;border-bottom:1px solid var(--hairline-soft);flex-wrap:wrap;">
            <div style="min-width:0;">
                <div style="font-size:15px;color:var(--ink);font-weight:600;">{{ $f->name }}</div>
                <div style="font-size:12px;color:var(--muted);margin-top:3px;">
                    {{ $f->branch?->name ?? '—' }}
                    · <span x-text="$store.ui.lang==='en' ? 'Custodian:' : 'Penjaga:'">Custodian:</span> {{ $f->custodian?->name ?? '—' }}
                    · <span style="font-weight:600;color:{{ $f->is_active ? 'var(--success)' : 'var(--muted-soft)' }};" x-text="$store.ui.lang==='en' ? @js($f->is_active ? 'Active' : 'Closed') : @js($f->is_active ? 'Aktif' : 'Ditutup')">{{ $f->is_active ? 'Active' : 'Closed' }}</span>
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Current balance' : 'Baki semasa'">Current balance</div>
                <div style="font-size:24px;font-weight:700;font-family:var(--font-mono);color:{{ (float) $f->balance > 0 ? 'var(--ink)' : 'var(--error)' }};">RM {{ number_format($f->balance, 2) }}</div>
                <div style="font-size:11.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Opening' : 'Pembukaan'">Opening</span> RM {{ number_format($f->opening_balance, 2) }}</div>
                @if ($privileged)
                    <form method="post" action="{{ route('pettycash.delete', $f) }}" style="margin-top:8px;" @submit="if (! confirm($store.ui.lang==='en' ? @js('Delete '.$f->name.'? This permanently removes the float and all its transactions, and cannot be undone.') : @js('Padam '.$f->name.'? Ini membuang float dan semua transaksinya secara kekal, dan tidak boleh dibatalkan.'))) $event.preventDefault();">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 11px;font-size:11.5px;color:var(--error);border-color:var(--error);" x-text="$store.ui.lang==='en' ? 'Delete float' : 'Padam float'">Delete float</button>
                    </form>
                @endif
            </div>
        </div>

        @if ($privileged)
            <div style="display:flex;gap:14px;padding:16px 20px;border-bottom:1px solid var(--hairline-soft);flex-wrap:wrap;">
                <form method="post" action="{{ route('pettycash.disburse', $f) }}" style="flex:1;min-width:300px;display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
                    @csrf
                    <div style="flex:1;min-width:90px;"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Disburse (RM)' : 'Bayar keluar (RM)'">Disburse (RM)</label><input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" style="{{ $fs }}width:100%;font-family:var(--font-mono);" /></div>
                    <div style="flex:1.2;min-width:120px;"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Payee' : 'Penerima'">Payee</label><input name="payee" required maxlength="120" placeholder="Paid to…" :placeholder="$store.ui.lang==='en' ? 'Paid to…' : 'Dibayar kepada…'" style="{{ $fs }}width:100%;" /></div>
                    <div style="flex:1.4;min-width:130px;"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Purpose' : 'Tujuan'">Purpose</label><input name="purpose" required maxlength="255" placeholder="What for…" :placeholder="$store.ui.lang==='en' ? 'What for…' : 'Untuk apa…'" style="{{ $fs }}width:100%;" /></div>
                    <button type="submit" class="uj-btn-ghost" style="height:38px;padding:0 14px;font-size:12.5px;color:var(--error);border-color:var(--error);" x-text="$store.ui.lang==='en' ? '− Pay out' : '− Bayar keluar'">− Pay out</button>
                </form>
                <form method="post" action="{{ route('pettycash.replenish', $f) }}" style="flex:1;min-width:260px;display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
                    @csrf
                    <div style="flex:1;min-width:90px;"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Replenish (RM)' : 'Tambah nilai (RM)'">Replenish (RM)</label><input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" style="{{ $fs }}width:100%;font-family:var(--font-mono);" /></div>
                    <div style="flex:2;min-width:140px;"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Note' : 'Nota'">Note</label><input name="note" maxlength="255" placeholder="Top-up source…" :placeholder="$store.ui.lang==='en' ? 'Top-up source…' : 'Sumber tambah nilai…'" style="{{ $fs }}width:100%;" /></div>
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? '+ Top up' : '+ Tambah nilai'">+ Top up</button>
                </form>
            </div>
            @if ($errors->has('amount'))
                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;padding:9px 20px;">{{ $errors->first('amount') }}</div>
            @endif
        @endif

        <div style="display:grid;grid-template-columns:90px 1.2fr 2fr 1fr;gap:8px;padding:11px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);">
            <span x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span><span x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span><span x-text="$store.ui.lang==='en' ? 'Payee / Purpose' : 'Penerima / Tujuan'">Payee / Purpose</span><span style="text-align:right;" x-text="$store.ui.lang==='en' ? 'Amount' : 'Jumlah'">Amount</span>
        </div>
        @forelse ($f->txns as $t)
            @php $isOut = $t->type === 'disbursement'; @endphp
            <div style="display:grid;grid-template-columns:90px 1.2fr 2fr 1fr;gap:8px;padding:11px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;font-size:13px;">
                <span style="color:var(--muted);font-size:12px;">{{ $t->txn_date->format('j M') }}</span>
                <span style="display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:12px;color:{{ $isOut ? 'var(--error)' : 'var(--success)' }};">
                    <span>{{ $isOut ? '▼' : '▲' }}</span><span x-text="$store.ui.lang==='en' ? @js($isOut ? 'Disbursement' : 'Replenishment') : @js($isOut ? 'Bayaran keluar' : 'Tambah nilai')">{{ $isOut ? 'Disbursement' : 'Replenishment' }}</span>
                </span>
                <span style="color:var(--body);min-width:0;">@if ($isOut){{ ($t->payee ? $t->payee.' — ' : '').$t->purpose }}@elseif ($t->note){{ $t->note }}@else<span x-text="$store.ui.lang==='en' ? 'Top-up' : 'Tambah nilai'">Top-up</span>@endif</span>
                <span style="text-align:right;font-family:var(--font-mono);font-weight:600;color:{{ $isOut ? 'var(--error)' : 'var(--success)' }};">{{ $isOut ? '−' : '+' }} RM {{ number_format($t->amount, 2) }}</span>
            </div>
        @empty
            <div style="padding:20px;text-align:center;font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No transactions yet on this float.' : 'Belum ada transaksi pada float ini.'">No transactions yet on this float.</div>
        @endforelse
    </div>
@empty
    <div class="uj-card" style="padding:28px 20px;text-align:center;">
        <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'No petty cash floats yet' : 'Belum ada float wang runcit'">No petty cash floats yet</div>
        <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'Click &quot;+ Open float&quot; above to set up a branch cash float with its opening balance — then record disbursements and replenishments against it.' : 'Klik &quot;+ Open float&quot; di atas untuk sediakan float tunai cawangan dengan baki pembukaannya — kemudian rekod disbursement dan replenishment terhadapnya.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'No branch petty cash floats have been set up yet. HR will add them here.' : 'Belum ada float wang runcit cawangan disediakan. HR akan menambahnya di sini.'"></span>@endif</div>
    </div>
@endforelse
</div>
@endsection
