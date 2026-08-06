# Document Vault Revamp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild `resources/views/screens/documents.blade.php` onto the app's current design system (`docs/DESIGN.md`) and the row/drawer idiom shared by helpdesk and knowledge-bank, dropping the boxy 3-stat-card header in favor of a single count/scope line, with no backend or route changes.

**Architecture:** Pure Blade view change. One new partial (`resources/views/partials/document-drawer.blade.php`) holds the per-document side drawer, reusing the app's shared `.wd-*` CSS grammar (already defined in `resources/css/app.css`, do not add new `.wd-` rules) and the `.uj-lv-rw-*` row classes. `documents.blade.php` itself is rewritten in place. `DocumentController` is untouched — same `screenData()`/`store()`/`download()`/`destroy()` contract.

**Tech Stack:** Laravel Blade, Alpine.js (inline `x-data`, no new JS files), Tailwind-free hand-written CSS already in `resources/css/app.css` (no CSS changes needed — every class this plan uses already exists).

## Global Constraints

- No changes to `app/Http/Controllers/DocumentController.php`, routes, migrations, or the `EmployeeDocument` model.
- No new CSS. Every class used (`.uj-card`, `.uj-card-head`, `.uj-card-title`, `.uj-pill`, `.uj-stamp`, `.uj-btn-primary`, `.uj-btn-ghost`, `.uj-lv-in`, `.uj-lv-file`, `.uj-lv-rw*`, `.uj-lv-empty`, `.wd*`) already exists in `resources/css/app.css`. If a step seems to need a new rule, stop and re-check — the class is almost certainly already there under a name this plan didn't anticipate.
- `.uj-stamp[data-tone]` only supports `red|amber|success|error` — never write `data-tone="info"`.
- Keep every existing bilingual `x-text="$store.ui.lang==='en' ? ... : ..."` pair; this app has no English-only strings in the UI.
- Keep the existing owner-forcing security behavior in the controller's `store()` untouched — the Blade form still submits `employee_id` the same way (hidden input for non-privileged, select for privileged).
- Work happens in the worktree at `.claude/worktrees/document-vault-revamp` (branch `document-vault-revamp`, already created off local `dev` HEAD, with `routes/dev-login.php`, `dev-login.html`, and `database/seeders/DevLoginSeeder.php` already copied in — do not re-copy or recreate these).
- Reachable in-browser at `http://worktree-document-vault-revamp.amanahku.localhost` (lerd auto-registers the worktree's vhost within a few seconds of creation — if it 404s, wait and retry, don't fall back to the main `:9100` site).
- Run `vendor/bin/pint --dirty --format agent` after any PHP/Blade edits, before considering a task done.

---

### Task 1: Category metadata + card head (drop stat row, add count/scope pills)

**Files:**
- Modify: `resources/views/screens/documents.blade.php:1-36` (the `@php` block and the 3-stat-card row)

**Interfaces:**
- Consumes: `$documents` (Collection grouped by category string), `$privileged` (bool), `$employees`, `$categories`, `$employee` — all unchanged from `DocumentController::screenData()`.
- Produces: `$catMeta` (array keyed by category name → `['tint' => css-color-string, 'bg' => css-color-string, 'icon' => raw-svg-path-string]`), `$catTone` (array keyed by category name → `'success'|'amber'|null`, for `.uj-stamp[data-tone]`), `$fmtSize` (unchanged closure), `$totalDocs` (unchanged int), `$scopeEn`/`$scopeMs` (strings) — all consumed by Task 2 and Task 3.

- [ ] **Step 1: Replace the `@php` block**

Replace lines 3-9 of `resources/views/screens/documents.blade.php` (the existing `$catIcon`/`$catColor`/`$fs`/`$totalDocs`/`$fmtSize` block) with:

