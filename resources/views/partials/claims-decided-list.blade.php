{{-- Decisions the viewer made this year, one row each. Claims twin of
     partials/leave-decided-list — same shape, different facts per row
     (amount and type rather than dates and days).

     Params: $items (Claim collection), $kind ('approved' | 'verified' | 'rejected').

     Read-only by design: these are settled. Two states a row must call out —
     a claim the applicant withdrew after it was approved, and one payroll has
     since reimbursed, which is the approval reaching its end, not undone. --}}
@php
    $copy = [
        'approved' => ['Approved this year', 'Diluluskan tahun ini', 'You have not approved anything this year.', 'Anda belum meluluskan apa-apa tahun ini.'],
        'verified' => ['Verified this year', 'Disahkan tahun ini', 'You have not verified anything this year.', 'Anda belum mengesahkan apa-apa tahun ini.'],
        'rejected' => ['Rejected this year', 'Ditolak tahun ini', 'You have not rejected anything this year.', 'Anda belum menolak apa-apa tahun ini.'],
    ][$kind];
    $isVerified = $kind === 'verified';
    // Where a verified request ended up. Cancelled and paid have their own stamps below.
    $outcome = [
        'verified' => ['with management', 'dengan pengurusan', 'amber'],
        'approved' => ['approved', 'diluluskan', 'success'],
        'rejected' => ['declined by management', 'ditolak pengurusan', 'error'],
    ];
@endphp
<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title">
            <span x-text="$store.ui.lang==='en' ? @js($copy[0]) : @js($copy[1])">{{ $copy[0] }}</span>
        </h3>
        <span class="uj-pill">{{ $items->count() }}</span>
    </div>

    @forelse ($items as $d)
        @php
            $withdrawn = $d->status === 'cancelled';
            $decidedAt = $isVerified ? $d->verified_at : ($d->rejected_at ?? $d->approved_at);
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
                @if ($isVerified && isset($outcome[$d->status]))
                    <span class="uj-stamp" data-tone="{{ $outcome[$d->status][2] }}"
                          x-text="$store.ui.lang==='en' ? @js($outcome[$d->status][0]) : @js($outcome[$d->status][1])">{{ $outcome[$d->status][0] }}</span>
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
            <span x-text="$store.ui.lang==='en' ? @js($copy[2]) : @js($copy[3])">{{ $copy[2] }}</span>
        </div>
    @endforelse
</div>
