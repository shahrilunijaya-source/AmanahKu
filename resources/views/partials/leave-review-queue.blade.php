{{-- One review queue, with per-row detail, chronology and bulk actions.

     Params: $items (LeaveRequest collection, balances eager-loaded on employee),
             $mode ('verify' | 'approve').

     Lives on the Approvals tab now. Each row states the balance the requester is
     left with if you say yes — the number an approver actually needs, which the
     queue never carried before. --}}
@php
    $isVerify = $mode === 'verify';
    $actionRoute = $isVerify ? 'leave.verify' : 'leave.approve';
    $bulkRoute = $isVerify ? 'leave.bulk-verify' : 'leave.bulk-approve';
    $titleEn = $isVerify ? 'Yours to verify' : 'Waiting for final approval';
    $titleMs = $isVerify ? 'Untuk anda sahkan' : 'Menunggu kelulusan akhir';
    $btnEn = $isVerify ? 'Verify' : 'Approve';
    $btnMs = $isVerify ? 'Sahkan' : 'Luluskan';
    $bulkEn = $isVerify ? 'Verify selected' : 'Approve selected';
    $bulkMs = $isVerify ? 'Sahkan dipilih' : 'Luluskan dipilih';
    $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
@endphp
<div class="uj-card" x-data="{ sel: [], allIds: @js($items->pluck('id')->map(fn ($i) => (string) $i)->values()) }">
    <div class="uj-card-head">
        <div style="display:flex;align-items:center;gap:10px;">
            <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? @js($titleEn) : @js($titleMs)">{{ $titleEn }}</span></h3>
            <span class="uj-pill" style="background:var(--red-tint);color:var(--red-active);">{{ $items->count() }}</span>
        </div>
        <div class="uj-lv-bulk" x-show="sel.length" x-cloak>
            <span><span x-text="sel.length"></span> <span x-text="$store.ui.lang==='en' ? 'selected' : 'dipilih'">selected</span></span>
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
    </div>

    <label class="uj-lv-selall">
        <input type="checkbox" class="uj-lv-ck" @change="sel = $event.target.checked ? [...allIds] : []"
               :checked="allIds.length && sel.length === allIds.length">
        <span x-text="$store.ui.lang==='en' ? 'Select all {{ $items->count() }}' : 'Pilih semua {{ $items->count() }}'">Select all</span>
    </label>

    @foreach ($items as $a)
        @php
            // Which balance these days actually come off, and what is left after.
            $balTypeId = $a->leaveType?->effectiveBalanceTypeId() ?? $a->leave_type_id;
            $bal = $a->employee?->leaveBalances->firstWhere('leave_type_id', $balTypeId);
            $after = $bal ? max(0, (float) $bal->balance - (float) $a->days) : null;
        @endphp
        <div class="uj-lv-rw" x-data="{ open: false }" :data-open="open ? '' : null">
            <div class="uj-lv-ar">
                <input type="checkbox" class="uj-lv-ck" value="{{ $a->id }}" x-model="sel">
                <span style="flex:0 0 32px;height:32px;border-radius:50%;background:{{ $a->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:grid;place-items:center;font-size:var(--t-micro);font-weight:600;">{{ $a->employee?->initials }}</span>
                <button type="button" class="uj-lv-ar-t" @click="open = !open">
                    <span class="uj-lv-rw-1">
                        {{ $a->employee?->name }}
                        @if ($a->employee?->position)<span style="color:var(--muted);font-weight:400;">· {{ $a->employee->position }}</span>@endif
                    </span>
                    <span class="uj-lv-rw-2">
                        {{ $a->leaveType?->name }}
                        · {{ $a->date_from->format('j M') }}@if (! $a->date_from->isSameDay($a->date_to)) – {{ $a->date_to->format('j M') }}@endif
                        · <span x-text="$store.ui.lang==='en' ? '{{ $num($a->days) }} {{ (float) $a->days == 1 ? 'day' : 'days' }}' : '{{ $num($a->days) }} hari'">{{ $num($a->days) }}</span>
                        @if ($a->attachment_path)
                            · <span x-text="$store.ui.lang==='en' ? 'document attached' : 'ada dokumen'">document attached</span>
                        @endif
                        <svg class="uj-lv-chev" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </span>
                    <span class="uj-lv-after">
                        @if ($after !== null)
                            <span x-text="$store.ui.lang==='en' ? 'Leaves them' : 'Baki mereka'">Leaves them</span>
                            <b>{{ $num($after) }}</b>
                            <span x-text="$store.ui.lang==='en' ? '{{ $bal->leaveType?->name ?? '' }} days' : 'hari {{ $bal->leaveType?->name ?? '' }}'">days</span>
                        @else
                            <span x-text="$store.ui.lang==='en' ? 'No balance recorded for this type' : 'Tiada baki direkodkan untuk jenis ini'"></span>
                        @endif
                        @if (! $isVerify && $a->verifiedBy)
                            · <span x-text="$store.ui.lang==='en' ? 'verified by {{ $a->verifiedBy->name }}' : 'disahkan oleh {{ $a->verifiedBy->name }}'">verified by {{ $a->verifiedBy->name }}</span>
                        @endif
                    </span>
                </button>
                <span class="uj-lv-acts">
                    <form method="post" action="{{ route($actionRoute, $a) }}">
                        @csrf
                        <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:var(--t-sm);">
                            <span x-text="$store.ui.lang==='en' ? @js($btnEn) : @js($btnMs)">{{ $btnEn }}</span>
                        </button>
                    </form>
                    <form method="post" action="{{ route('leave.reject', $a) }}">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:var(--t-sm);">
                            <span x-text="$store.ui.lang==='en' ? 'Decline' : 'Tolak'">Decline</span>
                        </button>
                    </form>
                </span>
            </div>
            <div class="uj-lv-fold" :style="open ? 'grid-template-rows:1fr' : 'grid-template-rows:0fr'">
                <div><div class="uj-lv-rw-in">
                    @if ($a->reason)<div class="uj-lv-quote">“{{ $a->reason }}”</div>@endif
                    @if ($a->attachment_path)
                        <div style="margin-bottom:11px;">
                            <a href="{{ route('leave.attachment', $a) }}" style="font-size:var(--t-sm);color:var(--red);text-decoration:none;">
                                <span x-text="$store.ui.lang==='en' ? 'Open supporting document' : 'Buka dokumen sokongan'">Open supporting document</span>
                                — {{ $a->attachment_name }}
                            </a>
                        </div>
                    @endif
                    @include('partials.leave-timeline', ['r' => $a])
                </div></div>
            </div>
        </div>
    @endforeach
</div>