```php
@php
    // Row icon tile per category — same tinted-tile convention as the helpdesk
    // $categoryMeta (resources/views/screens/helpdesk.blade.php) and Leave/Claims
    // disclosure rows. Reuses the same info/amber/success tint tokens as helpdesk
    // for visual consistency across screens.
    $catMeta = [
        'Contract'    => ['tint' => 'var(--info)', 'bg' => 'var(--info-tint,#eef4fb)', 'icon' => '<path d="M14 3v5h5"/><path d="M6 3h8l6 6v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M9 13h6M9 17h6"/>'],
        'Certificate' => ['tint' => 'var(--success)', 'bg' => 'var(--success-tint,#e7f4ec)', 'icon' => '<circle cx="12" cy="8" r="5"/><path d="M8.5 13 7 21l5-3 5 3-1.5-8"/>'],
        'ID'          => ['tint' => 'var(--amber)', 'bg' => 'var(--amber-tint,#f7efe0)', 'icon' => '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="9" cy="12" r="2"/><path d="M14 10h5M14 14h5"/>'],
        'Other'       => ['tint' => 'var(--muted)', 'bg' => 'var(--shelf)', 'icon' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>'],
    ];
    // .uj-stamp only supports red/amber/success/error tones (resources/css/app.css:353-364);
    // Contract and Other fall back to the stamp's neutral default rather than an
    // unsupported "info" tone.
    $catTone = ['Contract' => null, 'Certificate' => 'success', 'ID' => 'amber', 'Other' => null];
    $totalDocs = $documents->flatten()->count();
    $fmtSize = fn ($b) => $b >= 1048576 ? round($b / 1048576, 1).' MB' : ($b >= 1024 ? round($b / 1024).' KB' : $b.' B');
    $scopeEn = $privileged ? 'All employees' : 'My documents';
    $scopeMs = $privileged ? 'Semua pekerja' : 'Dokumen saya';
@endphp
```

- [ ] **Step 2: Remove the 3-stat-card row and rebuild the card head**

Delete the entire stat-card row block (the `<div style="display:flex;gap:16px;...">...</div>` currently at lines 28-36, containing "Total documents" / "Categories" / "Scope" cards).

Then find the card head block (currently):
```blade
<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Document vault' : 'Peti dokumen'">Document vault</h3>
        <button @click="add = ! add" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Upload' : '+ Muat naik')"></span></button>
    </div>
```

Replace it with:
```blade
<div class="uj-card">
    <div class="uj-card-head" style="flex-wrap:wrap;gap:10px;">
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Document vault' : 'Peti dokumen'">Document vault</h3>
            <span class="uj-pill" style="background:var(--canvas);color:var(--muted);">{{ $totalDocs }}</span>
            <span class="uj-pill" style="background:var(--canvas);color:var(--muted);" x-text="$store.ui.lang==='en' ? @js($scopeEn) : @js($scopeMs)">{{ $scopeEn }}</span>
        </div>
        <button @click="add = ! add" class="uj-btn-primary" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Upload' : '+ Muat naik')"></span></button>
    </div>
```

