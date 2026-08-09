# Late Clock-In Remark Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A late clock-in is refused until the employee types a reason, the same way an off-site or unlocatable punch already is.

**Architecture:** One new gate inside `ClockService::clockIn()`, placed after lateness is computed, reusing the existing `needs_justification` return status and the existing `clock_in_justification` column. The attendance screen mirrors the rule in Alpine so the reason drawer opens without a failed submit. The grace period that decides who counts as late moves out of the Work from home tab and gains a real default, because at zero grace the gate would fire on a 09:00:15 punch.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12, Alpine.js 3, Blade.

**Spec:** [docs/superpowers/specs/2026-08-07-late-clock-in-remark-design.md](../specs/2026-08-07-late-clock-in-remark-design.md)

## Global Constraints

- Run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- No new dependencies, no new base directories.
- Explicit return types and parameter type hints on every method (`declare(strict_types=1)` is already on these files).
- Curly braces on every control structure, even single-line bodies.
- No role checks anywhere in this work. Director and management are gated identically to an employee.
- All user-facing Blade strings ship in **both English and Malay**, following the `$store.ui.lang==='en' ? '…' : '…'` pattern already used in the files being edited.
- Server-side `ClockService` messages stay English-only, matching the existing `needs_justification` messages.
- Never assert a raw date or time column value across drivers: MySQL returns `HH:MM:SS` and SQLite returns `HH:MM`. Compare with `substr((string) $value, 0, 5)`, as `AttendanceAdminTest` already does.
- Grace is **15 minutes**.

## Two corrections to the spec, found while reading the tests

Both are refinements of decisions already approved, not new scope. They are folded into Task 1.

1. **The spec's data migration alone is not enough.** It sets existing `NULL` rows to 15, but the column has no default, so every tenant created *after* the migration is `NULL` again, which means zero grace and a gate that fires on a 09:00:15 punch. Task 1 therefore sets the column default **and** backfills.
2. **A second existing test breaks, not one.** The spec named `test_clock_in_after_work_start_is_marked_late`. `test_clock_in_past_tenant_grace_period_is_still_late` (tests/Feature/ClockServiceTest.php:173) also punches late with `$justification = null` and asserts `ok`. Both are updated in Task 2.

## File Structure

| File | Responsibility | Task |
|---|---|---|
| `database/migrations/2026_08_07_HHMMSS_default_late_grace_minutes.php` | **Create.** Column default 15, backfill existing `NULL` rows | 1 |
| `tests/Feature/ClockServiceTest.php` | **Modify.** Two existing late tests updated, four new cases | 1, 2 |
| `app/Attendance/ClockService.php` | **Modify.** The late gate | 2 |
| `app/Http/Controllers/Concerns/BuildsWorkData.php` | **Modify.** `lateGraceMinutes` into the attendance view payload | 3 |
| `tests/Feature/AttendanceScreenDataTest.php` | **Modify.** Assert the new payload key | 3 |
| `resources/views/screens/attendance.blade.php` | **Modify.** `lateNow()` mirror, four edits | 4 |
| `resources/views/screens/attendance-admin.blade.php` | **Modify.** Grace control lifted above the tab bar | 5 |
| `app/Http/Controllers/AttendanceAdminController.php` | **Modify.** `sometimes` on the four `wfh_*` rules | 5 |
| `tests/Feature/AttendanceAdminTest.php` | **Modify.** Partial-post regression test | 5 |

Task order matters: Task 1 must land before Task 2, because Task 2's tests rely on the default grace being 15.

---

### Task 1: Give the grace period a real default

Zero grace makes every employee late at 09:00:15. Nothing else in this plan is safe to ship until the default is real.

**Files:**
- Create: `database/migrations/2026_08_07_150000_default_late_grace_minutes.php`
- Test: `tests/Feature/ClockServiceTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `tenants.late_grace_minutes` defaults to `15` for new rows and is `15` on every previously-`NULL` row. `ClockService::isLate()` already reads it through `$employee->tenant->late_grace_minutes ?? 0`; that `?? 0` stays and becomes unreachable in practice.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ClockServiceTest.php`, after `test_clock_in_past_tenant_grace_period_is_still_late` (currently ends line 184):

