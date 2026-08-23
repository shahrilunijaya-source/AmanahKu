# Confirm-before-submit: full-page week review Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Clicking "Submit week" on the personal timesheet screen opens a full-page "Review before
you submit" step showing every day about to be posted, instead of submitting immediately.

**Architecture:** A client-side pane swap inside the Record tab's existing Alpine component
(`timesheetCapture`), gated by a new `reviewing` boolean. No new route, controller method, or DB
column — "Confirm & submit" calls the same `save(true)` the Submit button calls today.

**Tech Stack:** Laravel 13 Blade + Alpine.js (`resources/js/timesheet-capture.js`), PHPUnit feature
tests, existing `app.css` design tokens (no new CSS variables).

**Spec:** `docs/superpowers/specs/2026-08-18-timesheet-submit-review-design.md`

## Global Constraints

- No new route, controller method, or DB column (spec, "Approach").
- No new CSS variable or easing curve — reuse `.uj-overlay-enter/-leave/-from/-to` and
  `.uj-btn-primary`/`.uj-btn-ghost` verbatim (spec, "Motion").
- The review must iterate the exact set `flatRows()` will POST (`isEditable(d)` days with rows,
  plus every `locked` day) — never `dayDates()` or the reactive `days` (5/7) toggle (spec,
  "What the review must show").
- Every day's contribution to the category breakdown clamps at 100 (`Math.min(dayTotal(d), 100)`)
  so the split can never disagree with the headline week percentage (spec, same section).
- `vendor/bin/pint --dirty --format agent` must be run (and clean) before any task is considered
  done — this plan touches only PHP for the test files; the Blade/JS edits are unaffected by Pint
  but every task still ends with a pint pass since new PHP files are added.
- Run `php artisan test --compact --filter=Timesheet` after every task; the full suite once at
  the end (Task 4).

---

## File Structure

- **Modify:** `resources/js/timesheet-capture.js` — add `reviewing`, `openReview()`,
  `closeReview()`, `reviewDays()`, `categoryTotals()` to the existing `timesheetCapture` Alpine
  component. No new file: this component already owns every piece of state the review pane reads.
- **Modify:** `resources/views/screens/timesheets.blade.php` — wrap the existing day-editor markup
  in `x-show="!reviewing"`, add the new `x-show="reviewing"` pane as a sibling inside the same
  `x-data` root, rewire the Submit week button.
- **Create:** `tests/Feature/TimesheetSubmitReviewTest.php` — all new coverage for this feature.
  A new file, not an addition to `TimesheetPersonalTabsTest.php`, because it covers a different
  concern (the pre-submit review, not the Record/Review tab split) and keeps each test file's
  fixture set focused.

---

### Task 1: Review pane shell — state, focus, Escape, back-gesture, Submit button rewire

**Files:**
- Modify: `resources/js/timesheet-capture.js:738` (insert before the closing `}));`)
- Modify: `resources/views/screens/timesheets.blade.php:108-112` (wrap open) and `:662-668`
  (wrap close + new pane) and `:613-616` (Submit button)
- Test: `tests/Feature/TimesheetSubmitReviewTest.php` (new)

**Interfaces:**
- Produces: `reviewing` (bool, default `false`), `openReview()`, `closeReview()` — every later
  task's markup reads `reviewing` via `x-show`, and Task 2/3 add content inside the
  `x-show="reviewing"` pane this task creates.
