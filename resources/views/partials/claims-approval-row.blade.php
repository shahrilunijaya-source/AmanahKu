{{-- One claim on the Approvals tab: a table row (see partials.approval-groups) that
     folds open for the reason and the timeline.

     Params: $item (Claim), $showName (lead with the requester's name),
             $mode ('verify' | 'approve' for a pending row, null for a settled one). --}}
@php
    $c = $item;
    $act = ['verify' => ['claims.verify', 'Verify', 'Sahkan'], 'approve' => ['claims.approve', 'Approve', 'Luluskan']][$mode ?? ''] ?? null;
    $waitingDays = $act ? (int) $c->updated_at?->diffInDays(now()) : 0;
@endphp
<div class="uj-lv-rw" x-data="{ open: false }" :data-open="open ? '' : null">
    <div class="uj-ap-row">
        @if ($showName)
            <span class="uj-ap-name">{{ $c->employee?->display_name }}@if ($c->employee?->staff_id)<small>{{ $c->employee->staff_id }}</small>@endif</span>
        @endif
        <span>{{ $c->date?->format('d/m/Y') }}</span>
        <span>{{ ucfirst($c->type) }}</span>
        <span class="uj-ap-rem">
            {{ $c->title }}
            @if ($c->status === 'paid')<span class="uj-stamp" x-text="$store.ui.lang==='en' ? 'paid' : 'dibayar'">paid</span>@endif
            @if ($waitingDays >= 5)<span class="uj-stamp" data-tone="error">{{ $waitingDays }}<span x-text="$store.ui.lang==='en' ? 'd waiting' : ' hari'">d waiting</span></span>@endif
        </span>
        <span class="uj-ap-num">{{ number_format((float) $c->amount, 2) }}</span>
        <span class="uj-ap-acts">
            @if ($act)
                <form method="post" action="{{ route($act[0], $c) }}">
                    @csrf
                    <button type="submit" class="uj-btn-primary uj-ap-btn">
                        <span x-text="$store.ui.lang==='en' ? @js($act[1]) : @js($act[2])">{{ $act[1] }}</span>
                    </button>
                </form>
                <form method="post" action="{{ route('claims.reject', $c) }}">
                    @csrf
                    <button type="submit" class="uj-btn-ghost uj-ap-btn">
                        <span x-text="$store.ui.lang==='en' ? 'Decline' : 'Tolak'">Decline</span>
                    </button>
                </form>
            @endif
            @if ($c->receipt_path)
                <a class="uj-ap-ic" href="{{ route('claims.receipt', $c) }}" target="_blank" rel="noopener"
                   :title="$store.ui.lang==='en' ? 'Open receipt' : 'Buka resit'" :aria-label="$store.ui.lang==='en' ? 'Open receipt' : 'Buka resit'">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                </a>
            @endif
            <button type="button" class="uj-ap-ic" @click="open = !open" :aria-expanded="open"
                    :aria-label="$store.ui.lang==='en' ? 'Details' : 'Butiran'">
                <svg class="uj-lv-chev" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
            </button>
        </span>
    </div>
    <div class="uj-lv-fold" :style="open ? 'grid-template-rows:1fr' : 'grid-template-rows:0fr'">
        <div><div class="uj-lv-rw-in uj-ap-in">
            @if ($c->reason)<div class="uj-lv-quote">“{{ $c->reason }}”</div>@endif
            @include('partials.claims-timeline', ['c' => $c])
        </div></div>
    </div>
</div>
