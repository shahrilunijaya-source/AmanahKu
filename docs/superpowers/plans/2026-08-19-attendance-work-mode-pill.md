# Attendance Work-Mode Pill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff declare a site visit before punching, so planned customer work is recorded as an itinerary instead of an off-site violation, and remove the work-from-home geofence along with every stored home address.

**Architecture:** A two-option segmented pill on the attendance screen posts a `work_mode` field with the punch. `ClockService` swaps the radius justification gate for a destination gate when the mode is `site_visit`, and writes `site_visit_in` / `site_visit_out` flags instead of `out_of_radius_*`. The declared mode is stored on the record in two new columns, one per punch. Separately, `homeSite()` stops producing coordinates, the home-capture path is deleted, and a second migration drops the stored home columns.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12, Alpine.js 3, Tailwind 4, Blade. Local dev under lerd (`lerd artisan …`). Tests run on sqlite by default.

**Source spec:** `docs/superpowers/specs/2026-08-19-attendance-work-mode-pill-design.md` — read it before Task 1. Every decision below traces to it.

## Global Constraints

- **Mode values are the exact strings `'office_home'` and `'site_visit'`.** No enum class, no constants file — the codebase uses plain validated strings for `action`, `status` and `type`, and this follows suit.
- **`null` `work_mode` means Office / Home.** Pre-migration records are never back-filled and must render exactly as they do today.
- **Never remove or weaken an existing assertion** except the home-geofence tests named explicitly in Tasks 8 and 9. Those deletions are sanctioned by the spec's removal inventory; nothing else in `tests/` may be deleted.
- **`in_radius` / `out_radius` stay truthful in both modes.** A site visit outside the fence still records `in_radius = false`. Only the flag and the gate change.
- **Bilingual, always.** Every user-visible string added to a Blade file needs both EN and BM, following the `$store.ui.lang === 'en' ? … : …` pattern already in that file.
- **No em dashes in user-facing copy.** Use connector words.
- **Run `vendor/bin/pint --dirty --format agent`** after any PHP change, before committing.
- **Run `bun run build`** after any change to `resources/css/app.css` or a Blade file, and commit `public/build` alongside. Run `lerd artisan view:cache` *before* `bun run build` so the Tailwind scan is complete.
- **Order matters.** Tasks 1 to 7 deliver the pill and are independently shippable. Tasks 8 to 10 remove the home geofence. Task 10 is destructive and must be last.

---

## File Structure

| File | Responsibility | Tasks |
|------|----------------|-------|
| `database/migrations/2026_08_19_000001_add_work_mode_to_attendance_records.php` | **Create.** Adds `work_mode`, `clock_out_work_mode`. | 1 |
| `database/migrations/2026_08_19_000002_drop_home_geofence_columns.php` | **Create.** Drops the four home columns. Irreversible. | 10 |
| `app/Attendance/ClockService.php` | Punch rules. Gains the mode parameter and the site-visit gates; loses the home-capture block. | 2, 8 |
| `app/Attendance/ScheduleResolver.php` | Site resolution. `homeSite()` loses its coordinates and radius. | 8 |
| `app/Attendance/SiteSpec.php` | Site value object. Loses `needsHomeCapture`. | 8 |
| `app/Http/Controllers/AttendanceController.php` | Validates `work_mode` and passes it through. | 3 |
| `app/Http/Controllers/AttendanceAdminController.php` | Loses `updateHome()`, the `reset_home` path, the `wfh_radius_m` rule. | 9 |
| `app/Models/Employee.php` | Loses the three home casts. | 9 |
| `routes/web.php` | Loses `attendance.admin.home`. | 9 |
| `resources/views/screens/attendance.blade.php` | The pill, the sheet copy, the badge, the drawer deletion. | 4, 5, 6, 7, 8 |
| `resources/views/screens/attendance-report.blade.php` | Flag labels and the map gates. | 7 |
| `resources/views/partials/attendance-day.blade.php` | The geofence sentence. | 7 |
| `resources/views/screens/attendance-admin.blade.php` | Loses the home section, the radius input, the pending badge, the Home status column. | 9 |
| `resources/css/app.css` | Pill styles in; `.uj-at-note` / `.uj-at-selfie` out. | 4 |
| `tests/Feature/ClockServiceTest.php` | Domain rules. | 2, 8 |
| `tests/Feature/AttendanceClockEndpointTest.php` | Endpoint validation and pass-through. | 3 |
| `tests/Feature/AttendanceScreenTest.php` | Rendered markup of the clock screen. | 4, 5, 6 |
| `tests/Feature/AttendanceReportScreenTest.php` | Report badges and map gating. | 7 |
| `tests/Feature/AttendanceAdminTest.php` | Setup screen; home cases deleted. | 9 |

---

## Task 1: Add the work_mode columns

**Files:**
- Create: `database/migrations/2026_08_19_000001_add_work_mode_to_attendance_records.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `attendance_records.work_mode` (nullable string), `attendance_records.clock_out_work_mode` (nullable string). Every later task assumes both exist.

- [ ] **Step 1: Create the migration file**

Write exactly this to `database/migrations/2026_08_19_000001_add_work_mode_to_attendance_records.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The work mode the employee declared for each punch: 'office_home' (the default,
        // and what every pre-existing row means) or 'site_visit'. Paired in/out like every
        // other per-punch column on this table, because a day can start at the office and
        // end at a customer. Nullable and never back-filled — null reads as office_home.
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('work_mode')->nullable()->after('type');
            $table->string('clock_out_work_mode')->nullable()->after('work_mode');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['work_mode', 'clock_out_work_mode']);
        });
    }
};
```

- [ ] **Step 2: Run the migration on the dev database**

Run: `lerd artisan migrate`
Expected: the migration name printed with `DONE`. If it errors with `error 168`, the lerd MySQL redo directory is root-owned — `sudo chown -R mysql:mysql` the data dir and restart the service.

- [ ] **Step 3: Confirm the columns exist**

Run: `lerd artisan db:show --table=attendance_records`
Expected: `work_mode` and `clock_out_work_mode` both listed, both nullable.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_08_19_000001_add_work_mode_to_attendance_records.php
git commit -m "feat(attendance): add work_mode columns to attendance records

One per punch, matching how the table already pairs location, selfie and
justification. Null means a row written before the work-mode pill existed and
reads as office_home; nothing is back-filled."
```

---

## Task 2: Site-visit rules in ClockService

**Files:**
- Modify: `app/Attendance/ClockService.php`
- Test: `tests/Feature/ClockServiceTest.php`

**Interfaces:**
- Consumes: the columns from Task 1.
- Produces:
  - `ClockService::clockIn(Employee $employee, ?float $lat, ?float $lng, ?string $justification, ?string $photoPath, Carbon $now, string $workMode = 'office_home'): array{status:string, message:string}`
  - `ClockService::clockOut(Employee $employee, ?float $lat, ?float $lng, ?string $justification, ?string $photoPath, Carbon $now, string $workMode = 'office_home'): array{status:string, message:string}`
  - Both parameters are **appended last with a default**, so every existing call site and test keeps working untouched. Do not insert the parameter in the middle.

- [ ] **Step 1: Write the failing tests**

Append these to `tests/Feature/ClockServiceTest.php`, immediately before the closing brace:

