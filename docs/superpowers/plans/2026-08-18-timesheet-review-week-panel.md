# Timesheet Review tab — weekly entry panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Timesheet Review tab's cost-list-and-breakdown content with a read-only, week-by-week view of the signed-in employee's own entries (prev/next arrows + a week picker, entries grouped by day), where clicking an entry jumps into the Record tab on that entry's week with its edit form open.

**Architecture:** Extract the week-block-building loop already shipped for the all-staff report (`TimesheetController::reportData()`) into a shared `buildWeekBlocks()` method, reuse it for the signed-in employee's own weeks (`screenData()`), and render them with a new Alpine component (`timesheet-review.js`) that steps through the preloaded weeks client-side — same pattern the report screen's person drill-down already uses, no per-week network round trip. Entry links are plain `<a href>`, so the existing `partial-nav.js` global click-intercept handles the navigation into Record with no new JS wiring; `timesheet-capture.js` gets one small addition to auto-open a specific entry's edit form when the URL asks for it.

**Tech Stack:** Laravel 13 / PHP 8.5 (PHPUnit 12 feature tests), Blade + Alpine.js 3, Tailwind v4 (`resources/css/app.css`), Bun for JS build/test.

## Global Constraints

- Use `php artisan` commands directly (not composer scripts) for backend verification; `--compact` and `--filter`/a specific test file to keep runs fast.
- Run `vendor/bin/pint --dirty --format agent` after any PHP file edit, before considering a task done.
- Bun only for JS — `bun test`, `bun run build` — never `npm`/`node`/`npx`.
- No new dependencies. No new routes. No new authorization logic — every change stays inside the existing employee-scoped `screenData()`/Record-tab flow.
- Bilingual: every new user-visible string uses the existing `$store.ui.lang==='en' ? … : …` pattern.
- Full spec: `docs/superpowers/specs/2026-08-18-timesheet-review-week-panel-design.md`.

---

## File Structure

| File | Change |
|---|---|
| `app/Http/Controllers/TimesheetController.php` | Extract `buildWeekBlocks()`; wire `myWeeks` into `screenData()`; drop `personalBreakdown()`/`myBreakdown`; add `id` to `existingGrid` rows. |
| `resources/views/screens/timesheets.blade.php` | Replace Review tab markup (lines 755-845) with the new week-panel component; add `editEntryId` to the Record tab's `timesheetCapture(...)` config (lines 94-108). |
| `resources/css/app.css` | Add `.uj-tr-status-badge`, `.uj-tr-weekpick`, and link-specific `a.uj-tr-ent` states, next to the existing `.uj-tr-*` block (~line 1917). |
| `resources/js/timesheet-review.js` | **New.** Pure helpers (`fmtDays`, `reviewEntryUrl`) + `registerTimesheetReview(Alpine)`. |
| `resources/js/timesheet-review.test.js` | **New.** Bun tests for the pure helpers. |
| `resources/js/app.js` | Register the new component. |
| `resources/js/timesheet-capture.js` | Seed `id` on rows; add exported `findEditTarget()`; wire `editEntryId` auto-open into `init()`. |
| `resources/js/timesheet-capture.test.js` | Add tests for `findEditTarget()` and the readonly-skip guard. |
| `tests/Feature/TimesheetReviewWeekPanelTest.php` | **New.** Backend (`myWeeks`, `existingGrid` id) + Blade rendering coverage. |

---

### Task 1: Extract and extend `buildWeekBlocks()`

**Files:**
- Modify: `app/Http/Controllers/TimesheetController.php:582-638` (extract), `:397-406` call site area unaffected, new private method placed near `personalBreakdown()`.
- Test: `tests/Feature/TimesheetReportLensTest.php` (existing, run unmodified as a regression check), `tests/Feature/TimesheetReviewWeekPanelTest.php` (new, created in this task for the new fields).

**Interfaces:**
- Produces: `private function buildWeekBlocks(Collection $entries, ?Collection $timesheetsByWeekStart = null): array` — each block is `['label' => string, 'dates' => string, 'weekStart' => string, 'status' => ?string, 'days' => float, 'cost' => float, 'lines' => array]`; each line is `['id' => int, 'day' => string, 'label' => string, 'note' => ?string, 'days' => float]`.

- [ ] **Step 1: Write the failing test for the new fields**