```php
    /**
     * A tenant nobody has configured must still get a usable grace. Without a column
     * default, every new workspace starts at zero and its staff are late at 09:00:15 —
     * which, with the late-remark gate, means a typed reason every single morning.
     */
    public function test_a_new_tenant_gets_the_default_grace_period(): void
    {
        $fresh = Tenant::create(['slug' => 'brandnew', 'name' => 'Brand New', 'initials' => 'BN']);

        $this->assertSame(15, $fresh->fresh()->late_grace_minutes);
    }
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
lerd artisan test --compact --filter=test_a_new_tenant_gets_the_default_grace_period
```

Expected: FAIL. `Failed asserting that null is identical to 15.`

- [ ] **Step 3: Create the migration**

```bash
lerd artisan make:migration default_late_grace_minutes --no-interaction
```

Rename the generated file to `2026_08_07_150000_default_late_grace_minutes.php` if the timestamp differs, so it sorts after `2026_08_07_103726_add_late_grace_minutes_to_tenants.php`.

- [ ] **Step 4: Write the migration body**

Replace the whole generated file with:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give late_grace_minutes a real default.
 *
 * The column shipped nullable with no default, and ClockService::isLate() falls back to
 * `?? 0`, so every tenant has been running on zero grace: a punch at 09:00:15 against an
 * 09:00 start is already late. That was harmless while lateness was only a silent flag.
 * It stops being harmless the moment a late punch demands a typed reason, which would
 * otherwise fire on essentially every arrival, every morning.
 *
 * Two halves, and both are needed: the default covers tenants created from now on, the
 * backfill covers the ones that already exist. HR can still set any value from 0 to 120
 * on the Attendance Setup screen; this only decides where a tenant starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedTinyInteger('late_grace_minutes')->nullable()->default(15)->change();
        });

        DB::table('tenants')->whereNull('late_grace_minutes')->update(['late_grace_minutes' => 15]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedTinyInteger('late_grace_minutes')->nullable()->default(null)->change();
        });
    }
};
```

`down()` drops the default only. It deliberately does not null the values back out: that would be destroying a setting HR may have chosen since.

- [ ] **Step 5: Run the test and watch it pass**

```bash
lerd artisan test --compact --filter=test_a_new_tenant_gets_the_default_grace_period
```

Expected: PASS.

- [ ] **Step 6: Run the whole attendance suite to catch collateral damage**

Every test that creates a tenant now gets 15 minutes of grace, so any test punching between 09:00 and 09:15 and expecting `late` would flip. This is the check for that.

```bash
lerd artisan test --compact --filter=Attendance
```

Expected: PASS. `ClockServiceTest` punches at 09:20 (20 minutes late, still late at grace 15) and at 09:04 / 09:06 against an explicitly-set grace of 5, so none of them should move. If something does fail, read the failing test's clock time before changing anything: the fix is to set that test's grace explicitly, never to weaken the default.

- [ ] **Step 7: Run the migration against the real MySQL database**

Do not skip or reorder this. Tests run on sqlite in-memory (`phpunit.xml:31`) while staging and production run MySQL, and `->change()` is the single operation most likely to behave differently between the two. A green test suite proves nothing about whether this migration runs on MySQL. This step is the only place that gets checked before deploy.

It is also required for the app itself: the dev database is separate from the test database, so without it the attendance screens 500 despite green tests.

```bash
lerd artisan migrate
```

Expected: the migration runs clean. Then confirm the values actually landed:

```bash
lerd artisan tinker --execute 'foreach (\App\Models\Tenant::all() as $t) { echo $t->slug, " grace=", var_export($t->late_grace_minutes, true), PHP_EOL; }'
```

Expected: every tenant reports `grace=15`. Before this change they all reported `grace=NULL`.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/ tests/Feature/ClockServiceTest.php
git commit -m "fix(attendance): default the late grace period to 15 minutes

late_grace_minutes shipped nullable with no default and ClockService
falls back to zero, so every tenant has been running on no grace at all:
a punch at 09:00:15 against an 09:00 start already counts late.

Harmless while lateness was a silent flag. About to stop being harmless,
because a late punch is going to demand a typed reason, and at zero grace
that fires on nearly every arrival every morning.

Sets the column default and backfills the existing rows. HR keeps the
0-120 control on the Attendance Setup screen."
```

