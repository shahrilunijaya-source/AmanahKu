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
        @include('partials.ts-category-form', ['category' => null, 'action' => route('timesheet.admin.categories.store'), 'submitLabel' => 'Add category', 'ajaxTarget' => '#ts-categories'])
    </div>
</div>

<div id="ts-categories">
    @forelse ($categories as $cat)
        @include('partials.ts-category-row', ['cat' => $cat])
    @empty
        <div data-empty class="uj-card" style="padding:24px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No categories yet.' : 'Tiada kategori lagi.'">No categories yet.</span></div>
    @endforelse
</div>

{{-- ============================ PROJECTS ============================ --}}
<div style="display:flex;align-items:center;gap:9px;margin:26px 0 11px;">
    <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Projects & sub-pillars' : 'Projek & sub-tiang'">Projects &amp; sub-pillars</span></h2>
    <span id="ts-proj-count" style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;">{{ $projects->count() }}</span>
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
        @include('partials.ts-project-form', ['project' => null, 'action' => route('timesheet.admin.projects.store'), 'submitLabel' => 'Add project', 'ajaxTarget' => '#ts-projects'])
    </div>
</div>

<div id="ts-projects">
    @forelse ($projects as $project)
        @include('partials.ts-project-row', ['project' => $project])
    @empty
        <div data-empty class="uj-card" style="padding:24px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No projects yet.' : 'Tiada projek lagi.'">No projects yet.</span></div>
    @endforelse
</div>

{{-- Inline add without a page reload: intercept forms marked data-ajax, POST them,
     append the server-rendered row to the target list, then reset the form so the
     next entry is one keystroke away. Kills the "add → full refresh → re-scroll →
     re-open" loop that made bulk entry painful. --}}
<script>
    (function () {
        function bump(sel, by) {
            var el = sel && document.querySelector(sel);
            if (el) { el.textContent = (parseInt(el.textContent, 10) || 0) + by; }
        }
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (! form || ! form.matches || ! form.matches('form[data-ajax]')) { return; }
            e.preventDefault();
            var target = document.querySelector(form.dataset.target);
            var btn = form.querySelector('[type=submit]');
            if (btn) { btn.disabled = true; }

            fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: new FormData(form),
            }).then(function (r) {
                return r.json().then(function (d) { return { ok: r.ok, d: d }; }, function () { return { ok: r.ok, d: {} }; });
            }).then(function (res) {
                if (btn) { btn.disabled = false; }
                if (! res.ok) {
                    alert(res.d && res.d.message ? res.d.message : 'Could not save — check the fields.');
                    return;
                }
                if (target && res.d.html) {
                    var empty = target.querySelector('[data-empty]');
                    if (empty) { empty.remove(); }
                    target.insertAdjacentHTML('beforeend', res.d.html);
                    var added = target.lastElementChild;
                    if (window.Alpine && added) { window.Alpine.initTree(added); }
                    // Sub-pillar adds live inside a project card — bump that card's own count.
                    if (! res.d.count_sel) {
                        var card = target.closest('.uj-card');
                        var c = card && card.querySelector('[data-sub-count]');
                        if (c) { c.textContent = (parseInt(c.textContent, 10) || 0) + 1; }
                    }
                }
                bump(res.d.count_sel, 1);
                form.reset();
                var first = form.querySelector('input[name=name]');
                if (first) { first.focus(); }
            }).catch(function () {
                if (btn) { btn.disabled = false; }
                alert('Network error — not saved.');
            });
        });
    })();
</script>
@endsection