Create `tests/Feature/TimesheetReviewWeekPanelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Controllers\TimesheetController;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use App\Tenancy\CurrentTenant;
use Tests\TestCase;

class TimesheetReviewWeekPanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    private TimesheetCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Ops', 'requires_project' => false,
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

    public function test_reportdata_week_blocks_carry_week_start_and_line_id(): void
    {
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        $entry = $ts->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8,
        ]);

        app(CurrentTenant::class)->set($this->tenant);
        $request = Request::create('/app/timesheet-reports', 'GET', ['from' => '2026-06-15', 'to' => '2026-06-19']);
        $request->attributes->set('tenantRole', 'hr');
        $request->attributes->set('tenantScope', 'company');

        $data = app(TimesheetController::class)->reportData($request, $this->employee);

        $week = $data['staffWeeks'][$this->employee->id][0];
        $this->assertSame('2026-06-15', $week['weekStart']);
        $this->assertNull($week['status']); // reportData() doesn't pass $timesheetsByWeekStart
        $this->assertSame($entry->id, $week['lines'][0]['id']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/TimesheetReviewWeekPanelTest.php`
Expected: FAIL — `weekStart`/`id` keys don't exist yet (the field is simply absent from the current inline loop).

- [ ] **Step 3: Extract and extend `buildWeekBlocks()`**

In `app/Http/Controllers/TimesheetController.php`, replace lines 582-638 (the `$staffWeeks` build block, from `// ----- Staff weeks: person -> list of week blocks -----` through the closing `}` of the `foreach ($entriesByEmployee ...)` loop) with:

```php
        // ----- Staff weeks: person -> list of week blocks -----
        $staffWeeks = [];
        $entriesByEmployee = $entries->groupBy(fn ($e) => (int) $e->timesheet->employee_id);
        foreach ($entriesByEmployee as $empId => $empEntries) {
            $staffWeeks[$empId] = $this->buildWeekBlocks($empEntries);
        }
```

Then add the extracted-and-extended method. Place it directly above `personalBreakdown()` (before line 712):

```php
    /**
     * Group one person's timesheet entries into week blocks: label, date range, totals,
     * and each day's lines. Shared by the all-staff report (reportData(), one call per
     * employee, no $timesheetsByWeekStart — status stays null, unused there) and the
     * personal Review tab (screenData(), one call for the signed-in employee, with
     * $timesheetsByWeekStart so a week with a Timesheet row but zero entries yet still
     * gets a block).
     *
     * @param  Collection<int, TimesheetEntry>  $entries
     * @param  Collection<string, Timesheet>|null  $timesheetsByWeekStart  keyed by week_start date string
     * @return array<int, array{label: string, dates: string, weekStart: string, status: ?string, days: float, cost: float, lines: array}>
     */
    private function buildWeekBlocks(Collection $entries, ?Collection $timesheetsByWeekStart = null): array
    {
        $byWeek = $entries->groupBy(fn (TimesheetEntry $e) => Carbon::parse($e->entry_date)->startOfWeek()->toDateString());

        $weekStartStrs = $timesheetsByWeekStart
            ? $byWeek->keys()->merge($timesheetsByWeekStart->keys())->unique()->sort()
            : $byWeek->keys()->sort();

        $weekBlocks = [];
        foreach ($weekStartStrs as $weekStartStr) {
            $weekEntries = $byWeek->get($weekStartStr, collect());
            $weekStart = Carbon::parse($weekStartStr);
            $mon = $weekStart->copy();
            $fri = $weekStart->copy()->addDays(4);

            $dates = $mon->month === $fri->month
                ? $mon->format('j').' – '.$fri->format('j M')
                : $mon->format('j M').' – '.$fri->format('j M');

            $sortedLines = $weekEntries->sort(function (TimesheetEntry $a, TimesheetEntry $b) {
                $dateCmp = $a->entry_date->toDateString() <=> $b->entry_date->toDateString();
                if ($dateCmp !== 0) {
                    return $dateCmp;
                }
                $aDays = round((float) $a->percentage / 100, 2);
                $bDays = round((float) $b->percentage / 100, 2);

                return $bDays <=> $aDays;
            });

            $lines = [];
            foreach ($sortedLines as $e) {
                $labelParts = array_filter([
                    $e->category?->name ?: $e->project,
                    $e->projectRef?->name,
                    $e->subPillar?->name,
                ]);

                $lines[] = [
                    'id' => $e->id,
                    'day' => $e->entry_date->format('D j M'),
                    'label' => implode(' · ', $labelParts),
                    'note' => $e->description,
                    'days' => round((float) $e->percentage / 100, 2),
                ];
            }

            $weekDays = round($weekEntries->sum(fn ($e) => (float) $e->percentage) / 100, 2);
            $weekCost = round($weekEntries->sum(fn ($e) => (float) ($e->cost ?? 0)), 2);

            $weekBlocks[] = [
                'label' => 'Week '.$weekStart->isoWeek,
                'dates' => $dates,
                'weekStart' => $weekStartStr,
                'status' => $timesheetsByWeekStart?->get($weekStartStr)?->status,
                'days' => $weekDays,
                'cost' => $weekCost,
                'lines' => $lines,
            ];
        }

        return $weekBlocks;
    }

```

