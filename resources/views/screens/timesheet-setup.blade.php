@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'timesheet-setup',
    'en'  => [
        'title' => 'Timesheet setup',
        'body'  => 'Manage the building blocks staff pick from when they allocate their week: categories, projects, and the sub-pillars under each project. Mark a category "requires a project" (like Development or Maintenance) and staff must choose a project for it; others (Sales, On Leave, Public Holiday…) stand alone.',
        'who'   => 'HR & Management',
        'steps' => [
            'Add or edit the categories everyone sees in the dropdown — tick "requires a project" for delivery work.',
            'Add your projects (e.g. "KPT: RMS"), then add sub-pillars under each one (e.g. Frontend, Backend).',
            'Anything already used on a timesheet is deactivated instead of deleted, so reports keep their history.',
        ],
    ],
    'ms'  => [
        'title' => 'Tetapan lembaran masa',
        'body'  => 'Urus blok binaan yang dipilih staf semasa memperuntukkan minggu mereka: kategori, projek, dan sub-tiang di bawah setiap projek. Tandakan kategori "memerlukan projek" (seperti Pembangunan atau Penyelenggaraan) dan staf mesti memilih projek untuknya; yang lain (Jualan, Bercuti, Cuti Umum…) berdiri sendiri.',
        'who'   => 'HR & Pengurusan',
        'steps' => [
            'Tambah atau sunting kategori yang dilihat semua orang — tandakan "memerlukan projek" untuk kerja penghantaran.',
            'Tambah projek anda (cth. "KPT: RMS"), kemudian tambah sub-tiang di bawah setiap satu (cth. Frontend, Backend).',
            'Apa-apa yang telah digunakan pada lembaran masa akan dinyahaktifkan, bukan dipadam, supaya laporan kekal sejarahnya.',
        ],
    ],
])

{{-- ============================ CATEGORIES ============================ --}}
<div style="display:flex;align-items:center;gap:9px;margin:0 0 11px;">
    <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Categories' : 'Kategori'">Categories</span></h2>
    <span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;">{{ $categories->count() }}</span>
</div>

<div class="uj-card" style="padding:0;margin-bottom:14px;" x-data="{ open: false }">
    <button @click="open = ! open" type="button" style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;background:none;cursor:pointer;border:0;">
        <span style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:600;color:var(--ink);">
            <span style="width:24px;height:24px;border-radius:7px;background:var(--red-tint);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;">+</span>
            <span x-text="$store.ui.lang==='en' ? 'Add category' : 'Tambah kategori'">Add category</span>
        </span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg);transition:.15s' : 'transition:.15s'"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div x-show="open" x-cloak style="padding:18px 22px;border-top:1px solid var(--hairline);">
        @include('partials.ts-category-form', ['category' => null, 'action' => route('timesheet.admin.categories.store'), 'submitLabel' => 'Add category'])
    </div>
</div>

@forelse ($categories as $cat)
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
@empty
    <div class="uj-card" style="padding:24px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No categories yet.' : 'Tiada kategori lagi.'">No categories yet.</span></div>
@endforelse

{{-- ============================ PROJECTS ============================ --}}
<div style="display:flex;align-items:center;gap:9px;margin:26px 0 11px;">
    <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Projects & sub-pillars' : 'Projek & sub-tiang'">Projects &amp; sub-pillars</span></h2>
    <span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;">{{ $projects->count() }}</span>
</div>

<div class="uj-card" style="padding:0;margin-bottom:14px;" x-data="{ open: false }">
    <button @click="open = ! open" type="button" style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;background:none;cursor:pointer;border:0;">
        <span style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:600;color:var(--ink);">
            <span style="width:24px;height:24px;border-radius:7px;background:var(--red-tint);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;">+</span>
            <span x-text="$store.ui.lang==='en' ? 'Add project' : 'Tambah projek'">Add project</span>
        </span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg);transition:.15s' : 'transition:.15s'"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div x-show="open" x-cloak style="padding:18px 22px;border-top:1px solid var(--hairline);">
        @include('partials.ts-project-form', ['project' => null, 'action' => route('timesheet.admin.projects.store'), 'submitLabel' => 'Add project'])
    </div>
</div>

@forelse ($projects as $project)
    <div class="uj-card" style="padding:16px 20px;margin-bottom:12px;{{ $project->is_active ? '' : 'opacity:.6;' }}" x-data="{ edit: false, sub: false }">
        <div style="display:flex;gap:12px;align-items:center;">
            <span style="width:34px;height:34px;border-radius:8px;background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);font-size:11px;font-weight:600;font-family:var(--font-mono);display:flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $project->code ?: '—' }}</span>
            <div style="flex:1;min-width:0;">
                <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $project->name }}</div>
                <div style="font-size:11.5px;color:var(--muted);">{{ $project->subPillars->count() }} <span x-text="$store.ui.lang==='en' ? 'sub-pillars' : 'sub-tiang'">sub-pillars</span>@unless ($project->is_active) · <span x-text="$store.ui.lang==='en' ? 'inactive' : 'tidak aktif'">inactive</span>@endunless</div>
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
            @forelse ($project->subPillars as $sp)
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
            @empty
                <div style="font-size:12px;color:var(--muted);padding:4px 0 10px;"><span x-text="$store.ui.lang==='en' ? 'No sub-pillars yet — add the first one below.' : 'Tiada sub-tiang lagi — tambah yang pertama di bawah.'">No sub-pillars yet.</span></div>
            @endforelse
            <div style="margin-top:12px;">
                @include('partials.ts-subpillar-form', ['sub' => null, 'action' => route('timesheet.admin.subpillars.store', $project), 'submitLabel' => '+ Add', 'compact' => false])
            </div>
        </div>
    </div>
@empty
    <div class="uj-card" style="padding:24px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No projects yet.' : 'Tiada projek lagi.'">No projects yet.</span></div>
@endforelse
@endsection