---

### Task 2: The late gate

**Files:**
- Modify: `app/Attendance/ClockService.php:76` (insert after the `$late` assignment, before `$flags = []`)
- Test: `tests/Feature/ClockServiceTest.php`

**Interfaces:**
- Consumes: `tenants.late_grace_minutes` defaulting to 15 (Task 1).
- Produces: `ClockService::clockIn()` returns `['status' => 'needs_justification', 'message' => string]` when the punch is late and `$justification` is empty. Its signature is unchanged: `clockIn(Employee $employee, ?float $lat, ?float $lng, ?string $justification, ?string $photoPath, Carbon $now): array`. `AttendanceController::clock()` already handles that status, so no controller change is needed.

- [ ] **Step 1: Update the two existing tests that encode the old behaviour**

Both currently pass `null` for `$justification` and assert `ok`. That is precisely the behaviour being removed, so they must change rather than be left to fail.

In `tests/Feature/ClockServiceTest.php`, replace `test_clock_in_after_work_start_is_marked_late` (line 148) with:

```php
    public function test_clock_in_after_work_start_is_marked_late_and_needs_a_reason(): void
    {
        $now = Carbon::parse('2026-07-02 09:20:00');
        $svc = $this->service($this->office());

        // Lateness used to be recorded in silence. It now costs a reason, like every other
        // irregular punch.
        $this->assertSame('needs_justification', $svc->clockIn($this->employee, 3.10, 101.60, null, null, $now)['status']);
        $this->assertNull($this->employee->attendanceRecords()->onDate($now)->first());

        $res = $svc->clockIn($this->employee, 3.10, 101.60, 'Traffic jam on the LDP', null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('late', $record->status);
        $this->assertContains('late', $record->flags);
        $this->assertSame('Traffic jam on the LDP', $record->clock_in_justification);
    }
```

And replace `test_clock_in_past_tenant_grace_period_is_still_late` (line 173) with:

```php
    public function test_clock_in_past_tenant_grace_period_is_still_late(): void
    {
        $this->tenant->update(['late_grace_minutes' => 5]);
        $now = Carbon::parse('2026-07-02 09:06:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.10, 101.60, 'Overslept', null, $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('late', $record->status);
        $this->assertContains('late', $record->flags);
    }
```

- [ ] **Step 2: Write the new failing tests**

Add these three after the tests above:

```php
    /**
     * A punch inside the grace window is not late, so it must not be interrogated. This is
     * the test that stops the feature from asking the whole company for a reason every day.
     */
    public function test_clock_in_within_the_grace_window_is_never_asked_for_a_reason(): void
    {
        $this->tenant->update(['late_grace_minutes' => 15]);
        $now = Carbon::parse('2026-07-02 09:14:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.10, 101.60, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('on_time', $this->employee->attendanceRecords()->onDate($now)->first()->status);
    }

    /**
     * A site with no configured start time has no bar to be late against, so the gate must
     * stay silent rather than demand a reason nobody can give.
     */
    public function test_a_site_with_no_work_start_never_demands_a_late_reason(): void
    {
        $noHours = new SiteSpec('office', 'HQ', 3.10, 101.60, 200, null, null, null);
        $now = Carbon::parse('2026-07-02 23:59:00');

        $res = $this->service($noHours)->clockIn($this->employee, 3.10, 101.60, null, null, $now);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('on_time', $this->employee->attendanceRecords()->onDate($now)->first()->status);
    }

    /**
     * Late and off-site at once. The fence check runs first, so the employee is told about
     * the thing they can see (their location), and the single reason they type covers both.
     */
    public function test_a_late_off_site_punch_reports_the_fence_not_the_lateness(): void
    {
        $now = Carbon::parse('2026-07-02 09:20:00');

        // ~11km away from the office pin, and 20 minutes past the 09:00 start.
        $res = $this->service($this->office())->clockIn($this->employee, 3.20, 101.60, null, null, $now);

        $this->assertSame('needs_justification', $res['status']);
        $this->assertStringContainsString('outside', $res['message']);
    }
```

