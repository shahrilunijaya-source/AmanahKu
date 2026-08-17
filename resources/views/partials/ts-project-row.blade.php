{{-- One project row on the Projects register. Shared by the initial render and the
     AJAX append on add. Expects $project (with categories loaded and entries_count /
     work_items_count), $categories (full list, for the edit form) and $canEdit. --}}
@php
    $canEdit = $canEdit ?? false;
    $hay = mb_strtolower(trim($project->name.' '.$project->code));
@endphp
<div class="uj-card" style="padding:15px 18px;margin-bottom:10px;{{ $project->is_active ? '' : 'background:var(--canvas);' }}"
     x-data="{ edit: false }"
     {{-- Registers this row in the parent's `items` index (search/empty-state banner)
          on both the initial render and an AJAX-appended row (Alpine.initTree runs
          x-init same as first paint) — no separate server-built index to fall stale. --}}
     x-init="items.push({ hay: @js($hay), active: @js($project->is_active) })"
     x-show="(showOff || @js($project->is_active)) && @js($hay).includes(q.toLowerCase())">
    <div style="display:flex;gap:13px;align-items:center;">
        @if ($project->code)
            <span style="width:36px;height:36px;border-radius:9px;background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);font-size:11px;font-weight:600;font-family:var(--font-mono);display:flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $project->code }}</span>
        @endif
        <div style="flex:1;min-width:0;">
            <div style="font-size:14px;color:{{ $project->is_active ? 'var(--ink)' : 'var(--muted)' }};font-weight:500;">{{ $project->name }}</div>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin-top:5px;">
                @unless ($project->is_active)
                    <span class="uj-stamp"><span x-text="$store.ui.lang==='en' ? 'Inactive' : 'Tidak aktif'">Inactive</span></span>
                @endunless
                @foreach ($project->categories as $cat)
                    <span class="uj-pill" style="background:var(--canvas);color:var(--muted);">{{ $cat->name }}</span>
                @endforeach
                <span style="font-size:11.5px;font-weight:500;color:var(--muted-soft);font-family:var(--font-mono);font-variant-numeric:tabular-nums;">
                    @if ($project->entries_count || $project->work_items_count)
                        <span style="font-weight:600;color:var(--muted);">{{ $project->entries_count }}</span> <span x-text="$store.ui.lang==='en' ? 'timesheet lines' : 'baris lembaran masa'">timesheet lines</span>
                        · <span style="font-weight:600;color:var(--muted);">{{ $project->work_items_count }}</span> <span x-text="$store.ui.lang==='en' ? 'board cards' : 'kad papan'">board cards</span>
                    @else
                        <span x-text="$store.ui.lang==='en' ? 'not used yet' : 'belum digunakan'">not used yet</span>
                    @endif
                </span>
            </div>
        </div>
        @if ($canEdit)
            <button @click="edit = ! edit" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
            <form method="post" action="{{ route('projects.delete', $project) }}" onsubmit="return confirm('Delete or deactivate this project?')">
                @csrf
                <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
            </form>
        @endif
    </div>

    @if ($canEdit)
        <div x-show="edit" x-cloak style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
            @include('partials.ts-project-form', ['project' => $project, 'action' => route('projects.update', $project), 'categories' => $categories])
        </div>
    @endif
</div>
