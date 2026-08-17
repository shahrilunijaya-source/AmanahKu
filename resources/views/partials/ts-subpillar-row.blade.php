{{-- One sub-pillar row. Tenant-wide: it applies to every project, not to one.
     Shared by the initial render and the AJAX append on add. Expects $sp
     (SubPillar, with entries_count) and $canEdit. --}}
@php $canEdit = $canEdit ?? true; @endphp
<div style="display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--hairline-soft);" x-data="{ se: false }">
    <span style="flex:1;min-width:0;font-size:13.5px;font-weight:500;color:{{ $sp->is_active ? 'var(--ink)' : 'var(--muted)' }};">{{ $sp->name }}@unless ($sp->is_active) <span style="color:var(--muted);font-size:11px;">(<span x-text="$store.ui.lang==='en' ? 'inactive' : 'tidak aktif'">inactive</span>)</span>@endunless</span>
    <span style="font-size:11.5px;font-weight:500;color:var(--muted-soft);font-family:var(--font-mono);font-variant-numeric:tabular-nums;">
        <span style="font-weight:600;color:var(--muted);">{{ $sp->entries_count ?? 0 }}</span> <span x-text="$store.ui.lang==='en' ? 'timesheet lines' : 'baris lembaran masa'">timesheet lines</span>
    </span>
    @if ($canEdit)
        <button @click="se = ! se" type="button" class="uj-btn-ghost" style="height:28px;font-size:11.5px;padding:0 10px;"><span x-text="se ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
        <form method="post" action="{{ route('sub-pillars.delete', $sp) }}" onsubmit="return confirm('Delete or deactivate this sub-pillar?')">
            @csrf
            <button type="submit" class="uj-btn-ghost" style="height:28px;font-size:11.5px;padding:0 10px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
        </form>
        <div x-show="se" x-cloak style="flex-basis:100%;padding:8px 0 4px;">
            @include('partials.ts-subpillar-form', ['sub' => $sp, 'action' => route('sub-pillars.update', $sp), 'compact' => true])
        </div>
    @endif
</div>
