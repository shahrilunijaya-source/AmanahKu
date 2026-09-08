{{-- One project row on the Projects register. Shared by the initial render and the
     AJAX append on add. Expects $project (with categories + versions.createdBy loaded),
     $categories (full list, for the edit form), $canEdit, $employees (for the PM/PE
     pickers), $editableFields (the viewer's writable master fields) and $canReopen. --}}
@php
    $canEdit = $canEdit ?? false;
    $employees = $employees ?? collect();
    $editableFields = $editableFields ?? [];
    $canReopen = $canReopen ?? false;
    $hay = mb_strtolower(trim($project->name.' '.$project->code.' '.$project->project_code.' '.$project->client));
    $catIds = $project->categories->pluck('id')->all();
    $statusColours = ['planning' => 'var(--muted)', 'active' => 'var(--info)', 'closed' => 'var(--error)'];
    // History shows people by name, not employee id.
    $peopleNames = collect($employees)->pluck('display_name', 'id')->all();
@endphp
<div class="uj-card" style="padding:15px 18px;margin-bottom:10px;{{ $project->is_active ? '' : 'background:var(--canvas);' }}"
     x-data="{ edit: false, history: false }"
     {{-- Registers this row in the parent's `items` index (search/empty-state banner)
          on both the initial render and an AJAX-appended row (Alpine.initTree runs
          x-init same as first paint) — no separate server-built index to fall stale. --}}
     x-init="items.push({ hay: @js($hay), active: @js($project->is_active), cats: @js($catIds) })"
     x-show="(showOff || @js($project->is_active)) && @js($hay).includes(q.toLowerCase())
             && (! cats.length || @js($catIds).some(c => cats.includes(c)))">
    <div style="display:flex;gap:13px;align-items:center;flex-wrap:wrap;">
        @if ($project->code)
            <span style="width:36px;height:36px;border-radius:9px;background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);font-size:11px;font-weight:600;font-family:var(--font-mono);display:flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $project->code }}</span>
        @endif
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:14px;color:{{ $project->is_active ? 'var(--ink)' : 'var(--muted)' }};font-weight:500;">{{ $project->name }}</span>
                @if ($project->status)
                    <span class="uj-stamp" style="color:{{ $statusColours[$project->status] ?? 'var(--muted)' }};border-color:{{ $statusColours[$project->status] ?? 'var(--hairline)' }};">{{ ucfirst($project->status) }}</span>
                @endif
            </div>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin-top:5px;font-size:11.5px;color:var(--muted);">
                @if ($project->client)
                    <span>{{ $project->client }}</span>
                @endif
                @if ($project->contract_value !== null)
                    <span>·</span>
                    <span style="font-family:var(--font-mono);">RM {{ number_format((float) $project->contract_value, 2) }}</span>
                @endif
                @if ($project->pm || $project->pe)
                    <span>·</span>
                    <span>PM: {{ $project->pm?->display_name ?? '—' }} / PE: {{ $project->pe?->display_name ?? '—' }}</span>
                @endif
                @unless ($project->is_active)
                    <span class="uj-stamp"><span x-text="$store.ui.lang==='en' ? 'Archived' : 'Diarkibkan'">Archived</span></span>
                @endunless
                @foreach ($project->categories as $cat)
                    <span class="uj-pill" style="background:color-mix(in srgb, {{ $cat->colour() }} 13%, var(--card));color:{{ $cat->colour() }};">{{ $cat->name }}</span>
                @endforeach
            </div>
        </div>
        @if ($project->versions->isNotEmpty())
            <button @click="history = ! history" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="history ? ($store.ui.lang==='en' ? 'Hide history' : 'Sembunyi sejarah') : ($store.ui.lang==='en' ? 'History' : 'Sejarah')">History</span></button>
        @endif
        @if ($canEdit)
            <button @click="edit = ! edit" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
            <form method="post" action="{{ route('projects.archive', $project) }}">
                @csrf
                <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;">
                    @if ($project->is_active)
                        <span x-text="$store.ui.lang==='en' ? 'Archive' : 'Arkibkan'">Archive</span>
                    @else
                        <span x-text="$store.ui.lang==='en' ? 'Restore' : 'Pulihkan'">Restore</span>
                    @endif
                </button>
            </form>
            <form method="post" action="{{ route('projects.delete', $project) }}" onsubmit="return confirm('Delete or deactivate this project?')">
                @csrf
                <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
            </form>
        @endif
    </div>

    @if ($project->isClosed() && $canReopen)
        <form method="post" action="{{ route('projects.reopen', $project) }}" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--hairline-soft);display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            @csrf
            <div style="flex:1;min-width:200px;">
                <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Reopen reason (required)' : 'Sebab buka semula (diperlukan)'">Reopen reason (required)</span></label>
                <input name="reason" required maxlength="500" placeholder="Extension signed" style="width:100%;height:36px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
            </div>
            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Reopen' : 'Buka semula'">Reopen</span></button>
        </form>
    @endif

    @if ($project->versions->isNotEmpty())
        <div x-show="history" x-cloak style="margin-top:12px;padding-top:12px;border-top:1px solid var(--hairline-soft);display:flex;flex-direction:column;gap:8px;">
            @foreach ($project->versions->sortByDesc('version_no') as $version)
                <div style="font-size:12px;color:var(--body);">
                    <span style="font-weight:600;">v{{ $version->version_no }}</span>
                    <span style="color:var(--muted);">— {{ $version->effective_date->format('Y-m-d') }} — {{ $version->createdBy?->name ?? 'System' }}</span>
                    @if ($version->changes)
                        <div style="margin-top:2px;color:var(--muted);">
                            @foreach ($version->changes as $field => $delta)
                                @php $show = fn ($v) => $v === null || $v === '' ? '—' : (in_array($field, ['pm_id', 'pe_id'], true) ? ($peopleNames[$v] ?? $v) : $v); @endphp
                                <span>{{ \App\Projects\ProjectMaster::label($field) }}: {{ $show($delta['old'] ?? null) }} → {{ $show($delta['new'] ?? null) }}</span>@if (! $loop->last), @endif
                            @endforeach
                        </div>
                    @endif
                    @if ($version->reason)
                        <div style="margin-top:2px;font-style:italic;color:var(--muted);">{{ $version->reason }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($canEdit)
        <div x-show="edit" x-cloak style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
            @include('partials.ts-project-form', ['project' => $project, 'action' => route('projects.update', $project), 'categories' => $categories, 'employees' => $employees, 'editableFields' => $editableFields])
        </div>
    @endif
</div>
