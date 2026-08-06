# Changelog Screen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `/app/changelog` screen so every Amanahku user can see release notes, and Shazwan has one source of truth to cite when answering helpdesk tickets.

**Architecture:** Release notes live in a hand-authored `resources/changelog.yaml`, parsed by a new `App\Support\Changelog` helper. The existing single-dispatcher screen convention (`AppController::screen()` → `screens.{screen}` Blade view) picks it up with one `match` arm and one `Amanahku::page()` entry — no new route, no new controller, no DB table. A footer link in the sidebar makes it reachable from anywhere in the app.

**Tech Stack:** Laravel 13 / PHP 8.5, Blade + Alpine.js (bilingual EN/MS via `$store.ui.lang`), `symfony/yaml` (already a Composer dependency), PHPUnit.

## Global Constraints

- File-based entries only — no admin UI, no migration, no DB table (spec §Non-goals).
- Screen is open to any authenticated user in any tenant — no role gate, no `FeatureManager::screenAllowed` gate (spec §Backend wiring).
- Not added to the main nav array — reachable only by direct URL and the sidebar footer link (spec §Backend wiring, §Entry point).
- Follow `docs/DESIGN.md`: warm-paper tokens only (`var(--ink)`, `var(--muted)`, `var(--hairline-soft)`, `var(--font-mono)`, …), the five type steps, `uj-stamp` tones are `red`/`amber`/`success`/`error` only — never invent a `data-tone="info"` (spec §View, confirmed against `resources/css/app.css:353-363`).
- Every user-facing string ships in English and Bahasa Malaysia, `x-text` toggled off `$store.ui.lang`, with the English string repeated as static fallback content — the pattern every existing `screens/*.blade.php` uses (see `resources/views/screens/audit.blade.php`).
- `declare(strict_types=1)` on every new PHP class (the convention in `app/Support/Geo.php`, `app/Support/Csv.php`).
- `vendor/bin/pint --dirty --format agent` after any PHP edit, before considering a task done.

---

### Task 1: Changelog data file + `App\Support\Changelog` helper

**Files:**
- Create: `resources/changelog.yaml`
- Create: `app/Support/Changelog.php`
- Test: `tests/Unit/ChangelogTest.php`

**Interfaces:**
- Produces: `App\Support\Changelog::releases(): array` — returns a list of
  `['version' => string, 'date' => string, 'entries' => [['tag' => string, 'text' => string, 'text_ms' => string], ...]]`,
  newest release first (file order is preserved, no sorting performed — the YAML is written newest-first by convention). `text_ms` is always present in the returned array even when the YAML omits it (falls back to `text`).

- [ ] **Step 1: Write the data file**

```yaml
# resources/changelog.yaml
# Newest release first. tag is one of: added, improved, fixed.
# text_ms is optional — falls back to `text` when omitted.
- version: "2026.08.06"
  date: "2026-08-06"
  entries:
    - tag: added
      text: "New Changelog screen — see what shipped and when, right from the sidebar."
      text_ms: "Skrin Log Perubahan baharu — lihat apa yang dilancarkan dan bila, terus dari bar sisi."
    - tag: improved
      text: "Timesheet add-entry now opens in one popup with rich-text notes."
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Changelog;
use Tests\TestCase;

class ChangelogTest extends TestCase
{
    public function test_releases_returns_the_newest_release_first(): void
    {
        $releases = Changelog::releases();

        $this->assertNotEmpty($releases);
        $this->assertSame('2026.08.06', $releases[0]['version']);
        $this->assertSame('2026-08-06', $releases[0]['date']);
    }

    public function test_each_entry_has_a_tag_and_bilingual_text(): void
    {
        $entries = Changelog::releases()[0]['entries'];

        $this->assertNotEmpty($entries);
        foreach ($entries as $entry) {
            $this->assertContains($entry['tag'], ['added', 'improved', 'fixed']);
            $this->assertNotEmpty($entry['text']);
            $this->assertNotEmpty($entry['text_ms']);
        }
    }

    public function test_text_ms_falls_back_to_text_when_the_yaml_omits_it(): void
    {
        $entries = Changelog::releases()[0]['entries'];
        $improved = collect($entries)->firstWhere('tag', 'improved');

        $this->assertNotNull($improved, 'Seed data must keep one entry without text_ms to exercise the fallback.');
        $this->assertSame($improved['text'], $improved['text_ms']);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact tests/Unit/ChangelogTest.php`
Expected: FAIL with a "Class App\Support\Changelog not found" (or similar) error.

- [ ] **Step 4: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Yaml\Yaml;

