# Personal Timesheet Record/Review Split — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the personal `timesheets` screen into two tabs — Record (capture card + a new live week shelf) and Review (history + analytics stacked) — so a daily writer lands on a focused recording view.

**Architecture:** Pure Blade + Alpine on `resources/views/screens/timesheets.blade.php`. An outer `x-data` tab root wraps the existing `timesheetCapture` card (Record) and the two existing reference panels (Review), un-nesting the panels' current inner tab bar. The week shelf lives inside the `timesheetCapture` scope and reads its existing helpers.

**Tech Stack:** Laravel 13 Blade, Alpine 3, PHPUnit 12. No JS/CSS file change, no asset rebuild.

## Global Constraints

- **No asset rebuild.** Reuse the already-authored `uj-tr-tabs` / `uj-tr-tab` CSS classes for the tab bar (they exist in `resources/css/app.css`); inline-style the shelf; compute shelf figures with inline Alpine expressions. Do not edit `resources/js/timesheet-capture.js` or `resources/css/app.css`. Do not run `bun run build`.
- **Follow the file's convention:** `screens/timesheets.blade.php` styles with inline `style="…"`, not utility classes. Match it. The only class reuse is the tab bar.
- **Frontend only:** no controller, route, nav, or migration change. `myTimesheets`, `myBreakdown`, `$canSeeCost` already arrive from `TimesheetController::screenData`.
- **Bilingual:** every visible string is an `x-text="$store.ui.lang==='en' ? '…' : '…'"` span with the EN text as literal fallback content (so server-rendered tests can `assertSee` it).
- **Tab behaviour copied from the report screen** (`screens/timesheet-reports.blade.php` lines 34–74, 130–146): `tab` seeded from `request()->query('tab')`, default `record`, unknown falls back to `record`, `setTab` writes the query with `history.replaceState`.
- **No count badge** on either tab.
- Pint clean: `vendor/bin/pint --dirty --format agent`.

---

### Task 1: Tab scaffold + un-nest Review

Wrap the screen body in a two-tab root; move the capture card under a Record tab and the two reference panels under a Review tab; delete the panels' inner tab bar.

**Files:**
- Modify: `resources/views/screens/timesheets.blade.php`
- Test: `tests/Feature/TimesheetPersonalTabsTest.php` (create)

**Interfaces:**
- Produces: rendered `x-data` root exposing `tab` (`'record'|'review'`) and `setTab(t)`, seeded `@js($tab)` where `$tab = request()->query('tab') === 'review' ? 'review' : 'record'`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TimesheetPersonalTabsTest.php`. Model the fixtures on `TimesheetChaseCardTest` (tenant + user attached with role `employee`, an `Employee` row with `user_id`, `actingAs` + `withSession(['current_tenant' => …])`). Freeze time with `Carbon::setTestNow('2026-06-19 12:00:00')` in `setUp` and clear it in `tearDown`.

```php
public function test_the_screen_offers_record_and_review_tabs(): void
{
    $r = $this->actingInTenant($this->user)->get('/app/timesheets');
    $r->assertOk();
    $r->assertSee('Record', false);
    $r->assertSee('Review', false);
}

public function test_record_is_the_default_tab(): void
{
    $this->actingInTenant($this->user)->get('/app/timesheets')
        ->assertOk()->assertSee("tab: 'record'", false);
}

public function test_tab_review_deep_links(): void
{
    $this->actingInTenant($this->user)->get('/app/timesheets?tab=review')
        ->assertOk()->assertSee("tab: 'review'", false);
}