- Consumes: existing `readonly`, `saving`, `weekComplete()`, `overDays()`, `blockingMessage()`,
  `save()` (all already on `timesheetCapture`, untouched).

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/TimesheetSubmitReviewTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimesheetSubmitReviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->tenant = Tenant::create(['slug' => 'unijaya', 'name' => 'Unijaya', 'initials' => 'UJ']);
        $this->user = User::create([
            'name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'password' => Hash::make('password'),
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Aisyah Rahman', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_the_record_tab_carries_a_review_pane(): void
    {
        $r = $this->actingInTenant()->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('Review before you submit', false);
        $r->assertSee('id="ts-review-title"', false);
    }

    public function test_the_submit_button_opens_the_review_instead_of_saving(): void
    {
        $r = $this->actingInTenant()->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('id="ts-submit-btn"', false);
        $r->assertSee('@click="openReview()"', false);
    }

    public function test_the_review_pane_has_its_own_confirm_button(): void
    {
        $r = $this->actingInTenant()->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('id="ts-confirm-submit-btn"', false);
        $r->assertSee('@click="save(true)"', false);
    }

    public function test_the_review_pane_closes_on_escape_and_the_back_gesture(): void
    {
        $r = $this->actingInTenant()->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('@keydown.escape.window', false);
        $r->assertSee('@popstate.window', false);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/TimesheetSubmitReviewTest.php`
Expected: FAIL — none of "Review before you submit", `ts-review-title`, `ts-submit-btn`,
`openReview()`, `ts-confirm-submit-btn`, `@keydown.escape.window`, `@popstate.window` exist yet.

- [ ] **Step 3: Add the JS state and open/close methods**

In `resources/js/timesheet-capture.js`, insert immediately before the closing `}));` (after
`save()`'s closing `},` at line 738):

```js
        // ---- pre-submit review ---------------------------------------------
        // A pane swap, not a dialog: no aria-modal, no focus trap. openReview()/closeReview()
        // own the two things a pane swap always gets wrong — where focus goes, and what the
        // back gesture does. Escape and "Back to editing" both call history.back() so every
        // closing path funnels through the one popstate listener below.
        reviewing: false,
        openReview() {
            if (this.readonly) return;
            this.reviewing = true;
            history.pushState(null, '', location.href);
            this.$nextTick(() => document.getElementById('ts-review-title')?.focus());
        },
        closeReview() {
            this.reviewing = false;
            this.$nextTick(() => document.getElementById('ts-submit-btn')?.focus());
        },
