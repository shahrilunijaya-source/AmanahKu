{{-- One leave request on the Approvals tab: a table row (see partials.approval-groups)
     that folds open for the reason, the document and the timeline.

     Params: $item (LeaveRequest), $showName (lead with the requester's name),
             $mode ('verify' | 'approve' for a pending row, null for a settled one).
     A pending row also carries the bulk-select checkbox (`sel` on the queue wrapper)
     and the balance the person is left with if you say yes. --}}
@php
    $a = $item;
    $act = ['verify' => ['leave.verify', 'Verify', 'Sahkan'], 'approve' => ['leave.approve', 'Approve', 'Luluskan']][$mode ?? ''] ?? null;
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    if ($act) {
        // Which balance these days actually come off, and what is left after.
        $balTypeId = $a->leaveType?->effectiveBalanceTypeId() ?? $a->leave_type_id;
        $bal = $a->employee?->leaveBalances->firstWhere('leave_type_id', $balTypeId);
        $after = $bal ? max(0, (float) $bal->balance - (float) $a->days) : null;
    }
@endphp
<div class="uj-lv-rw" x-data="{ open: false }" :data-open="open ? '' : null">
    <div class="uj-ap-row">
        @if ($showName)
            <span class="uj-ap-name">{{ $a->employee?->display_name }}@if ($a->employee?->staff_id)<small>{{ $a->employee->staff_id }}</small>@endif</span>
        @endif
        <span>{{ $a->date_from?->format('d/m/Y') }}@if ($a->date_to && ! $a->date_to->isSameDay($a->date_from)) – {{ $a->date_to->format('d/m/Y') }}@endif</span>
        <span>
            {{ $a->leaveType?->name }}
            @if ($act)
                <small class="uj-ap-sub">
                    @if ($after !== null)
                        <span x-text="$store.ui.lang==='en' ? 'Leaves them' : 'Baki mereka'">Leaves them</span> <b>{{ $num($after) }}</b>
                    @elseif ($a->leaveType?->deducts_from_leave_type_id)
                        <span x-text="$store.ui.lang==='en' ? 'No paid balance, approving makes this unpaid leave' : 'Tiada baki berbayar, kelulusan menjadikannya cuti tanpa gaji'"></span>
                    @endif
                </small>
            @endif
        </span>
        <span class="uj-ap-num">{{ $num($a->days) }}</span>
        <span class="uj-ap-rem">
            {{ $a->reason }}
            @if ($mode === 'approve' && $a->verifiedBy)
                <small class="uj-ap-sub" x-text="$store.ui.lang==='en' ? 'verified by {{ $a->verifiedBy->name }}' : 'disahkan oleh {{ $a->verifiedBy->name }}'">verified by {{ $a->verifiedBy->name }}</small>
            @endif
        </span>
        <span class="uj-ap-acts">
            @if ($act)
                <input type="checkbox" class="uj-lv-ck" value="{{ $a->id }}" x-model="sel"
                       :aria-label="$store.ui.lang==='en' ? 'Select' : 'Pilih'">
                <form method="post" action="{{ route($act[0], $a) }}" id="lv-act-{{ $a->id }}">
                    @csrf
                    <button type="submit" class="uj-btn-primary uj-ap-btn">
                        <span x-text="$store.ui.lang==='en' ? @js($act[1]) : @js($act[2])">{{ $act[1] }}</span>
                    </button>
                </form>
                <form method="post" action="{{ route('leave.reject', $a) }}">
                    @csrf
                    <button type="submit" class="uj-btn-ghost uj-ap-btn">
                        <span x-text="$store.ui.lang==='en' ? 'Decline' : 'Tolak'">Decline</span>
                    </button>
                </form>
            @endif
            @if ($a->attachment_path)
                {{-- Opens in a tab and renders there (the route streams inline), so an MC
                     is read before the decision instead of landing in Downloads. --}}
                <a class="uj-ap-ic" href="{{ route('leave.attachment', $a) }}" target="_blank" rel="noopener"
                   :title="$store.ui.lang==='en' ? 'Preview supporting document' : 'Pratonton dokumen sokongan'"
                   :aria-label="$store.ui.lang==='en' ? 'Preview supporting document' : 'Pratonton dokumen sokongan'">
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
            @if ($mode === 'approve' && $a->verify_note)
                <div class="uj-lv-quote" style="margin-bottom:11px;">
                    “{{ $a->verify_note }}”
                    <span style="display:block;color:var(--muted);font-size:var(--t-sm);">— {{ $a->verifiedBy?->name }}</span>
                </div>
            @endif
            @if ($mode === 'verify')
                {{-- Posts with the Verify button: the action form is a sibling of this fold,
                     so the field joins it by `form=` rather than restructuring the row. --}}
                <label class="uj-lv-field" for="lv-note-{{ $a->id }}">
                    <span x-text="$store.ui.lang==='en' ? 'Your comment' : 'Komen anda'">Your comment</span>
                    <span class="uj-lv-opt" x-text="$store.ui.lang==='en' ? '— optional, seen by the approver' : '— pilihan, dilihat oleh pelulus'"></span>
                </label>
                <textarea class="uj-lv-in" id="lv-note-{{ $a->id }}" form="lv-act-{{ $a->id }}"
                          name="verify_note" rows="2" maxlength="500" style="margin-bottom:11px;"
                          :placeholder="$store.ui.lang==='en' ? 'Anything management should know before approving.' : 'Apa-apa yang pengurusan patut tahu sebelum meluluskan.'"></textarea>
            @endif
            @include('partials.leave-timeline', ['r' => $a])
        </div></div>
    </div>
</div>
