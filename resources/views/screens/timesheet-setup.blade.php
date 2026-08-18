@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'timesheet-setup',
    'en'  => [
        'title' => 'Timesheet setup',
        'body'  => 'Manage the categories staff pick from when they allocate their week. Mark a category "requires a project" (like Development or Maintenance) and staff must choose a project for it; others (Sales, On Leave, Public Holiday…) stand alone.',
        'who'   => 'HR & Management',
        'steps' => [
            'Add or edit the categories everyone sees in the dropdown — tick "requires a project" for delivery work.',
            'Projects and sub-pillars live on their own screen now: Workplace → Projects.',
            'Anything already used on a timesheet is deactivated instead of deleted, so reports keep their history.',
        ],
    ],
    'ms'  => [
        'title' => 'Tetapan lembaran masa',
        'body'  => 'Urus kategori yang dipilih staf semasa memperuntukkan minggu mereka. Tandakan kategori "memerlukan projek" (seperti Pembangunan atau Penyelenggaraan) dan staf mesti memilih projek untuknya; yang lain (Jualan, Bercuti, Cuti Umum…) berdiri sendiri.',
        'who'   => 'HR & Pengurusan',
        'steps' => [
            'Tambah atau sunting kategori yang dilihat semua orang — tandakan "memerlukan projek" untuk kerja penghantaran.',
            'Projek dan sub-tiang kini berada di skrin sendiri: Tempat Kerja → Projek.',
            'Apa-apa yang telah digunakan pada lembaran masa akan dinyahaktifkan, bukan dipadam, supaya laporan kekal sejarahnya.',
        ],
    ],
])

{{-- ============================ CATEGORIES ============================ --}}
<div style="display:flex;align-items:center;gap:9px;margin:0 0 11px;">
    <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Categories' : 'Kategori'">Categories</span></h2>
    <span id="ts-cat-count" style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;">{{ $categories->count() }}</span>
</div>

{{-- Add stays open across submits: rows are appended via AJAX (see script), so the
     panel never collapses and the page never reloads mid-entry. --}}
<div class="uj-card" style="padding:0;margin-bottom:14px;" x-data="{ open: false }">
    <button @click="open = ! open" type="button" style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;background:none;cursor:pointer;border:0;">
        <span style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:600;color:var(--ink);">
            <span style="width:24px;height:24px;border-radius:7px;background:var(--red-tint);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;">+</span>
            <span x-text="$store.ui.lang==='en' ? 'Add category' : 'Tambah kategori'">Add category</span>
        </span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg);transition:.15s' : 'transition:.15s'"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div x-show="open" x-cloak style="padding:18px 22px;border-top:1px solid var(--hairline);">
        @include('partials.ts-category-form', ['category' => null, 'action' => route('timesheet.admin.categories.store'), 'ajaxTarget' => '#ts-categories'])
    </div>
</div>

<div id="ts-categories">
    @forelse ($categories as $cat)
        @include('partials.ts-category-row', ['cat' => $cat])
    @empty
        <div data-empty class="uj-card" style="padding:24px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No categories yet.' : 'Tiada kategori lagi.'">No categories yet.</span></div>
    @endforelse
</div>

@include('partials.ajax-row-add')
@endsection
