{{-- Spec F5: the latest payroll run's pay-by date (EA s.19, seven days after the period
     ends) and whether HR has marked it paid. Text only, so "Keep it plain" needs nothing. --}}
@php $run = $w['run'] ?? null; @endphp
<div class="uj-dw-body">
    @if (! $run)
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en' ? 'No payroll run yet.' : 'Belum ada run gaji.'">No payroll run yet.</p>
    @else
        <div style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $run->label }}</div>
        <div style="font-size:12.5px;color:{{ $w['late'] ? 'var(--error)' : 'var(--body)' }};margin-top:4px;">
            @if ($run->status !== 'finalized')
                <span x-text="$store.ui.lang==='en' ? 'Draft, not finalized.' : 'Draf, belum dimuktamadkan.'">Draft, not finalized.</span>
            @else
                <span x-text="$store.ui.lang==='en' ? 'Finalized' : 'Dimuktamadkan'">Finalized</span> {{ $run->finalized_at?->format('j M') }} ·
                <span x-text="$store.ui.lang==='en' ? 'pay by' : 'bayar sebelum'">pay by</span> {{ $w['payBy'] }} ·
                @if ($run->paid_at)
                    <span x-text="$store.ui.lang==='en' ? 'paid' : 'dibayar'">paid</span> {{ $run->paid_at->format('j M') }}
                @else
                    <span x-text="$store.ui.lang==='en' ? 'not yet marked paid' : 'belum ditanda dibayar'">not yet marked paid</span>
                @endif
            @endif
        </div>
    @endif
    @if (($w['openNotices'] ?? 0) > 0)
        <div style="font-size:12.5px;color:{{ ($w['overdueNotices'] ?? 0) > 0 ? 'var(--error)' : 'var(--body)' }};margin-top:6px;">
            {{ $w['openNotices'] }} <span x-text="$store.ui.lang==='en' ? 'statutory notices to file' : 'notis berkanun belum difailkan'">statutory notices to file</span>@if (($w['overdueNotices'] ?? 0) > 0) · {{ $w['overdueNotices'] }} <span x-text="$store.ui.lang==='en' ? 'overdue' : 'lewat'">overdue</span>@endif
        </div>
    @endif
</div>
<div class="uj-dw-foot">
    <a class="uj-dw-link" style="margin-left:auto" href="{{ route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'payout']) }}"
       x-text="$store.ui.lang==='en' ? 'Open payroll' : 'Buka gaji'">Open payroll</a>
</div>