- [ ] **Step 3: Run the tests and watch them fail**

```bash
lerd artisan test --compact tests/Feature/ClockServiceTest.php
```

Expected: FAIL. `test_clock_in_after_work_start_is_marked_late_and_needs_a_reason` fails first, asserting `'needs_justification'` but getting `'ok'`. The two grace tests and the no-hours test should already pass, because they exercise paths the gate must leave alone.

- [ ] **Step 4: Add the gate**

In `app/Attendance/ClockService.php`, find the `$late` assignment at line 76:

```php
        $late = $this->isLate($site->workStart, $site->workEnd, $now, $employee->tenant->late_grace_minutes ?? 0);
        $flags = [];
```

Insert the gate between those two lines:

```php
        $late = $this->isLate($site->workStart, $site->workEnd, $now, $employee->tenant->late_grace_minutes ?? 0);

        // Lateness was the one anomaly recorded in silence: the flag told HR that somebody
        // was late but never why, and gave the employee no moment to say so. It now costs
        // the same typed reason as an off-site or unlocatable punch. Placed after the fence
        // checks on purpose — someone both late and off-site is told about their location,
        // which is the part they can see, and the one reason they type covers both.
        if ($late && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'You are clocking in after '.$site->workStart.'. Add a reason to clock in.'];
        }

        $flags = [];
```

- [ ] **Step 5: Run the tests and watch them pass**

```bash
lerd artisan test --compact tests/Feature/ClockServiceTest.php
```

Expected: PASS, all of them.

- [ ] **Step 6: Run the wider attendance suite**

```bash
lerd artisan test --compact --filter=Attendance
```

Expected: PASS. `AttendanceClockEndpointTest` travels to 10:00 but builds no branch, so its employee has no `work_start` and is never judged late. If it fails, that assumption has changed and the test needs an explicit site with no hours, not a relaxed gate.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Attendance/ClockService.php tests/Feature/ClockServiceTest.php
git commit -m "feat(attendance): require a reason for a late clock-in

Every other irregular punch already costs a typed reason before it is
accepted: off-site, no GPS, early clock-out, short hours. A late clock-in
was the exception, saving with status=late and a flag but no explanation,
so HR could see that somebody was late and never learn why.

Reuses the existing needs_justification round-trip and the existing
clock_in_justification column, so no migration and no controller change.
The gate sits after the fence checks, so a punch that is both late and
off-site reports the location and one reason covers both.

No role is exempt: a director is gated the same as an employee."
```

---

### Task 3: Put the grace and shift start into the attendance view payload

The browser cannot judge lateness without the shift start and the grace. `site` already carries `workStart`; the grace lives on the tenant and is not in the payload.

**Files:**
- Modify: `app/Http/Controllers/Concerns/BuildsWorkData.php:86` (beside the existing `site` key)
- Test: `tests/Feature/AttendanceScreenDataTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: the attendance view receives `$lateGraceMinutes` as an `int`. Task 4 reads it.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/AttendanceScreenDataTest.php`:

```php
    /**
     * The screen mirrors the server's lateness rule so a late punch opens the reason drawer
     * in place. It cannot do that without the grace, which lives on the tenant.
     */
    public function test_the_payload_carries_the_tenant_late_grace(): void
    {
        $this->tenant->update(['late_grace_minutes' => 25]);

        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        $this->assertSame(25, $response->viewData('lateGraceMinutes'));
    }
```

This uses the `$this->user` and `$this->tenant` properties and the `actingAs` plus `withSession` pattern the class already establishes in `setUp()` and in `test_week_records_exclude_days_before_this_week`.

- [ ] **Step 2: Run the test and watch it fail**

```bash
lerd artisan test --compact --filter=test_the_payload_carries_the_tenant_late_grace
```

Expected: FAIL, with `viewData()` finding no `lateGraceMinutes` key on the response.

- [ ] **Step 3: Add the key**

In `app/Http/Controllers/Concerns/BuildsWorkData.php`, find:

```php
            'geofencedSites' => $employee
                ? app(ScheduleResolver::class)->configuredSites($employee->tenant_id)
                : [],
