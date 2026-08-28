@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'projects',
    'en'  => [
        'title' => 'Projects',
        'body'  => 'Every project Unijaya is working on, and the sub-pillars they all share. Timesheets, T.A.A. cards and Track read this same list, so a project only ever needs adding once — here.',
        'who'   => 'Everyone can read · Manager, Management & HR can edit',
        'steps' => [
            'Add a project with its name, then tag the categories it falls under.',
            'Sub-pillars are the kind of work — Management, Meeting, Technical. One list, shared by every project.',
            'Anything already used on a timesheet is deactivated instead of deleted, so reports keep their history.',
        ],
    ],
    'ms'  => [
        'title' => 'Projek',
        'body'  => 'Setiap projek yang sedang dijalankan Unijaya, dan sub-tiang yang dikongsi semuanya. Lembaran masa, kad T.A.A. dan Track membaca senarai yang sama, jadi projek hanya perlu ditambah sekali — di sini.',
        'who'   => 'Semua boleh baca · Pengurus, Pengurusan & HR boleh sunting',
        'steps' => [
            'Tambah projek dengan namanya, kemudian tandakan kategori yang berkaitan.',
            'Sub-tiang ialah jenis kerja — Pengurusan, Mesyuarat, Teknikal. Satu senarai, dikongsi setiap projek.',
            'Apa-apa yang telah digunakan pada lembaran masa akan dinyahaktifkan, bukan dipadam, supaya laporan kekal sejarahnya.',
        ],
    ],
])

{{-- ============================ PROJECTS ============================ --}}
{{-- items starts empty: each row registers itself via x-init (see
     ts-project-row.blade.php) so a row appended later by the AJAX add script joins
     the same list through the same path as the initial render — one source of truth,
     no separately-computed index that a later add could fall out of sync with. --}}
