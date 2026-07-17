{{-- One category card. Shared by the initial render and the AJAX append on add.
     Expects $cat (TimesheetCategory). --}}
<div class="uj-card" style="padding:14px 20px;margin-bottom:10px;{{ $cat->is_active ? '' : 'opacity:.6;' }}" x-data="{ edit: false }">
    <div style="display:flex;gap:12px;align-items:center;">
        <div style="flex:1;min-width:0;">
            <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $cat->name }}<span style="color:var(--muted-soft);font-weight:400;"> · {{ $cat->name_ms }}</span></div>
            <div style="display:flex;gap:8px;margin-top:4px;">
                @if ($cat->requires_project)
                    <span class="uj-pill" style="background:var(--red-tint);color:var(--red);font-size:10.5px;"><span x-text="$store.ui.lang==='en' ? 'Needs project' : 'Perlu projek'">Needs project</span></span>
                @else
                    <span class="uj-pill" style="background:var(--canvas);color:var(--muted);font-size:10.5px;"><span x-text="$store.ui.lang==='en' ? 'Standalone' : 'Sendiri'">Standalone</span></span>
                @endif
                @unless ($cat->is_active)<span class="uj-pill" style="background:var(--canvas);color:var(--muted);font-size:10.5px;"><span x-text="$store.ui.lang==='en' ? 'Inactive' : 'Tidak aktif'">Inactive</span></span>@endunless
            </div>
        </div>
        <button @click="edit = ! edit" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
        <form method="post" action="{{ route('timesheet.admin.categories.delete', $cat) }}" onsubmit="return confirm('Delete or deactivate this category?')">
            @csrf
            <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
        </form>
    </div>
    <div x-show="edit" x-cloak style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
        @include('partials.ts-category-form', ['category' => $cat, 'action' => route('timesheet.admin.categories.update', $cat), 'submitLabel' => 'Save changes'])
    </div>
</div>
