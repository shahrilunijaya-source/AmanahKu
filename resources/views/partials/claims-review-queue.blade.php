{{-- One list of claims on the Approvals tab, grouped by requester.

     Params: $items (Claim collection, employee eager-loaded; verifiedBy for the timeline),
             $mode ('verify' | 'approve' for a pending queue, null for a settled list),
             $title ([en, ms], optional heading). Claims has no bulk route, so each
             pending row acts on its own. --}}
@if (! empty($title))
    <div class="uj-ap-qhead">
        <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? @js($title[0]) : @js($title[1])">{{ $title[0] }}</span></h3>
        <span class="uj-pill">{{ $items->count() }}</span>
    </div>
@endif
@include('partials.approval-groups', [
    'items' => $items,
    'row' => 'partials.claims-approval-row',
    'rowWith' => ['mode' => $mode ?? null],
    'cols' => [['Trans. date', 'Tarikh urus niaga'], ['Claim item', 'Item tuntutan'], ['Remark', 'Catatan'], ['Amount (RM)', 'Jumlah (RM)']],
    'grid' => '96px minmax(90px, .8fr) minmax(160px, 2.4fr) 96px '.($mode ?? null ? '220px' : '72px'),
    'total' => fn ($group) => number_format((float) $group->sum('amount'), 2),
])