```

- [ ] **Step 4: Wrap the day-editor in `x-show="!reviewing"` and wire the popstate/Escape listeners**

In `resources/views/screens/timesheets.blade.php`, find:

```blade
            weekLabel: @js($weekLabel ?? null),
         })">

        {{-- ---- Week shelf: live week-percent allocated, read straight from the capture
```

Replace with:

```blade
            weekLabel: @js($weekLabel ?? null),
         })"
         @popstate.window="if (reviewing) closeReview()"
         @keydown.escape.window="if (reviewing) history.back()">

        <div x-show="!reviewing">
        {{-- ---- Week shelf: live week-percent allocated, read straight from the capture
```

- [ ] **Step 5: Close the wrapper and add the review pane shell**

In the same file, find the date-jump sheet's closing tags (immediately after the "Locked days…"
hint text):

```blade
                <div style="margin-top:14px;font-size:11.5px;color:var(--muted);line-height:1.5;">
                    <span x-text="$store.ui.lang==='en' ? 'Locked days (on leave, public holiday) are filled in for you and can\'t be edited.' : 'Hari dikunci (cuti, hari kelepasan) sudah diisi untuk anda dan tidak boleh disunting.'"></span>
                </div>
            </div>
        </div>
    </div>
    </div>
```

Replace with:

```blade
                <div style="margin-top:14px;font-size:11.5px;color:var(--muted);line-height:1.5;">
                    <span x-text="$store.ui.lang==='en' ? 'Locked days (on leave, public holiday) are filled in for you and can\'t be edited.' : 'Hari dikunci (cuti, hari kelepasan) sudah diisi untuk anda dan tidak boleh disunting.'"></span>
                </div>
            </div>
        </div>
        </div>

        {{-- ===================== REVIEW: full-page pre-submit check =====================
             Not a dialog: no backdrop, no aria-modal, no focus trap. Replaces the whole
             Record pane (tab bar and old Submit button included) so there is only ever one
             submit path on screen. ---- --}}
        <div x-show="reviewing" x-cloak
             x-transition:enter="uj-overlay-enter" x-transition:enter-start="uj-overlay-from" x-transition:enter-end="uj-overlay-to"
             x-transition:leave="uj-overlay-leave" x-transition:leave-start="uj-overlay-to" x-transition:leave-end="uj-overlay-from">
            <button type="button" @click="history.back()" class="uj-btn-ghost" style="height:32px;padding:0 10px;font-size:13px;margin-bottom:10px;">
                <span x-text="$store.ui.lang==='en' ? '← Back to editing' : '← Kembali sunting'">&larr; Back to editing</span>
            </button>
            <h2 id="ts-review-title" tabindex="-1" style="outline:none;font-size:18px;font-weight:600;margin:0 0 3px;">
                <span x-text="$store.ui.lang==='en' ? 'Review before you submit' : 'Semak sebelum hantar'">Review before you submit</span>
            </h2>
            <div style="font-size:12.5px;color:var(--muted);margin-bottom:18px;">{{ $weekStartC->format('j M') }} &ndash; {{ $weekStartC->copy()->addDays(4)->format('j M Y') }} &middot; <span x-text="$store.ui.lang==='en' ? 'every working day at 100%' : 'setiap hari bekerja pada 100%'"></span></div>

            <div id="ts-review-summary"></div>
            <div id="ts-review-days"></div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:22px;padding-top:16px;border-top:1px solid var(--hairline);flex-wrap:wrap;">
                <div style="font-size:12px;flex:1;min-width:200px;">
                    <span x-show="!weekComplete()" :style="{ color: overDays().length ? 'var(--error)' : 'var(--amber-ink)' }" x-text="blockingMessage()"></span>
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="button" @click="history.back()" :disabled="saving" class="uj-btn-ghost" style="height:40px;padding:0 18px;font-size:13px;">
                        <span x-text="$store.ui.lang==='en' ? 'Back to editing' : 'Kembali sunting'">Back to editing</span>
                    </button>
                    <button type="button" id="ts-confirm-submit-btn" @click="save(true)" :disabled="!weekComplete() || readonly || saving"
                        :style="(!weekComplete() || readonly || saving) ? { opacity:'.5', cursor:'not-allowed' } : {}"
                        class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;">
                        <span x-text="saving ? ($store.ui.lang==='en' ? 'Submitting…' : 'Menghantar…') : ($store.ui.lang==='en' ? 'Confirm & submit' : 'Sahkan & hantar')">Confirm &amp; submit</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    </div>
```

- [ ] **Step 6: Rewire the Submit week button**

In the same file, find:

```blade
                <button type="button" @click="save(false, true)" :disabled="readonly || saving" class="uj-btn-ghost" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save draft' : 'Simpan draf'">Save draft</span></button>
                <button type="button" @click="save(true)" :disabled="!weekComplete() || readonly || saving"
                    :style="(!weekComplete() || readonly) ? { opacity:'.5', cursor:'not-allowed' } : {}"
                    class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Submit week' : 'Hantar minggu'">Submit week</span></button>
```

Replace with:

```blade
                <button type="button" @click="save(false, true)" :disabled="readonly || saving" class="uj-btn-ghost" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save draft' : 'Simpan draf'">Save draft</span></button>
                <button type="button" id="ts-submit-btn" @click="openReview()" :disabled="!weekComplete() || readonly || saving"
                    :style="(!weekComplete() || readonly) ? { opacity:'.5', cursor:'not-allowed' } : {}"
                    class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Submit week' : 'Hantar minggu'">Submit week</span></button>
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/TimesheetSubmitReviewTest.php`
Expected: PASS (all 4 tests).

- [ ] **Step 8: Format and run the wider timesheet suite**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact --filter=Timesheet`
Expected: all green (this task only adds/moves markup and two new state properties — no
existing behaviour changes).

- [ ] **Step 9: Commit**

```bash
git add resources/js/timesheet-capture.js resources/views/screens/timesheets.blade.php tests/Feature/TimesheetSubmitReviewTest.php
git commit -m "$(cat <<'EOF'
feat(timesheets): open a full-page review before submit

Submit week no longer posts immediately — it opens a pane showing the
whole week first (header/footer only for now; entries land in the
next commits). Confirm & submit still calls the same save(true).
EOF
)"
```

---

### Task 2: Category summary — `reviewDays()`, `categoryTotals()`, stacked bar + legend

**Files:**
- Modify: `resources/js/timesheet-capture.js` (insert after Task 1's `closeReview()`)
- Modify: `resources/views/screens/timesheets.blade.php` (fill `#ts-review-summary`)
- Test: `tests/Feature/TimesheetSubmitReviewTest.php` (add a test)

**Interfaces:**
- Consumes: `dayTotal(iso)`, `lockedPct(iso)`, `locked`, `rows`, `categories`,
  `categoryColour(categoryId)` (all pre-existing on `timesheetCapture`).
- Produces: `reviewDays()` → `string[]` (sorted ISO dates: every day with `isEditable(d)` and a
  non-empty `rows[d]`, plus every day in `locked`). `categoryTotals()` →
  `{label: string, colour: string, pct: number}[]`, sorted by `pct` descending. Task 3's day
  cards read `reviewDays()` too — this is the one function both the summary and the day list
  must share, per the spec's "one source of truth" rule.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TimesheetSubmitReviewTest.php`:

```php
    public function test_the_review_pane_has_a_category_summary(): void
    {
        $r = $this->actingInTenant()->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('id="ts-review-summary"', false);
        $r->assertSee('categoryTotals()', false);
        $r->assertSee('reviewDays()', false);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=test_the_review_pane_has_a_category_summary`
Expected: FAIL — `#ts-review-summary` is currently an empty `<div>`, no `categoryTotals()` or
`reviewDays()` call anywhere in the page.

- [ ] **Step 3: Add `reviewDays()` and `categoryTotals()`**

In `resources/js/timesheet-capture.js`, insert immediately after `closeReview()`'s closing `},`
(added in Task 1):

```js
        // The exact set flatRows() will POST: isEditable() days holding rows, plus every
        // locked day. Deliberately NOT dayDates() — that follows the reactive 5/7 "Show
        // weekend" toggle, which flatRows() ignores entirely (Saturday's rows still post
        // even after the toggle is switched back to 5). Sorted so the day cards read Mon→Fri.
        reviewDays() {
            const rowDays = Object.keys(this.rows).filter((d) => this.isEditable(d) && (this.rows[d] || []).length);
            const lockedDays = Object.keys(this.locked);

            return [...new Set([...rowDays, ...lockedDays])].sort();
        },
        // Week split by category, over reviewDays() — not dayDates() — for the same reason.
        // Each day's contribution clamps at 100 so an over-allocated day can't push the split
        // past the headline week-percent figure shown directly above it.
        categoryTotals() {
            const days = this.reviewDays();
            const denom = Math.max(1, days.length) * 100;
            const buckets = {};

            for (const iso of days) {
                const raw = this.dayTotal(iso);
                const scale = raw > 100 ? 100 / raw : 1;

                if (this.locked[iso]) {
                    const label = this.locked[iso].label;
                    const key = 'locked:' + label;
                    buckets[key] = buckets[key] || { label, colour: 'var(--muted)', amount: 0 };
                    buckets[key].amount += this.lockedPct(iso) * scale;
                }
                for (const r of (this.rows[iso] || [])) {
                    const cat = this.categories.find((c) => String(c.id) === String(r.category_id));
                    const key = 'cat:' + r.category_id;
                    buckets[key] = buckets[key] || { label: cat ? cat.name : '', colour: this.categoryColour(r.category_id), amount: 0 };
                    buckets[key].amount += (parseFloat(r.percentage) || 0) * scale;
                }
            }

            return Object.values(buckets)
                .map((b) => ({ label: b.label, colour: b.colour, pct: Math.round((b.amount / denom) * 100) }))
                .filter((b) => b.pct > 0)
                .sort((a, b) => b.pct - a.pct);
        },
```

- [ ] **Step 4: Fill the summary card**

In `resources/views/screens/timesheets.blade.php`, find:

```blade
            <div id="ts-review-summary"></div>
```

Replace with:

```blade
            <div id="ts-review-summary" style="background:var(--shelf,#ece9e1);border:1px solid var(--shelf-line,#ddd9cf);border-radius:14px;padding:16px;margin-bottom:20px;">
                <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:10px;">
                    <span style="font-size:20px;font-weight:600;font-family:var(--font-mono);"
                        x-text="Math.round(reviewDays().reduce((s,d)=>s+Math.min(dayTotal(d),100),0) / Math.max(1, reviewDays().length*100) * 100) + '%'">0%</span>
                    <span style="font-size:12px;color:var(--body);" x-text="$store.ui.lang==='en' ? 'of the week allocated' : 'daripada minggu diperuntukkan'"></span>
                </div>
                <div aria-hidden="true" style="display:flex;height:9px;border-radius:999px;overflow:hidden;gap:2px;margin-bottom:10px;">
                    <template x-for="b in categoryTotals()" :key="b.label">
                        <div :style="{ width: b.pct + '%', background: b.colour }"></div>
                    </template>
                </div>
                <div style="display:flex;gap:14px;flex-wrap:wrap;">
                    <template x-for="b in categoryTotals()" :key="b.label + '-legend'">
                        <div style="display:flex;align-items:center;gap:6px;font-size:11px;color:var(--body);">
                            <span style="width:8px;height:8px;border-radius:2px;flex-shrink:0;" :style="{ background: b.colour }"></span>
                            <span x-text="b.label + ' · ' + b.pct + '%'"></span>
                        </div>
                    </template>
                </div>
            </div>
```

The bar is `aria-hidden="true"` — the legend right below it already carries every category name
and percentage as real text, so the bar would otherwise be a second, unlabelled copy for a
screen reader (spec, "Accessibility").

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=test_the_review_pane_has_a_category_summary`
Expected: PASS.

- [ ] **Step 6: Format and run the wider timesheet suite**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact --filter=Timesheet`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add resources/js/timesheet-capture.js resources/views/screens/timesheets.blade.php tests/Feature/TimesheetSubmitReviewTest.php
git commit -m "$(cat <<'EOF'
feat(timesheets): category breakdown on the pre-submit review

reviewDays() is the one place that decides which days are "the week
being submitted" — the summary bar and (next commit) the day cards
both read it, so they can never disagree with what flatRows() posts.
EOF
)"
```

---

### Task 3: Day cards — every entry, plus locked/half-locked days

**Files:**
- Modify: `resources/views/screens/timesheets.blade.php` (fill `#ts-review-days`)
- Test: `tests/Feature/TimesheetSubmitReviewTest.php` (add a test)

**Interfaces:**
- Consumes: `reviewDays()`, `rows`, `locked`, `lockedPct(iso)`, `dayTotal(iso)`, `dayLong(iso)`,
  `rowLabel(r)`, `categoryColour(categoryId)` (all pre-existing or added in Task 2).
- Produces: nothing new — this is the last piece the review pane needs.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TimesheetSubmitReviewTest.php`, adding the needed imports
(`App\Models\Project`, `App\Models\PublicHoliday`, `App\Models\Timesheet`,
`App\Models\TimesheetCategory`, `App\Models\TimesheetEntry`) to the `use` block at the top:

```php
    public function test_the_review_pane_renders_entries_and_locked_days_client_side(): void
    {
        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'AmanahKu Platform']);

        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft',
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $sheet->id, 'entry_date' => '2026-06-15',
            'category_id' => $category->id, 'project_id' => $project->id, 'percentage' => 100,
            'description' => 'Weekly review mockups, tab styling',
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $r = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $r->assertOk();
        // The row's own data reaches the page — rowLabel()/dayLong() resolve it client-side,
        // so this proves the data is there, not that Alpine has rendered it (browser-verified).
        $r->assertSee('Weekly review mockups, tab styling', false);
        $r->assertSee('"name":"AmanahKu Platform"', false);
        $r->assertSee('"label":"Awal Muharram"', false);
        // The day-card template itself is present.
        $r->assertSee('x-text="rowLabel(r)"', false);
        $r->assertSee('x-text="dayLong(d)"', false);
        $r->assertSee('x-text="locked[d].label"', false);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=test_the_review_pane_renders_entries_and_locked_days_client_side`
Expected: FAIL — `#ts-review-days` is currently an empty `<div>`; none of the `x-text` assertions
match anything on the page yet. (The data assertions — `Weekly review mockups…`,
`"name":"AmanahKu Platform"`, `"label":"Awal Muharram"` — already pass, since that data reaches
the page today via `existingGrid`/`tsProjects`/`tsLocked` regardless of the review pane. Confirm
the failure is specifically the three `x-text` assertions.)

- [ ] **Step 3: Fill the day cards**

In `resources/views/screens/timesheets.blade.php`, find:

```blade
            <div id="ts-review-days"></div>
```

Replace with:

```blade
            <div id="ts-review-days" style="display:flex;flex-direction:column;gap:14px;">
                <template x-for="d in reviewDays()" :key="d">
                    <div style="border:1px solid var(--hairline);border-radius:12px;padding:12px 15px;">
                        <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px;">
                            <span style="font-size:13px;font-weight:700;" x-text="dayLong(d)"></span>
                            <span style="font-size:12.5px;font-family:var(--font-mono);font-weight:600;color:var(--success-ink,#1f8a65);" x-text="dayTotal(d) + '%'"></span>
                        </div>
                        <template x-if="locked[d]">
                            <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-top:1px solid var(--hairline-soft);">
                                <span aria-hidden="true" style="font-size:11px;color:var(--muted);margin-top:2px;">&#128274;</span>
                                <div style="flex:1;font-size:12.5px;font-weight:600;" x-text="locked[d].label"></div>
                                <div style="font-size:13px;font-family:var(--font-mono);font-weight:600;" x-text="lockedPct(d) + '%'"></div>
                            </div>
                        </template>
                        <template x-for="(r, i) in (rows[d] || [])" :key="i">
                            <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-top:1px solid var(--hairline-soft);">
                                <span :style="{ background: categoryColour(r.category_id) }" style="width:8px;height:8px;border-radius:2px;margin-top:4px;flex-shrink:0;"></span>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:12.5px;font-weight:600;" x-text="rowLabel(r)"></div>
                                    <div x-show="r.description" style="font-size:11.5px;color:var(--body);margin-top:2px;" x-text="r.description"></div>
                                </div>
                                <div style="font-size:13px;font-family:var(--font-mono);font-weight:600;" x-text="(parseFloat(r.percentage)||0) + '%'"></div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
```

The lock icon is `aria-hidden="true"` — the row already states "On Leave" / the holiday's name in
text; a screen reader announcing "lock, On Leave" on top of that is noise (spec,
"Accessibility" — an existing gap on the day view too, not copied forward here).

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=test_the_review_pane_renders_entries_and_locked_days_client_side`
Expected: PASS.

- [ ] **Step 5: Format and run the wider timesheet suite**

Run: `vendor/bin/pint --dirty --format agent`
Run: `php artisan test --compact --filter=Timesheet`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add resources/views/screens/timesheets.blade.php tests/Feature/TimesheetSubmitReviewTest.php
git commit -m "$(cat <<'EOF'
feat(timesheets): day-by-day entries on the pre-submit review

Every day in reviewDays() gets its own card: locked leave/holiday
rows (with the lock icon hidden from screen readers, same gap the
day view already has) alongside the staffer's own lines. The review
is now feature-complete.
EOF
)"
```

---

### Task 4: Full suite, Pint, and browser verification

**Files:** none (verification only; fixes here land as small follow-up commits if anything
surfaces).

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: all green, including the pre-existing `TimesheetPersonalTabsTest`,
`TimesheetScreenDataTest`, `TimesheetTest`, `TimesheetChaseCardTest` (the wrap in Task 1 must not
change what those tests already assert about the Record tab's markup).

- [ ] **Step 2: Final Pint pass**

Run: `vendor/bin/pint --dirty --format agent`
Expected: clean (no output / no changed files).

- [ ] **Step 3: Browser verification — golden path**

Using the `manager@amanahku.test` or `employee@amanahku.test` dev quick-login (see project
CLAUDE.md, "Dev quick-login"), open the Timesheet screen for a week with a mix of entries across
several days:

- Click "Submit week". Confirm no network request fires (check the Network tab) — the review
  opens purely client-side.
- Confirm every day shown matches what was entered: category, project, sub-pillar, note, %.
- Confirm the category bar's segment widths and legend percentages sum to the headline week
  percentage shown above them.
- Click "Back to editing". Confirm the day editor reappears with nothing lost, and the Submit
  week button (not the URL) has focus.
- Click "Submit week" again, then "Confirm & submit". Confirm exactly one POST to
  `/app/timesheets` fires, and the page reloads into the submitted, read-only state.

- [ ] **Step 4: Browser verification — the Saturday case**

This is the scenario the spec calls "the load-bearing rule of the whole screen":

- On a fresh draft week, tap "Show weekend" (in the day strip), fill in Saturday with an entry,
  tap "Hide weekend" so Saturday is no longer visible in the day strip.
- Click "Submit week". Confirm the review still shows Saturday's card and entry, and the category
  breakdown is computed over 6 days, not 5.

- [ ] **Step 5: Browser verification — over-100% day**

- On a day, add enough lines to push it over 100%.
- Open the review (Submit week is disabled here per `weekComplete()`, so open it via the browser
  console: `document.querySelector('[x-data]').__x.$data.openReview()` — or temporarily comment
  out the `:disabled` binding locally to reach the screen). Confirm the category percentages
  still sum to the same headline week percentage shown above them (the 100-clamp working), and
  `blockingMessage()` explains the over-allocation next to the disabled Confirm button.

- [ ] **Step 6: Browser verification — keyboard and screen sizes**

- Tab from the page top with the review closed; click Submit week; confirm focus lands on the
  "Review before you submit" heading, not back at the top of the document.
- Tab forward: confirm nothing behind the review pane (the tab bar, the day editor) receives
  focus while `reviewing` is true.
- Press Escape: confirm the review closes and focus returns to the Submit week button.
- Reopen the review, use the browser's back button/gesture: confirm it closes the review, not
  the whole Timesheet screen.
- Resize to 1280px and 375px: confirm the day cards and category legend reflow without
  horizontal scrolling.

- [ ] **Step 7: Fix anything the browser pass finds**

If any check above fails, fix it in `timesheet-capture.js` / `timesheets.blade.php`, re-run
`php artisan test --compact --filter=Timesheet`, re-run `vendor/bin/pint --dirty --format agent`,
and commit the fix separately (do not fold into Task 1-3's commits, which already passed review
at the time they were made).

```bash
git add -A
git commit -m "fix(timesheets): <describe what the browser pass caught>"
```

- [ ] **Step 8: Nothing to commit if the pass was clean**

If Steps 3-6 found nothing, there is no Step 8 commit — Task 3's commit already represents the
finished, verified feature.