```

Add immediately after it:

```php
            // The screen judges lateness itself so a late punch opens the reason drawer in
            // place instead of costing a failed submit. `?? 0` mirrors ClockService::isLate()
            // exactly, so the browser and the server can never disagree about who is late.
            'lateGraceMinutes' => (int) ($employee?->tenant->late_grace_minutes ?? 0),
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
lerd artisan test --compact --filter=test_the_payload_carries_the_tenant_late_grace
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Concerns/BuildsWorkData.php tests/Feature/AttendanceScreenDataTest.php
git commit -m "feat(attendance): pass the tenant late grace to the attendance screen

The screen is about to judge lateness itself, so a late punch opens the
reason drawer in place rather than costing a failed submit. It already
has the shift start on the resolved site; the grace lives on the tenant
and was not in the payload."
```

---

### Task 4: Mirror the late rule in the browser

**Files:**
- Modify: `resources/views/screens/attendance.blade.php` at four points: Alpine state (~line 113), `lateNow()` beside `earlyNow()` (~line 252), `proceed()` (~line 270), the red label (~line 700)

**Interfaces:**
- Consumes: `$lateGraceMinutes` from Task 3, `$site->workStart` (already available in this view), and the server gate from Task 2 as the backstop.
- Produces: nothing consumed by later tasks.

**Read before editing:** the comment at line 180-190 explains why `earlyNow()` was deliberately kept out of the `isReq` getter. Lateness has the same shape and gets the same treatment. Do not wire `lateNow()` into `isReq`.

- [ ] **Step 1: Add the two state properties**

Find, in the Alpine `x-data` block:

```js
              expectedEnd: '{{ $site?->workEnd ?? '' }}',
```

Add directly above it:

```js
              expectedStart: '{{ $site?->workStart ?? '' }}',
              graceMin: {{ $lateGraceMinutes ?? 0 }},
```

- [ ] **Step 2: Add `lateNow()`**

Find `earlyNow()` (around line 252):

```js
              earlyNow() {
                  if (!this.expectedEnd) return false;
                  const p = this.expectedEnd.split(':');
                  const now = new Date();
                  return (now.getHours()*60 + now.getMinutes()) < (Number(p[0])*60 + Number(p[1]));
              },
```

Add directly above it:

```js
              // Mirror of ClockService::isLate for the single-day case. The server gate is
              // still the authority — this only saves the employee a failed submit, and a
              // device with a wrong clock is caught there.
              lateNow() {
                  if (!this.expectedStart) return false;
                  const p = this.expectedStart.split(':');
                  const now = new Date();
                  return (now.getHours()*60 + now.getMinutes()) > (Number(p[0])*60 + Number(p[1]) + this.graceMin);
              },
```

- [ ] **Step 3: Raise the reason on a late clock-in**

In `proceed()`, find:

```js
                  if (this.action === 'out' && this.earlyNow()) need = true;
```

Add directly below it:

```js
                  if (this.action === 'in' && this.lateNow()) need = true;
```

No photo is requested: a late punch inside the fence is already located by the fence, and `needPhoto` stays driven by the off-site and no-fix cases alone.

- [ ] **Step 4: Give the red label a late branch**

Find the label span (around line 700) and replace its `x-text` expression:

```html
                          x-text="(siteLat !== null && fenceStatus === 'out')
                              ? ($store.ui.lang==='en' ? 'Reason required — you appear to be outside the expected location' : 'Sebab diperlukan — anda kelihatan di luar lokasi yang dijangka')
                              : ((action === 'out' && earlyNow())
                                  ? ($store.ui.lang==='en' ? 'Reason required — you are clocking out before your shift ends' : 'Sebab diperlukan — anda clock out sebelum shift tamat')
                                  : ($store.ui.lang==='en' ? 'Reason required — see details below' : 'Sebab diperlukan — lihat butiran di bawah'))"
```

with:

```html
                          x-text="(siteLat !== null && fenceStatus === 'out')
                              ? ($store.ui.lang==='en' ? 'Reason required — you appear to be outside the expected location' : 'Sebab diperlukan — anda kelihatan di luar lokasi yang dijangka')
                              : ((action === 'out' && earlyNow())
                                  ? ($store.ui.lang==='en' ? 'Reason required — you are clocking out before your shift ends' : 'Sebab diperlukan — anda clock out sebelum shift tamat')
                                  : ((action === 'in' && lateNow())
                                      ? ($store.ui.lang==='en' ? 'Reason required — you are clocking in after your shift started' : 'Sebab diperlukan — anda clock in selepas shift bermula')
                                      : ($store.ui.lang==='en' ? 'Reason required — see details below' : 'Sebab diperlukan — lihat butiran di bawah')))"