class Changelog
{
    /**
     * Parsed release notes from resources/changelog.yaml, newest release first (file order).
     *
     * @return array<int, array{version: string, date: string, entries: array<int, array{tag: string, text: string, text_ms: string}>}>
     */
    public static function releases(): array
    {
        /** @var array<int, array{version: string, date: string, entries: array<int, array{tag: string, text: string, text_ms?: string}>}> $raw */
        $raw = Yaml::parseFile(resource_path('changelog.yaml'));

        return array_map(static fn (array $release): array => [
            'version' => $release['version'],
            'date' => $release['date'],
            'entries' => array_map(static fn (array $entry): array => [
                'tag' => $entry['tag'],
                'text' => $entry['text'],
                'text_ms' => $entry['text_ms'] ?? $entry['text'],
            ], $release['entries']),
        ], $raw);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact tests/Unit/ChangelogTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/changelog.yaml app/Support/Changelog.php tests/Unit/ChangelogTest.php
git commit -m "$(cat <<'EOF'
feat(changelog): add release-notes data file and parser

File-based YAML entries, newest first, parsed into a plain array —
no DB table, so shipping a release note is just a commit like any
other release artefact.
EOF
)"
```

---

### Task 2: Wire the `changelog` screen into `AppController`

**Files:**
- Modify: `app/Http/Controllers/AppController.php:20` (add `use App\Support\Changelog;`)
- Modify: `app/Http/Controllers/AppController.php:339` (add a `screenData()` match arm, next to `'audit'`)
- Modify: `app/Support/Amanahku.php:304` (add a `page()` entry)
- Test: `tests/Feature/ChangelogScreenTest.php`

**Interfaces:**
- Consumes: `App\Support\Changelog::releases()` from Task 1.
- Produces: `GET /app/changelog` returns 200 with `$releases` in view data (view itself still resolves to `screens.empty` until Task 3 — that is expected and this task's test only checks the page title, not content).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangelogScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
    }

    public function test_any_authenticated_user_can_open_the_changelog_screen(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/changelog');

        $response->assertOk();
        $response->assertViewHas('releases');
        $response->assertSee('Changelog');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/ChangelogScreenTest.php`
Expected: FAIL — `assertViewHas('releases')` fails because the `changelog` arm doesn't exist yet (falls through to `default => []`).

- [ ] **Step 3: Add the import and the `screenData()` arm**

In `app/Http/Controllers/AppController.php`, add the import after `use App\Support\Amanahku;` (line 20):

```php
use App\Support\Amanahku;
use App\Support\Changelog;
```

In the `screenData()` match block, add a `changelog` arm right after the `'audit'` arm:

```php
            'audit' => ['logs' => AuditLog::latest()->take(50)->get()],
            'changelog' => ['releases' => Changelog::releases()],
```

- [ ] **Step 4: Add the `Amanahku::page()` entry**

In `app/Support/Amanahku.php`, inside the `$pages` array of `page()`, add:

```php
            'changelog' => ['title' => 'Changelog', 'title_ms' => 'Log Perubahan', 'sub' => "What's new in Amanahku.", 'sub_ms' => 'Apa yang baharu dalam Amanahku.', 'crumb' => ['Changelog']],
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/ChangelogScreenTest.php`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AppController.php app/Support/Amanahku.php tests/Feature/ChangelogScreenTest.php
git commit -m "$(cat <<'EOF'
feat(changelog): wire the changelog screen into the app dispatcher

No new route: /app/changelog rides the existing /app/{screen?}
dispatcher, open to any role in any tenant since it's release-note
content, not a tenant-configurable module.
EOF
)"
```

---

### Task 3: Blade view

**Files:**
- Create: `resources/views/screens/changelog.blade.php`
- Modify: `tests/Feature/ChangelogScreenTest.php` (extend with content assertions)

**Interfaces:**
- Consumes: `$releases` (from Task 2's view data), each release shaped as `App\Support\Changelog::releases()` documents.

- [ ] **Step 1: Write the failing test (append to the existing test class)**

Add this method to `tests/Feature/ChangelogScreenTest.php`:

```php
    public function test_it_lists_every_release_with_its_tagged_entries(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/changelog');

        $response->assertOk();
        $response->assertSee('2026.08.06');
        $response->assertSee('New Changelog screen', false);
        $response->assertSee('Timesheet add-entry now opens in one popup', false);
        $response->assertSee('Added');
        $response->assertSee('Improved');
        // 'added' carries the success tone; 'improved' carries no data-tone (neutral default).
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'uj-stamp" data-tone="success"'),
            'Exactly one entry in the seed data is tagged added.'
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/ChangelogScreenTest.php`
Expected: FAIL — the entry text and tags are not on the page yet (view falls back to `screens.empty`, which renders nothing but the page title).

- [ ] **Step 3: Write the view**

```blade
{{-- resources/views/screens/changelog.blade.php --}}
@extends('layouts.app')

@php
    $tagTone = ['added' => 'success', 'fixed' => 'error'];
    $tagLabel = [
        'added' => ['en' => 'Added', 'ms' => 'Ditambah'],
        'improved' => ['en' => 'Improved', 'ms' => 'Ditambah baik'],
        'fixed' => ['en' => 'Fixed', 'ms' => 'Dibaiki'],
    ];
@endphp

