{{-- Decisions the viewer made this year, one row each. Claims twin of
     partials/leave-decided-list — same shape, different facts per row
     (amount and type rather than dates and days).

     Params: $items (Claim collection), $kind ('approved' | 'rejected').

     Read-only by design: these are settled. Two states a row must call out —
     a claim the applicant withdrew after it was approved, and one payroll has
     since reimbursed, which is the approval reaching its end, not undone. --}}
@php
    $isApproved = $kind === 'approved';
@endphp
<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title">
            <span x-text="$store.ui.lang==='en'
                ? @js($isApproved ? 'Approved this year' : 'Rejected this year')
                : @js($isApproved ? 'Diluluskan tahun ini' : 'Ditolak tahun ini')">{{ $isApproved ? 'Approved this year' : 'Rejected this year' }}</span>
        </h3>
        <span class="uj-pill">{{ $items->count() }}</span>
    </div>

    @forelse ($items as $d)
        @php
            $withdrawn = $d->status === 'cancelled';
            $decidedAt = $d->rejected_at ?? $d->approved_at;
        @endphp
        <div class="uj-lv-drw" @if ($withdrawn) data-withdrawn @endif>
            <div class="uj-lv-drw-main">
                <b>{{ $d->employee?->name }}</b>
                <span class="uj-lv-drw-type">{{ $d->title }}</span>
                @if ($withdrawn)
                    <span class="uj-stamp" data-tone="amber"
                          x-text="$store.ui.lang==='en' ? 'withdrawn by applicant' : 'ditarik balik pemohon'">withdrawn by applicant</span>
                @elseif ($d->status === 'paid')
                    {{-- No tone: the neutral default. Paid is the happy end of an
                         approval, not something to flag. --}}
                    <span class="uj-stamp"
                          x-text="$store.ui.lang==='en' ? 'paid' : 'dibayar'">paid</span>
                @endif
            </div>
            <div class="uj-lv-drw-meta">
                <span>RM {{ number_format((float) $d->amount, 2) }}</span>
                <span>{{ $d->date?->format('j M') }}</span>
                @if ($decidedAt)
                    <span class="uj-lv-drw-when">{{ $decidedAt->format('j M') }}</span>
                @endif
            </div>
        </div>
    @empty
        <div class="uj-lv-empty">
            <span x-text="$store.ui.lang==='en'
                ? @js($isApproved ? 'You have not approved anything this year.' : 'You have not rejected anything this year.')
                : @js($isApproved ? 'Anda belum meluluskan apa-apa tahun ini.' : 'Anda belum menolak apa-apa tahun ini.')">{{ $isApproved ? 'You have not approved anything this year.' : 'You have not rejected anything this year.' }}</span>
        </div>
    @endforelse
</div>