```php
    // --- Declared site visits -------------------------------------------------

    public function test_a_declared_site_visit_off_site_is_recorded_not_flagged(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn(
            $this->employee, 3.20, 101.60, 'Customer ABC, Shah Alam', 'attendance-photos/a.jpg', $now, 'site_visit'
        );

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('site_visit', $record->work_mode);
        $this->assertContains('site_visit_in', $record->flags);
        $this->assertNotContains('out_of_radius_in', $record->flags);
        // The fence result stays truthful even though it is no longer an accusation.
        $this->assertFalse($record->in_radius);
        $this->assertSame('Customer ABC, Shah Alam', $record->clock_in_justification);
    }

    public function test_a_site_visit_with_no_destination_is_refused(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn(
            $this->employee, 3.20, 101.60, null, 'attendance-photos/a.jpg', $now, 'site_visit'
        );

        $this->assertSame('needs_justification', $res['status']);
        $this->assertStringContainsString('where you are going', $res['message']);
        $this->assertNull($this->employee->attendanceRecords()->onDate($now)->first());
    }

    public function test_a_site_visit_destination_of_only_whitespace_is_refused(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn(
            $this->employee, 3.20, 101.60, '   ', 'attendance-photos/a.jpg', $now, 'site_visit'
        );

        $this->assertSame('needs_justification', $res['status']);
    }

    public function test_a_site_visit_still_needs_a_selfie(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn(
            $this->employee, 3.20, 101.60, 'Customer ABC', null, $now, 'site_visit'
        );

        $this->assertSame('needs_photo', $res['status']);
    }

    public function test_a_late_site_visit_is_still_late(): void
    {
        $now = Carbon::parse('2026-07-02 10:30:00');

        $res = $this->service($this->office())->clockIn(
            $this->employee, 3.20, 101.60, 'Customer ABC', 'attendance-photos/a.jpg', $now, 'site_visit'
        );

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertSame('late', $record->status);
        $this->assertContains('late', $record->flags);
        $this->assertContains('site_visit_in', $record->flags);
    }

    public function test_a_site_visit_declared_inside_the_fence_is_allowed(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn(
            $this->employee, 3.1001, 101.6001, 'Leaving for Customer ABC', 'attendance-photos/a.jpg', $now, 'site_visit'
        );

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertTrue($record->in_radius);
        $this->assertSame('site_visit', $record->work_mode);
        $this->assertContains('site_visit_in', $record->flags);
    }

    public function test_a_site_visit_clock_out_inherits_the_mode_without_asking_again(): void
    {
        $in = Carbon::parse('2026-07-02 08:55:00');
        $out = Carbon::parse('2026-07-02 18:05:00');
        $service = $this->service($this->office());

        $service->clockIn($this->employee, 3.20, 101.60, 'Customer ABC', 'attendance-photos/a.jpg', $in, 'site_visit');
        $res = $service->clockOut($this->employee, 3.20, 101.60, null, 'attendance-photos/b.jpg', $out, 'site_visit');

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($in)->first();
        $this->assertSame('site_visit', $record->clock_out_work_mode);
        $this->assertContains('site_visit_out', $record->flags);
        $this->assertNotContains('out_of_radius_out', $record->flags);
    }

    public function test_a_clock_out_mode_never_overwrites_the_clock_in_mode(): void
    {
        $in = Carbon::parse('2026-07-02 08:55:00');
        $out = Carbon::parse('2026-07-02 18:05:00');
        $service = $this->service($this->office());

        $service->clockIn($this->employee, 3.1001, 101.6001, null, 'attendance-photos/a.jpg', $in);
        $service->clockOut($this->employee, 3.20, 101.60, 'Ended at Customer ABC', 'attendance-photos/b.jpg', $out, 'site_visit');

        $record = $this->employee->attendanceRecords()->onDate($in)->first();
        $this->assertSame('office_home', $record->work_mode);
        $this->assertSame('site_visit', $record->clock_out_work_mode);
        $this->assertContains('site_visit_out', $record->flags);
        $this->assertNotContains('site_visit_in', $record->flags);
    }

    public function test_switching_to_site_visit_at_clock_out_needs_a_destination(): void
    {
        $in = Carbon::parse('2026-07-02 08:55:00');
        $out = Carbon::parse('2026-07-02 18:05:00');
        $service = $this->service($this->office());

        // Clocked in normally, at the office, on time.
        $service->clockIn($this->employee, 3.1001, 101.6001, null, 'attendance-photos/a.jpg', $in);
        $res = $service->clockOut($this->employee, 3.20, 101.60, null, 'attendance-photos/b.jpg', $out, 'site_visit');

        $this->assertSame('needs_justification', $res['status']);
        $this->assertStringContainsString('where you were', $res['message']);
    }

    public function test_a_site_visit_clock_out_before_the_shift_ends_is_still_early(): void
    {
        $in = Carbon::parse('2026-07-02 08:55:00');
        $out = Carbon::parse('2026-07-02 15:00:00');
        $service = $this->service($this->office());

        $service->clockIn($this->employee, 3.20, 101.60, 'Customer ABC', 'attendance-photos/a.jpg', $in, 'site_visit');
        $res = $service->clockOut($this->employee, 3.20, 101.60, null, 'attendance-photos/b.jpg', $out, 'site_visit');

        $this->assertSame('needs_justification', $res['status']);
    }

    public function test_office_home_mode_is_unchanged_when_the_parameter_is_omitted(): void
    {
        $now = Carbon::parse('2026-07-02 08:55:00');

        $res = $this->service($this->office())->clockIn($this->employee, 3.20, 101.60, null, null, $now);

        $this->assertSame('needs_justification', $res['status']);
        $this->assertNull($this->employee->attendanceRecords()->onDate($now)->first());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `lerd artisan test --compact --filter='site_visit|declared_site|never_overwrites' tests/Feature/ClockServiceTest.php`
Expected: FAIL. The first failures are `Too few arguments` / unknown named argument, because `clockIn()` does not take a seventh parameter yet.

- [ ] **Step 3: Add the mode parameter and the site-visit gates to clockIn**

In `app/Attendance/ClockService.php`, change the `clockIn` signature to append the parameter:

```php
    public function clockIn(Employee $employee, ?float $lat, ?float $lng, ?string $justification, ?string $photoPath, Carbon $now, string $workMode = 'office_home'): array
```

Immediately after `$inRadius = $this->within($site, $lat, $lng);`, add:

```php
        // A declared site visit: the employee said before punching that today is customer
        // work. That changes the framing, never the evidence — GPS, selfie and a typed line
        // are all still collected, and in_radius below is still recorded truthfully.
        $siteVisit = $workMode === 'site_visit';
```

Replace the out-of-radius gate (currently the block whose comment reads
"Outside the geofence must be justified") with these two, in this order:

```php
        // The price of declaring a site visit, and the only thing that keeps the pill from
        // being a one-tap exemption from the geofence: say where you are going.
        if ($siteVisit && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'Say where you are going to clock in.'];
        }

        // Outside the geofence must be justified — never hard-blocked (bad GPS shouldn't strand staff).
        // Skipped for a declared site visit, which has already paid with a destination above.
        if (! $siteVisit && $inRadius === false && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'You appear to be outside '.$site->label.'. Add a reason to clock in.'];
        }
```

Replace the flag block's radius entry so the two are mutually exclusive:

```php
        if ($siteVisit) {
            $flags[] = 'site_visit_in';
        } elseif ($inRadius === false) {
            $flags[] = 'out_of_radius_in';
        }
```

Add one entry to `$attributes`, directly after `'in_radius' => $inRadius,`:

```php
            'work_mode' => $siteVisit ? 'site_visit' : 'office_home',
```

- [ ] **Step 4: Add the mode parameter and gates to clockOut**

Change the signature the same way:

```php
    public function clockOut(Employee $employee, ?float $lat, ?float $lng, ?string $justification, ?string $photoPath, Carbon $now, string $workMode = 'office_home'): array
```

After `$short = $this->isShort($worked, $record->expected_min_hours);`, add:

```php
        $siteVisit = $workMode === 'site_visit';

        // Only a mode that was NOT already declared this morning owes a destination. A day
        // declared a site visit at clock-in already carries the text on the record, and
        // asking for it again at 6pm collects nothing new.
        $newlyDeclared = $siteVisit && $record->work_mode !== 'site_visit';
```

Add the destination gate immediately before the existing "Leaving the site early" gate:

```php
        if ($newlyDeclared && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'Say where you were to clock out.'];
        }
```

Change the existing early/off-site gate so only the radius term is dropped for a site visit.
Leaving at 3pm is still early and a short day is still short, site visit or not:

```php
        if (((! $siteVisit && $outRadius === false) || $early || $short) && ! $this->filled($justification)) {
            return ['status' => 'needs_justification', 'message' => 'This clock-out looks early or off-site. Add a reason to clock out.'];
        }