@section('screen')
<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Changelog' : 'Log Perubahan'">Changelog</h3>
    </div>
    @forelse ($releases as $release)
        <div style="padding:16px 20px;border-bottom:1px solid var(--hairline-soft);">
            <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:10px;">
                <span style="font-family:var(--font-mono);font-size:13px;font-weight:600;color:var(--ink);">{{ $release['version'] }}</span>
                <span style="font-size:12px;color:var(--muted);">{{ \Illuminate\Support\Carbon::parse($release['date'])->format('j M Y') }}</span>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;">
                @foreach ($release['entries'] as $entry)
                    @php $tone = $tagTone[$entry['tag']] ?? null; @endphp
                    <div style="display:flex;align-items:baseline;gap:10px;">
                        <span class="uj-stamp" @if ($tone) data-tone="{{ $tone }}" @endif style="flex-shrink:0;"
                              x-text="$store.ui.lang==='en' ? @js($tagLabel[$entry['tag']]['en']) : @js($tagLabel[$entry['tag']]['ms'])">{{ $tagLabel[$entry['tag']]['en'] }}</span>
                        <span style="font-size:13.5px;color:var(--ink);line-height:1.5;"
                              x-text="$store.ui.lang==='en' ? @js($entry['text']) : @js($entry['text_ms'])">{{ $entry['text'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div style="padding:28px 20px;text-align:center;">
            <div style="font-size:13px;color:var(--ink);font-weight:500;"
                 x-text="$store.ui.lang==='en' ? 'No releases yet' : 'Belum ada keluaran'">No releases yet</div>
        </div>
    @endforelse
</div>
@endsection
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/ChangelogScreenTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add resources/views/screens/changelog.blade.php tests/Feature/ChangelogScreenTest.php
git commit -m "$(cat <<'EOF'
feat(changelog): render the changelog screen

One uj-card, releases newest-first, each entry tagged with a
uj-stamp (success/neutral/error — never red, nothing here is an
action). Bilingual EN/MS via x-text, same pattern every other
screen uses.
EOF
)"
```

---

### Task 4: Sidebar footer entry point

**Files:**
- Modify: `resources/views/partials/sidebar.blade.php` (inside `.uj-sb-foot`)
- Modify: `tests/Feature/ChangelogScreenTest.php` (extend with a reachability assertion)

**Interfaces:**
- Consumes: `route('app.screen', 'changelog')` (standard Laravel named-route helper, no new route).

- [ ] **Step 1: Write the failing test (append to the existing test class)**

```php
    public function test_the_sidebar_footer_links_to_the_changelog_from_any_screen(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/dash');

        $response->assertOk();
        $response->assertSee(route('app.screen', 'changelog'), false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/ChangelogScreenTest.php`
Expected: FAIL — the dashboard screen's sidebar has no changelog link yet.

- [ ] **Step 3: Add the footer link**

In `resources/views/partials/sidebar.blade.php`, inside `.uj-sb-foot`, add this link right after the "Raise a ticket" button's `@endif` and before the workspace-switcher `<a>` (see the block starting `<div class="uj-sb-foot">`):

```blade
        <a href="{{ route('app.screen', 'changelog') }}" class="uj-feedback-btn"
           :title="$store.ui.lang==='en' ? 'Changelog' : 'Log Perubahan'"
           style="width:100%;display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:9px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);color:#fff;font-size:var(--t-sm);font-weight:500;text-align:left;text-decoration:none;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;color:var(--sidebar-dim);"><path d="M3 6h.01M3 12h.01M3 18h.01M8 6h13M8 12h13M8 18h13"></path></svg>
            <span class="uj-nav-lbl uj-sb-hide" x-text="$store.ui.lang==='en' ? 'Changelog' : 'Log Perubahan'">Changelog</span>
        </a>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/ChangelogScreenTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add resources/views/partials/sidebar.blade.php tests/Feature/ChangelogScreenTest.php
git commit -m "$(cat <<'EOF'
feat(changelog): add a sidebar footer link to the changelog

Reachable from every screen, not just the main nav — it's a
permanent utility page, not a work screen, so it sits beside
"Raise a ticket" rather than in the nav tree.
EOF
)"
```

---

### Task 5: Full verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full changelog test surface**

Run: `php artisan test --compact tests/Unit/ChangelogTest.php tests/Feature/ChangelogScreenTest.php`
Expected: PASS (8 tests total: 3 unit + 5 feature)

- [ ] **Step 2: Run the full test suite to catch regressions**

Run: `php artisan test --compact`
Expected: PASS — no pre-existing test should reference `screens.changelog` or the sidebar footer's exact child count in a way this change could break; if anything unrelated fails, investigate before proceeding (do not assume it's flaky).

- [ ] **Step 3: Final Pint pass**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no further diffs (Tasks 1–4 already ran Pint after each edit).

- [ ] **Step 4: Manual smoke check**

Ask the user to open `http://localhost:9100/dev/login?email=employee@amanahku.test&tenant=unijaya`, then click "Changelog" in the sidebar footer, and confirm the release renders with the Added/Improved stamps in the right tone (success / neutral) and the EN/MS language toggle switches both the tag words and the entry text.
