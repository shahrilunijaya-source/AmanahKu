{{-- One sub-pillar row inside a project card. Shared by the initial render and the
     AJAX append on add, so the two never drift. Expects $sp (ProjectSubPillar). --}}
<div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--hairline-soft);" x-data="{ se: false }">
    <span style="flex:1;min-width:0;font-size:12.5px;color:var(--ink);{{ $sp->is_active ? '' : 'color:var(--muted);' }}">{{ $sp->name }}@unless ($sp->is_active) <span style="color:var(--muted-soft);font-size:11px;">(<span x-text="$store.ui.lang==='en' ? 'inactive' : 'tidak aktif'">inactive</span>)</span>@endunless</span>
    <button @click="se = ! se" type="button" class="uj-btn-ghost" style="height:28px;font-size:11.5px;padding:0 10px;"><span x-text="se ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
    <form method="post" action="{{ route('timesheet.admin.subpillars.delete', $sp) }}" onsubmit="return confirm('Delete or deactivate this sub-pillar?')">
        @csrf
        <button type="submit" class="uj-btn-ghost" style="height:28px;font-size:11.5px;padding:0 10px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
    </form>
    <div x-show="se" x-cloak style="flex-basis:100%;padding:8px 0 4px;">
        @include('partials.ts-subpillar-form', ['sub' => $sp, 'action' => route('timesheet.admin.subpillars.update', $sp), 'submitLabel' => 'Save', 'compact' => true])
    </div>
</div>