- [ ] **Step 4: Run the new test, and the existing report tests, to confirm no regression**

Run: `php artisan test --compact tests/Feature/TimesheetReviewWeekPanelTest.php tests/Feature/TimesheetReportLensTest.php tests/Feature/TimesheetReportScreenTest.php`
Expected: All PASS. The report tests passing unmodified proves the extraction preserved `reportData()`'s exact prior output (same label/dates/days/cost/lines shape it always had, plus the new ignored `weekStart`/`status`/`id` fields those tests don't check).

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TimesheetController.php tests/Feature/TimesheetReviewWeekPanelTest.php
git commit -m "refactor(timesheet): extract buildWeekBlocks(), add weekStart/status/id fields"
```

---

### Task 2: Wire `myWeeks` into `screenData()`, drop the breakdown card's data, add entry `id` to `existingGrid`

**Files:**
- Modify: `app/Http/Controllers/TimesheetController.php:102-127` (screenData body), `:167-191` (return array), `:712-749` (delete `personalBreakdown()`).
- Test: `tests/Feature/TimesheetReviewWeekPanelTest.php` (extend).

**Interfaces:**
- Consumes: `buildWeekBlocks()` from Task 1.
- Produces: `screenData()` return array gains `myWeeks` (same shape as `staffWeeks[$empId]` above, but not keyed — a plain list for one employee) and each `existingGrid` row gains `id`. `myBreakdown`/`breakdownFrom`/`breakdownTo` are removed from the return array — Task 3 depends on `myWeeks` existing; nothing depends on the removed keys (verified via `grep -rn "myBreakdown\|breakdownFrom\|breakdownTo" tests/ resources/` returning no hits outside the view being replaced in Task 3).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/TimesheetReviewWeekPanelTest.php`:

```php
    public function test_screendata_my_weeks_includes_every_week_including_an_empty_draft(): void
    {
        $submitted = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-08', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        $submitted->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-08',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8,
        ]);
        // Current week's draft exists but has no entries yet.
        Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets');

        $response->assertOk();
        $weeks = collect($response->viewData('myWeeks'))->keyBy('weekStart');
        $this->assertCount(2, $weeks);
        $this->assertSame('submitted', $weeks['2026-06-08']['status']);
        $this->assertSame(1.0, $weeks['2026-06-08']['days']);
        $this->assertSame('draft', $weeks['2026-06-15']['status']);
        $this->assertSame([], $weeks['2026-06-15']['lines']);
    }

    public function test_existing_grid_rows_carry_the_entry_id(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        $entry = $sheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $grid = $response->viewData('existingGrid');
        $this->assertSame($entry->id, $grid['2026-06-15'][0]['id']);
    }
```

- [ ] **Step 2: Run to verify both fail**

Run: `php artisan test --compact tests/Feature/TimesheetReviewWeekPanelTest.php`
Expected: FAIL — `myWeeks` view data key doesn't exist; `existingGrid` rows have no `id`.

- [ ] **Step 3: Wire `myWeeks`**

In `screenData()`, replace lines 102-105:

```php
        // Personal time breakdown (person-days, never RM) for the signed-in staff: where
        // their own recorded time went, by category and project, over a chosen period.
        [$pbFrom, $pbTo] = $this->periodFromRequest($request);
        $myBreakdown = $employee ? $this->personalBreakdown($employee, $pbFrom, $pbTo) : null;
```

with:

```php
        // Week-by-week view of the signed-in staff's own entries (Review tab): every
        // week they have a Timesheet row for, draft or submitted, entries grouped by
        // day. No date bound — one person's data, small by construction.
        $myTimesheetsByWeekStart = $myTimesheets->keyBy(fn (Timesheet $t) => $t->week_start->toDateString());
        $myWeeks = $employee ? $this->buildWeekBlocks($myTimesheets->flatMap->entries, $myTimesheetsByWeekStart) : [];
```

In the return array (lines 167-191), remove:

