@extends('layouts.app')

@php
    $sc = ['submitted' => 'var(--amber)', 'verified' => 'var(--info)', 'approved' => 'var(--success)', 'rejected' => 'var(--error)', 'paid' => 'var(--info)'];

    // Merge the two-step gate (see RoutesApprovalsByReportingLine) into one "Needs you"
    // queue, tagging each row with which step it's at. Approve-step rows are further
    // along (closer to payment) so they lead; each group keeps the date-descending
    // order the underlying queries already produced.
    $needsYou = $claimsToApprove->map(fn ($c) => ['claim' => $c, 'step' => 'approve'])
        ->concat($claimsToVerify->map(fn ($c) => ['claim' => $c, 'step' => 'verify']))
        ->values();
@endphp

@section('screen')
<div x-data="{ tab: 'needs-you' }">

    {{-- Tabs — same idiom as payroll.blade.php: no transition, approvers hit these often. --}}
    <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--hairline);">
        <button @click="tab = 'needs-you'" :style="tab === 'needs-you' ? { color:'var(--red)', borderBottom:'2px solid var(--red)' } : { color:'var(--muted)', borderBottom:'2px solid transparent' }" style="background:none;padding:9px 14px;font-size:13px;font-weight:500;cursor:pointer;margin-bottom:-1px;display:flex;align-items:center;gap:7px;">
            <span x-text="$store.ui.lang==='en' ? 'Needs you' : 'Perlu tindakan anda'">Needs you</span>
            @if ($needsYou->isNotEmpty())
                <span class="uj-pill" style="background:var(--red-tint);color:var(--red);">{{ $needsYou->count() }}</span>
            @endif
        </button>
        @if (! empty($privileged))
            <button @click="tab = 'all'" :style="tab === 'all' ? { color:'var(--red)', borderBottom:'2px solid var(--red)' } : { color:'var(--muted)', borderBottom:'2px solid transparent' }" style="background:none;padding:9px 14px;font-size:13px;font-weight:500;cursor:pointer;margin-bottom:-1px;" x-text="$store.ui.lang==='en' ? 'All claims' : 'Semua tuntutan'">All claims</button>
        @endif
    </div>

    {{-- ════ TAB: Needs you ════ --}}
    <div x-show="tab === 'needs-you'" x-cloak>
        <div class="uj-card">
            @forelse ($needsYou as $row)
                @php $c = $row['claim']; $isVerify = $row['step'] === 'verify'; @endphp
                <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                    <div style="width:32px;height:32px;border-radius:50%;background:{{ $c->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $c->employee?->initials }}</div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $c->title }}</div>
                        {{-- Step label rides the meta line, not a chip beside the title: the title
                             is what the approver reads first, and a chip there competes with it. --}}
                        <div style="font-size:12px;color:var(--muted);">
                            {{ $c->employee?->name }} · {{ ucfirst($c->type) }} · {{ $c->date->format('j M Y') }} ·
                            @if ($isVerify)
                                <span style="color:var(--amber);" x-text="$store.ui.lang==='en' ? 'step 1, verify' : 'langkah 1, sahkan'">step 1, verify</span>
                            @else
                                <span style="color:var(--info);" x-text="$store.ui.lang==='en' ? 'step 2, approve' : 'langkah 2, luluskan'">step 2, approve</span>
                            @endif
                        </div>
                        @if ($c->receipt_path)<a href="{{ route('claims.receipt', $c) }}" style="font-size:11.5px;color:var(--info);text-decoration:none;">📎 <span x-text="$store.ui.lang==='en' ? 'View receipt' : 'Lihat resit'">View receipt</span></a>@endif
                        @if (! $isVerify && $c->verifiedBy)<div style="font-size:11px;color:var(--success);">{{ $c->verifiedBy->name }} <span x-text="$store.ui.lang==='en' ? 'verified' : 'sahkan'">verified</span></div>@endif
                    </div>
                    <div style="font-size:14px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $c->currency }} {{ number_format($c->amount, 2) }}</div>
                    @if ($isVerify)
                        <form method="post" action="{{ route('claims.verify', $c) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Verify' : 'Sahkan'">Verify</button></form>
                    @else
                        <form method="post" action="{{ route('claims.approve', $c) }}">@csrf<button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Approve' : 'Luluskan'">Approve</button></form>
                    @endif
                    <form method="post" action="{{ route('claims.reject', $c) }}">@csrf<button class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</button></form>
                </div>
            @empty
                <div style="padding:36px 20px;text-align:center;">
                    <div style="font-size:14px;color:var(--ink);font-weight:500;margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Nothing waiting on you' : 'Tiada yang menunggu anda'">Nothing waiting on you</span></div>
                    <div style="font-size:12.5px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Claims your team submits will appear here.' : 'Tuntutan yang dihantar pasukan anda akan muncul di sini.'">Claims your team submits will appear here.</span></div>
                </div>
            @endforelse
        </div>
    </div>

    {{-- ════ TAB: All claims (management / hr only) ════ --}}
    @if (! empty($privileged))
        <div x-show="tab === 'all'" x-cloak>
            @php
                $companyStatuses = [
                    'submitted' => ['Submitted', 'Dihantar'],
                    'verified' => ['Verified', 'Disahkan'],
                    'approved' => ['Approved', 'Diluluskan'],
                    'paid' => ['Paid', 'Dibayar'],
                ];
            @endphp
            {{-- Tiles sit on the canvas, not inside the table card: a card nested in a card
                 reads as one region and the money figures wrapped onto two lines. --}}
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px;margin-bottom:16px;">
                @foreach ($companyStatuses as $status => $label)
                    <div class="uj-card uj-stat">
                        <div class="uj-stat-label" x-text="$store.ui.lang==='en' ? '{{ $label[0] }}' : '{{ $label[1] }}'">{{ $label[0] }}</div>
                        <div class="uj-stat-value" style="color:{{ $sc[$status] }};">RM {{ number_format($claimTotals[$status]->total ?? 0, 2) }}</div>
                        @php($statusCount = (int) ($claimTotals[$status]->count ?? 0))
                        <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">
                            {{ $statusCount }} <span x-text="$store.ui.lang==='en' ? @js(\Illuminate\Support\Str::plural('claim', $statusCount)) : 'claim'">{{ \Illuminate\Support\Str::plural('claim', $statusCount) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="uj-card" style="margin-bottom:16px;">
                <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Company claims' : 'Claim syarikat'">Company claims</h3></div>
                <div style="display:flex;gap:14px;padding:8px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;border-top:1px solid var(--hairline);">
                    <div style="width:80px;" x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</div>
                    <div style="flex:1;min-width:0;" x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</div>
                    <div style="width:100px;" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</div>
                    <div style="width:110px;text-align:right;" x-text="$store.ui.lang==='en' ? 'Amount' : 'Jumlah'">Amount</div>
                    <div style="width:90px;text-align:right;" x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</div>
                </div>
                @forelse ($allClaims as $c)
                    <div style="display:flex;align-items:center;gap:14px;padding:11px 20px;border-bottom:1px solid var(--hairline-soft);">
                        <div style="width:80px;font-size:12px;color:var(--muted);">{{ $c->date->format('j M Y') }}</div>
                        <div style="flex:1;min-width:0;font-size:13px;color:var(--ink);font-weight:500;">{{ $c->employee?->name }}</div>
                        <div style="width:100px;font-size:12px;color:var(--muted);">{{ ucfirst($c->type) }}</div>
                        <div style="width:110px;text-align:right;font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $c->currency }} {{ number_format($c->amount, 2) }}</div>
                        <div style="width:90px;text-align:right;font-size:11px;font-weight:600;color:{{ $sc[$c->status] }};">{{ ucfirst($c->status) }}</div>
                    </div>
                @empty
                    <div style="padding:28px 20px;text-align:center;">
                        <div style="font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No claims yet' : 'Belum ada claim'">No claims yet</span></div>
                    </div>
                @endforelse
            </div>
        </div>
    @endif
</div>
@endsection
