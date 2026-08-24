{{-- One ticket's summary row — click opens the detail as a side drawer (partials/ticket-drawer),
     same slide-over grammar as the Knowledge Bank / T.O.T. panels, instead of an inline fold.
     Used by both the privileged board (screens/helpdesk.blade.php) and My tickets
     (partials/my-tickets.blade.php); $privileged switches the employee name in the subline and
     the manage form in the drawer. Shares $categoryMeta/$statusTone/$statusMeta/$statusMs/
     $priorityColor/$statuses/$employees from the parent screen's PHP block. --}}
@php
    $cm = $categoryMeta[$t->category] ?? $categoryMeta['Other'];
@endphp
<div class="uj-lv-rw" x-data="{ drawerOpen: {{ $privileged && $errors->any() && (int) old('_ticket') === $t->id ? 'true' : 'false' }} }">
    <button type="button" class="uj-lv-rw-head" @click="drawerOpen = true">
        <span class="uj-lv-rw-ico" style="background:{{ $cm['bg'] }};color:{{ $cm['tint'] }};">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $cm['icon'] !!}</svg>
        </span>
        <span class="uj-lv-rw-t">
            <span class="uj-lv-rw-1">{{ $t->subject }}</span>
            <span class="uj-lv-rw-2">
                @if ($privileged)
                    @if ($t->employee?->name){{ $t->employee->display_name }}@else<span x-text="$store.ui.lang==='en' ? 'Unknown' : 'Tidak diketahui'">Unknown</span>@endif ·
                @endif
                {{ $t->category }} · <span style="color:{{ $priorityColor[$t->priority] ?? 'var(--muted)' }};font-weight:600;">{{ ucfirst($t->priority) }}</span> ·
                {{ $t->created_at?->format('j M Y') }}
                @if ($t->assignee) · → {{ $t->assignee->name }}@endif
            </span>
        </span>
        <span class="uj-stamp" @if ($statusTone[$t->status] ?? null) data-tone="{{ $statusTone[$t->status] }}" @endif
              x-text="$store.ui.lang==='en' ? @js($statusMeta[$t->status]['label'] ?? ucfirst($t->status)) : @js($statusMs[$t->status] ?? ucfirst($t->status))">{{ $statusMeta[$t->status]['label'] ?? ucfirst($t->status) }}</span>
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;color:var(--muted-soft);"><path d="M9 6l6 6-6 6"/></svg>
    </button>

    @include('partials.ticket-drawer', ['t' => $t, 'cm' => $cm, 'privileged' => $privileged])
</div>
