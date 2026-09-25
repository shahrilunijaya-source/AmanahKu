{{-- One list of leave requests on the Approvals tab, grouped by requester.

     Params: $items (LeaveRequest collection; balances eager-loaded on employee for a
             pending queue), $mode ('verify' | 'approve' for a pending queue, null for a
             settled list), $title ([en, ms], optional heading), $showWhere (add a
             "Where it is" column; an approve queue with it may hold still-submitted rows,
             verifiable when their id is in $verifyIds, read-only otherwise).

     A pending queue carries the bulk actions: tick rows (or all of them) and verify or
     approve them in one go. --}}
@php
    $mode ??= null;
    $showWhere ??= false;
    $verifyIds ??= [];
    // Only rows at the stage this queue acts on can be ticked.
    $actionable = $mode === 'approve' ? $items->where('status', 'verified') : $items;
    $isVerify = $mode === 'verify';
    $bulkRoute = $isVerify ? 'leave.bulk-verify' : 'leave.bulk-approve';
    $bulkEn = $isVerify ? 'Verify selected' : 'Approve selected';
    $bulkMs = $isVerify ? 'Sahkan dipilih' : 'Luluskan dipilih';
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
@endphp
<div class="uj-tab-stack" @if ($mode) x-data="{ sel: [], allIds: @js($actionable->pluck('id')->map(fn ($i) => (string) $i)->values()) }" @endif>
    @if (! empty($title) || $mode)
        <div class="uj-ap-qhead">
            @if (! empty($title))
                <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? @js($title[0]) : @js($title[1])">{{ $title[0] }}</span></h3>
                <span class="uj-pill">{{ $items->count() }}</span>
            @endif
            @if ($mode && $actionable->isNotEmpty())
                <label class="uj-ap-selall">
                    <input type="checkbox" class="uj-lv-ck" @change="sel = $event.target.checked ? [...allIds] : []"
                           :checked="allIds.length && sel.length === allIds.length">
                    <span x-text="$store.ui.lang==='en' ? 'Select all {{ $actionable->count() }}' : 'Pilih semua {{ $actionable->count() }}'">Select all</span>
                </label>
                <div class="uj-lv-bulk" x-show="sel.length" x-cloak>
                    <button type="button" class="uj-lv-more" style="margin:0;" @click="sel = []"
                            x-text="$store.ui.lang==='en' ? 'Clear' : 'Kosongkan'">Clear</button>
                    <form method="post" action="{{ route($bulkRoute) }}">
                        @csrf
                        <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                        <button type="submit" class="uj-btn-primary" style="height:32px;padding:0 14px;font-size:var(--t-sm);">
                            <span x-text="$store.ui.lang==='en' ? @js($bulkEn) : @js($bulkMs)">{{ $bulkEn }}</span>
                            (<span x-text="sel.length"></span>)
                        </button>
                    </form>
                </div>
            @endif
        </div>
    @endif
    @include('partials.approval-groups', [
        'items' => $items,
        'row' => 'partials.leave-approval-row',
        'rowWith' => ['mode' => $mode, 'showWhere' => $showWhere, 'verifyIds' => $verifyIds],
        'cols' => [['Apply period', 'Tempoh cuti'], ['Leave type', 'Jenis cuti'], ['Days', 'Hari'], ['Reason', 'Sebab'], ...($showWhere ? [['Where it is', 'Di mana']] : [])],
        // The extra column has to come out of the others, or the action buttons get pushed off the card.
        'grid' => $showWhere
            ? 'minmax(100px, 1.2fr) minmax(80px, 1fr) 44px minmax(90px, 2fr) minmax(100px, 1.3fr) '.($mode ? '244px' : '72px')
            : 'minmax(150px, 1.3fr) minmax(110px, 1fr) 56px minmax(140px, 2fr) '.($mode ? '244px' : '72px'),
        'total' => fn ($group) => $num($group->sum('days')).' '.((float) $group->sum('days') == 1 ? 'day' : 'days'),
    ])
</div>