```

Change the out-radius flag entry the same way as clock-in:

```php
        if ($siteVisit) {
            $flags[] = 'site_visit_out';
        } elseif ($outRadius === false) {
            $flags[] = 'out_of_radius_out';
        }
```

Add one entry to `$updates`, directly after `'out_radius' => $outRadius,`:

```php
            'clock_out_work_mode' => $siteVisit ? 'site_visit' : 'office_home',
```

- [ ] **Step 5: Run the new tests**

Run: `lerd artisan test --compact --filter='site_visit|declared_site|office_home_mode' tests/Feature/ClockServiceTest.php`
Expected: PASS, all eleven.

- [ ] **Step 6: Run the whole ClockService suite to prove nothing regressed**

Run: `lerd artisan test --compact tests/Feature/ClockServiceTest.php`
Expected: PASS, every test. The pre-existing off-site, late, early and overnight cases must all still pass untouched — they are the proof that Office / Home mode is byte-for-byte unchanged.

- [ ] **Step 7: Note the nuance on the docblock that appears to forbid this**

`ScheduleResolver::matchActualSite` carries a refusal that a future reader will read as
contradicting the pill: *"Staff never pick their site from a list …"*. That decision still stands —
the pill declares intent, not location, and `matchActualSite` runs untouched in both modes. Add one
line to the end of that docblock so the next reader sees the distinction rather than a conflict:

```php
     * The work-mode pill does not weaken this: it declares *intent* ("today is a customer
     * visit"), never a site. The GPS is still measured against the same resolved site in both
     * modes — see docs/superpowers/specs/2026-08-19-attendance-work-mode-pill-design.md.
```

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Attendance/ClockService.php app/Attendance/ScheduleResolver.php tests/Feature/ClockServiceTest.php
git commit -m "feat(attendance): accept a declared work mode in ClockService

A site visit trades the off-site justification gate for a destination gate and
writes site_visit_in/out instead of out_of_radius_in/out. Everything else the
punch collects is unchanged: GPS, selfie, in_radius, the late gate, the early
gate and the short-hours gate all behave exactly as before.

The mode parameter is appended last with a default so every existing caller and
test is untouched."
```

---

## Task 3: Accept work_mode at the clock endpoint

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php:130-145`
- Test: `tests/Feature/AttendanceClockEndpointTest.php`

**Interfaces:**
- Consumes: `ClockService::clockIn(..., string $workMode)` from Task 2.
- Produces: the endpoint accepts a `work_mode` POST field. Task 4's Blade posts into it.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AttendanceClockEndpointTest.php`, before the closing brace. Read the file's existing `setUp` and any punch helper first and follow the same shape — these tests use whatever helper the file already defines for posting a punch, or post directly to `/app/attendance/clock` with `action`, `latitude`, `longitude`, `photo` and `justification` as the existing tests do:

```php
    public function test_a_declared_site_visit_reaches_the_record(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/attendance/clock', [
                'action' => 'in',
                'work_mode' => 'site_visit',
                'latitude' => 3.20,
                'longitude' => 101.60,
                'justification' => 'Customer ABC, Shah Alam',
                'photo' => UploadedFile::fake()->image('selfie.jpg'),
            ]);

        $record = $this->employee->attendanceRecords()->first();
        $this->assertNotNull($record);
        $this->assertSame('site_visit', $record->work_mode);
    }

    public function test_a_junk_work_mode_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/attendance/clock', [
                'action' => 'in',
                'work_mode' => 'holiday',
                'latitude' => 3.20,
                'longitude' => 101.60,
                'justification' => 'Anywhere',
                'photo' => UploadedFile::fake()->image('selfie.jpg'),
            ])
            ->assertSessionHasErrors('work_mode');

        $this->assertNull($this->employee->attendanceRecords()->first());
    }

    public function test_a_punch_with_no_work_mode_field_still_works(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/attendance/clock', [
                'action' => 'in',
                'latitude' => 3.20,
                'longitude' => 101.60,
                'justification' => 'Bad GPS today',
                'photo' => UploadedFile::fake()->image('selfie.jpg'),
            ]);

        $record = $this->employee->attendanceRecords()->first();
        $this->assertNotNull($record);
        $this->assertSame('office_home', $record->work_mode);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `lerd artisan test --compact --filter='work_mode|site_visit' tests/Feature/AttendanceClockEndpointTest.php`
Expected: FAIL. The first two fail because `work_mode` is neither validated nor passed on.

- [ ] **Step 3: Add the validation rule**

In `app/Http/Controllers/AttendanceController.php`, inside `validateClock()`, add after the `action` rule:

```php
            // The mode the employee declared before punching. Nullable so an older cached
            // page, or any client that omits the field, still punches — read as office_home,
            // which is exactly the behaviour that existed before the pill.
            'work_mode' => ['nullable', 'in:office_home,site_visit'],
```

- [ ] **Step 4: Pass it through**

In `clock()`, after `$justification = $validated['justification'] ?? null;`, add:

```php
        $workMode = $validated['work_mode'] ?? 'office_home';
```

Then extend both service calls:

```php
        if ($validated['action'] === 'in') {
            $result = $this->clock->clockIn($employee, $lat, $lng, $justification, $photoPath, $now, $workMode);
        } else {
            $result = $this->clock->clockOut($employee, $lat, $lng, $justification, $photoPath, $now, $workMode);
        }
```

- [ ] **Step 5: Run the tests**

Run: `lerd artisan test --compact tests/Feature/AttendanceClockEndpointTest.php`
Expected: PASS, including every pre-existing test in the file.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AttendanceController.php tests/Feature/AttendanceClockEndpointTest.php
git commit -m "feat(attendance): validate and forward the declared work mode

Nullable on purpose: a cached page or a client that omits the field punches as
office_home, which is the behaviour that existed before the pill."
```

---

## Task 4: The pill, and deleting the inline remark drawer

**Files:**
- Modify: `resources/views/screens/attendance.blade.php`
- Modify: `resources/css/app.css:1490-1510`
- Test: `tests/Feature/AttendanceScreenTest.php`

**Interfaces:**
- Consumes: the `work_mode` field accepted in Task 3.
- Produces: Alpine state `workMode` (string, `'office_home'` or `'site_visit'`) on the clock component, readable by Tasks 5 and 6. A hidden input `name="work_mode"` bound to it. The `name="justification"` attribute now lives on `#attendance-sheet-reason`.

**THE TRAP.** `name="justification"` currently lives on the drawer's textarea. The sheet's textarea is `x-model` only and posts nothing. Delete the drawer without moving that attribute and every typed reason is silently dropped on submit, and the server then refuses the punch for a missing reason the employee can see themselves typing. Step 4 moves it; Step 1's `test_a_reason_typed_in_the_sheet_reaches_the_server` is the guard.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/AttendanceScreenTest.php`, before the closing brace:

```php
    public function test_the_screen_offers_the_work_mode_pill(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        $this->assertStringContainsString('uj-at-mode', $html);
        $this->assertStringContainsString('name="work_mode"', $html);
        $this->assertStringContainsString('Site visit', $html);
        $this->assertStringContainsString('Lawatan tapak', $html);
    }

    public function test_the_inline_remark_drawer_is_gone(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-notebtn', $html);
        $this->assertStringNotContainsString('uj-at-note', $html);
        $this->assertStringNotContainsString('attendance-remarks', $html);
    }

    /**
     * The reason box that posts must be exactly one box. Two would mean the drawer survived;
     * zero would mean the name attribute was deleted along with the drawer, and every typed
     * reason would vanish on submit while the screen still looked correct.
     */
    public function test_a_reason_typed_in_the_sheet_reaches_the_server(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'name="justification"'));
        $this->assertStringContainsString('attendance-sheet-reason', $html);

        // And the attribute is on the sheet's textarea, not somewhere else on the page.
        $this->assertMatchesRegularExpression(
            '/id="attendance-sheet-reason"[^>]*name="justification"|name="justification"[^>]*id="attendance-sheet-reason"/',
            $html
        );
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `lerd artisan test --compact --filter='work_mode_pill|remark_drawer|reaches_the_server' tests/Feature/AttendanceScreenTest.php`
Expected: FAIL on all three.

- [ ] **Step 3: Add the pill state and the hidden input**

In `resources/views/screens/attendance.blade.php`, inside the `x-data` object (near `serverJustify` around line 114), add:

```js
              // Declared before the punch, posted with it. Seeded from the open record so a
              // day declared a site visit this morning is still declared at clock-out, and
              // still switchable for someone who ended up somewhere else.
              workMode: @js(old('work_mode', $today?->work_mode === 'site_visit' && ! $co ? 'site_visit' : 'office_home')),
```

**Why `$today` is the right source.** `BuildsWorkData::workData()` resolves `today` by preferring
the still-open punch bounded to yesterday-or-today, exactly as `ClockService::clockOut()` looks it
up, and only falling back to the on-date record. So on a shift that crosses midnight the pill
inherits the declared mode along with `$ci`, `$co` and the hidden `action` input, all of which read
from the same value. Do not add a separate lookup for the pill — it would be the one thing on the
shelf disagreeing with the button beside it.

Beside the existing hidden `action` input (line 757), add:

```blade
        <input type="hidden" name="work_mode" :value="workMode" />
```

- [ ] **Step 4: Move the name attribute onto the sheet textarea**

Find `#attendance-sheet-reason` in the sheet markup and add `name="justification"` to it, so it reads:

```blade
                        <textarea id="attendance-sheet-reason" name="justification" x-ref="sheetReason" x-model="reason" rows="2" maxlength="500"
                                  aria-required="true" :placeholder="sheetReasonPlaceholder($store.ui.lang)"></textarea>
```

Directly under it, add the validation message that used to live in the drawer:

```blade
                        @error('justification')<div style="color:var(--red);font-size:11.5px;margin-top:4px;">{{ $message }}</div>@enderror
```

- [ ] **Step 5: Render the pill above the punch button**

Insert this immediately before the action row that holds the punch button (the row containing `class="uj-at-go"`):

```blade
        {{-- Declared before the punch: the ordinary day needs no tap, a customer visit needs
             one. Not a site picker — the GPS is still measured against the same place either
             way, and the declaration costs a typed destination in the sheet. --}}
        <div class="uj-at-mode" role="group" :aria-label="$store.ui.lang==='en' ? 'Working mode' : 'Mod kerja'">
            <button type="button" :data-on="workMode === 'office_home'" @click="workMode = 'office_home'"
                    :aria-pressed="workMode === 'office_home' ? 'true' : 'false'"
                    x-text="$store.ui.lang==='en' ? 'Office / Home' : 'Pejabat / Rumah'">Office / Home</button>
            <button type="button" :data-on="workMode === 'site_visit'" @click="workMode = 'site_visit'"
                    :aria-pressed="workMode === 'site_visit' ? 'true' : 'false'"
                    x-text="$store.ui.lang==='en' ? 'Site visit' : 'Lawatan tapak'">Site visit</button>
        </div>
```

- [ ] **Step 6: Delete the drawer and everything that only served it**

Delete, in `resources/views/screens/attendance.blade.php`:

1. The whole `<div class="uj-at-note" …>` block (label, textarea, the `@error` that was inside it, and the `.uj-at-selfie` div with its "Take a selfie" ghost button).
2. The `data-notebtn` toggle button in the action row.
3. From the `x-data` object: `noteOpen`, the `toggleNote()` method, the `isReq` getter, and the `this.$watch('isReq', …)` block in `init()`.

Then confirm nothing still references them:

```bash
grep -n "noteOpen\|toggleNote\|isReq\|uj-at-note\|uj-at-selfie\|attendance-remarks\|data-notebtn" resources/views/screens/attendance.blade.php
```

Expected: no output. If `isReq` still appears, a consumer was missed — find it before continuing.

- [ ] **Step 7: Swap the drawer styles for the pill styles**

In `resources/css/app.css`, delete the `.uj-at-note*` and `.uj-at-selfie*` rules (around lines 1490 to 1510) and add:

```css
.uj-at-mode { display: inline-flex; padding: 3px; margin-bottom: 12px; background: var(--hairline-soft); border-radius: 10px; gap: 3px; }
.uj-at-mode button { height: 34px; padding: 0 14px; border: 0; border-radius: 8px; background: transparent; color: var(--muted); font-size: 13px; font-weight: 500; cursor: pointer; transition: background .14s cubic-bezier(.23, 1, .32, 1), color .14s cubic-bezier(.23, 1, .32, 1); }
.uj-at-mode button[data-on] { background: #fff; color: var(--ink); font-weight: 600; box-shadow: 0 1px 2px rgb(0 0 0 / .06); }
```

- [ ] **Step 8: Run the tests**

Run: `lerd artisan test --compact tests/Feature/AttendanceScreenTest.php`
Expected: PASS, including every pre-existing test in the file.

- [ ] **Step 9: Build the assets**

```bash
lerd artisan view:cache
bun run build
```

- [ ] **Step 10: Verify in the browser**

Open `http://localhost:9100/app/attendance` with the preview tools, log in as `employee@amanahku.test`, and confirm: the pill renders above the punch button, tapping switches the highlight, and there is no remark box or selfie button under the punch button. Check the console for Alpine errors — a leftover reference to a deleted method shows up there, not in a test.

- [ ] **Step 11: Commit**

```bash
git add resources/views/screens/attendance.blade.php resources/css/app.css tests/Feature/AttendanceScreenTest.php public/build
git commit -m "feat(attendance): add the work-mode pill and delete the remark drawer

The pill declares the day before the punch. The drawer under the punch button
goes with it: the sheet already renders a camera and a reason box together and
opens on every punch anyway, so the drawer was a second copy of a form the
employee is about to be shown.

name=\"justification\" moves onto the sheet textarea. It lived on the drawer's,
and deleting the drawer without moving it would have dropped every typed reason
on submit while the screen still looked correct."
```

---

## Task 5: Site-visit copy in the punch sheet

**Files:**
- Modify: `resources/views/screens/attendance.blade.php` (the sheet's copy methods and `proceed()`)
- Test: `tests/Feature/AttendanceScreenTest.php`

**Interfaces:**
- Consumes: `workMode` from Task 4.
- Produces: the sheet raises its reason box for a site visit and never calls it off-site.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/AttendanceScreenTest.php`:

```php
    public function test_the_sheet_carries_site_visit_copy_in_both_languages(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        // The site_visit branch exists in each of the sheet's four copy methods.
        $this->assertStringContainsString('Where are you going?', $html);
        $this->assertStringContainsString('Ke mana anda pergi?', $html);
        $this->assertStringContainsString('Tell your manager where you are going', $html);
        $this->assertSame(
            4,
            substr_count($html, 'site_visit:'),
            'sheetTitle, sheetBody, sheetReasonLabel and sheetReasonPlaceholder each need a site_visit case'
        );
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `lerd artisan test --compact --filter=site_visit_copy tests/Feature/AttendanceScreenTest.php`
Expected: FAIL — `Where are you going?` is not in the markup.

- [ ] **Step 3: Raise the sheet's reason box for a site visit**

`proceed()` picks the sheet's reason kind from one priority chain. Everything downstream
(`sheetNeed`, `sheetReasonNeed`, the four copy methods) reads that one value, so this is a
one-line change. Replace this line:

```js
                  let reasonKind = noFix ? 'no_location' : (offSite ? 'off_site' : null);
```

with:

```js
                  // A declared site visit owes a destination, and outranks 'off_site' because
                  // the fence is no longer what is being asked about. It does NOT outrank
                  // 'no_location', which mirrors ClockService's own gate order: an unlocatable
                  // punch is reported as unlocatable whatever the employee declared.
                  let reasonKind = noFix
                      ? 'no_location'
                      : (this.workMode === 'site_visit' ? 'site_visit' : (offSite ? 'off_site' : null));
```

Nothing else in `proceed()` changes. `needReason`, the combined selfie-and-reason gate, and the
submit path all already work off `reasonKind`.

- [ ] **Step 4: Add the copy to all four sheet methods**

In `sheetTitle`, add to both language maps:

```js
                      site_visit: 'Site visit',
```
```js
                      site_visit: 'Lawatan tapak',
```

In `sheetBody`:

```js
                      site_visit: 'Tell your manager where you are going. Your selfie and location are still recorded.',
```
```js
                      site_visit: 'Beritahu pengurus anda ke mana anda pergi. Selfie dan lokasi anda tetap direkodkan.',
```

In `sheetReasonLabel`:

```js
                      site_visit: 'Where are you going?',
```
```js
                      site_visit: 'Ke mana anda pergi?',
```

In `sheetReasonPlaceholder`:

```js
                      site_visit: 'e.g. Customer ABC, Shah Alam',
```
```js
                      site_visit: 'cth. Customer ABC, Shah Alam',
```

- [ ] **Step 5: Point server-side re-entry at the sheet**

`serverJustify` (line ~114) and the old `noteOpen` initialiser opened the drawer on a refused punch.
The drawer is gone, so a refused punch must reopen the **sheet** with the reason box shown and the
text restored from `old('justification')`. In `init()`, replace whatever remains of the `noteOpen`
seeding with a call that opens the sheet in reason mode when `serverJustify` is true.

Verify by hand: submit an off-site punch with no reason, and confirm the sheet reopens carrying both
the previous text and the red validation message added in Task 4 Step 4.

- [ ] **Step 6: Update the screen's guide copy, which now describes a box that is gone**

The `@include('partials.guide', …)` block at the top of the file still tells staff to "Add a remark
if there is something your manager should know about the day. It is optional." That box was deleted
in Task 4, and nothing says the pill exists. Replace the `en` and `ms` `body` and `steps` with these
exact values. Leave the `title` and `who` keys untouched.

`en.body`:

```
Pick your working mode, then clock in when you start and clock out when you finish. A selfie is required every time and the camera opens on its own. Your GPS is checked against where you are meant to be that day, which is your office, your client site, or your home. On a site visit you say where you are going instead, and there is no off-site flag. Clocking in late or out early still needs a reason.
```

`en.steps`, in order:

```php
            'The banner shows where you are expected today and your hours.',
            'Pick your working mode first. Leave it on "Office / Home" for an ordinary day, or tap "Site visit" if you are going to a customer.',
            'Tap "Clock in" and allow location. The camera opens to take your selfie, which is required for every clock in and clock out, no exceptions.',
            'On a site visit the same window asks where you are going. Say the place, for example "Customer ABC, Shah Alam".',
            'If you are late, off-site, or your device cannot find your location, that window asks for a reason instead. Your manager sees it with the punch.',
            'Clock out when you finish, with another selfie. Leaving before your end time needs a reason too.',
```

`ms.body`:

```
Pilih mod kerja anda, kemudian clock in bila mula dan clock out bila habis. Selfie diperlukan setiap kali dan kamera terbuka sendiri. GPS anda disemak dengan tempat anda sepatutnya berada hari itu, iaitu pejabat, lokasi klien, atau rumah. Untuk lawatan tapak anda nyatakan ke mana anda pergi, dan tiada tanda luar lokasi. Clock in lewat atau clock out awal tetap perlu sebab.
```

`ms.steps`, in order:

```php
            'Sepanduk menunjukkan di mana anda sepatutnya hari ini dan waktu kerja anda.',
            'Pilih mod kerja anda dahulu. Biarkan pada "Pejabat / Rumah" untuk hari biasa, atau tekan "Lawatan tapak" jika anda ke tempat pelanggan.',
            'Tekan "Clock in" dan benarkan lokasi. Kamera terbuka untuk ambil selfie anda, yang diperlukan untuk setiap clock in dan clock out, tiada pengecualian.',
            'Untuk lawatan tapak, tetingkap yang sama bertanya ke mana anda pergi. Nyatakan tempatnya, contohnya "Customer ABC, Shah Alam".',
            'Jika anda lewat, di luar lokasi, atau peranti anda tidak dapat mencari lokasi, tetingkap itu meminta sebab. Pengurus anda melihatnya bersama rekod.',
            'Clock out bila habis, dengan satu lagi selfie. Balik sebelum waktu tamat perlu sebab juga.',
```

- [ ] **Step 7: Run the tests**

Run: `lerd artisan test --compact tests/Feature/AttendanceScreenTest.php`
Expected: PASS.

- [ ] **Step 8: Build and verify in the browser**

```bash
lerd artisan view:cache
bun run build
```

Pick Site visit, tap Clock in, and confirm the sheet says "Site visit" with the destination box, and
never the words "off-site" or "flagged". Switch the language toggle and confirm the BM copy.

- [ ] **Step 9: Commit**

```bash
git add resources/views/screens/attendance.blade.php tests/Feature/AttendanceScreenTest.php public/build
git commit -m "feat(attendance): give the punch sheet its site-visit copy

The sheet is the loudest place the old accusation was written. Under a declared
site visit it now asks where you are going instead of telling you that you
appear to be outside the expected location and that your manager will see it
flagged, which stopped being true.

A refused punch reopens the sheet rather than the drawer that no longer exists."
```

---

## Task 6: The tappable location badge

**Files:**
- Modify: `resources/views/screens/attendance.blade.php` (`fenceText`, `geoFail`, the badge markup)
- Test: `tests/Feature/AttendanceScreenTest.php`

**Interfaces:**
- Consumes: `workMode` from Task 4, the existing `bestFix` / `fenceStatus` / `fenceDistM` state.
- Produces: `fenceStatus` gains a `'fail'` value. Nothing later depends on it.

- [ ] **Step 1: Write the failing test**

```php
    public function test_the_location_badge_is_tappable_and_has_a_failure_state(): void
    {
        $html = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance')->assertOk()->getContent();

        $this->assertStringContainsString('recheckFence()', $html);
        $this->assertStringContainsString('Location not found', $html);
        $this->assertStringContainsString('Lokasi tidak dijumpai', $html);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `lerd artisan test --compact --filter=location_badge tests/Feature/AttendanceScreenTest.php`
Expected: FAIL.

- [ ] **Step 3: Add the re-check method**

The badge currently fills once in `init()`. Lift that block into a named method so a tap can re-run
it. Add to the `x-data` object:

```js
              /**
               * The badge reads once on load, so a page opened in the car still says off-site
               * after walking in. Tapping re-runs the same acquisition — no new geolocation
               * code, and no second button beside the punch, which is what produced the
               * "I clocked in twice" confusion.
               */
              recheckFence() {
                  if (!window.isSecureContext || !navigator.geolocation) { this.fenceStatus = 'none'; return; }
                  this.fenceStatus = 'wait';
                  this.bestFix(
                      (pos) => {
                          const m = this.matchSite(pos.coords.latitude, pos.coords.longitude);
                          if (m) {
                              this.matchedLabel = m.label === this.assignedLabel ? '' : m.label;
                              this.fenceDistM = Math.round(m.d);
                              this.fenceStatus = 'in';
                          } else if (this.siteLat !== null) {
                              this.matchedLabel = '';
                              this.fenceDistM = Math.round(this.distTo(pos.coords.latitude, pos.coords.longitude, this.siteLat, this.siteLng));
                              this.fenceStatus = 'out';
                          } else {
                              this.fenceStatus = 'none';
                          }
                      },
                      (kind, err) => {
                          if (kind === 'denied') { this.geoFail('denied', err); this.fenceStatus = 'none'; return; }
                          // A slow or unavailable lookup used to leave the badge blank with no
                          // message at all. Say so, and offer the retry.
                          this.fenceStatus = 'fail';
                      },
                      true,
                      12000
                  );
              },
```

Then replace the inline `bestFix` block in `init()` with a call to `this.recheckFence()`.

- [ ] **Step 4: Add the failure copy and the site-visit wording to fenceText**

At the top of `fenceText(lang)`, add:

```js
                  if (this.fenceStatus === 'fail') {
                      return lang === 'en' ? 'Location not found — tap to check' : 'Lokasi tidak dijumpai — tekan untuk semak';
                  }
```

In the `'out'` branch, return the distance without the verdict when a site visit is declared. The
distance is still useful; the judgement is the thing the pill exists to remove:

```js
                  if (this.fenceStatus === 'out') {
                      const dStr = this.fenceDistM < 1000 ? this.fenceDistM + 'm' : (this.fenceDistM / 1000).toFixed(1) + ' km';
                      if (this.workMode === 'site_visit') {
                          return lang === 'en' ? (dStr + ' from ' + this.assignedLabel) : (dStr + ' dari ' + this.assignedLabel);
                      }
                      // ... existing "Off-site · Xm away" strings, unchanged
                  }
```

- [ ] **Step 5: Make the badge a button**

Change the badge element to a `<button type="button">` carrying `@click="recheckFence()"`, keeping
its `uj-at-fence` class and `:data-f="fenceStatus"`, and widen its `x-show` to include the new
`'fail'` state:

```blade
                        <button type="button" class="uj-at-fence" @click="recheckFence()"
                                x-show="fenceStatus !== 'none' && fenceStatus !== 'wait'"
                                :data-f="workMode === 'site_visit' && fenceStatus === 'out' ? 'info' : fenceStatus" x-cloak>
                            <i></i>
                            <span x-text="fenceText($store.ui.lang)"></span>
                        </button>
```

Add the two new tones to `resources/css/app.css` beside the existing `.uj-at-fence[data-f=…]` rules:

```css
.uj-at-fence[data-f="fail"] { background: var(--hairline-soft); color: var(--muted); }
.uj-at-fence[data-f="info"] { background: #eaf1fb; color: #1d4d8f; }
.uj-at-fence { border: 0; cursor: pointer; font-family: inherit; }
```

- [ ] **Step 6: Run the tests**

Run: `lerd artisan test --compact tests/Feature/AttendanceScreenTest.php`
Expected: PASS.

- [ ] **Step 7: Build and verify in the browser**

```bash
lerd artisan view:cache
bun run build
```

Confirm the badge is clickable and repaints, and that picking Site visit turns "Off-site · 1.2 km
away" into "1.2 km from …" in the quiet blue tone.

- [ ] **Step 8: Commit**

```bash
git add resources/views/screens/attendance.blade.php resources/css/app.css tests/Feature/AttendanceScreenTest.php public/build
git commit -m "fix(attendance): make the location badge re-checkable and honest

It read once on page load, so a page opened in the car still said off-site after
walking in, and a slow lookup left it blank with no message at all. It is now a
button that re-runs the same acquisition, and it says so when the lookup fails.

Under a declared site visit it shows the distance without the verdict."
```

---

## Task 7: Site visits in the report and the day partial

**Files:**
- Modify: `resources/views/screens/attendance-report.blade.php:8-12, :149, :158`
- Modify: `resources/views/screens/attendance.blade.php:8-12`
- Modify: `resources/views/partials/attendance-day.blade.php:55-62`
- Test: `tests/Feature/AttendanceReportScreenTest.php`

**Interfaces:**
- Consumes: the `site_visit_in` / `site_visit_out` flags and `work_mode` written in Task 2.
- Produces: nothing later depends on it.

- [ ] **Step 1: Write the failing test**

The file already provides an `actAsHr()` helper and an `$this->hrEmployee` fixture — use both rather
than building a new HR user. Append:

```php
    public function test_a_site_visit_row_reads_as_a_visit_and_still_offers_the_map(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->hrEmployee->id,
            'date' => now()->toDateString(),
            'status' => 'on_time',
            'clock_in' => '09:00:00',
            'latitude' => 3.20,
            'longitude' => 101.60,
            'in_radius' => false,
            'work_mode' => 'site_visit',
            'clock_in_justification' => 'Customer ABC, Shah Alam',
            'flags' => ['site_visit_in'],
        ]);

        $html = $this->actAsHr()
            ->get('/app/attendance-report?'.http_build_query(['drill' => $this->hrEmployee->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Site visit', $html);
        $this->assertStringContainsString('Customer ABC, Shah Alam', $html);
        $this->assertStringNotContainsString('Off-site in', $html);
        // The map is exactly the thing a manager wants on a declared visit.
        $this->assertStringContainsString('3.2', $html);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `lerd artisan test --compact --filter=site_visit_row tests/Feature/AttendanceReportScreenTest.php`
Expected: FAIL — the raw flag string renders and the map coordinates are absent.

- [ ] **Step 3: Add the flag labels in both label maps**

In `resources/views/screens/attendance-report.blade.php`, add to `$flagLabel` after the
`out_of_radius_*` entries:

```php
        'site_visit_in' => ['Site visit', 'Lawatan tapak'],
        'site_visit_out' => ['Site visit out', 'Lawatan tapak (keluar)'],
```

Add the same two entries to the **separate** label map in
`resources/views/screens/attendance.blade.php:8-12`, which is the employee's own screen. Without
them the employee reads a raw `site_visit_in` string on their own record.

Style both new badges in the informational tone, not the red used for violations — follow whatever
mechanism the file already uses to tone a badge.

- [ ] **Step 4: Widen the map gates**

In `resources/views/screens/attendance-report.blade.php`, line 149 and line 158, widen each
`in_array('out_of_radius_in', …)` condition to also admit the site-visit flag, keeping the existing
null-coordinate guards exactly as they are:

```php
                        if ((in_array('out_of_radius_in', $recFlags, true) || in_array('site_visit_in', $recFlags, true))
                            && $r->latitude !== null && $r->longitude !== null) {
```

and the matching change for `out_of_radius_out` / `site_visit_out` with the clock-out coordinates.

- [ ] **Step 5: Fix the day partial's geofence sentence**

`resources/views/partials/attendance-day.blade.php:59` reads `$r->in_radius === false` directly, not
the flag, so it would still print "Clock landed outside the expected geofence" for a punch the app
just told the employee was fine. Add a site-visit branch **before** that check:

```php
    if ($r->work_mode === 'site_visit' || $r->clock_out_work_mode === 'site_visit') {
        // Keyed off which punch actually declared the visit, not a blind fallback: a late
        // clock-in carries its own remark, and `?:` would show that tardiness excuse as the
        // destination of a visit declared later at clock-out.
        $dest = $r->work_mode === 'site_visit' ? $r->clock_in_justification : $r->clock_out_justification;
        $whereEn = 'Site visit'.($dest ? ": {$dest}." : '.');
        $whereMs = 'Lawatan tapak'.($dest ? ": {$dest}." : '.');
    } elseif ($hasRadiusFlag || $r->in_radius === false || $r->out_radius === false) {
```

Do **not** add `site_visit_*` to `$redFlags` on line 8. A declared visit no longer carries an
`out_of_radius_*` flag, so the row already stops going red on its own, which is correct.

Two other `out_of_radius_*` readers are also correct as they stand, and must be left alone:

- `$offSiteThisMonth` in `app/Http/Controllers/Concerns/BuildsWorkData.php` counts off-site punches
  for the month. A declared site visit is not an off-site punch, so it correctly stops being
  counted. Do not add the new flags to that filter.
- `$hasRadiusFlag` in the day partial (line 59) keeps its existing meaning; the site-visit branch
  added above runs before it and takes precedence.

- [ ] **Step 6: Run the tests**

Run: `lerd artisan test --compact tests/Feature/AttendanceReportScreenTest.php tests/Feature/AttendanceReportLocationTest.php tests/Feature/AttendanceScreenTest.php`
Expected: PASS. `AttendanceReportLocationTest` is the guard that the map gate widened without
breaking the existing off-site case.

- [ ] **Step 7: Build and commit**

```bash
lerd artisan view:cache
bun run build
vendor/bin/pint --dirty --format agent
git add resources/views public/build tests/Feature/AttendanceReportScreenTest.php
git commit -m "feat(attendance): render declared site visits as visits, not violations

Both label maps gain the two new flags, the report map widens to plot a declared
visit, and the day partial stops calling it a geofence breach. in_radius stays
false on the record because it is true that they were outside the fence; the
sentence just stops treating that as the story."
```

---

## Task 8: Remove the home geofence from the domain

**Files:**
- Modify: `app/Attendance/ScheduleResolver.php:145-164`
- Modify: `app/Attendance/SiteSpec.php:23`
- Modify: `app/Attendance/ClockService.php:40-48`
- Modify: `resources/views/screens/attendance.blade.php:804`
- Test: `tests/Feature/ClockServiceTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks. Independent of the pill.
- Produces: `SiteSpec::__construct()` loses its ninth parameter `needsHomeCapture`. Task 9's tests must not pass it.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ClockServiceTest.php`:

```php
    public function test_a_home_day_is_never_fenced_and_never_captures_a_location(): void
    {
        $home = new SiteSpec('home', 'Work from home', null, null, 200, '09:00', '18:00', 8.0);
        $now = Carbon::parse('2026-07-02 08:55:00');

        // Nowhere near anything configured, and with no reason typed.
        $res = $this->service($home)->clockIn($this->employee, 5.42, 100.33, null, 'attendance-photos/a.jpg', $now);

        $this->assertSame('ok', $res['status']);
        $record = $this->employee->attendanceRecords()->onDate($now)->first();
        $this->assertNull($record->in_radius);
        $this->assertSame([], $record->flags ?? []);
        $this->assertSame('wfh', $record->type);
    }

    public function test_a_home_day_is_still_marked_late(): void
    {
        $home = new SiteSpec('home', 'Work from home', null, null, 200, '09:00', '18:00', 8.0);
        $now = Carbon::parse('2026-07-02 10:30:00');

        $res = $this->service($home)->clockIn($this->employee, 5.42, 100.33, 'Slept in', 'attendance-photos/a.jpg', $now);

        $this->assertSame('ok', $res['status']);
        $this->assertSame('late', $this->employee->attendanceRecords()->onDate($now)->first()->status);
    }
```

- [ ] **Step 2: Delete the two obsolete home tests**

Delete `test_first_home_clock_in_registers_and_locks_the_home_location` and
`test_a_home_day_at_the_office_does_not_register_the_office_as_home` from
`tests/Feature/ClockServiceTest.php`. Both assert the capture-and-lock behaviour the spec removes,
so they cannot be updated, only removed. This deletion is sanctioned by the spec's removal
inventory and is the only one permitted in this file.

- [ ] **Step 3: Run to verify the new tests fail**

Run: `lerd artisan test --compact --filter=home_day tests/Feature/ClockServiceTest.php`
Expected: FAIL — `SiteSpec` still takes `needsHomeCapture` and `homeSite()` still supplies coordinates.

- [ ] **Step 4: Drop needsHomeCapture from SiteSpec**

Delete the `public readonly bool $needsHomeCapture = false,` parameter and its comment from
`app/Attendance/SiteSpec.php`.

- [ ] **Step 5: Delete the capture-and-lock block in ClockService**

Delete the whole `if ($site === $assigned && $site->type === 'home' && $site->needsHomeCapture …)`
block from `clockIn()`, including the `$site = $this->resolver->resolve($employee, $now);` line
inside it.

- [ ] **Step 6: Strip the coordinates out of homeSite()**

Rewrite `homeSite()` in `app/Attendance/ScheduleResolver.php` as:

```php
    /**
     * A home day is timed but not fenced. The company rule is be on time and do the work,
     * so where the laptop physically sits is not something the company acts on, and
     * measuring it cost a stored home address for every remote worker in exchange for
     * proving only that they were in the right building.
     *
     * No coordinates means within() returns null, and out_of_radius_* is only written on an
     * explicit false — so a home punch can never be flagged off-site.
     */
    private function homeSite(Employee $employee): SiteSpec
    {
        // Company rule: every WFH day follows the single company-wide WFH hours set on the
        // Attendance Setup screen (tenant.wfh_*) — never the staff's own branch. Falls back
        // to the staff's branch only when the company hours haven't been set yet.
        $t = $employee->tenant;
        $b = $employee->branch;

        $minHours = $t?->wfh_min_hours ?? $b?->min_hours;

        return new SiteSpec(
            type: 'home',
            label: 'Work from home',
            latitude: null,
            longitude: null,
            radiusM: 0,
            workStart: $this->hhmm($t?->wfh_work_start) ?? $this->hhmm($b?->work_start),
            workEnd: $this->hhmm($t?->wfh_work_end) ?? $this->hhmm($b?->work_end),
            minHours: $minHours !== null ? (float) $minHours : null,
        );
    }
```

- [ ] **Step 7: Remove the capture hint from the attendance screen**

Delete the `@if ($site->needsHomeCapture) · <span …>home registers on this clock-in</span>@endif`
fragment at `resources/views/screens/attendance.blade.php:804`.

- [ ] **Step 8: Confirm nothing still references the removed member**

```bash
grep -rn "needsHomeCapture" app resources tests
```

Expected: no output.

- [ ] **Step 9: Run the tests**

Run: `lerd artisan test --compact tests/Feature/ClockServiceTest.php tests/Feature/AttendanceScreenTest.php`
Expected: PASS.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app resources/views/screens/attendance.blade.php tests/Feature/ClockServiceTest.php
git commit -m "feat(attendance): stop fencing the work-from-home day

homeSite() no longer carries coordinates, so within() returns null and a home
punch can never be flagged off-site. The first-punch capture that registered and
locked an employee's home address is deleted with it.

What survives: the selfie, the recorded GPS, the WFH hours and the late check. A
home day is still timed and still evidenced, it is simply not fenced."
```

---

## Task 9: Remove the home-address admin surface

**Files:**
- Modify: `app/Http/Controllers/AttendanceAdminController.php:93, :108-113, :138, :155-180`
- Modify: `routes/web.php:188`
- Modify: `app/Models/Employee.php:138-140`
- Modify: `resources/views/screens/attendance-admin.blade.php:28, :89-90, :205, :218-300, arrangements grid`
- Test: `tests/Feature/AttendanceAdminTest.php`

**Interfaces:**
- Consumes: Task 8. The domain must already ignore home coordinates before the screens that set them are removed.
- Produces: route `attendance.admin.home` no longer exists. Column drops in Task 10 become safe.

- [ ] **Step 1: Delete the six obsolete tests**

From `tests/Feature/AttendanceAdminTest.php`, delete:

- `test_hr_can_register_a_wfh_staff_home_address`
- `test_hr_can_register_a_hybrid_staff_home_address`
- `test_registering_a_home_for_an_office_staff_is_rejected`
- `test_coordinates_are_required_and_range_checked`
- `test_plain_employee_cannot_register_a_home_address`
- `test_hr_cannot_register_a_home_for_another_tenants_staff`

Every one of them posts to the endpoint being removed. Sanctioned by the spec's removal inventory.

- [ ] **Step 2: Update the four tests that merely mention home or the radius**

- `test_attendance_setup_screen_renders`: delete the
  `$this->assertStringContainsString('Registered home addresses', $html);` line, and add
  `$this->assertStringNotContainsString('Registered home addresses', $html);` in its place.
- `test_hr_can_set_the_company_wfh_policy`: remove `'wfh_radius_m' => 500,` from the payload and
  `$this->assertSame(500, $t->wfh_radius_m);` from the assertions.
- `test_saving_the_grace_alone_leaves_the_wfh_hours_untouched`: same two removals.
- `test_plain_employee_cannot_set_the_wfh_policy`: it posts `['wfh_radius_m' => 500]` as its payload
  and asserts the tenant is untouched. Swap both to `wfh_min_hours`:

```php
        $this->actingAs($this->employeeUser)
            ->post('/app/attendance-admin/wfh-policy', ['wfh_min_hours' => 6])
            ->assertForbidden();

        $this->assertNull($this->tenant->fresh()->wfh_min_hours);
```

- `test_wfh_follows_company_hours_not_the_staffs_own_branch`,
  `test_hybrid_home_days_also_follow_company_hours`,
  `test_wfh_falls_back_to_branch_hours_when_company_policy_blank`: remove
  `'home_latitude' => 3.0, 'home_longitude' => 101.0,` from the employee creation and
  `'wfh_radius_m' => 500` from any policy payload. Their real subject is the hours, which is
  untouched, so the rest of each test stands.

- [ ] **Step 3: Run to see what still fails**

Run: `lerd artisan test --compact tests/Feature/AttendanceAdminTest.php`
Expected: FAIL — the screen still renders "Registered home addresses".

- [ ] **Step 4: Strip the controller**

In `app/Http/Controllers/AttendanceAdminController.php`:

1. Delete the entire `updateHome()` method and its docblock.
2. Delete `'reset_home' => ['nullable', 'boolean'],` from the `updateEmployee()` validation rules.
3. Delete the `if (! empty($data['reset_home'])) { … }` block from `updateEmployee()`.
4. Delete `'wfh_radius_m' => ['nullable', 'integer', 'between:20,5000'],` from `updateWfhPolicy()`.

Remove any `use` import left unused by the deletions.

- [ ] **Step 5: Delete the route**

Remove line 188 of `routes/web.php`:

```php
        Route::post('/app/attendance-admin/staff/{employee}/home', [AttendanceAdminController::class, 'updateHome'])->name('attendance.admin.home');
```

- [ ] **Step 6: Delete the model casts**

Remove `'home_latitude'`, `'home_longitude'` and `'home_locked_at'` from the `casts()` array in
`app/Models/Employee.php`.

- [ ] **Step 7: Strip the Attendance Setup screen**

In `resources/views/screens/attendance-admin.blade.php`:

1. Delete the `$wfhPending = …` line (28) and the amber tab badge that renders it (89-90).
2. Delete the `Radius (m)` input from the Company WFH hours form (205).
3. Delete the whole "Registered home addresses" section — the heading, the empty state, the
   `@foreach ($wfhStaff …)` loop and its per-staff map pickers (roughly 218-300).
4. In the Staff arrangements grid, delete the "Home status" column: its header cell, the
   `reset_home` checkbox cell, and one column from the `$colArr` grid template.

If `$wfhStaff` has no remaining consumer after (3), delete its assignment too.

- [ ] **Step 8: Confirm nothing dangles**

```bash
grep -rn "home_latitude\|home_longitude\|home_locked_at\|wfh_radius_m\|reset_home\|updateHome\|attendance.admin.home\|wfhPending" app resources routes tests
```

Expected: no output.

- [ ] **Step 9: Run the tests**

Run: `lerd artisan test --compact tests/Feature/AttendanceAdminTest.php tests/Feature/AllScreensRenderTest.php`
Expected: PASS. `AllScreensRenderTest` catches a Blade variable left referenced after its
assignment was deleted, which a targeted test would miss.

- [ ] **Step 10: Build, verify, commit**

```bash
lerd artisan view:cache
bun run build
vendor/bin/pint --dirty --format agent
```

Open the Attendance Setup screen as HR and confirm: the WFH tab still saves company hours, minimum
hours and the late grace; there is no home section, no radius box, no pending badge; and the staff
arrangements grid still saves an arrangement and a hybrid weekday pattern.

```bash
git add app resources routes tests/Feature/AttendanceAdminTest.php public/build
git commit -m "feat(attendance): remove the home-address registration surface

HR's registration screen, the reset control, the company radius setting and the
endpoint behind them all go. The WFH tab keeps its reason to exist: company
hours, minimum hours and the late grace are untouched, and so is deciding who
works from home and on which weekdays.

The columns themselves are dropped separately, so this is still reversible."
```

---

## Task 10: Drop the home columns

**Files:**
- Create: `database/migrations/2026_08_19_000002_drop_home_geofence_columns.php`

**Interfaces:**
- Consumes: Tasks 8 and 9. Nothing may read or write these columns before this runs.
- Produces: the columns are gone. Irreversible.

**DESTRUCTIVE.** This deletes real staff home addresses. Do not run it against staging or production
without a `mysqldump` taken first — the standing rule in `docs/RULES.md`, and the only copy of these
coordinates that will exist afterwards. Production is devops-owned: they take the dump and they run
the migration. Local and test databases are disposable, so Step 2 is safe here.

- [ ] **Step 1: Create the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work-from-home is no longer fenced, so the stored home coordinates are no longer read by
 * anything. Keeping them would be the worst of both outcomes: unused, but still the most
 * sensitive location the app holds for every remote worker.
 *
 * down() recreates the columns but NOT the values. There is no working rollback for this
 * migration — the deploy that carries it takes a mysqldump first, and that dump is the only
 * copy of these coordinates that will exist afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['home_latitude', 'home_longitude', 'home_locked_at']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('wfh_radius_m');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('home_latitude', 10, 7)->nullable()->after('work_arrangement');
            $table->decimal('home_longitude', 10, 7)->nullable()->after('home_latitude');
            $table->timestamp('home_locked_at')->nullable()->after('home_longitude');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('wfh_radius_m')->nullable();
        });
    }
};
```

Check the `tenants` migration that added `wfh_radius_m` and match its exact column definition in
`down()` so a rollback restores the same shape.

- [ ] **Step 2: Run it locally**

Run: `lerd artisan migrate`
Expected: `DONE`.

- [ ] **Step 3: Prove a fresh install still builds**

Run: `lerd artisan migrate:fresh --seed`
Expected: every migration runs clean. This is what catches a seeder still setting `home_latitude`.
If `database/seeders/DatabaseSeeder.php` sets any of the dropped columns, remove those keys.

**Two warnings before running this.** `migrate:fresh` **wipes the dev database**, including the
seeded quick-login accounts that Task 11's browser walk depends on. And a plain re-seed on this
machine's dev database is known to fail on a duplicate super-admin. So either run this proof against
a throwaway database, or accept the wipe and restore the dev logins afterwards with:

```bash
lerd artisan db:seed --class=DevLoginSeeder
```

`DevLoginSeeder` is gitignored, so confirm it still exists in `database/seeders/` before wiping
anything. If it does not, do not run `migrate:fresh` at all — run `lerd artisan migrate` and inspect
the seeders by hand instead.

- [ ] **Step 4: Run the full suite**

Run: `lerd artisan test --compact`
Expected: PASS, everything.

- [ ] **Step 5: Commit**

```bash
git add database
git commit -m "feat(attendance): drop the stored home coordinates

Nothing reads them since the home geofence was removed, and keeping them means
holding every remote worker's home address for no purpose. down() recreates the
columns but never the values, so the deploy that carries this takes a mysqldump
first and that dump is the only copy that will exist."
```

---

## Task 11: Full verification before release

**Files:** none modified unless a failure is found.

- [ ] **Step 1: Static analysis**

Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: no new errors. CI only runs on pull requests into `main`, so a push straight to staging
would otherwise deploy unanalysed code.

- [ ] **Step 2: Full test suite**

Run: `lerd artisan test --compact`
Expected: PASS, everything.

- [ ] **Step 3: Formatting**

Run: `vendor/bin/pint --format agent`
Expected: no changes. If it reformats anything, commit that.

- [ ] **Step 4: Assets are current**

```bash
lerd artisan view:cache
bun run build
git status --short public/build
```

Expected: clean. If `public/build` changed, an earlier task forgot to rebuild — commit it now.

- [ ] **Step 5: Walk the flow in the browser**

At `http://localhost:9100/app/attendance`, as `employee@amanahku.test`:

1. Office / Home, on time, inside the fence: the sheet shows the camera only, punch succeeds.
2. Site visit with the destination blank: refused, the sheet asks where you are going.
3. Site visit with a destination: punch succeeds.
4. As `hr@amanahku.test`, open Attendance Reports and drill into that employee: the row reads
   "Site visit" with the destination, in blue, and the 📍 map opens on the punch point.
5. Attendance Setup, WFH tab: company hours save; no home section, no radius, no pending badge.

- [ ] **Step 6: Report the outcome**

State plainly what passed, what did not, and anything skipped. Do not report completion with a
failing step outstanding.
