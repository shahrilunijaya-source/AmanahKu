{{-- Superior/management review queue with per-row detail + chronology and bulk actions.
     Params: $items (LeaveRequest collection), $mode ('verify' | 'approve'). --}}
@php
    $isVerify = $mode === 'verify';
    $actionRoute = $isVerify ? 'leave.verify' : 'leave.approve';
    $bulkRoute = $isVerify ? 'leave.bulk-verify' : 'leave.bulk-approve';
    $titleEn = $isVerify ? 'To verify (your team)' : 'To approve (verified)';
    $titleMs = $isVerify ? 'Untuk disahkan (pasukan anda)' : 'Untuk diluluskan (disahkan)';
    $btnEn = $isVerify ? 'Verify' : 'Approve';
    $btnMs = $isVerify ? 'Sahkan' : 'Luluskan';
    $bulkEn = $isVerify ? 'Verify selected' : 'Approve selected';
    $bulkMs = $isVerify ? 'Sahkan dipilih' : 'Luluskan dipilih';
    $pillBg = $isVerify ? 'var(--amber-tint,#fbf3e6)' : 'var(--red-tint)';
    $pillFg = $isVerify ? '#7a5418' : 'var(--red)';
@endphp
<div class="uj-card" style="margin-bottom:16px;" x-data="{ sel: [], allIds: @js($items->pluck('id')->map(fn ($i) => (string) $i)->values()) }">
    <div class="uj-card-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? '{{ $titleEn }}' : '{{ $titleMs }}'">{{ $titleEn }}</span></h3>
            <span class="uj-pill" style="background:{{ $pillBg }};color:{{ $pillFg }};">{{ $items->count() }}</span>
        </div>
        {{-- Bulk action bar — appears once something is selected. --}}
        <div x-show="sel.length" x-cloak style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:12px;color:var(--muted);"><span x-text="sel.length"></span> <span x-text="$store.ui.lang==='en' ? 'selected' : 'dipilih'">selected</span></span>
            <button type="button" @click="sel = []" style="background:none;border:none;font-size:12px;color:var(--muted);cursor:pointer;"><span x-text="$store.ui.lang==='en' ? 'Clear' : 'Kosongkan'">Clear</span></button>
            <form method="post" action="{{ route($bulkRoute) }}">
                @csrf
                <template x-for="id in sel" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                <button type="submit" class="uj-btn-primary" style="height:32px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? '{{ $bulkEn }}' : '{{ $bulkMs }}'">{{ $bulkEn }}</span> (<span x-text="sel.length"></span>)</button>
            </form>
        </div>
    </div>

    {{-- Select-all header --}}
    <label style="display:flex;align-items:center;gap:10px;padding:8px 20px;border-bottom:1px solid var(--hairline-soft);cursor:pointer;font-size:11.5px;color:var(--muted);">
        <input type="checkbox" @change="sel = $event.target.checked ? [...allIds] : []" :checked="allIds.length && sel.length === allIds.length" style="width:15px;height:15px;cursor:pointer;">
        <span x-text="$store.ui.lang==='en' ? 'Select all' : 'Pilih semua'">Select all</span>
    </label>

    @foreach ($items as $a)
        <div x-data="{ open: false }" style="border-bottom:1px solid var(--hairline-soft);">
            <div style="display:flex;align-items:center;gap:12px;padding:12px 20px;">
                <input type="checkbox" value="{{ $a->id }}" x-model="sel" style="width:15px;height:15px;cursor:pointer;flex-shrink:0;">
                <div style="width:32px;height:32px;border-radius:50%;background:{{ $a->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $a->employee?->initials }}</div>
                <div @click="open = !open" style="flex:1;min-width:0;cursor:pointer;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $a->employee?->name }}</div>
                    <div style="font-size:12px;color:var(--muted);">
                        {{ $a->leaveType?->name }} · {{ $a->date_from->format('j M') }}–{{ $a->date_to->format('j M') }} · {{ $a->days }}d
                        @if ($a->attachment_path)· 📎@endif
                        <span x-text="open ? '▾' : '▸'" style="color:var(--muted-soft);">▸</span>
                    </div>
                    @if (! $isVerify && $a->verifiedBy)<div style="font-size:11px;color:var(--success);">{{ $a->verifiedBy->name }} <span x-text="$store.ui.lang==='en' ? 'verified' : 'sahkan'">verified</span></div>@endif
                </div>
                <form method="post" action="{{ route($actionRoute, $a) }}">@csrf<button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? '{{ $btnEn }}' : '{{ $btnMs }}'">{{ $btnEn }}</span></button></form>
                <form method="post" action="{{ route('leave.reject', $a) }}">@csrf<button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Reject' : 'Tolak'">Reject</span></button></form>
            </div>
            {{-- Detail + chronology --}}
            <div x-show="open" x-collapse style="padding:2px 20px 14px 62px;">
                @if ($a->reason)<div style="font-size:12px;color:var(--muted);margin-bottom:10px;font-style:italic;">“{{ $a->reason }}”</div>@endif
                @if ($a->attachment_path)<div style="margin-bottom:10px;"><a href="{{ route('leave.attachment', $a) }}" style="font-size:11.5px;color:var(--info);text-decoration:none;">📎 <span x-text="$store.ui.lang==='en' ? 'View supporting document' : 'Lihat dokumen sokongan'">View supporting document</span></a></div>@endif
                @include('partials.leave-timeline', ['r' => $a])
            </div>
        </div>
    @endforeach
</div>