- [ ] **Step 3: Format check**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no errors (Blade files are typically untouched by Pint's PHP formatting, but the `@php` block content must still be valid PHP — this catches syntax mistakes).

- [ ] **Step 4: Render check**

Run: `php artisan test --compact --filter=AllScreensRenderTest`
Expected: PASS (the `/app/documents` route in the render-sweep still returns 200; confirms no Blade syntax error was introduced).

- [ ] **Step 5: Commit**

```bash
git add resources/views/screens/documents.blade.php
git commit -m "refactor(documents): drop stat-card row for a count/scope pill pair in the card head"
```

---

### Task 2: Restyle the upload form onto design-system input classes

**Files:**
- Modify: `resources/views/screens/documents.blade.php` (the upload `<form>` block, previously lines 38-56 before Task 1's edits shifted line numbers — locate by the `route('documents.store')` action)

**Interfaces:**
- Consumes: `$categories`, `$privileged`, `$employees`, `$employee` (unchanged), `old()`/`$errors` (Laravel request lifecycle, unchanged).
- Produces: no new variables: same `<form>` posting the same fields (`title`, `category`, `employee_id`, `file`) that `DocumentController::store()` already validates.

- [ ] **Step 1: Replace the upload form markup**

Find the whole upload `<div x-show="add" ...>` card block (contains the `<form method="post" action="{{ route('documents.store') }}" ...>`). Replace its **inner form contents** (keep the outer `<div x-show="add" x-cloak class="uj-card" ...>` wrapper and the `<h3>` heading as-is) — specifically replace everything from `<form method="post"` to the closing `</form>` with:

```blade
    <form method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data">
        @csrf
        @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;align-items:start;">
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Title *' : 'Tajuk *'">Title *</label><input name="title" value="{{ old('title') }}" required maxlength="160" class="uj-lv-in" /></div>
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label><select name="category" class="uj-lv-in" style="margin-bottom:6px;">@foreach ($categories as $c)<option value="{{ $c }}" @selected(old('category') === $c)>{{ $c }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Helps people find the file later. Contract for offer letters and agreements, Certificate for qualifications, ID for IC/passport.', 'ms' => 'Membantu orang mencari fail kemudian. Contract untuk surat tawaran dan perjanjian, Certificate untuk kelayakan, ID untuk IC/pasport.'])</div>
            @if ($privileged)
                <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Owner *' : 'Pemilik *'">Owner *</label><select name="employee_id" required class="uj-lv-in" style="margin-bottom:6px;"><option value="" x-text="$store.ui.lang==='en' ? 'Select employee…' : 'Pilih pekerja…'">Select employee…</option>@foreach ($employees as $e)<option value="{{ $e->id }}" @selected((string) old('employee_id') === (string) $e->id)>{{ $e->name }}</option>@endforeach</select>@include('partials.hint', ['en' => 'Whose private file this is. Double-check — only this person and HR will see it, so the wrong choice leaks personal data.', 'ms' => 'Fail peribadi ini milik siapa. Semak dua kali — hanya orang ini dan HR akan melihatnya, jadi pilihan yang salah membocorkan data peribadi.', 'tone' => 'warn'])</div>
            @else
                <input type="hidden" name="employee_id" value="{{ $employee?->id }}" />
            @endif
            <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'File *' : 'Fail *'">File *</label><div class="uj-lv-file"><input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" /></div>@include('partials.hint', ['en' => 'PDF, JPG, PNG, DOC or DOCX, up to 8 MB. Scans and photos of documents are fine.', 'ms' => 'PDF, JPG, PNG, DOC atau DOCX, sehingga 8 MB. Imbasan dan gambar dokumen pun boleh.'])</div>
        </div>
        <p style="font-size:11.5px;color:var(--muted);margin-top:10px;" x-text="$store.ui.lang==='en' ? 'PDF, JPG, PNG, DOC or DOCX · max 8 MB. Files are stored privately and downloaded only through a secure link.' : 'PDF, JPG, PNG, DOC atau DOCX · maksimum 8 MB. Fail disimpan secara peribadi dan dimuat turun hanya melalui pautan selamat.'">PDF, JPG, PNG, DOC or DOCX · max 8 MB. Files are stored privately and downloaded only through a secure link.</p>
        <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:12px;" x-text="$store.ui.lang==='en' ? 'Upload' : 'Muat naik'">Upload</button>
    </form>
```

- [ ] **Step 2: Render check**

Run: `php artisan test --compact --filter=AllScreensRenderTest`
Expected: PASS.

- [ ] **Step 3: Format + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/screens/documents.blade.php
git commit -m "refactor(documents): move upload form inputs onto .uj-lv-in / .uj-lv-file"
```

---

### Task 3: Document detail side-drawer partial

**Files:**
- Create: `resources/views/partials/document-drawer.blade.php`

**Interfaces:**
- Consumes (passed via `@include('partials.document-drawer', [...])`): `$doc` (an `EmployeeDocument` instance with `employee` eager-loaded — has `->id`, `->title`, `->category`, `->original_name`, `->size`, `->created_at`, `->employee?->name`), `$catTone` (the full array from Task 1, keyed by category), `$privileged` (bool), `$fmtSize` (closure from Task 1).
- Produces: nothing — leaf partial. Expects an ancestor element with Alpine `x-data="{ drawerOpen: false }"` and a `drawerOpen` boolean in scope (provided by Task 4's row wrapper).
- Relies on routes `documents.download` and `documents.destroy` (unchanged, both take an `EmployeeDocument` route-bound parameter).

- [ ] **Step 1: Write the partial**

```blade
{{-- Document detail as a side drawer — same .wd-* grammar as helpdesk/knowledge-bank
     drawers (do not add new .wd- rules). Teleported so it is never clipped by the row
     and only one paints at a time. Expects an ancestor with x-data="{ drawerOpen: false }". --}}
<template x-teleport="body">
    <div x-show="drawerOpen" x-cloak>
        <div class="wd-scrim" :data-open="drawerOpen ? '' : null" @click="drawerOpen = false"></div>
        <aside class="wd" :data-open="drawerOpen ? '' : null" role="dialog" aria-modal="true"
               @keydown.escape.window="drawerOpen = false">

            <div class="wd-head">
                <span class="uj-stamp" @if ($catTone[$doc->category] ?? null) data-tone="{{ $catTone[$doc->category] }}" @endif>{{ $doc->category }}</span>
                <button type="button" class="wd-ico" style="margin-left:auto;" @click="drawerOpen = false"
                        :aria-label="$store.ui.lang==='en' ? 'Close' : 'Tutup'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="wd-body">
                <h2 class="wd-title">{{ $doc->title }}</h2>
                <p class="wd-sub">
                    @if ($privileged){{ $doc->employee?->name ?? '—' }} · @endif
                    {{ $fmtSize($doc->size) }} · {{ $doc->created_at?->format('d M Y') }}
                </p>

                {{-- Same attachment-chip markup as partials/ticket-drawer.blade.php:50-54 —
                     extension badge + filename, pointing at the auth-gated download route,
                     never a public URL. --}}
                <a href="{{ route('documents.download', $doc) }}"
                   style="display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 12px;border-radius:8px;border:1px solid var(--hairline-soft);font-size:12.5px;color:var(--body);text-decoration:none;">
                    <span style="font-weight:700;font-size:10.5px;color:var(--muted);">{{ strtoupper(pathinfo($doc->original_name, PATHINFO_EXTENSION)) }}</span>
                    <span>{{ $doc->original_name }}</span>
                </a>

                <hr class="wd-rule">

                <div style="display:flex;gap:10px;">
                    <a href="{{ route('documents.download', $doc) }}" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;text-decoration:none;display:inline-flex;align-items:center;" x-text="$store.ui.lang==='en' ? 'Download' : 'Muat turun'">Download</a>
                    <form method="post" action="{{ route('documents.destroy', $doc) }}" onsubmit="return confirm('Delete this document?');">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:38px;padding:0 16px;font-size:13px;color:var(--red);" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</button>
                    </form>
                </div>
            </div>
        </aside>
    </div>
</template>
```

- [ ] **Step 2: Commit**

This partial isn't wired up yet (Task 4 includes it), so it can't be exercised standalone. Commit it as part of Task 4 instead — skip a separate commit here and proceed directly to Task 4.

---

### Task 4: Category-grouped `.uj-lv-rw` rows + wire up the drawer

**Files:**
- Modify: `resources/views/screens/documents.blade.php` (the `@forelse ($documents as $category => $docs)` block through `@endforelse`, and the empty state inside it)

**Interfaces:**
- Consumes: `$documents` (Collection<string, Collection<EmployeeDocument>>), `$catMeta`/`$catTone`/`$fmtSize`/`$privileged` from Task 1, `partials.document-drawer` from Task 3.
- Produces: nothing further downstream — this is the last piece of the screen.

- [ ] **Step 1: Replace the category-grouped list**

Find the `@forelse ($documents as $category => $docs)` ... `@endforelse` block. Replace the whole block (including the per-category header div, the per-document row div, and the `@empty` branch) with:

```blade
    @forelse ($documents as $category => $docs)
        @php $cm = $catMeta[$category] ?? $catMeta['Other']; @endphp
        <div style="padding:13px 20px 6px;font-size:var(--t-micro);font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:{{ $cm['tint'] }};border-top:1px solid var(--hairline-soft);">
            {{ $category }} <span style="color:var(--muted);font-weight:600;letter-spacing:normal;text-transform:none;">· {{ $docs->count() }}</span>
        </div>
        @foreach ($docs as $doc)
            <div class="uj-lv-rw" x-data="{ drawerOpen: false }">
                <button type="button" class="uj-lv-rw-head" @click="drawerOpen = true">
                    <span class="uj-lv-rw-ico" style="background:{{ $cm['bg'] }};color:{{ $cm['tint'] }};">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $cm['icon'] !!}</svg>
                    </span>
                    <span class="uj-lv-rw-t">
                        <span class="uj-lv-rw-1">{{ $doc->title }}</span>
                        <span class="uj-lv-rw-2">
                            @if ($privileged){{ $doc->employee?->name ?? '—' }} · @endif
                            {{ $fmtSize($doc->size) }} · {{ $doc->created_at?->format('d M Y') }}
                        </span>
                    </span>
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;color:var(--muted-soft);"><path d="M9 6l6 6-6 6"/></svg>
                </button>

                @include('partials.document-drawer', ['doc' => $doc, 'catTone' => $catTone, 'privileged' => $privileged, 'fmtSize' => $fmtSize])
            </div>
        @endforeach
    @empty
        <div class="uj-lv-empty">
            <b x-text="$store.ui.lang==='en' ? 'No documents yet' : 'Belum ada dokumen'">No documents yet</b>
            @php
                $emptyEn = 'Click "+ Upload" to add the first file. ' . ($privileged ? 'Pick the right owner so it stays private to them.' : 'Only you and HR will be able to see it.');
                $emptyMs = 'Klik "+ Upload" untuk tambah fail pertama. ' . ($privileged ? 'Pilih pemilik yang betul supaya ia kekal peribadi kepada mereka.' : 'Hanya anda dan HR akan dapat melihatnya.');
            @endphp
            <span x-text="$store.ui.lang==='en' ? @js($emptyEn) : @js($emptyMs)"></span>
        </div>
    @endforelse
```

- [ ] **Step 2: Format check**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no errors.

- [ ] **Step 3: Automated render + feature checks**

Run: `php artisan test --compact --filter=AllScreensRenderTest`
Expected: PASS.

Run: `php artisan test --compact tests/Feature/ModulesBatchTest.php`
Expected: PASS — confirms upload/download/delete/authorization behavior is unaffected by the view rewrite (these tests drive the controller directly, not the view, but they must still pass since the plan touches nothing they depend on).

- [ ] **Step 4: Manual browser verification**

Rebuild assets and check in the worktree's own preview (not the main `:9100` site):

```bash
cd .claude/worktrees/document-vault-revamp
bun run build
```

Then in the browser pane:
1. Navigate to `http://worktree-document-vault-revamp.amanahku.localhost/dev/login?email=hr@amanahku.test&tenant=unijaya`, then to `/app/documents`.
   - Confirm the card head shows "Document vault", a count pill, and an "All employees" pill — no 3-card stat row.
   - Confirm category groups show tinted SVG icon tiles, not emoji.
   - Click a row: the `.wd` drawer slides in from the right with category stamp, title, owner name, file chip, Download and Delete buttons.
   - Click Download: file downloads. Click Delete: `confirm()` dialog appears; cancel it (don't actually delete a seeded document).
   - Click "+ Upload", fill the form (title, category, owner via the `.uj-lv-in` select, a small file via the `.uj-lv-file` input), submit, confirm the new row appears in the right category group with the right icon tile.
2. Navigate to `http://worktree-document-vault-revamp.amanahku.localhost/dev/login?email=employee@amanahku.test&tenant=unijaya`, then to `/app/documents`.
   - Confirm the scope pill reads "My documents", no owner picker in the upload form, no owner name in row sublines or drawer.
   - Confirm only this employee's own documents are listed.
3. Resize the browser pane to 390px width (mobile). Confirm the card head pills wrap instead of overflowing, rows stay legible, and the drawer still covers the viewport usably.

Fix anything broken, re-run this step, until all of the above holds.

- [ ] **Step 5: Commit**

```bash
git add resources/views/screens/documents.blade.php resources/views/partials/document-drawer.blade.php public/build
git commit -m "feat(documents): category-grouped .uj-lv-rw rows opening a .wd-* detail drawer"
```

---

### Task 5: Full regression pass

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: all tests pass (same pass/fail counts as the pre-work baseline — no regression introduced anywhere else by this view-only change).

- [ ] **Step 2: Report**

Summarize: files changed, screenshot(s) from Task 4 Step 4, test results. Note the branch (`document-vault-revamp`) and worktree path for the user to review or merge.
