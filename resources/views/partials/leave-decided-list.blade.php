{{-- Decisions the viewer made this year, one row each.

     Params: $items (LeaveRequest collection), $kind ('approved' | 'rejected').

     Read-only by design: these are settled. The one thing a row must say beyond the
     facts is when an approved leave was later withdrawn by the applicant — for a
     while the viewer believed that person was away, and may have planned around it. --}}
@php
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
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
            $decidedAt = $d->rejected_at ?? $d->approved_at ?? $d->verified_at;
        @endphp
        <div class="uj-lv-drw" @if ($withdrawn) data-withdrawn @endif>
            <div class="uj-lv-drw-main">
                <b>{{ $d->employee?->name }}</b>
                <span class="uj-lv-drw-type">{{ $d->leaveType?->name }}</span>
                @if ($withdrawn)
                    {{-- Not a decision the viewer made — the applicant pulled it afterwards.
                         Shown, not hidden: they had already been told this person was away. --}}
                    <span class="uj-stamp" data-tone="amber"
                          x-text="$store.ui.lang==='en' ? 'withdrawn by applicant' : 'ditarik balik pemohon'">withdrawn by applicant</span>
                @endif
            </div>
            <div class="uj-lv-drw-meta">
                <span>{{ $d->date_from?->format('j M') }}@if ($d->date_to && ! $d->date_to->isSameDay($d->date_from)) – {{ $d->date_to->format('j M') }}@endif</span>
                <span>{{ $num($d->days) }}<span x-text="$store.ui.lang==='en' ? 'd' : 'h'">d</span></span>
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
