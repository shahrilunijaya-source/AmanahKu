{{-- One project card with its sub-pillars. Shared by the initial render and the AJAX
     append on add. Expects $project (Project, with subPillars loaded). --}}
<div class="uj-card" style="padding:16px 20px;margin-bottom:12px;{{ $project->is_active ? '' : 'opacity:.6;' }}" x-data="{ edit: false, sub: false }">
    <div style="display:flex;gap:12px;align-items:center;">
        <span style="width:34px;height:34px;border-radius:8px;background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);font-size:11px;font-weight:600;font-family:var(--font-mono);display:flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $project->code ?: '—' }}</span>
        <div style="flex:1;min-width:0;">
            <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $project->name }}</div>
            <div style="font-size:11.5px;color:var(--muted);"><span data-sub-count>{{ $project->subPillars->count() }}</span> <span x-text="$store.ui.lang==='en' ? 'sub-pillars' : 'sub-tiang'">sub-pillars</span>@unless ($project->is_active) · <span x-text="$store.ui.lang==='en' ? 'inactive' : 'tidak aktif'">inactive</span>@endunless</div>
        </div>
        <button @click="sub = ! sub" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="sub ? ($store.ui.lang==='en' ? 'Hide sub-pillars' : 'Sembunyi sub-tiang') : ($store.ui.lang==='en' ? 'Sub-pillars' : 'Sub-tiang')">Sub-pillars</span></button>
        <button @click="edit = ! edit" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
        <form method="post" action="{{ route('timesheet.admin.projects.delete', $project) }}" onsubmit="return confirm('Delete or deactivate this project?')">
            @csrf
            <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
        </form>
    </div>

    {{-- Edit project --}}
    <div x-show="edit" x-cloak style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
        @include('partials.ts-project-form', ['project' => $project, 'action' => route('timesheet.admin.projects.update', $project), 'submitLabel' => 'Save changes'])
    </div>

    {{-- Sub-pillars --}}
    <div x-show="sub" x-cloak style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
        <div id="ts-subs-{{ $project->id }}">
            @foreach ($project->subPillars as $sp)
                @include('partials.ts-subpillar-row', ['sp' => $sp])
            @endforeach
        </div>
        <div style="margin-top:12px;">
            @include('partials.ts-subpillar-form', ['sub' => null, 'action' => route('timesheet.admin.subpillars.store', $project), 'submitLabel' => '+ Add', 'compact' => false, 'ajaxTarget' => '#ts-subs-'.$project->id])
        </div>
    </div>
</div>
