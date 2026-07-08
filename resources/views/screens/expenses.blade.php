@extends('layouts.app')

@php
    $sc = [
        'draft' => 'var(--muted)',
        'submitted' => 'var(--amber)',
        'approved' => 'var(--success)',
        'rejected' => 'var(--error)',
        'paid' => 'var(--info)',
    ];
    $pendingTotal = $myReports->where('status', 'submitted')->sum('total');
    $approvedTotal = $myReports->whereIn('status', ['approved', 'paid'])->sum('total');
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'expenses',
    'en'  => [
        'title' => 'Expense reports',
        'body'  => 'Group several expenses into one report — for example a whole month of client visits — instead of claiming them one by one. You build the report as a draft, add as many lines as you need, then submit the whole report to a manager for approval.',
        'who'   => 'Staff build & submit · Managers & HR approve',
        'steps' => [
            'Give the report a title and period, then add one line per expense (date, category, description, amount).',
            'Use "+ Add line" for each extra expense. The running total updates as you go.',
            'Click "Create draft" — nothing is sent yet. A draft can still be edited and have lines added.',
            'When the report is complete, press "Submit" on the draft to send it to a manager for approval.',
        ],
    ],
    'ms'  => [
        'title' => 'Laporan perbelanjaan',
        'body'  => 'Kumpulkan beberapa perbelanjaan dalam satu laporan — contohnya sebulan penuh lawatan client — daripada tuntut satu-satu. Anda bina laporan sebagai draft, tambah seberapa banyak baris yang perlu, kemudian hantar keseluruhan laporan kepada pengurus untuk kelulusan.',
        'who'   => 'Staf bina & hantar · Pengurus & HR luluskan',
        'steps' => [
            'Beri laporan satu tajuk dan tempoh, kemudian tambah satu baris bagi setiap perbelanjaan (tarikh, kategori, keterangan, jumlah).',
            'Guna "+ Add line" untuk setiap perbelanjaan tambahan. Jumlah keseluruhan dikemas kini secara langsung.',
            'Klik "Create draft" — belum ada apa-apa dihantar lagi. Draft masih boleh disunting dan ditambah baris.',
            'Apabila laporan dah lengkap, tekan "Submit" pada draft untuk hantar kepada pengurus untuk kelulusan.',
        ],
    ],
])
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'My reports' : 'Laporan saya'">My reports</div><div class="uj-stat-value">{{ $myReports->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Pending amount' : 'Jumlah menunggu'">Pending amount</div><div class="uj-stat-value" style="color:var(--amber);">RM {{ number_format($pendingTotal, 2) }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Approved (YTD)' : 'Diluluskan (YTD)'">Approved (YTD)</div><div class="uj-stat-value" style="color:var(--success);">RM {{ number_format($approvedTotal, 2) }}</div></div>
</div>

@if ($privileged && $pendingReports->isNotEmpty())
    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Reports to approve' : 'Laporan untuk diluluskan'">Reports to approve</h3><span class="uj-pill" style="background:var(--red-tint);color:var(--red);">{{ $pendingReports->count() }}</span></div>
        @foreach ($pendingReports as $r)
            <div x-data="{ open: false }" style="border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;">
                    <div style="width:32px;height:32px;border-radius:50%;background:{{ $r->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $r->employee?->initials }}</div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $r->title }}</div>
                        <div style="font-size:12px;color:var(--muted);">{{ $r->employee?->name }} · {{ $r->period_label }} · {{ $r->lines->count() }} <span x-text="$store.ui.lang==='en' ? ({{ $r->lines->count() }} === 1 ? 'line' : 'lines') : 'baris'">{{ $r->lines->count() === 1 ? 'line' : 'lines' }}</span></div>
                    </div>
                    <button type="button" @click="open = ! open" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;"><span x-text="open ? ($store.ui.lang==='en' ? 'Hide' : 'Sembunyi') : ($store.ui.lang==='en' ? 'Lines' : 'Baris')"></span></button>
                    <div style="font-size:14px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">RM {{ number_format($r->total, 2) }}</div>
                    <form method="post" action="{{ route('expenses.approve', $r) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</button></form>
                    <form method="post" action="{{ route('expenses.reject', $r) }}">@csrf<button class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</button></form>
                </div>
                <div x-show="open" x-cloak style="padding:0 20px 14px 66px;">
                    @foreach ($r->lines as $line)
                        <div style="display:flex;justify-content:space-between;gap:12px;font-size:12px;color:var(--muted);padding:4px 0;border-top:1px solid var(--hairline-soft);">
                            <span>{{ $line->expense_date->format('j M') }} · {{ $line->category }} — {{ $line->description }}</span>
                            <span style="font-family:var(--font-mono);color:var(--ink);">RM {{ number_format($line->amount, 2) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1.3;min-width:360px;padding:24px;"
         x-data="{ lines: [{ expense_date: '{{ now()->toDateString() }}', category: '{{ $categories[0] }}', description: '', amount: '' }] }">
        <h3 class="uj-card-title" style="margin-bottom:16px;" x-text="$store.ui.lang==='en' ? 'New expense report' : 'Laporan perbelanjaan baharu'">New expense report</h3>
        <form method="post" action="{{ route('expenses.store') }}">
            @csrf
            <div style="display:flex;gap:16px;margin-bottom:16px;">
                <div style="flex:1.4;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label>
                    <input name="title" required value="{{ old('title') }}" placeholder="e.g. June client visits" :placeholder="$store.ui.lang==='en' ? 'e.g. June client visits' : 'cth. Lawatan client Jun'" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Period' : 'Tempoh'">Period</label>
                    <input name="period_label" value="{{ old('period_label', now()->format('F Y')) }}" placeholder="June 2026" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>
            </div>
            @error('title')<div style="font-size:12px;color:var(--error);margin-top:-10px;margin-bottom:14px;">{{ $message }}</div>@enderror
            @include('partials.hint', ['en' => 'Title is a short label for the batch (e.g. "June client visits"); period is the month it covers.', 'ms' => 'Title ialah label ringkas untuk kumpulan ini (cth. "June client visits"); period ialah bulan yang dirangkumi.'])

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:8px;" x-text="$store.ui.lang==='en' ? 'Expense lines' : 'Baris perbelanjaan'">Expense lines</label>
            @include('partials.hint', ['en' => 'One line per receipt. Set the date it happened, the category, what it was for, and the exact amount.', 'ms' => 'Satu baris bagi setiap resit. Tetapkan tarikh ia berlaku, kategori, untuk apa, dan jumlah tepat.'])
            <template x-for="(line, i) in lines" :key="i">
                <div style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
                    <input type="date" :name="`lines[${i}][expense_date]`" x-model="line.expense_date" required style="width:140px;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <select :name="`lines[${i}][category]`" x-model="line.category" required style="width:130px;height:38px;padding:0 8px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);">
                        @foreach ($categories as $cat)
                            <option value="{{ $cat }}">{{ $cat }}</option>
                        @endforeach
                    </select>
                    <input type="text" :name="`lines[${i}][description]`" x-model="line.description" required placeholder="Description" :placeholder="$store.ui.lang==='en' ? 'Description' : 'Penerangan'" style="flex:1;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <input type="number" step="0.01" min="0.01" :name="`lines[${i}][amount]`" x-model="line.amount" required placeholder="0.00" style="width:100px;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;font-family:var(--font-mono);outline:none;" />
                    <button type="button" @click="lines.splice(i, 1)" x-show="lines.length > 1" class="uj-btn-ghost" style="height:38px;padding:0 10px;font-size:13px;" :title="$store.ui.lang==='en' ? 'Remove line' : 'Buang baris'" title="Remove line">&times;</button>
                </div>
            </template>
            @error('lines')<div style="font-size:12px;color:var(--error);margin:4px 0 10px;">{{ $message }}</div>@enderror

            <div style="display:flex;align-items:center;justify-content:space-between;margin:14px 0 18px;">
                <button type="button" @click="lines.push({ expense_date: '{{ now()->toDateString() }}', category: '{{ $categories[0] }}', description: '', amount: '' })" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? '+ Add line' : '+ Tambah baris'">+ Add line</button>
                <div style="font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Total:' : 'Jumlah:'">Total:</span> <span style="font-family:var(--font-mono);font-weight:600;color:var(--ink);">RM <span x-text="lines.reduce((s, l) => s + (parseFloat(l.amount) || 0), 0).toFixed(2)"></span></span></div>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Create draft' : 'Cipta draf'">Create draft</button>
        </form>
    </div>

    <div class="uj-card" style="flex:1;min-width:320px;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'My reports' : 'Laporan saya'">My reports</h3></div>
        @forelse ($myReports as $r)
            <div x-data="{ open: false }" style="border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 20px;">
                    <div style="min-width:0;">
                        <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $r->title }}</div>
                        <div style="font-size:11.5px;color:var(--muted);">{{ $r->period_label }} · {{ $r->lines->count() }} <span x-text="$store.ui.lang==='en' ? ({{ $r->lines->count() }} === 1 ? 'line' : 'lines') : 'baris'">{{ $r->lines->count() === 1 ? 'line' : 'lines' }}</span></div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">RM {{ number_format($r->total, 2) }}</div>
                        <div style="font-size:11px;font-weight:600;color:{{ $sc[$r->status] }};">{{ ucfirst($r->status) }}</div>
                    </div>
                </div>
                <div style="display:flex;gap:8px;padding:0 20px 12px;">
                    <button type="button" @click="open = ! open" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;"><span x-text="open ? ($store.ui.lang==='en' ? 'Hide lines' : 'Sembunyi baris') : ($store.ui.lang==='en' ? 'View lines' : 'Lihat baris')"></span></button>
                    @if ($r->status === 'draft')
                        <form method="post" action="{{ route('expenses.submit', $r) }}">@csrf<button class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Submit' : 'Hantar'">Submit</button></form>
                    @endif
                </div>
                <div x-show="open" x-cloak style="padding:0 20px 14px;">
                    @foreach ($r->lines as $line)
                        <div style="display:flex;justify-content:space-between;gap:12px;font-size:12px;color:var(--muted);padding:4px 0;border-top:1px solid var(--hairline-soft);">
                            <span>{{ $line->expense_date->format('j M') }} · {{ $line->category }} — {{ $line->description }}</span>
                            <span style="font-family:var(--font-mono);color:var(--ink);">RM {{ number_format($line->amount, 2) }}</span>
                        </div>
                    @endforeach
                    @if ($r->status === 'draft')
                        <form method="post" action="{{ route('expenses.lines', $r) }}" style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap;">
                            @csrf
                            <input type="date" name="expense_date" value="{{ now()->toDateString() }}" required style="width:130px;height:34px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;outline:none;" />
                            <select name="category" required style="width:120px;height:34px;padding:0 6px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;background:#fff;color:var(--ink);">
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                            </select>
                            <input type="text" name="description" required placeholder="Description" :placeholder="$store.ui.lang==='en' ? 'Description' : 'Penerangan'" style="flex:1;min-width:120px;height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;outline:none;" />
                            <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" style="width:90px;height:34px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;font-size:12px;font-family:var(--font-mono);outline:none;" />
                            <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? '+ Add' : '+ Tambah'">+ Add</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No expense reports yet' : 'Belum ada laporan perbelanjaan'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Build your first report on the left. Save it as a draft, add all the lines, then submit it for approval — it will appear here.' : 'Bina laporan pertama anda di sebelah kiri. Simpan sebagai draft, tambah semua baris, kemudian hantar untuk kelulusan — ia akan muncul di sini.'"></span></div>
            </div>
        @endforelse
    </div>
</div>
@endsection
