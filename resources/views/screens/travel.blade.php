@extends('layouts.app')

@php
    $statusMeta = [
        'submitted' => ['label' => 'Submitted', 'color' => 'var(--amber)'],
        'approved' => ['label' => 'Approved', 'color' => 'var(--success)'],
        'rejected' => ['label' => 'Rejected', 'color' => 'var(--red)'],
    ];
    $transportLabel = ['flight' => 'Flight', 'car' => 'Car', 'train' => 'Train', 'other' => 'Other'];
    $transportMs = ['flight' => 'Penerbangan', 'car' => 'Kereta', 'train' => 'Keretapi', 'other' => 'Lain-lain'];
    $fs = 'height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;background:#fff;color:var(--ink);outline:none;';
    $pill = fn ($s) => '<span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:'.($statusMeta[$s]['color'] ?? 'var(--muted)').';"><span style="width:8px;height:8px;border-radius:50%;background:'.($statusMeta[$s]['color'] ?? 'var(--muted)').';"></span>'.($statusMeta[$s]['label'] ?? ucfirst($s)).'</span>';
@endphp

@section('screen')

@include('partials.guide', [
    'key' => 'travel',
    'en'  => [
        'title' => 'Business travel requests',
        'body'  => 'Staff request approval before a work trip — where they\'re going, why, the dates and how they\'ll travel. A manager approves or rejects it. Approval is what authorises the trip; actual receipts are claimed separately afterwards on the Claims screen.',
        'who'   => 'Staff request · Managers & HR approve',
        'steps' => [
            'Enter the destination and the purpose (the business reason for the trip).',
            'Set the depart and return dates, and choose how the person is travelling.',
            'Add an estimated cost if known — this is a guide for approval, not the final claim.',
            'Submit before travelling. The trip shows as "Submitted" until a manager decides.',
        ],
    ],
    'ms'  => [
        'title' => 'Permohonan perjalanan kerja',
        'body'  => 'Staf mohon kelulusan sebelum perjalanan kerja — ke mana, sebab apa, tarikh dan cara mereka bergerak. Pengurus lulus atau tolak. Kelulusan inilah yang membenarkan perjalanan itu; resit sebenar dituntut berasingan kemudian di skrin Claims.',
        'who'   => 'Staf mohon · Pengurus & HR luluskan',
        'steps' => [
            'Masukkan destinasi dan tujuan (sebab perniagaan bagi perjalanan itu).',
            'Tetapkan tarikh bertolak dan pulang, dan pilih cara orang itu bergerak.',
            'Tambah anggaran kos jika tahu — ini panduan untuk kelulusan, bukan claim akhir.',
            'Hantar sebelum bertolak. Perjalanan akan kekal "Submitted" sehingga pengurus buat keputusan.',
        ],
    ],
])

