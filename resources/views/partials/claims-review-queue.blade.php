{{-- One claim review queue, with per-row detail and chronology.

     Mirrors partials.leave-review-queue, without the bulk actions — claims has no
     bulk-verify/bulk-approve route, so each row acts on its own. Each row leads with
     the person and the amount, which is what an approver scans by.

     Params: $items (Claim collection; verifiedBy eager-loaded for the approve queue),
             $mode ('verify' | 'approve'), $typeMeta. --}}
@php
    $isVerify = $mode === 'verify';
    $actionRoute = $isVerify ? 'claims.verify' : 'claims.approve';
    $titleEn = $isVerify ? 'Yours to verify' : 'Waiting for final approval';
    $titleMs = $isVerify ? 'Untuk anda sahkan' : 'Menunggu kelulusan akhir';
    $btnEn = $isVerify ? 'Verify' : 'Approve';
    $btnMs = $isVerify ? 'Sahkan' : 'Luluskan';
@endphp
<div class="uj-card">
    <div class="uj-card-head">
        <div style="display:flex;align-items:center;gap:10px;">
            <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? @js($titleEn) : @js($titleMs)">{{ $titleEn }}</span></h3>
            <span class="uj-pill" style="background:var(--red-tint);color:var(--red-active);">{{ $items->count() }}</span>
        </div>
    </div>

    @foreach ($items as $c)
        @php
            $tm = $typeMeta[$c->type] ?? $typeMeta['other'];
            $waitingDays = (int) $c->updated_at?->diffInDays(now());
        @endphp
        <div class="uj-lv-rw" x-data="{ open: false }" :data-open="open ? '' : null">
            <div class="uj-lv-ar">
                <span style="flex:0 0 32px;height:32px;border-radius:50%;background:{{ $c->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:grid;place-items:center;font-size:var(--t-micro);font-weight:600;">{{ $c->employee?->initials }}</span>
                <button type="button" class="uj-lv-ar-t" @click="open = !open" style="display:flex;flex-direction:column;gap:2px;">
                    <span class="uj-lv-rw-1">
                        {{ $c->employee?->name }}
                        @if ($c->employee?->position)<span style="color:var(--muted);font-weight:400;">· {{ $c->employee->position }}</span>@endif
                    </span>
                    <span class="uj-lv-rw-2">
                        {{ $c->title }} · {{ ucfirst($c->type) }} · {{ $c->date->format('j M') }}
                        @if ($c->receipt_path) · <span x-text="$store.ui.lang==='en' ? 'receipt attached' : 'ada resit'">receipt attached</span>@endif
                        @if ($waitingDays >= 5) · <span style="color:var(--red-active);">{{ $waitingDays }}<span x-text="$store.ui.lang==='en' ? 'd waiting' : ' hari'">d waiting</span></span>@endif
                        <svg class="uj-lv-chev" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </span>
                    <span class="uj-lv-after">
                        <b style="font-family:var(--font-mono);font-size:var(--t-base);color:var(--ink);">{{ $c->currency }} {{ number_format($c->amount, 2) }}</b>
                        @if (! $isVerify && $c->verifiedBy)
                            · <span x-text="$store.ui.lang==='en' ? 'verified by {{ $c->verifiedBy->name }}' : 'disahkan oleh {{ $c->verifiedBy->name }}'">verified by {{ $c->verifiedBy->name }}</span>
                        @endif
                    </span>
                </button>
                <span class="uj-lv-acts">
                    <form method="post" action="{{ route($actionRoute, $c) }}">
                        @csrf
                        <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:var(--t-sm);">
                            <span x-text="$store.ui.lang==='en' ? @js($btnEn) : @js($btnMs)">{{ $btnEn }}</span>
                        </button>
                    </form>
                    <form method="post" action="{{ route('claims.reject', $c) }}">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:var(--t-sm);">
                            <span x-text="$store.ui.lang==='en' ? 'Decline' : 'Tolak'">Decline</span>
                        </button>
                    </form>
                </span>
            </div>
            <div class="uj-lv-fold" :style="open ? 'grid-template-rows:1fr' : 'grid-template-rows:0fr'">
                <div><div class="uj-lv-rw-in">
                    @if ($c->reason)<div class="uj-lv-quote">“{{ $c->reason }}”</div>@endif
                    @if ($c->receipt_path)
                        <div style="margin-bottom:11px;">
                            <a href="{{ route('claims.receipt', $c) }}" style="font-size:var(--t-sm);color:var(--red);text-decoration:none;">
                                <span x-text="$store.ui.lang==='en' ? 'Open receipt' : 'Buka resit'">Open receipt</span>
                                @if ($c->receipt_name) — {{ $c->receipt_name }}@endif
                            </a>
                        </div>
                    @endif
                    @include('partials.claims-timeline', ['c' => $c])
                </div></div>
            </div>
        </div>
    @endforeach
</div>