```

Keep the em dashes in these Blade strings: they match every neighbouring string in this file, and changing them would be unrelated churn.

- [ ] **Step 5: Verify in the browser**

```bash
lerd artisan view:cache
```

Log in as an employee whose branch start time has already passed today:

```
http://localhost:9100/dev/login?email=employee@amanahku.test&tenant=unijaya
```

Go to the attendance screen and tap Clock in. Expected: no page reload, no error flash, the reason drawer opens with a red "Reason required — you are clocking in after your shift started". Type a reason and tap Clock in again. Expected: the punch saves and the day shows as late.

If the employee has already clocked in today, reverse the punch first from Attendance Setup as `hr@amanahku.test`, or the screen will say "Already clocked in today."

- [ ] **Step 6: Check the browser console is clean**

Read the console for errors. A typo in the `x-text` ternary shows up as an Alpine expression error, not a visual break.

- [ ] **Step 7: Commit**

```bash
git add resources/views/screens/attendance.blade.php
git commit -m "feat(attendance): open the reason drawer in place on a late clock-in

The server refuses a late punch without a reason. Left to the server
alone, the employee pays a failed submit and a page reload for it, and
back()->withInput() discards the cached GPS fix and any selfie preview.

Mirrors the rule in Alpine the same way off-site, no-fix and early-out
already are, so the drawer opens in place on the first tap. lateNow() is
deliberately kept out of isReq for the reason recorded above the getter:
a condition true for most of the day would paint the screen red from
morning to night."
```

---

### Task 5: Lift the grace control out of the Work from home tab

The control that decides who is late for the whole company sits inside a tab labelled Work from home. Harmless while nothing read it. Now it is the dial HR reaches for when the complaints start.

**Files:**
- Modify: `resources/views/screens/attendance-admin.blade.php` (remove the grace input at line 190 and its caption at line 193, add a new form above the tab bar at line 67)
- Modify: `app/Http/Controllers/AttendanceAdminController.php:133-139` (validation rules)
- Test: `tests/Feature/AttendanceAdminTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing consumed by later tasks. `updateWfhPolicy` keeps its route name `attendance.admin.wfh-policy` and its signature.

- [ ] **Step 1: Write the failing regression test**

This is the test that matters most in this task. Without it the data loss below is invisible.

Add to `tests/Feature/AttendanceAdminTest.php`, after `test_hr_can_set_the_company_wfh_policy` (ends line 164):

```php
    /**
     * The grace control now posts on its own, without the WFH hours beside it. Laravel
     * backfills an absent-but-nullable key as null, so without `sometimes` on the wfh_*
     * rules this save would silently wipe the company's work-from-home hours.
     */
    public function test_saving_the_grace_alone_leaves_the_wfh_hours_untouched(): void
    {
        $this->tenant->update([
            'wfh_work_start' => '10:00',
            'wfh_work_end' => '16:00',
            'wfh_min_hours' => 6,
            'wfh_radius_m' => 500,
        ]);

        $this->actingAsRole('hr')
            ->post('/app/attendance-admin/wfh-policy', ['late_grace_minutes' => 20])
            ->assertRedirect()->assertSessionHas('ok');

        $t = $this->tenant->fresh();
        $this->assertSame(20, $t->late_grace_minutes);
        // DB time format differs by driver (SQLite 'HH:MM' / MySQL 'HH:MM:SS'); compare on HH:MM.
        $this->assertSame('10:00', substr((string) $t->wfh_work_start, 0, 5));
        $this->assertSame('16:00', substr((string) $t->wfh_work_end, 0, 5));
        $this->assertEquals(6.0, (float) $t->wfh_min_hours);
        $this->assertSame(500, $t->wfh_radius_m);
    }
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
lerd artisan test --compact --filter=test_saving_the_grace_alone_leaves_the_wfh_hours_untouched
```