```php
            // Personal time breakdown (days only) + its period.
            'myBreakdown' => $myBreakdown,
            'breakdownFrom' => $pbFrom->toDateString(),
            'breakdownTo' => $pbTo->toDateString(),
```

and add, next to `'myTimesheets' => $myTimesheets,`:

```php
            'myWeeks' => $myWeeks,
```

- [ ] **Step 4: Add `id` to `existingGrid`**

In `screenData()`, lines 119-125, replace:

```php
                $existingGrid[$e->entry_date->toDateString()][] = [
                    'category_id' => $e->category_id,
```

with:

```php
                $existingGrid[$e->entry_date->toDateString()][] = [
                    'id' => $e->id,
                    'category_id' => $e->category_id,
```

(rest of that array literal unchanged).

- [ ] **Step 5: Delete `personalBreakdown()`**

Delete lines 712-749 (the docblock at 712-718 through the method's closing `}` and its trailing blank line).

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Feature/TimesheetReviewWeekPanelTest.php`
Expected: PASS.

Run the full existing timesheet suite to confirm nothing else touched `myBreakdown`/`personalBreakdown`:
Run: `php artisan test --compact --filter=Timesheet`
Expected: all PASS. The Review tab's old breakdown markup is still in the Blade file at
this point (Task 3 replaces it), but every reference to `$myBreakdown`/`$breakdownFrom`/
`$breakdownTo` sits inside `@if (! empty($myBreakdown)) … @endif`
(`timesheets.blade.php:782-843`) — `empty()` on an undefined variable is `true` with no
warning, so that whole block goes dead-but-harmless rather than erroring. If anything
fails here, stop and investigate before continuing; it means a reference to one of these
three exists somewhere this plan didn't account for.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TimesheetController.php tests/Feature/TimesheetReviewWeekPanelTest.php
git commit -m "feat(timesheet): add myWeeks + entry id to screenData(), drop personal breakdown"
```

---

### Task 3: Review tab UI — new Alpine component, Blade markup, CSS

**Files:**
- Create: `resources/js/timesheet-review.js`
- Create: `resources/js/timesheet-review.test.js`
- Modify: `resources/js/app.js`
- Modify: `resources/views/screens/timesheets.blade.php:755-845`
- Modify: `resources/css/app.css` (insert after line 1917)
- Test: `tests/Feature/TimesheetReviewWeekPanelTest.php` (extend)

**Interfaces:**
- Consumes: `myWeeks` from Task 2 (`weekStart`, `status`, `label`, `dates`, `days`, `lines[].{id,day,label,note,days}`).
- Produces: `Alpine.data('timesheetReview', cfg => {...})` registered globally; `export function fmtDays(n): string`; `export function reviewEntryUrl(baseUrl, weekStart, line): string|null` — both importable and used by Task 4 only by reference (Task 4 doesn't call these directly, no interface dependency).

- [ ] **Step 1: Write the failing JS tests**

Create `resources/js/timesheet-review.test.js`:

```js
import { test, expect } from 'bun:test';
import { fmtDays, reviewEntryUrl } from './timesheet-review';

test('fmtDays() drops trailing zeros', () => {
    expect(fmtDays(1)).toBe('1');
    expect(fmtDays(0.5)).toBe('0.5');
    expect(fmtDays(1.25)).toBe('1.25');
});

test('reviewEntryUrl() builds a tab=record + week + edit link for a normal entry', () => {
    const url = reviewEntryUrl('/app/timesheets', '2026-06-15', { id: 42 });
    expect(url).toBe('/app/timesheets?tab=record&week=2026-06-15&edit=42');
});

test('reviewEntryUrl() returns null for a system-generated line (no id)', () => {
    expect(reviewEntryUrl('/app/timesheets', '2026-06-15', { id: null })).toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `bun test resources/js/timesheet-review.test.js`
Expected: FAIL — `./timesheet-review` module doesn't exist yet.

- [ ] **Step 3: Write `timesheet-review.js`**

Create `resources/js/timesheet-review.js`:

```js
/**
 * Timesheet Review tab — read-only week-by-week view of the signed-in employee's own
 * entries. Reuses the week-block shape and step-through-preloaded-weeks pattern
 * timesheet-report.js's person drill-down already established (weekIdx/prevWeek/
 * nextWeek, no fetch per step), for one person's own weeks instead of a viewed
 * colleague's.
 */
import { dayColor } from './timesheet-report';

export function fmtDays(n) {
    return (Math.round(n * 100) / 100).toFixed(2).replace(/\.?0+$/, '');
}

/**
 * Build the link into Record for one entry line: its week, its edit form. Lines with
 * no `id` are system-generated (leave/holiday) — Record has no editable row for those
 * (see TimesheetController::existingGrid, which excludes source-tagged entries), so
 * there is nothing to link to.
 */
export function reviewEntryUrl(baseUrl, weekStart, line) {
    if (!line.id) return null;
    const sep = baseUrl.includes('?') ? '&' : '?';

    return `${baseUrl}${sep}tab=record&week=${encodeURIComponent(weekStart)}&edit=${encodeURIComponent(line.id)}`;
}

export function registerTimesheetReview(Alpine) {
    Alpine.data('timesheetReview', (cfg) => ({
        baseUrl: cfg.baseUrl,
        weeks: cfg.weeks || [],
        weekIdx: Math.max(0, (cfg.weeks || []).length - 1), // default: most recent week
        weekDir: 'fwd',

        get currentWeek() { return this.weeks[this.weekIdx] || null; },
        prevWeek() { if (this.weekIdx > 0) { this.weekDir = 'back'; this.weekIdx--; } },
        nextWeek() { if (this.weekIdx < this.weeks.length - 1) { this.weekDir = 'fwd'; this.weekIdx++; } },

        fmtDays,
        dayColor,
        entryUrl(line) { return reviewEntryUrl(this.baseUrl, this.currentWeek?.weekStart, line); },
    }));
}
```

- [ ] **Step 4: Run the JS tests**

Run: `bun test resources/js/timesheet-review.test.js`
Expected: PASS.

- [ ] **Step 5: Register the component**

In `resources/js/app.js`, add the import next to the existing timesheet imports (line 16):

```js
import { registerTimesheetReview } from './timesheet-review';
```

And the registration call next to line 60:

```js
registerTimesheetReview(Alpine);
```

- [ ] **Step 6: Add CSS**

In `resources/css/app.css`, insert after line 1917 (`.uj-tr-wk[data-dir="back"] { ... }`), before the `@media (prefers-reduced-motion: reduce)` block:

```css

.uj-tr-status-badge { display: inline-block; font-size: var(--t-micro); font-weight: 600; padding: 2px 8px; border-radius: 999px; margin-right: 6px; }
.uj-tr-status-badge[data-status="draft"] { color: var(--muted); background: var(--hairline-soft); }
.uj-tr-status-badge[data-status="submitted"] { color: var(--amber-ink); background: #f8ecd8; }

.uj-tr-weekpick { display: block; width: 100%; max-width: 280px; margin: 8px auto 12px; padding: 7px 10px; text-align: center; font-size: var(--t-sm); color: var(--ink); background: var(--card); border: 1px solid var(--shelf-line); border-radius: 9px; }

/* Review tab's entry rows are real links into Record; the report screen's own
   .uj-tr-ent (plain divs, no link) is untouched by this. */
a.uj-tr-ent { text-decoration: none; cursor: pointer; border-radius: 8px; margin: 0 -8px; padding: 3px 8px; transition: background .12s var(--ease); }
a.uj-tr-ent:hover { background: var(--hairline-soft); }
a.uj-tr-ent:focus-visible { outline: 2px solid var(--red); outline-offset: -2px; }
```

- [ ] **Step 7: Write the failing Blade rendering test**

Add to `tests/Feature/TimesheetReviewWeekPanelTest.php`:

```php
    public function test_review_tab_renders_week_nav_and_entry_link(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        $entry = $sheet->entries()->create([
            'tenant_id' => $this->tenant->id, 'entry_date' => '2026-06-15',
            'category_id' => $this->category->id, 'percentage' => 100, 'hours' => 8,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?tab=review');

        $response->assertOk();
        $response->assertSee('timesheetReview(', false);
        $response->assertSee('"weekStart":"2026-06-15"', false);
        $response->assertSee('"id":'.$entry->id, false);
    }

    public function test_review_tab_has_no_cost_gate(): void
    {
        // A plain employee (not a money role) still gets myWeeks — no canSeeCost check
        // wraps the Review tab any more.
        Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?tab=review');

        $response->assertOk();
        $response->assertSee('timesheetReview(', false);
    }
```

- [ ] **Step 8: Run to verify these two fail**

Run: `php artisan test --compact tests/Feature/TimesheetReviewWeekPanelTest.php`
Expected: FAIL — the Blade still renders the old "My weeks" / breakdown markup, no `timesheetReview(` component exists in the response.

- [ ] **Step 9: Replace the Review tab markup**

In `resources/views/screens/timesheets.blade.php`, replace lines 755-845 (from `<div x-show="tab==='review'" x-cloak role="tabpanel">` through its matching `</div>{{-- /tab=review panel --}}`) with:

```blade
    <div x-show="tab==='review'" x-cloak role="tabpanel"
         x-data="timesheetReview({
            baseUrl: @js(route('app.screen', ['screen' => 'timesheets'])),
            weeks: @js($myWeeks),
         })">
        <template x-if="weeks.length === 0">
            <div class="uj-tr-panel">
                <div class="uj-tr-empty" x-text="$store.ui.lang==='en' ? 'No weeks yet.' : 'Belum ada minggu.'"></div>
            </div>
        </template>
        <template x-if="weeks.length > 0">
            <div class="uj-tr-panel">
                <div class="uj-tr-weeknav-hd">
                    <button type="button" class="uj-tr-weeknav-btn" @click="prevWeek()" :disabled="weekIdx === 0"
                        :aria-label="$store.ui.lang==='en' ? 'Previous week' : 'Minggu sebelum'">&lsaquo;</button>
                    <span class="uj-tr-weeknav-pos" x-text="(weekIdx + 1) + ' / ' + weeks.length"></span>
                    <button type="button" class="uj-tr-weeknav-btn" @click="nextWeek()" :disabled="weekIdx === weeks.length - 1"
                        :aria-label="$store.ui.lang==='en' ? 'Next week' : 'Minggu seterusnya'">&rsaquo;</button>
                </div>
                <select class="uj-tr-weekpick" x-model.number="weekIdx"
                    :aria-label="$store.ui.lang==='en' ? 'Jump to week' : 'Lompat ke minggu'">
                    <template x-for="(w, i) in weeks" :key="i">
                        <option :value="i" x-text="w.label + ' — ' + w.dates"></option>
                    </template>
                </select>
                <template x-for="wk in (currentWeek ? [currentWeek] : [])" :key="weekIdx">
                    <div class="uj-tr-wk" :data-dir="weekDir">
                        <div class="hdr">
                            <span x-text="wk.label + ' · ' + wk.dates"></span>
                            <span>
                                <span class="uj-tr-status-badge" :data-status="wk.status || 'draft'"
                                    x-text="wk.status === 'submitted' ? ($store.ui.lang==='en' ? 'Submitted' : 'Dihantar') : ($store.ui.lang==='en' ? 'Draft' : 'Draf')"></span>
                                <span x-text="fmtDays(wk.days) + ' md'"></span>
                            </span>
                        </div>
                        <template x-if="wk.lines.length === 0">
                            <div class="uj-tr-empty" x-text="$store.ui.lang==='en' ? 'No entries this week.' : 'Tiada entri minggu ini.'"></div>
                        </template>
                        <template x-for="(line, lidx) in wk.lines" :key="lidx">
                            <template x-if="line.id">
                                <a class="uj-tr-ent" :href="entryUrl(line)">
                                    <div class="uj-tr-ent-day" :style="'color:' + dayColor(line.day)" x-text="line.day"></div>
                                    <span x-text="line.label"></span>
                                    <template x-if="line.note">
                                        <span class="n" x-html="line.note"></span>
                                    </template>
                                    <span class="d" x-text="fmtDays(line.days)"></span>
                                </a>
                            </template>
                            <template x-if="!line.id">
                                <div class="uj-tr-ent">
                                    <div class="uj-tr-ent-day" :style="'color:' + dayColor(line.day)" x-text="line.day"></div>
                                    <span x-text="line.label"></span>
                                    <template x-if="line.note">
                                        <span class="n" x-html="line.note"></span>
                                    </template>
                                    <span class="d" x-text="fmtDays(line.days)"></span>
                                </div>
                            </template>
                        </template>
                        <template x-if="wk.status === 'submitted'">
                            <div class="uj-tr-note" x-text="$store.ui.lang==='en'
                                ? 'This week is submitted. Click an entry to open it on the Record tab — reopen it there to make changes.'
                                : 'Minggu ini telah dihantar. Ketik satu entri untuk membukanya di tab Rekod — buka semula di sana untuk membuat perubahan.'"></div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>{{-- /tab=review panel --}}
```

- [ ] **Step 10: Run the Blade rendering tests**

Run: `php artisan test --compact tests/Feature/TimesheetReviewWeekPanelTest.php`
Expected: PASS.

- [ ] **Step 11: Run the full timesheet suite**

Run: `php artisan test --compact --filter=Timesheet`
Expected: All PASS, including `TimesheetPersonalTabsTest` (it only checks tab-switch behavior and doesn't reference the removed markup, per the earlier `grep` — confirm no failures there).

- [ ] **Step 12: Pint, build assets, commit**

```bash
vendor/bin/pint --dirty --format agent
bun run build
git add resources/js/timesheet-review.js resources/js/timesheet-review.test.js resources/js/app.js \
        resources/views/screens/timesheets.blade.php resources/css/app.css public/build \
        tests/Feature/TimesheetReviewWeekPanelTest.php
git commit -m "feat(timesheet): render the Review tab as a read-only weekly entry panel"
```

---

### Task 4: Click-through into Record — auto-open the linked entry

**Files:**
- Modify: `resources/js/timesheet-capture.js:62-74` (row seeding), add exported `findEditTarget()`, modify `init()` (after line 90).
- Modify: `resources/views/screens/timesheets.blade.php:94-108` (add `editEntryId` to the `timesheetCapture(...)` config) — *(this is the same file as Task 3, a different block — the Record tab's own config, untouched by Task 3.)*
- Test: `resources/js/timesheet-capture.test.js`

**Interfaces:**
- Consumes: `?edit=<id>` query param (read server-side in Blade, passed as `cfg.editEntryId` — not read client-side from `window.location`, since `window` doesn't exist under the bun test runner and every other cross-request value on this screen, e.g. `tab`, is already resolved server-side in Blade and handed to Alpine as a plain config value).
- Produces: `export function findEditTarget(rows, editId): {iso: string, index: number} | null`.

- [ ] **Step 1: Write the failing test for `findEditTarget()`**

Add to `resources/js/timesheet-capture.test.js` (near the top, after the existing imports):

```js
import { findEditTarget } from './timesheet-capture';
```

Add test cases:

```js
test('findEditTarget() finds the day and index of a matching row id', () => {
    const rows = {
        '2026-08-03': [{ id: 10, category_id: 1 }, { id: 11, category_id: 2 }],
        '2026-08-04': [{ id: 12, category_id: 1 }],
    };
    expect(findEditTarget(rows, '11')).toEqual({ iso: '2026-08-03', index: 1 });
    expect(findEditTarget(rows, 12)).toEqual({ iso: '2026-08-04', index: 0 });
});

test('findEditTarget() returns null when no row matches', () => {
    const rows = { '2026-08-03': [{ id: 10 }] };
    expect(findEditTarget(rows, '999')).toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `bun test resources/js/timesheet-capture.test.js`
Expected: FAIL — `findEditTarget` is not exported.

- [ ] **Step 3: Add `id` to the row seed and export `findEditTarget()`**

In `resources/js/timesheet-capture.js`, replace lines 67-73:

```js
                this.rows[iso] = seed[iso].map((e) => ({
                    category_id: e.category_id || '',
                    project_id: e.project_id || '',
                    sub_pillar_id: e.sub_pillar_id || '',
                    description: e.description || '',
                    percentage: e.percentage,
                }));
```

with:

```js
                this.rows[iso] = seed[iso].map((e) => ({
                    id: e.id,
                    category_id: e.category_id || '',
                    project_id: e.project_id || '',
                    sub_pillar_id: e.sub_pillar_id || '',
                    description: e.description || '',
                    percentage: e.percentage,
                }));
```

Add the exported helper near the top of the file, just before `export function registerTimesheetCapture(Alpine) {`:

```js
/** Find which day + row index carries this entry id, for the Review tab's "open this
 *  entry" deep link. Pure — no Alpine/DOM — so it's testable without a browser. */
export function findEditTarget(rows, editId) {
    for (const iso of Object.keys(rows)) {
        const i = rows[iso].findIndex((r) => String(r.id) === String(editId));
        if (i !== -1) return { iso, index: i };
    }

    return null;
}

```

- [ ] **Step 4: Run the test**

Run: `bun test resources/js/timesheet-capture.test.js`
Expected: PASS on the two new tests; all pre-existing tests in this file still pass (the `id` addition to seeded rows doesn't remove or rename any field the other tests check).

- [ ] **Step 5: Write the failing readonly-guard test**

Add to `resources/js/timesheet-capture.test.js`:

```js
test('init() ignores editEntryId on a readonly (submitted) week — nothing to auto-open until recalled', () => {
    const c = makeComponent({ days: 5, readonly: true, editEntryId: '10', existing: { [WEEK_START]: [{ id: 10, category_id: 1, percentage: 100 }] } });
    expect(() => c.init()).not.toThrow();
    expect(c.picker.open).toBe(false);
});
```

- [ ] **Step 6: Run to verify it fails**

Run: `bun test resources/js/timesheet-capture.test.js`
Expected: FAIL — `editEntryId` isn't read anywhere yet, so this specific test actually already passes trivially (nothing throws, picker stays closed) — but confirm by running it in isolation before Step 7's change, so it's a real before/after check: `bun test resources/js/timesheet-capture.test.js -t "ignores editEntryId"`.

- [ ] **Step 7: Wire `editEntryId` into `init()`**

In `resources/js/timesheet-capture.js`, add `editEntryId: cfg.editEntryId || null,` to the component's property list, alongside `readonly: cfg.readonly || false,` (in the `Alpine.data('timesheetCapture', (cfg) => ({ ... }))` object).

At the end of `init()` (after the `if (!this.readonly && this.weekComplete()) { ... }` block, i.e. after line 89's closing `}`), add:

```js

            // Deep link from the Review tab ("open this entry"). Skipped on a submitted
            // week: Record shows it locked with a reopen banner, there is no row to edit
            // until it's recalled, and openEditRow() assumes an editable this.selected day.
            if (this.editEntryId && !this.readonly) {
                const target = findEditTarget(this.rows, this.editEntryId);
                if (target) {
                    this.selected = target.iso;
                    this.$nextTick(() => this.openEditRow(target.index));
                }
            }
```

- [ ] **Step 8: Run all timesheet-capture tests**

Run: `bun test resources/js/timesheet-capture.test.js`
Expected: PASS — including the readonly-guard test (still true after the wiring exists, because `!this.readonly` short-circuits before touching `this.$nextTick`/`openEditRow`, which the DOM-less test runner can't execute).

- [ ] **Step 9: Pass `editEntryId` from Blade**

In `resources/views/screens/timesheets.blade.php`, in the Record tab's `timesheetCapture({...})` config (lines 94-108), add one line after `readonly: @js($weekLocked),`:

```blade
            readonly: @js($weekLocked),
            editEntryId: @js(request()->query('edit')),
```

- [ ] **Step 10: Run the full JS suite and the full timesheet PHP suite**

Run: `bun test resources/js/`
Expected: PASS, all files.

Run: `php artisan test --compact --filter=Timesheet`
Expected: PASS, all files (Blade change is additive — one more key in an existing `@js()` config object — no existing assertion inspects that object's exact key set).

- [ ] **Step 11: Pint, build assets, commit**

```bash
vendor/bin/pint --dirty --format agent
bun run build
git add resources/js/timesheet-capture.js resources/js/timesheet-capture.test.js \
        resources/views/screens/timesheets.blade.php public/build
git commit -m "feat(timesheet): auto-open a Review-tab-linked entry on the Record tab"
```

---

### Task 5: Full regression and manual verification

**Files:** none (verification only).

- [ ] **Step 1: Full backend suite**

Run: `php artisan test --compact`
Expected: PASS. Any failure outside the Timesheet* files means something unrelated broke — stop and investigate rather than proceeding.

- [ ] **Step 2: Full JS suite**

Run: `bun test resources/js/`
Expected: PASS.

- [ ] **Step 3: Pint check (whole diff, not just `--dirty`)**

Run: `vendor/bin/pint --format agent`
Expected: no changes reported beyond what earlier tasks already committed.

- [ ] **Step 4: Manual browser verification**

Using the dev quick-login (`employee@amanahku.test`, local only):

1. Open Timesheet → Review tab. Confirm the week nav (arrows + picker) appears, defaults to the most recent week, and a plain (non-money) employee account sees it (no `canSeeCost` gate).
2. Step through weeks with the arrows and the picker; confirm they stay in sync (moving one updates the other's displayed position) and the direction-based slide animation plays.
3. Click an entry on a **draft** week → lands on Record, that week, with the entry's edit form already open.
4. Click an entry on a **submitted** week → lands on Record, that week, showing the existing "This week is submitted. Reopen it to make changes." banner — no edit dialog forced open.
5. Confirm a leave/holiday line (if any test data has one) renders without a click affordance.
6. Toggle language (`$store.ui.lang`) and confirm every new string switches (empty states, status badge, submitted note).
7. Resize to mobile width; confirm the panel and nav remain usable.

- [ ] **Step 5: Final commit (if manual verification surfaced fixes)**

If Step 4 found nothing to fix, this task produces no commit — the three feature commits from Tasks 1-4 stand as the complete change.