public function test_an_unknown_tab_falls_back_to_record(): void
{
    $this->actingInTenant($this->user)->get('/app/timesheets?tab=nonsense')
        ->assertOk()->assertSee("tab: 'record'", false);
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `lerd artisan test --compact tests/Feature/TimesheetPersonalTabsTest.php`
Expected: FAIL — no `tab: 'record'` in output yet (screen has no tab root).

- [ ] **Step 3: Add the `$tab` php + tab root + tab bar**

After the existing `@php … @endphp` block at the top of `@section('screen')` (keep `see-all-btn`, `guide`, and the `positionMissing` card above the tabs), compute `$tab`:

```php
@php $tab = request()->query('tab') === 'review' ? 'review' : 'record'; @endphp
```

Open the tab root immediately after the `positionMissing` card and before the capture `uj-card`:

```blade
<div x-data="{
    tab: @js($tab),
    setTab(t) {
        this.tab = t
        const url = new URL(location)
        url.searchParams.set('tab', t)
        history.replaceState(null, '', url)
    },
}">
    <div class="uj-tr-tabs" role="tablist">
        <button type="button" class="uj-tr-tab" role="tab" :data-on="tab==='record'"
            :aria-selected="tab==='record'" @click="setTab('record')">
            <span x-text="$store.ui.lang==='en' ? 'Record' : 'Rekod'">Record</span>
        </button>
        <button type="button" class="uj-tr-tab" role="tab" :data-on="tab==='review'"
            :aria-selected="tab==='review'" @click="setTab('review')">
            <span x-text="$store.ui.lang==='en' ? 'Review' : 'Semak'">Review</span>
        </button>
    </div>

    <div x-show="tab==='record'" x-transition.opacity.duration.150ms x-cloak role="tabpanel">
        {{-- existing capture uj-card moves here, unchanged --}}
    </div>

    <div x-show="tab==='review'" x-transition.opacity.duration.150ms x-cloak role="tabpanel">
        {{-- existing reference panels move here, un-nested (Task 1 Step 4) --}}
    </div>
</div>
```

Move the existing capture `<div class="uj-card" … x-data="timesheetCapture(…)">…</div>` (current lines 78–509) intact into the `tab==='record'` panel. Move the reference-panels `<div class="uj-card" x-data="{ tab: 'sheets' }" …>…</div>` (current lines 512–632) into the `tab==='review'` panel — its rework is Step 4.

- [ ] **Step 4: Un-nest the Review panels**

In the block now under `tab==='review'`, delete the inner tab bar (`<div style="display:flex;gap:4px;padding:6px;border-bottom…">` with the two `@click="tab='sheets'"` / `tab='spent'` buttons) and remove `x-data="{ tab: 'sheets' }"` from that `uj-card`. Replace the `x-show="tab === 'sheets'"` wrapper on the history list and the `x-show="tab === 'spent'"` wrapper on the breakdown with plain always-rendered sections, each introduced by a heading matching the file's style:

```blade
<div style="padding:14px 20px 4px;font-size:13px;font-weight:600;color:var(--ink);">
    <span x-text="$store.ui.lang==='en' ? 'My timesheets' : 'Timesheet saya'">My timesheets</span>
    <span style="color:var(--muted);font-weight:400;">({{ $myTimesheets->count() }})</span>
</div>
```

Keep the `@if (! empty($myBreakdown))` guard around the breakdown section (it already gates rendering). Give the breakdown its own heading `My time spent` / `Masa saya` in the same style. The `uj-card` keeps only its outer wrapper; the two sections stack inside it separated by the existing `border`/padding.

> The word `tab` now refers ONLY to the outer Record/Review root. Confirm no leftover `tab === 'sheets'` / `tab === 'spent'` references remain (grep the file).

- [ ] **Step 5: Run tests, expect pass**

Run: `lerd artisan test --compact tests/Feature/TimesheetPersonalTabsTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the existing timesheet suite**

Run: `lerd artisan test --compact --filter=Timesheet`
Expected: PASS — the split must not break `TimesheetCaptureTest`, `TimesheetCostTest`, etc.

- [ ] **Step 7: Pint + commit**

Run: `vendor/bin/pint --dirty --format agent`

```bash
git add resources/views/screens/timesheets.blade.php tests/Feature/TimesheetPersonalTabsTest.php
git commit -m "feat(timesheets): split personal screen into Record and Review tabs"
```

---

### Task 2: Week shelf on the Record tab

Add a live week-percent shelf as the first child inside the `timesheetCapture` card, ported and slimmed from the reference (`~/Downloads/Timesheets.dc.html` lines 95–118).

**Files:**
- Modify: `resources/views/screens/timesheets.blade.php`
- Test: `tests/Feature/TimesheetPersonalTabsTest.php` (add one test)

**Interfaces:**
- Consumes: the `timesheetCapture` scope helpers `dayDates()`, `isOffDay(iso)`, `dayTotal(iso)`, `dayState(iso)` (already defined in `resources/js/timesheet-capture.js`).

- [ ] **Step 1: Write the failing test**

```php
public function test_the_record_tab_shows_a_week_shelf(): void
{
    $this->actingInTenant($this->user)->get('/app/timesheets')
        ->assertOk()->assertSee('of the week allocated', false);
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `lerd artisan test --compact --filter=test_the_record_tab_shows_a_week_shelf`
Expected: FAIL — string not present.

- [ ] **Step 3: Add the shelf markup**

As the FIRST child inside `<div class="uj-card" … x-data="timesheetCapture(…)">`, before the "Today header" comment, insert the shelf. It reads the parent scope directly (no nested `x-data`), so the reduce expressions reference the helpers unqualified:

```blade
{{-- Week shelf: live week-percent allocated, read from the capture scope. --}}
<div style="background:var(--shelf,#ece9e1);border:1px solid var(--shelf-line,#ddd9cf);border-radius:14px;padding:20px 22px;margin:-4px -4px 18px;">
    <div style="display:flex;align-items:baseline;gap:13px;flex-wrap:wrap;">
        <span style="font:600 46px/1 var(--font-mono);color:var(--ink);letter-spacing:-.03em;font-variant-numeric:tabular-nums;"
            x-text="Math.round(dayDates().filter(d=>!isOffDay(d)).reduce((s,d)=>s+Math.min(dayTotal(d),100),0) / Math.max(1, dayDates().filter(d=>!isOffDay(d)).length*100) * 100) + '%'">0%</span>
        <span style="font-size:13.5px;color:var(--body);" x-text="$store.ui.lang==='en' ? 'of the week allocated' : 'daripada minggu diperuntukkan'">of the week allocated</span>
    </div>
    <div style="font-size:12.5px;color:var(--muted);margin-top:7px;" x-text="$store.ui.lang==='en' ? 'Every working day must reach 100% before the week can be submitted.' : 'Setiap hari bekerja mesti mencapai 100% sebelum minggu boleh dihantar.'">Every working day must reach 100% before the week can be submitted.</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
        <div style="background:var(--card,#fff);border:1px solid var(--shelf-line,#ddd9cf);border-radius:9px;padding:7px 12px;display:flex;align-items:baseline;gap:7px;">
            <b style="font:600 17px/1 var(--font-mono);color:var(--success-ink,#1f8a65);"
               x-text="dayDates().filter(d=>!isOffDay(d) && ['done','locked'].includes(dayState(d))).length"></b>
            <span style="font-size:11px;color:var(--body);" x-text="$store.ui.lang==='en' ? 'days at 100%' : 'hari pada 100%'">days at 100%</span>
        </div>
        <div style="background:var(--card,#fff);border:1px solid var(--shelf-line,#ddd9cf);border-radius:9px;padding:7px 12px;display:flex;align-items:baseline;gap:7px;">
            <b style="font:600 17px/1 var(--font-mono);color:var(--amber-ink,#c08532);"
               x-text="dayDates().filter(d=>!isOffDay(d) && !['done','locked'].includes(dayState(d))).length"></b>
            <span style="font-size:11px;color:var(--body);" x-text="$store.ui.lang==='en' ? 'left to fill' : 'lagi untuk diisi'">left to fill</span>
        </div>
    </div>
</div>
```

> The denominator uses `Math.max(1, …)` so an all-off-day view never divides by zero. Locked days return `dayTotal === 100` and `dayState === 'locked'`, so they count as complete in both the percent and the "days at 100%" chip.

- [ ] **Step 4: Run tests, expect pass**

Run: `lerd artisan test --compact tests/Feature/TimesheetPersonalTabsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Browser verify the live figure** (cannot be unit-tested — client-side)

Open the dev preview (`laravel-app`), dev-login as `employee@amanahku.test&tenant=unijaya`, navigate to `/app/timesheets`. Confirm: the shelf shows a percent; editing a day's rows updates the percent and the two chips live; switching to Review hides the shelf; `?tab=review` deep-links; both render at 1280px and 375px. Capture a screenshot.

- [ ] **Step 6: Pint + commit**

Run: `vendor/bin/pint --dirty --format agent`

```bash
git add resources/views/screens/timesheets.blade.php tests/Feature/TimesheetPersonalTabsTest.php
git commit -m "feat(timesheets): live week-percent shelf on the Record tab"
```

---

## Self-Review

- **Spec coverage:** Tab bar (Task 1 Step 3) ✓; Record default + deep-link + fallback (Task 1 tests) ✓; Review un-nested & stacked (Task 1 Step 4) ✓; week shelf, live, client-side (Task 2) ✓; no backend/token/report-screen change (Global Constraints) ✓; tests + browser verify ✓.
- **Placeholder scan:** none — all markup and test code is literal.
- **Type consistency:** `tab` values `'record'`/`'review'` used identically across both panels and tests; helper names (`dayDates`, `isOffDay`, `dayTotal`, `dayState`) match `timesheet-capture.js`.
- **Note:** the shelf test asserts the static label `of the week allocated`, not the computed percent (client-side, browser-verified in Task 2 Step 5) — this hedge is stated in the spec.
