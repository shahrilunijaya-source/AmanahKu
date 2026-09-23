{{-- The Approvals tab's toolbar, shared by Leave and Claims: four status pills, then
     the filter bar (All or a date range, and a search).

     Params: $counts (['pending' => int, 'approved' => int, 'rejected' => int, 'cancelled' => int]),
             $filters (approvalFilters() from BuildsWorkData),
             $periodEn / $periodMs (what the range filters on: transaction date, apply period).

     The pills switch client-side through `st` on the parent panel. The filter bar is a
     plain GET form, so the server does the narrowing and the pill counts follow it;
     `st` rides along so the page comes back on the same pill. --}}
@php
    $pills = [
        'pending' => ['Pending', 'Menunggu', null],
        'approved' => ['Approved', 'Diluluskan', 'ok'],
        'rejected' => ['Rejected', 'Ditolak', 'no'],
        'cancelled' => ['Cancelled', 'Dibatalkan', 'warn'],
    ];
@endphp
<div class="uj-lv-stbar">
    @foreach ($pills as $key => [$en, $ms, $tone])
        <button type="button" class="uj-lv-stchip" @if ($tone) data-tone="{{ $tone }}" @endif
                :data-on="st === @js($key) ? '' : null" :aria-pressed="st === @js($key)" @click="st = @js($key)">
            <span x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</span>
            <b>{{ $counts[$key] }}</b>
        </button>
    @endforeach
</div>

<form method="get" class="uj-ap-bar" x-data="{ period: @js($filters['period']) }">
    <input type="hidden" name="tab" value="approvals">
    <input type="hidden" name="st" :value="st">
    <div class="uj-ap-row1">
        <div class="uj-ap-seg" role="radiogroup">
            <label>
                <input type="radio" name="period" value="all" x-model="period" @change="$el.form.requestSubmit()">
                <span x-text="$store.ui.lang==='en' ? 'All' : 'Semua'">All</span>
            </label>
            <label>
                <input type="radio" name="period" value="range" x-model="period" @change="$el.form.requestSubmit()">
                <span x-text="$store.ui.lang==='en' ? @js($periodEn) : @js($periodMs)">{{ $periodEn }}</span>
            </label>
        </div>
        <span class="uj-ap-dates" x-show="period === 'range'" @if ($filters['period'] !== 'range') x-cloak @endif>
            <input type="date" name="from" value="{{ $filters['from'] }}" @change="$el.form.requestSubmit()"
                   :aria-label="$store.ui.lang==='en' ? 'From' : 'Dari'">
            <span aria-hidden="true">–</span>
            <input type="date" name="to" value="{{ $filters['to'] }}" @change="$el.form.requestSubmit()"
                   :aria-label="$store.ui.lang==='en' ? 'To' : 'Hingga'">
        </span>
    </div>
    <input type="search" name="q" value="{{ $filters['q'] }}" class="uj-ap-q"
           :placeholder="$store.ui.lang==='en' ? 'Search name, staff ID or remark, then press Enter' : 'Cari nama, ID staf atau catatan, kemudian tekan Enter'"
           :aria-label="$store.ui.lang==='en' ? 'Search' : 'Cari'">
</form>