Expected: FAIL. The grace saves as 20, but `wfh_work_start` comes back `null` instead of `10:00`. That failure is the bug this task exists to prevent.

- [ ] **Step 3: Make the wfh_* rules partial-safe**

In `app/Http/Controllers/AttendanceAdminController.php`, replace the rules inside `updateWfhPolicy`:

```php
        $data = $request->validate([
            'wfh_work_start' => ['nullable', 'date_format:H:i'],
            'wfh_work_end' => ['nullable', 'date_format:H:i'],
            'wfh_min_hours' => ['nullable', 'numeric', 'between:0,24'],
            'wfh_radius_m' => ['nullable', 'integer', 'between:20,5000'],
            'late_grace_minutes' => ['nullable', 'integer', 'between:0,120'],
        ]);
```

with:

```php
        // Every field is `sometimes` because two separate forms post here: the WFH hours
        // card and the standalone grace control. Without it Laravel backfills each absent
        // nullable key as null, and saving the grace would wipe the company's WFH hours.
        // Same trap, same fix as WorkItemController::update().
        $data = $request->validate([
            'wfh_work_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'wfh_work_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'wfh_min_hours' => ['sometimes', 'nullable', 'numeric', 'between:0,24'],
            'wfh_radius_m' => ['sometimes', 'nullable', 'integer', 'between:20,5000'],
            'late_grace_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,120'],
        ]);
```

Clearing a field from the UI still works: the WFH form always posts its inputs, so blanking one sends an empty string, `ConvertEmptyStringsToNull` turns it into a real null, and `sometimes` only skips keys that are genuinely absent.

Also update the method's docblock: the sentence saying `late_grace_minutes` "rides along here too" now describes a separate form posting to the same endpoint, not a field sharing the WFH card.

- [ ] **Step 4: Run both policy tests and watch them pass**

```bash
lerd artisan test --compact --filter="test_saving_the_grace_alone_leaves_the_wfh_hours_untouched|test_hr_can_set_the_company_wfh_policy"
```

Expected: PASS, both. The second proves the full-form save still works after the rule change.

- [ ] **Step 5: Remove the grace field from the WFH card**

In `resources/views/screens/attendance-admin.blade.php`, delete this whole `<div>` from the WFH form (line 190):

```html
            <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Late grace (min)' : 'Tempoh lewat (min)'">Late grace (min)</span></label><input name="late_grace_minutes" type="number" min="0" max="120" value="{{ $wfhPolicy?->late_grace_minutes }}" placeholder="0" style="{{ $fs }}width:110px;{{ $mono }}" /></div>
```

And delete the caption paragraph directly below the form (line 193), the one beginning `Grace period applies to every arrangement`. Both move to the new control.

- [ ] **Step 6: Add the standalone grace control above the tabs**

Find the tab bar's opening. It sits just after the `x-data` wrapper at line 63 and before the first tab button at line 67. Insert the new card between them, so it shows on all three tabs:

```html
{{-- 1. Lateness — above the tabs on purpose. This one setting governs every arrangement,
     and it used to sit inside the Work from home card, where nobody looking for it would
     think to open. It decides whether an office worker is stopped for a reason at 09:01. --}}
<div class="uj-card" style="padding:18px 20px;margin:0 0 16px;">
    <div class="uj-card-head" style="padding:0 0 10px;"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Lateness' : 'Kelewatan'">Lateness</h3></div>
    <form method="post" action="{{ route('attendance.admin.wfh-policy') }}" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
        @csrf
        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Late grace (min)' : 'Tempoh lewat (min)'">Late grace (min)</span></label><input name="late_grace_minutes" type="number" min="0" max="120" value="{{ $wfhPolicy?->late_grace_minutes }}" placeholder="15" style="{{ $fs }}width:110px;{{ $mono }}" /></div>
        <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
    </form>
    <p style="font-size:12px;color:var(--muted);margin:10px 0 0;" x-text="$store.ui.lang==='en' ? 'Applies to every arrangement — office, client, work-from-home and hybrid alike. Staff who clock in after this window must give a reason before the punch is accepted.' : 'Terpakai pada setiap susunan — pejabat, klien, kerja-dari-rumah dan hibrid. Staf yang clock in selepas tempoh ini mesti beri sebab sebelum rekod diterima.'">Applies to every arrangement — office, client, work-from-home and hybrid alike. Staff who clock in after this window must give a reason before the punch is accepted.</p>
</div>
```