@if ($privileged && $pendingApprovals->isNotEmpty())
    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Trips to approve' : 'Perjalanan untuk diluluskan'">Trips to approve</h3>
            <span class="uj-pill" style="background:var(--red-tint);color:var(--red);">{{ $pendingApprovals->count() }}</span>
        </div>
        @foreach ($pendingApprovals as $t)
            <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="width:32px;height:32px;border-radius:50%;background:{{ $t->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $t->employee?->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $t->destination }}</div>
                    <div style="font-size:12px;color:var(--muted);">{{ $t->employee?->name }} · <span x-text="$store.ui.lang==='en' ? @js($transportLabel[$t->transport] ?? ucfirst($t->transport)) : @js($transportMs[$t->transport] ?? ucfirst($t->transport))">{{ $transportLabel[$t->transport] ?? ucfirst($t->transport) }}</span> · {{ $t->depart_date->format('j M') }} – {{ $t->return_date->format('j M Y') }}</div>
                </div>
                @if ($t->estimated_cost !== null)
                    <div style="font-size:14px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">RM {{ number_format((float) $t->estimated_cost, 2) }}</div>
                @endif
                <form method="post" action="{{ route('travel.approve', $t) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</button></form>
                <form method="post" action="{{ route('travel.reject', $t) }}">@csrf<button class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</button></form>
            </div>
        @endforeach
    </div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1.3;min-width:340px;padding:24px;">
        <h3 class="uj-card-title" style="margin-bottom:16px;" x-text="$store.ui.lang==='en' ? 'New travel request' : 'Permohonan perjalanan baharu'">New travel request</h3>
        <form method="post" action="{{ route('travel.store') }}">
            @csrf
            @if ($errors->any())
                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:16px;">{{ $errors->first() }}</div>
            @endif
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Destination *' : 'Destinasi *'">Destination *</label>
            <input name="destination" value="{{ old('destination') }}" required maxlength="150" placeholder="e.g. Kuala Lumpur — client HQ" :placeholder="$store.ui.lang==='en' ? 'e.g. Kuala Lumpur — client HQ' : 'cth. Kuala Lumpur — HQ client'" style="{{ $fs }}width:100%;margin-bottom:16px;" />
            @include('partials.hint', ['en' => 'Where the trip is to — city and, if useful, the place being visited (e.g. client HQ).', 'ms' => 'Ke mana perjalanan ini — bandar dan, jika berguna, tempat yang dilawati (cth. client HQ).'])
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Purpose *' : 'Tujuan *'">Purpose *</label>
            <textarea name="purpose" required maxlength="1000" rows="3" placeholder="Reason for the business trip." :placeholder="$store.ui.lang==='en' ? 'Reason for the business trip.' : 'Sebab perjalanan kerja.'" style="width:100%;padding:12px 14px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:16px;outline:none;resize:vertical;">{{ old('purpose') }}</textarea>
            <div style="display:flex;gap:16px;margin-bottom:16px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Depart date *' : 'Tarikh bertolak *'">Depart date *</label>
                    <input name="depart_date" type="date" value="{{ old('depart_date', now()->toDateString()) }}" required style="{{ $fs }}width:100%;" />
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Return date *' : 'Tarikh pulang *'">Return date *</label>
                    <input name="return_date" type="date" value="{{ old('return_date', now()->toDateString()) }}" required style="{{ $fs }}width:100%;" />
                </div>
            </div>
            @include('partials.hint', ['en' => 'Depart is the first day away; return is the day back. Return must be on or after the depart date.', 'ms' => 'Depart ialah hari pertama bertolak; return ialah hari pulang. Return mesti pada atau selepas tarikh depart.'])
            <div style="display:flex;gap:16px;margin-bottom:18px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Transport *' : 'Pengangkutan *'">Transport *</label>
                    <select name="transport" required style="{{ $fs }}width:100%;">
                        @foreach ($transport as $v)
                            <option value="{{ $v }}" @selected(old('transport', 'flight') === $v) x-text="$store.ui.lang==='en' ? @js($transportLabel[$v] ?? ucfirst($v)) : @js($transportMs[$v] ?? ucfirst($v))">{{ $transportLabel[$v] ?? ucfirst($v) }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Estimated cost (RM)' : 'Anggaran kos (RM)'">Estimated cost (RM)</label>
                    <input name="estimated_cost" type="number" step="0.01" min="0" value="{{ old('estimated_cost') }}" placeholder="0.00" style="{{ $fs }}width:100%;font-family:var(--font-mono);" />
                    @include('partials.hint', ['en' => 'A rough estimate to help the approver — optional. The real spend is claimed later with receipts on the Claims screen.', 'ms' => 'Anggaran kasar untuk bantu pelulus — pilihan. Perbelanjaan sebenar dituntut kemudian dengan resit di skrin Claims.'])
                </div>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Submit request' : 'Hantar permohonan'">Submit request</button>
        </form>
    </div>

    <div class="uj-card" style="flex:1;min-width:300px;">
        <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'My trips' : 'Perjalanan saya'">My trips</h3></div>
        @forelse ($myRequests as $t)
            <div style="padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                    <div style="min-width:0;">
                        <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $t->destination }}</div>
                        <div style="font-size:11.5px;color:var(--muted);margin-top:3px;"><span x-text="$store.ui.lang==='en' ? @js($transportLabel[$t->transport] ?? ucfirst($t->transport)) : @js($transportMs[$t->transport] ?? ucfirst($t->transport))">{{ $transportLabel[$t->transport] ?? ucfirst($t->transport) }}</span> · {{ $t->depart_date->format('j M') }} – {{ $t->return_date->format('j M Y') }}</div>
                    </div>
                    {!! $pill($t->status) !!}
                </div>
                <div style="font-size:12.5px;color:var(--body);margin-top:7px;white-space:pre-line;">{{ $t->purpose }}</div>
                @if ($t->estimated_cost !== null)
                    <div style="font-size:12px;color:var(--muted);margin-top:6px;font-family:var(--font-mono);"><span x-text="$store.ui.lang==='en' ? 'Est.' : 'Angg.'">Est.</span> RM {{ number_format((float) $t->estimated_cost, 2) }}</div>
                @endif
                @if ($t->status !== 'submitted' && $t->approver)
                    <div style="font-size:11.5px;color:var(--muted);margin-top:6px;">{{ ucfirst($t->status) }} <span x-text="$store.ui.lang==='en' ? 'by' : 'oleh'">by</span> {{ $t->approver->name }}{{ $t->decided_at ? ' · '.$t->decided_at->format('j M Y') : '' }}</div>
                @endif
            </div>
        @empty
            <div style="padding:40px 24px;text-align:center;">
                <div style="font-size:13.5px;color:var(--ink);font-weight:500;margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'No trips requested yet' : 'Belum ada perjalanan dimohon'"></span></div>
                <div style="font-size:12.5px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Use the form on the left to request approval for a business trip. Your trips and their status will appear here.' : 'Guna borang di sebelah kiri untuk mohon kelulusan perjalanan kerja. Perjalanan anda dan statusnya akan muncul di sini.'"></span></div>
            </div>
        @endforelse
    </div>
</div>

@endsection