<div x-data="{ q: '', showOff: false, cats: [], items: [] }">
    <div style="display:flex;align-items:center;gap:9px;margin:0 0 11px;">
        <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Projects' : 'Projek'">Projects</span></h2>
        {{-- Plain text, never Alpine-bound: the AJAX add script increments this node. --}}
        <span id="ts-proj-count" style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;font-family:var(--font-mono);font-variant-numeric:tabular-nums;">{{ $projects->count() }}</span>
    </div>

    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
        <input x-model="q" type="search" :placeholder="$store.ui.lang==='en' ? 'Search name or code' : 'Cari nama atau kod'"
               style="flex:1;min-width:200px;max-width:320px;height:40px;padding:0 14px;background:var(--card);border:1px solid var(--hairline);border-radius:9px;font-size:14px;outline:none;" />
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px;color:var(--muted);cursor:pointer;">
            <input type="checkbox" x-model="showOff" />
            <span x-text="$store.ui.lang==='en' ? 'Show archived' : 'Tunjuk diarkibkan'">Show archived</span>
        </label>
    </div>

    {{-- Category chips. Nothing picked = no filter at all (the common case), rather
         than every chip pre-selected and a project vanishing the moment one is tapped
         off. Each chip carries the category's own colour, the same one its pill wears
         on the rows below. --}}
    <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px;">
        @foreach ($projectCategories as $cat)
            <button type="button" data-cat-chip
                    @click="cats.includes({{ $cat->id }}) ? cats = cats.filter(c => c !== {{ $cat->id }}) : cats.push({{ $cat->id }})"
                    :aria-pressed="cats.includes({{ $cat->id }})"
                    class="uj-pill"
                    {{-- Every declaration lives in the binding: Alpine rewrites the whole
                         style attribute, so anything left in a static one is wiped on the
                         first toggle. --}}
                    :style="'cursor:pointer;border:1px solid ' + (cats.includes({{ $cat->id }})
                        ? 'color-mix(in srgb, {{ $cat->colour() }} 35%, transparent);background:color-mix(in srgb, {{ $cat->colour() }} 15%, var(--card));color:{{ $cat->colour() }};'
                        : 'var(--hairline);background:var(--canvas);color:var(--muted);')">
                <span style="width:6px;height:6px;border-radius:50%;background:{{ $cat->colour() }};display:inline-block;margin-right:6px;"></span>{{ $cat->name }}
            </button>
        @endforeach
        <button type="button" x-show="cats.length" x-cloak @click="cats = []"
                style="background:none;border:0;cursor:pointer;font-size:12px;color:var(--muted);text-decoration:underline;">
            <span x-text="$store.ui.lang==='en' ? 'Clear' : 'Kosongkan'">Clear</span>
        </button>
    </div>

    @if ($canEdit)
        <div class="uj-card" style="padding:0;margin-bottom:14px;" x-data="{ open: false }">
            <button @click="open = ! open" type="button" style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;background:none;cursor:pointer;border:0;">
                <span style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:600;color:var(--ink);">
                    <span style="width:24px;height:24px;border-radius:7px;background:var(--red-tint);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;">+</span>
                    <span x-text="$store.ui.lang==='en' ? 'Add project' : 'Tambah projek'">Add project</span>
                </span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg);transition:.15s' : 'transition:.15s'"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div x-show="open" x-cloak style="padding:18px 22px;border-top:1px solid var(--hairline);">
                @include('partials.ts-project-form', ['project' => null, 'action' => route('projects.store'), 'ajaxTarget' => '#ts-projects', 'categories' => $addCategories])
            </div>
        </div>
    @endif

    <div id="ts-projects">
        @forelse ($projects as $project)
            @include('partials.ts-project-row', ['project' => $project, 'categories' => $projectCategories, 'canEdit' => $canEdit])
        @empty
            <div data-empty class="uj-card" style="padding:24px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No projects yet.' : 'Tiada projek lagi.'">No projects yet.</span></div>
        @endforelse
    </div>

    {{-- Shown only when a search or the inactive filter hides everything. --}}
    <div x-show="items.length && ! items.some(i => (showOff || i.active) && i.hay.includes(q.toLowerCase()) && (! cats.length || i.cats.some(c => cats.includes(c))))" x-cloak
         class="uj-card" style="padding:24px;text-align:center;font-size:13px;color:var(--muted);">
        <span x-text="$store.ui.lang==='en' ? 'Nothing to show with these filters.' : 'Tiada apa-apa untuk dipaparkan dengan tapisan ini.'">Nothing to show with these filters.</span>
    </div>
</div>

{{-- ============================ SUB-PILLARS ============================ --}}
<div style="display:flex;align-items:center;gap:9px;margin:34px 0 4px;">
    <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Sub-pillars' : 'Sub-tiang'">Sub-pillars</span></h2>
    <span id="ts-sub-count" style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;font-family:var(--font-mono);font-variant-numeric:tabular-nums;">{{ $subPillars->count() }}</span>
</div>
<p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;max-width:60ch;">
    <span x-text="$store.ui.lang==='en'
        ? 'The kind of work, not a part of a project. One list, shared by every project — staff pick one when they book time, or leave it blank and book the whole project.'
        : 'Jenis kerja, bukan sebahagian daripada projek. Satu senarai, dikongsi setiap projek — staf pilih satu semasa merekod masa, atau biarkan kosong untuk keseluruhan projek.'">The kind of work, not a part of a project.</span>
</p>

<div class="uj-card" style="padding:6px 18px 14px;">
    <div id="ts-subpillars">
        @forelse ($subPillars as $sp)
            @include('partials.ts-subpillar-row', ['sp' => $sp, 'canEdit' => $canEdit])
        @empty
            <div data-empty style="padding:18px 0;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No sub-pillars yet.' : 'Tiada sub-tiang lagi.'">No sub-pillars yet.</span></div>
        @endforelse
    </div>
    @if ($canEdit)
        <div style="margin-top:12px;padding-top:13px;border-top:1px solid var(--hairline);">
            @include('partials.ts-subpillar-form', ['sub' => null, 'action' => route('sub-pillars.store'), 'compact' => false, 'ajaxTarget' => '#ts-subpillars'])
        </div>
    @endif
</div>

@include('partials.ajax-row-add')
@endsection