The placeholder is `15`, not `0`, so a tenant that has never saved the field sees the value it is actually running on.

Check the surrounding numbered Blade comments (`{{-- 2. Client sites --}}`, `{{-- 3. Work from home --}}`, `{{-- 4. Staff arrangements --}}`) and renumber if this file's existing comment numbering collides.

- [ ] **Step 7: Confirm the screen still renders**

```bash
lerd artisan test --compact --filter=test_attendance_setup_screen_renders
```

Expected: PASS.

- [ ] **Step 8: Verify in the browser**

```bash
lerd artisan view:cache
```

Log in as HR and open Attendance Setup:

```
http://localhost:9100/dev/login?email=hr@amanahku.test&tenant=unijaya
```

Expected: a Lateness card above the three tabs, showing 15, visible whichever tab is selected. Change it to 20, save, reload, and confirm both that it reads 20 and that the Work from home tab's hours are unchanged.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AttendanceAdminController.php resources/views/screens/attendance-admin.blade.php tests/Feature/AttendanceAdminTest.php
git commit -m "feat(attendance): lift the late grace control out of the WFH tab

late_grace_minutes governs lateness for every arrangement, but it sat
inside the Work from home card with a caption apologising for the fact.
Now that a late punch demands a reason, this is the dial HR reaches for
when the complaints arrive, and it needs to be where they look.

Moves it above the tab bar as its own Lateness card, visible on every
tab, with the placeholder showing the real default instead of 0.

Posting it alone would have wiped the WFH hours: all five fields were
nullable, and Laravel backfills an absent nullable key as null. Every
rule is now `sometimes`, the same fix WorkItemController::update()
already carries, held by a regression test."
```

---

### Task 6: Full suite and release preparation

**Files:** none modified unless the suite finds something.

- [ ] **Step 1: Run the full test suite**

```bash
lerd artisan test --compact
```

Expected: PASS. Anything failing outside `tests/Feature/Attendance*` is collateral from the grace default in Task 1: read the failing test's clock time before touching anything.

- [ ] **Step 2: Rebuild assets**

`view:cache` first, or the Tailwind scan misses Blade classes.

```bash
lerd artisan view:cache
bun run build
```

- [ ] **Step 3: Commit the built assets**

```bash
git add public/build
git commit -m "chore(assets): rebuild for the late clock-in remark screens"
```

Skip this step if `git status` shows `public/build` unchanged.

- [ ] **Step 4: Report back before deploying**

Do not push to staging as part of this plan. Summarise what landed and hand the release decision back, because the grace migration changes behaviour for every employee in every workspace on the first morning after deploy.

---

## Verification against the spec's success criteria

| Criterion | Proved by |
|---|---|
| 1. Late punch with no reason is refused, screen opens the field | Task 2 Step 5, Task 4 Step 5 |
| 2. Late punch with a reason saves with `late` status, flag and text | Task 2 Step 5 |
| 3. A punch inside the 15-minute grace is untouched | Task 1 Step 5, Task 2 Step 5 |
| 4. A director is gated identically | No role logic exists in `ClockService`; Task 2 adds none |
| 5. The drawer opens in place, no reload, no failed submit | Task 4 Step 5 |
| 6. Grace control visible without opening the WFH tab, and saving it leaves WFH hours intact | Task 5 Steps 4 and 8 |
| 7. Test suites pass | Task 6 Step 1 |

## Known ceilings, carried forward from the spec

Not addressed here. The first one will be felt within days of shipping.

- **Leave, public holidays and weekends are invisible to `ClockService`.** An employee on approved leave who punches on a public holiday is marked late and will now be asked to justify a day they were never due to work. `ReminderTargets` already knows how to skip all three; the clock does not ask.
- No "absent" concept: a day with no punch produces no row.
- A forgotten clock-out older than one day is never found again.
- `status = 'pending'` exists in the enum but nothing writes it.
