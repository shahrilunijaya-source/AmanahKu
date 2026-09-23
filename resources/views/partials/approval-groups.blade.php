{{-- Requests on the Approvals tab, grouped under whoever asked. Anyone with two or
     more gets a header band (name, position, staff ID, total) that folds; everyone
     with a single request shares one plain table with a Requester column in front.

     Params: $items (collection, employee eager-loaded), $row (row view name),
             $rowWith (extra params for each row), $cols (list of [en, ms] headings),
             $grid (grid-template-columns for those headings plus a fixed-width actions cell, so every row lines up with the heading),
             $total (fn (Collection $group): string). --}}
@php
    $groups = $items->groupBy('employee_id');
    $singles = $groups->filter(fn ($g) => $g->count() === 1)->flatten(1);
@endphp

@foreach ($groups->filter(fn ($g) => $g->count() > 1) as $group)
    @php $e = $group->first()->employee; @endphp
    <div class="uj-card uj-ap-grp" x-data="{ open: true }" style="--ap-cols: {{ $grid }};">
        <button type="button" class="uj-ap-band" @click="open = !open" :aria-expanded="open">
            <span class="uj-ap-av" style="background:{{ $e?->avatar_color ?? '#3a6ea5' }};">{{ $e?->initials }}</span>
            <span class="uj-ap-who">
                <b>{{ $e?->display_name }}</b>
                <span>{{ $e?->position }}@if ($e?->position && $e?->staff_id) · @endif{{ $e?->staff_id }}</span>
            </span>
            <span class="uj-ap-total"><span x-text="$store.ui.lang==='en' ? 'Total' : 'Jumlah'">Total</span> <b>{{ $total($group) }}</b></span>
            <svg class="uj-ap-bchev" :data-open="open ? '' : null" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        <div x-show="open">
            <div class="uj-ap-row uj-ap-head">
                @foreach ($cols as [$en, $ms])<span x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</span>@endforeach
                <span></span>
            </div>
            @foreach ($group as $item)
                @include($row, $rowWith + ['item' => $item, 'showName' => false])
            @endforeach
        </div>
    </div>
@endforeach

@if ($singles->isNotEmpty())
    <div class="uj-card uj-ap-grp" style="--ap-cols: minmax(130px, 1.3fr) {{ $grid }};">
        <div class="uj-ap-row uj-ap-head">
            <span x-text="$store.ui.lang==='en' ? 'Requester' : 'Pemohon'">Requester</span>
            @foreach ($cols as [$en, $ms])<span x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</span>@endforeach
            <span></span>
        </div>
        @foreach ($singles as $item)
            @include($row, $rowWith + ['item' => $item, 'showName' => true])
        @endforeach
    </div>
@endif
