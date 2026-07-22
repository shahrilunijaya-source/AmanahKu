# Timesheet Capture Revamp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the weekly matrix capture grid at `/app/timesheets` with day-first entry, and make approved leave, public holidays, draft compliance, and submit-recall behave correctly.

**Architecture:** A new read-only `App\Timesheet\LockedDays` service answers "which weekdays of this week does HR already own", from `public_holidays` and approved `leave_requests`. `TimesheetController::store()` keeps its existing delete-and-rewrite-the-week transaction and simply appends freshly computed locked rows on every save, so leave data can never drift. The Blade screen and its Alpine component are rewritten from a lines-by-days matrix to one editable day at a time with a week strip above it. No data migration; the `timesheet_entries` shape is unchanged apart from one nullable column.

**Tech Stack:** Laravel (PHP 8.5), Blade, Alpine.js, Vite, PHPUnit with `RefreshDatabase`, MySQL via lerd.

## Global Constraints

- Spec of record: `docs/superpowers/specs/2026-07-22-timesheet-capture-revamp-design.md`. Decisions are labelled D1 to D7 there and referenced by label below.
- Backfill window (D3): the current week plus the previous **three** weeks. Hardcoded constant, not configuration.
- Future days (D2) are not editable, enforced on the server as well as the client.
- Locked days (D4) override user rows in **drafts only**. A `submitted` timesheet is never rewritten.
- `On Leave` and `Public Holiday` are removed from the manual picker (D5) but stay in `timesheet_categories`.
- Tenant scoping is automatic via the `BelongsToTenant` trait. Never add manual `where('tenant_id', ...)` clauses.
- All new PHP files start with `declare(strict_types=1);` and match the existing docblock style in `app/Timesheet/TimesheetCompliance.php`.
- Tests are PHPUnit classes under `Tests\Feature` or `Tests\Unit` using `RefreshDatabase`. Run with `php artisan test`. Under lerd use `lerd artisan test`.
- Quality gates already in CI: `vendor/bin/pint` and `vendor/bin/phpstan analyse`. Both must pass before each commit.
- Bilingual UI copy: every user-facing string needs an `x-text="$store.ui.lang==='en' ? '…' : '…'"` pair, matching the existing screen.

---

### Task 1: `LockedDays` service

Computes which weekdays are already accounted for by an approved leave request or a public
holiday. Pure read, no writes, no knowledge of timesheets.

**Files:**
- Create: `app/Timesheet/LockedDays.php`
- Test: `tests/Unit/LockedDaysTest.php`

**Interfaces:**
- Consumes: `App\Models\Employee`, `App\Models\LeaveRequest`, `App\Models\PublicHoliday`, `App\Models\LeaveType`.
- Produces:
  - `LockedDays::forWeek(Employee $employee, CarbonInterface $weekStart): array` returning
    `array<string, array{label: string, source: string}>` keyed by ISO date, Mon to Fri only.
    `source` is `'holiday'` or `'leave'`.
  - `LockedDays::forWeekMany(Collection $employees, CarbonInterface $weekStart): array` returning
    the same per-day arrays keyed by employee id, computed in two queries for the whole
    collection rather than two per employee. Task 2's `roster()` depends on this.
  - `LockedDays::entryRows(Employee $employee, CarbonInterface $weekStart): array` returning rows
    ready for `TimesheetEntry::create()`, each with keys
    `entry_date, category_id, project_id, sub_pillar_id, percentage, description, project, hours, source`.
    Returns `[]` when the tenant has no matching category (fail open, per spec).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LockedDaysTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Tenancy\CurrentTenant;
use App\Timesheet\LockedDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit coverage for LockedDays. The week under test is Mon 2026-07-20 to Fri 2026-07-24.
 */
class LockedDaysTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private LockedDays $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->svc = app(LockedDays::class);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    public function test_a_public_holiday_locks_that_weekday(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);

        $locked = $this->svc->forWeek($this->employee, '2026-07-20');

        $this->assertSame(['2026-07-22'], array_keys($locked));
        $this->assertSame('holiday', $locked['2026-07-22']['source']);
        $this->assertSame('Awal Muharram', $locked['2026-07-22']['label']);
    }

    public function test_approved_leave_locks_every_weekday_it_covers(): void
    {
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-07-21', 'date_to' => '2026-07-22',
            'days' => 2, 'status' => 'approved',
        ]);

        $locked = $this->svc->forWeek($this->employee, '2026-07-20');

        $this->assertSame(['2026-07-21', '2026-07-22'], array_keys($locked));
        $this->assertSame('leave', $locked['2026-07-21']['source']);
        $this->assertSame('Annual', $locked['2026-07-21']['label']);
    }

    public function test_unapproved_leave_locks_nothing(): void
    {
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-07-21', 'date_to' => '2026-07-21',
            'days' => 1, 'status' => 'submitted',
        ]);

        $this->assertSame([], $this->svc->forWeek($this->employee, '2026-07-20'));
    }

    public function test_a_public_holiday_outranks_leave_on_the_same_day(): void
    {
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-07-22', 'date_to' => '2026-07-22',
            'days' => 1, 'status' => 'approved',
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);

        $locked = $this->svc->forWeek($this->employee, '2026-07-20');

        $this->assertSame('holiday', $locked['2026-07-22']['source']);
    }

    public function test_weekend_days_are_never_locked(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Sunday Feast', 'date' => '2026-07-26']);

        $this->assertSame([], $this->svc->forWeek($this->employee, '2026-07-20'));
    }

    public function test_entry_rows_carry_the_matching_category_and_full_percentage(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false,
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);

        $rows = $this->svc->entryRows($this->employee, '2026-07-20');

        $this->assertCount(1, $rows);
        $this->assertSame('2026-07-22', $rows[0]['entry_date']);
        $this->assertSame(100.0, $rows[0]['percentage']);
        $this->assertSame('holiday', $rows[0]['source']);
        $this->assertSame(8.0, $rows[0]['hours']);
    }

    public function test_entry_rows_are_empty_when_the_tenant_deleted_the_category(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);

        $this->assertSame([], $this->svc->entryRows($this->employee, '2026-07-20'));
    }

    public function test_for_week_many_keys_locked_days_by_employee(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-07-20', 'date_to' => '2026-07-20',
            'days' => 1, 'status' => 'approved',
        ]);

        $many = $this->svc->forWeekMany(collect([$this->employee, $other]), '2026-07-20');

        // The employee on leave has both the leave Monday and the shared holiday Wednesday.
        $this->assertSame(['2026-07-20', '2026-07-22'], array_keys($many[$this->employee->id]));
        // The other employee shares only the holiday.
        $this->assertSame(['2026-07-22'], array_keys($many[$other->id]));
    }

    public function test_for_week_many_matches_for_week_per_employee(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-07-22']);

        $many = $this->svc->forWeekMany(collect([$this->employee]), '2026-07-20');

        $this->assertEquals($this->svc->forWeek($this->employee, '2026-07-20'), $many[$this->employee->id]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `lerd artisan test --filter=LockedDaysTest`
Expected: FAIL with `Class "App\Timesheet\LockedDays" does not exist`.

- [ ] **Step 3: Write the implementation**

Create `app/Timesheet/LockedDays.php`:

```php
<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\TimesheetCategory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which weekdays of a timesheet week are already accounted for by a fact HR owns:
 * an approved leave request, or a public holiday.
 *
 * A locked day is filled to 100% and the employee cannot log work against it. Read-only:
 * this class never writes. Callers persist the rows it returns.
 *
 * Half-day leave is deliberately not handled: LeaveController computes
 * `days = date_from->diffInDays(date_to) + 1`, always a whole number, so no fractional
 * leave day can exist today. Revisit this class if half-days are ever introduced.
 */
final class LockedDays
{
    /** Category names the generated rows are filed under, by source. */
    private const CATEGORY_NAME = ['holiday' => 'Public Holiday', 'leave' => 'On Leave'];

    /**
     * @return array<string, array{label: string, source: string}> keyed by ISO date, Mon–Fri only
     */
    public function forWeek(Employee $employee, CarbonInterface $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $end = $start->addDays(4);

        $holidays = PublicHoliday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (PublicHoliday $h) => $h->date->toDateString());

        $leave = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $end->toDateString())
            ->whereDate('date_to', '>=', $start->toDateString())
            ->get();

        $locked = [];

        for ($i = 0; $i < 5; $i++) {
            $day = $start->addDays($i);
            $iso = $day->toDateString();

            if ($holiday = $holidays->get($iso)) {
                // A holiday outranks leave: nobody burns annual leave on a public holiday.
                $locked[$iso] = ['label' => $holiday->name, 'source' => 'holiday'];

                continue;
            }

            $covering = $leave->first(
                fn (LeaveRequest $r) => $day->betweenIncluded($r->date_from, $r->date_to)
            );

            if ($covering) {
                $locked[$iso] = ['label' => $covering->leaveType?->name ?: 'Leave', 'source' => 'leave'];
            }
        }

        return $locked;
    }

    /**
     * forWeek for a whole roster in two queries instead of two per employee.
     *
     * roster() renders the entire team, so calling forWeek() in a loop would be an N+1: the
     * holiday query is identical for every employee, and the leave query can be a single
     * whereIn. This returns the same per-day arrays forWeek() does, keyed by employee id.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $employees
     * @return array<int, array<string, array{label: string, source: string}>>
     */
    public function forWeekMany(Collection $employees, CarbonInterface $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $end = $start->addDays(4);

        $holidays = PublicHoliday::whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (PublicHoliday $h) => $h->date->toDateString());

        $leaveByEmployee = LeaveRequest::with('leaveType')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $end->toDateString())
            ->whereDate('date_to', '>=', $start->toDateString())
            ->get()
            ->groupBy('employee_id');

        $out = [];

        foreach ($employees as $employee) {
            $leave = $leaveByEmployee->get($employee->id) ?? collect();
            $locked = [];

            for ($i = 0; $i < 5; $i++) {
                $day = $start->addDays($i);
                $iso = $day->toDateString();

                if ($holiday = $holidays->get($iso)) {
                    $locked[$iso] = ['label' => $holiday->name, 'source' => 'holiday'];

                    continue;
                }

                $covering = $leave->first(
                    fn (LeaveRequest $r) => $day->betweenIncluded($r->date_from, $r->date_to)
                );

                if ($covering) {
                    $locked[$iso] = ['label' => $covering->leaveType?->name ?: 'Leave', 'source' => 'leave'];
                }
            }

            $out[$employee->id] = $locked;
        }

        return $out;
    }

    /**
     * The same locked days shaped as timesheet_entries rows, ready to persist.
     *
     * Categories are matched by name because timesheet_categories has no stable key beyond
     * unique(tenant_id, name). A tenant that renamed or deleted the category gets no rows,
     * which is the intended fail-open: the day simply behaves as a normal working day.
     *
     * @return array<int, array<string, mixed>>
     */
    public function entryRows(Employee $employee, CarbonInterface $weekStart): array
    {
        $locked = $this->forWeek($employee, $weekStart);

        if ($locked === []) {
            return [];
        }

        $categories = TimesheetCategory::whereIn('name', array_values(self::CATEGORY_NAME))
            ->get()
            ->keyBy('name');

        $hoursPerDay = (float) config('manday.hours_per_day', 8);

        $rows = [];

        foreach ($locked as $iso => $day) {
            $category = $categories->get(self::CATEGORY_NAME[$day['source']]);

            if (! $category) {
                continue;
            }

            $rows[] = [
                'entry_date' => $iso,
                'category_id' => $category->id,
                'project_id' => null,
                'sub_pillar_id' => null,
                'percentage' => 100.0,
                'description' => null,
                // Legacy readable fallback for any code still reading the string column.
                'project' => $category->name.' — '.$day['label'],
                'hours' => round($hoursPerDay, 2),
                'source' => $day['source'],
            ];
        }

        return $rows;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `lerd artisan test --filter=LockedDaysTest`
Expected: PASS, 9 tests.

- [ ] **Step 5: Run the quality gates**

Run: `vendor/bin/pint app/Timesheet/LockedDays.php tests/Unit/LockedDaysTest.php && vendor/bin/phpstan analyse`
Expected: both clean.

- [ ] **Step 6: Commit**

```bash
git add app/Timesheet/LockedDays.php tests/Unit/LockedDaysTest.php
git commit -m "feat(timesheet): add LockedDays for approved leave and public holidays

Computes which weekdays HR already owns, and shapes them as entry rows.
Holidays outrank leave. Fails open when a tenant has deleted the category."
```

---

### Task 2: Compliance stops counting drafts, and skips fully locked weeks

Implements D6, plus the "whole week on leave" exclusion the spec calls for so somebody on
holiday is not flagged overdue for a week they were never expected to fill.

**Files:**
- Modify: `app/Timesheet/TimesheetCompliance.php`
- Modify: `tests/Unit/TimesheetComplianceTest.php`

**Interfaces:**
- Consumes: `LockedDays::forWeek()` from Task 1.
- Produces: no signature changes. `isComplete()`, `isLate()`, `roster()` and `pending()` keep
  their current signatures and change behaviour only.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/TimesheetComplianceTest.php`. Note the existing `sheet()` helper in that
file creates the timesheet; check its signature before writing and pass `status` the way it
already does.

```php
    public function test_a_full_but_unsubmitted_draft_is_not_complete(): void
    {
        Carbon::setTestNow('2026-06-24 09:00:00');
        $emp = $this->employee();
        $sheet = $this->sheet($emp, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        $sheet->update(['status' => 'draft']);

        $this->assertFalse($this->svc->isComplete($emp, '2026-06-22'));
    }

    public function test_the_same_week_submitted_is_complete(): void
    {
        Carbon::setTestNow('2026-06-24 09:00:00');
        $emp = $this->employee();
        $sheet = $this->sheet($emp, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        $sheet->update(['status' => 'submitted']);

        $this->assertTrue($this->svc->isComplete($emp, '2026-06-22'));
    }

    public function test_an_employee_whose_whole_week_is_public_holidays_is_never_late(): void
    {
        Carbon::setTestNow('2026-06-26 17:30:00'); // Friday, past the deadline
        $emp = $this->employee();

        foreach (['2026-06-22', '2026-06-23', '2026-06-24', '2026-06-25', '2026-06-26'] as $d) {
            PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Shutdown', 'date' => $d]);
        }

        $this->assertFalse($this->svc->isLate($emp, '2026-06-22'));
        $this->assertSame(0, $this->svc->roster($this->tenant, '2026-06-22')->count());
    }
```

Add `use App\Models\PublicHoliday;` to that file's imports.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `lerd artisan test --filter=TimesheetComplianceTest`
Expected: `test_a_full_but_unsubmitted_draft_is_not_complete` FAILS (returns true today), and
`test_an_employee_whose_whole_week_is_public_holidays_is_never_late` FAILS. The submitted case
already passes, which is expected.

- [ ] **Step 3: Add the status check to `isComplete()`**

In `app/Timesheet/TimesheetCompliance.php`, replace the body of `isComplete()`:

```php
    /**
     * True when the week is finalised AND every weekday Mon–Fri sums to 100% (±0.01).
     *
     * A draft does not count however full it is (D6): before this check, a staffer could
     * fill a draft, never submit it, and still read as DONE on the roster while keeping the
     * sheet editable — which rewarded not submitting.
     */
    public function isComplete(Employee $employee, CarbonInterface $weekStart): bool
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();

        $sheet = Timesheet::with('entries')
            ->where('employee_id', $employee->id)
            ->forWeek($start)
            ->first();

        return $sheet !== null
            && $this->isFinalised($sheet)
            && $this->weekdaysComplete($sheet->entries, $start);
    }

    /** A sheet counts towards compliance only once the staffer has finalised it. */
    private function isFinalised(Timesheet $sheet): bool
    {
        return in_array($sheet->status, ['submitted', 'approved'], true);
    }
```

- [ ] **Step 4: Apply the same rule to `isLate()` and `roster()`**

In `isLate()`, replace the `$complete` assignment:

```php
        $complete = $sheetLoaded
            ? $sheet !== null && $this->isFinalised($sheet) && $this->weekdaysComplete($sheet->entries, $start)
            : $this->isComplete($employee, $start);
```

In `roster()`, replace the `$complete` line inside the `map()`:

```php
                $complete = $sheet !== null
                    && $this->isFinalised($sheet)
                    && $this->weekdaysComplete($sheet->entries, $start);
```

- [ ] **Step 5: Exclude fully locked weeks in `isEligible()`**

Add the constructor and extend `isEligible()`:

```php
    public function __construct(private readonly LockedDays $lockedDays) {}
```

```php
    private function isEligible(Employee $employee, CarbonImmutable $weekStart): bool
    {
        if ($employee->status !== 'active') {
            return false;
        }

        if ($employee->joined_at !== null && $employee->joined_at->greaterThan($weekStart)) {
            return false;
        }

        // Nobody is expected to file a timesheet for a week they were never at work for.
        return count($this->lockedDays->forWeek($employee, $weekStart)) < 5;
    }
```

Add `use App\Timesheet\LockedDays;` — same namespace, so no import needed; delete this note if
your editor adds one.

`roster()` filters employees in SQL and does not currently call `isEligible()`. It renders the
whole team, so use the batched `forWeekMany()` (one pair of queries for the roster) rather than
`forWeek()` in a loop (a pair per employee). Add, after the `$employees` query is fetched:

```php
        $lockedByEmployee = $this->lockedDays->forWeekMany($employees, $start);
        $employees = $employees->reject(
            fn (Employee $e) => count($lockedByEmployee[$e->id] ?? []) >= 5
        )->values();
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `lerd artisan test --filter=TimesheetComplianceTest`
Expected: PASS, all tests including the three new ones.

- [ ] **Step 7: Run the neighbouring suites**

Run: `lerd artisan test --filter='Timesheet'`
Expected: `TimesheetReminderTest` may now fail where it seeded a full draft and expected no
reminder. If so, update those fixtures to `status => 'submitted'`, since the intent of those
tests is "a complete week gets no reminder", and after D6 completeness requires submission.
Do not weaken the new rule to keep an old fixture passing.

- [ ] **Step 8: Commit**

```bash
git add app/Timesheet/TimesheetCompliance.php tests/Unit/TimesheetComplianceTest.php tests/Feature/TimesheetReminderTest.php
git commit -m "fix(timesheet): a draft no longer counts as compliant

isComplete/isLate/roster now require submitted or approved. A week that is
entirely public holiday or approved leave drops out of the roster entirely,
so nobody is chased for a week they were never at work for."
```

---

### Task 3: Persist locked rows on save, and override draft rows

Implements D4 and adds the `source` column. `store()` already deletes and rewrites the whole
week inside a transaction, so this is an append plus a skip inside machinery that exists.

**Files:**
- Create: `database/migrations/2026_07_22_000001_add_source_to_timesheet_entries.php`
- Modify: `app/Http/Controllers/TimesheetController.php`
- Modify: `tests/Feature/TimesheetTest.php`

**Interfaces:**
- Consumes: `LockedDays::forWeek()` and `LockedDays::entryRows()` from Task 1.
- Produces: `TimesheetEntry.source` is `'leave'`, `'holiday'`, or `null`. `store()` returns
  `response()->json(['ok' => true, 'status' => string, 'locked' => array<string, array>])`
  when `$request->expectsJson()`, otherwise the existing redirect. Task 7's autosave depends
  on that JSON shape.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TimesheetTest.php`, and add
`use App\Models\LeaveRequest; use App\Models\LeaveType; use App\Models\PublicHoliday;` to its
imports:

```php
    public function test_a_public_holiday_is_persisted_as_a_locked_entry(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false,
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertRedirect();

        $locked = TimesheetEntry::where('entry_date', '2026-06-17')->first();
        $this->assertNotNull($locked);
        $this->assertSame('holiday', $locked->source);
        $this->assertSame('100.00', (string) $locked->percentage);
    }

    public function test_approved_leave_replaces_work_rows_on_that_day_in_a_draft(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false,
        ]);
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-06-17', 'date_to' => '2026-06-17',
            'days' => 1, 'status' => 'approved',
        ]);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-17', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertRedirect();

        $rows = TimesheetEntry::where('entry_date', '2026-06-17')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('leave', $rows[0]->source);
    }

    public function test_a_draft_may_be_saved_with_no_user_rows(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false,
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, TimesheetEntry::count());
    }

    public function test_the_json_response_carries_the_locked_days(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false,
        ]);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $this->actingInTenant()->postJson('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertOk()->assertJsonPath('locked.2026-06-17.source', 'holiday');
    }

    public function test_a_submitted_week_is_never_rewritten_by_later_leave_approval(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false,
        ]);
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $sheet->id, 'entry_date' => '2026-06-17',
            'category_id' => $this->category->id, 'percentage' => 100, 'project' => 'Others', 'hours' => 8,
        ]);

        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-06-17', 'date_to' => '2026-06-17',
            'days' => 1, 'status' => 'approved',
        ]);

        // The week is already finalised, so the save is refused outright rather than merged.
        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertStatus(422);

        $rows = TimesheetEntry::where('entry_date', '2026-06-17')->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->source);
    }

    public function test_cancelling_approved_leave_clears_the_locked_row_on_the_next_save(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false,
        ]);
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual']);
        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-06-17', 'date_to' => '2026-06-17',
            'days' => 1, 'status' => 'approved',
        ]);

        $payload = [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ];

        $this->actingInTenant()->post('/app/timesheets', $payload)->assertRedirect();
        $this->assertSame(1, TimesheetEntry::where('source', 'leave')->count());

        $leave->update(['status' => 'rejected']);

        $this->actingInTenant()->post('/app/timesheets', $payload)->assertRedirect();
        $this->assertSame(0, TimesheetEntry::where('source', 'leave')->count());
    }
```

Delete the existing `test_validation_rejects_a_timesheet_with_no_entries` test. It asserts the
opposite of the new rule and is replaced by `test_a_draft_may_be_saved_with_no_user_rows`.
Add `use App\Models\TimesheetEntry;` if it is not already imported.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `lerd artisan test --filter=TimesheetTest`
Expected: the four new tests FAIL. The first two fail on the missing `source` column, the
third on `entries` being `required|array|min:1`, the fourth on a redirect instead of JSON.

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_07_22_000001_add_source_to_timesheet_entries.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            // Null = the staffer typed this row. 'leave' / 'holiday' = generated from an
            // approved leave request or a public holiday, and regenerated on every save.
            $table->string('source', 16)->nullable()->after('percentage');
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
```

`percentage` is a real column, added by
`2026_06_24_120004_extend_timesheet_entries_for_allocation.php`, so the `->after()` is valid.

- [ ] **Step 4: Run the migration**

Run: `lerd artisan migrate`
Expected: `2026_07_22_000001_add_source_to_timesheet_entries ... DONE`.

- [ ] **Step 5: Relax the entries rule and inject the service**

In `app/Http/Controllers/TimesheetController.php`, add `use App\Timesheet\LockedDays;` to the
imports, then in `store()` change the validation line:

```php
            'entries' => ['present', 'array'],
```

and immediately after `$data = $request->validate([...]);` add:

```php
        $lockedDays = app(LockedDays::class);
        $locked = $lockedDays->forWeek($employee, Carbon::parse($data['week_start'])->startOfDay());
```

- [ ] **Step 6: Drop user rows on locked days, then append the generated ones**

Still in `store()`, replace the `$entries = $this->normaliseEntries($data['entries']);` line:

```php
        // D4: an approved leave day or public holiday is a fact HR owns. Anything the staffer
        // typed against that day is wrong by definition, so it is dropped rather than merged.
        $userEntries = array_values(array_filter(
            $data['entries'],
            fn (array $e) => ! isset($locked[Carbon::parse($e['entry_date'])->toDateString()])
        ));

        $entries = $this->normaliseEntries($userEntries);
        $entries = array_merge($entries, $lockedDays->entryRows($employee, $data['week_start']));
```

The `assertDayTotals($entries)` call that follows now sees the locked rows too, which is
correct: a locked day already totals 100.

- [ ] **Step 7: Return JSON when the caller asks for it**

Replace the two `return back()->with('ok', ...)` statements at the end of `store()`:

```php
        $message = $submitNow
            ? 'Timesheet submitted for approval.'
            : 'Draft saved — '.count($entries).' '.(count($entries) === 1 ? 'entry' : 'entries').'.';

        if ($submitNow) {
            AuditLog::record('Submitted timesheet', ($timesheet->week_label ?: $timesheet->week_start->toDateString()).' · '.count($entries).' entries');
        }

        // The day-first screen autosaves over fetch(); the plain form POST still redirects.
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'status' => $timesheet->status,
                'locked' => $locked,
            ]);
        }

        return back()->with('ok', $message);
```

Change the method's return type to `RedirectResponse|JsonResponse` and add
`use Illuminate\Http\JsonResponse;`.

- [ ] **Step 8: Allow `source` through mass assignment**

`TimesheetEntry` uses `protected $guarded = []`, so no change is needed. Confirm by reading
`app/Models/TimesheetEntry.php`; if it declares `$fillable` instead, add `'source'` to it.

- [ ] **Step 9: Run the tests to verify they pass**

Run: `lerd artisan test --filter=TimesheetTest`
Expected: PASS, including the four new tests.

- [ ] **Step 10: Run the quality gates and commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse
git add database/migrations/2026_07_22_000001_add_source_to_timesheet_entries.php app/Http/Controllers/TimesheetController.php tests/Feature/TimesheetTest.php
git commit -m "feat(timesheet): approved leave and holidays override draft days

Locked rows are regenerated from live leave data on every save, so a
cancelled leave day clears itself with no sync job. Drafts may now save with
no user rows, which a fully-locked week needs. store() answers JSON for the
upcoming autosave."
```

---

### Task 4: Reject future and stale entry dates

Implements D2 and D3 on the server. This task breaks existing tests on purpose; Step 4 fixes
them.

**Files:**
- Modify: `app/Http/Controllers/TimesheetController.php`
- Modify: `tests/Feature/TimesheetTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `TimesheetController::BACKFILL_WEEKS = 3`, referenced by Task 6 when it computes
  the earliest selectable week for the UI.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TimesheetTest.php`:

```php
    public function test_an_entry_dated_after_today_is_rejected(): void
    {
        Carbon::setTestNow('2026-06-17 09:00:00'); // Wednesday of that week

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-19', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertSessionHasErrors('entries.0.entry_date');

        Carbon::setTestNow();
    }

    public function test_an_entry_older_than_the_backfill_window_is_rejected(): void
    {
        Carbon::setTestNow('2026-07-22 09:00:00'); // five weeks after the target week

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertSessionHasErrors('entries.0.entry_date');

        Carbon::setTestNow();
    }

    public function test_an_entry_inside_the_backfill_window_is_accepted(): void
    {
        Carbon::setTestNow('2026-07-01 09:00:00'); // two weeks after the target week

        $this->actingInTenant()->post('/app/timesheets', [
            'week_start' => '2026-06-15',
            'entries' => [
                ['entry_date' => '2026-06-15', 'category_id' => $this->category->id, 'percentage' => 100],
            ],
        ])->assertSessionHasNoErrors();

        Carbon::setTestNow();
    }
```

Add `use Illuminate\Support\Carbon;` to the test file if it is not already imported.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `lerd artisan test --filter=TimesheetTest`
Expected: the first two new tests FAIL, because no date bound exists yet.

- [ ] **Step 3: Add the window check**

In `app/Http/Controllers/TimesheetController.php`, add the constant next to `MONEY_ROLES`:

```php
    /**
     * How far back a staffer may still edit. The current week plus this many earlier weeks.
     *
     * Blocking past days outright is not an option: a forgotten Monday could never reach
     * 100%, so the week could never be submitted. An unbounded window is not either, because
     * it lets somebody backfill months the night before an audit.
     */
    private const BACKFILL_WEEKS = 3;
```

In `store()`, right after the `$userEntries` filter from Task 3, add:

```php
        $this->assertDatesInWindow($userEntries);
```

and add the method next to `assertDayTotals()`:

```php
    /**
     * Entry dates must be today or earlier (D2 — you cannot have spent time you have not
     * spent), and no earlier than the backfill window (D3). Generated leave and holiday rows
     * bypass this: they are approved facts, not claims, and may legitimately sit in the future.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function assertDatesInWindow(array $entries): void
    {
        $today = Carbon::now()->startOfDay();
        $earliest = Carbon::now()->startOfWeek()->subWeeks(self::BACKFILL_WEEKS);

        foreach ($entries as $i => $e) {
            $date = Carbon::parse($e['entry_date'])->startOfDay();

            if ($date->greaterThan($today)) {
                throw ValidationException::withMessages([
                    "entries.$i.entry_date" => $date->format('D, j M').' has not happened yet.',
                ]);
            }

            if ($date->lessThan($earliest)) {
                throw ValidationException::withMessages([
                    "entries.$i.entry_date" => $date->format('D, j M').' is too far back to edit. Ask HR to reopen it.',
                ]);
            }
        }
    }
```

- [ ] **Step 4: Pin time in the existing tests**

The tests written before this plan post `week_start => '2026-06-15'` against the real clock, so
they now fall outside the window and fail. Pin the clock in `tests/Feature/TimesheetTest.php`
by adding to `setUp()`, after `parent::setUp()`:

```php
        // The suite's fixtures all sit in the week of Mon 2026-06-15. Pin "now" to that
        // week's Friday so those dates are in the past and inside the backfill window.
        Carbon::setTestNow('2026-06-19 12:00:00');
```

and add a `tearDown()`:

```php
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
```

The three tests from Step 1 set their own `setTestNow` and reset it, which overrides this
safely.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `lerd artisan test --filter=TimesheetTest`
Expected: PASS, whole class.

- [ ] **Step 6: Run the full suite**

Run: `lerd artisan test`
Expected: PASS. `TimesheetCostTest` writes rows through the model rather than the controller,
so it is unaffected; if it fails, the cause is elsewhere and must not be papered over by
loosening the window.

- [ ] **Step 7: Run the quality gates and commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse
git add app/Http/Controllers/TimesheetController.php tests/Feature/TimesheetTest.php
git commit -m "feat(timesheet): reject future-dated and stale entries

Entry dates must be today or earlier and within the current week plus three.
Generated leave and holiday rows bypass the check, being approved facts."
```

---

### Task 5: Recall a submitted week back to draft

Implements D7. Submit is currently a one-way door with no key, not even for HR.

**Files:**
- Modify: `routes/web.php:344` (next to `timesheets.submit`)
- Modify: `app/Http/Controllers/TimesheetController.php`
- Modify: `tests/Feature/TimesheetTest.php`

**Interfaces:**
- Consumes: `TimesheetController::authorizeOwner()`, the private helper `submit()` already uses.
- Produces: named route `timesheets.recall`, `POST /app/timesheets/{timesheet}/recall`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TimesheetTest.php`:

```php
    public function test_the_owner_can_recall_a_submitted_week(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
            'submitted_at' => now(),
        ]);

        $this->actingInTenant()->post("/app/timesheets/{$sheet->id}/recall")->assertRedirect();

        $sheet->refresh();
        $this->assertSame('draft', $sheet->status);
        $this->assertNull($sheet->submitted_at);
    }

    public function test_recalling_a_draft_is_refused(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 0,
        ]);

        $this->actingInTenant()->post("/app/timesheets/{$sheet->id}/recall")->assertStatus(422);
    }

    public function test_a_non_owner_cannot_recall_someone_elses_week(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Someone Else',
            'status' => 'active', 'workload' => 'green',
        ]);
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $other->id,
            'week_start' => '2026-06-15', 'status' => 'submitted', 'total_hours' => 8,
            'submitted_at' => now(),
        ]);

        $this->actingInTenant()->post("/app/timesheets/{$sheet->id}/recall")->assertForbidden();

        $this->assertSame('submitted', $sheet->refresh()->status);
    }
```

`authorizeOwner()` decides the status code. Read it before writing this test and match the
assertion to what it actually throws; if it aborts 404 rather than 403, use `assertNotFound()`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `lerd artisan test --filter=TimesheetTest`
Expected: FAIL with a 404, since the route does not exist.

- [ ] **Step 3: Add the route**

In `routes/web.php`, directly below the `timesheets.submit` line:

```php
        Route::post('/app/timesheets/{timesheet}/recall', [TimesheetController::class, 'recall'])->name('timesheets.recall');
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/TimesheetController.php`, directly below `submit()`:

```php
    /**
     * Put a submitted week back into draft so its owner can fix it.
     *
     * Nothing approves timesheets today, so there is no decision to invalidate. This exists
     * because submit was otherwise irreversible: a typo could only be undone in the database.
     */
    public function recall(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $this->authorizeOwner($request, $timesheet);
        abort_unless($timesheet->status === 'submitted', 422, 'Only a submitted week can be recalled.');

        $timesheet->update(['status' => 'draft', 'submitted_at' => null]);
        AuditLog::record('Recalled timesheet', $timesheet->week_label ?: $timesheet->week_start->toDateString());

        return back()->with('ok', 'Week reopened. Fix it and submit again.');
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `lerd artisan test --filter=TimesheetTest`
Expected: PASS.

- [ ] **Step 6: Run the quality gates and commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse
git add routes/web.php app/Http/Controllers/TimesheetController.php tests/Feature/TimesheetTest.php
git commit -m "feat(timesheet): let the owner recall a submitted week

Submit was a one-way door with no key, not even for HR. Recall returns the
sheet to draft and writes an audit line."
```

---

### Task 6: Screen data for the day-first UI

Feeds the new component: locked days, the flat work-item list, the editable window, and today.
Also drops `On Leave` and `Public Holiday` from the manual picker (D5).

**Files:**
- Modify: `app/Http/Controllers/TimesheetController.php` (`screenData()`)
- Test: `tests/Feature/TimesheetScreenDataTest.php`

**Interfaces:**
- Consumes: `LockedDays::forWeek()` (Task 1), `TimesheetController::BACKFILL_WEEKS` (Task 4).
- Produces, added to the array `screenData()` returns:
  - `tsLocked`: `array<string, array{label: string, source: string}>`
  - `tsItems`: `array<int, array{key: string, category_id: int, project_id: ?int, sub_pillar_id: ?int, label: string}>`
  - `tsToday`: ISO date string
  - `tsEarliestWeek`: ISO date string, the Monday of the earliest editable week
  - `tsCategories` keeps its shape but excludes the two generated categories.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TimesheetScreenDataTest.php` using the same harness as
`tests/Feature/TimesheetTest.php` (copy `setUp`, `actingInTenant`, and the `Carbon::setTestNow`
pin from Task 4 Step 4):

```php
    public function test_the_picker_excludes_the_generated_categories(): void
    {
        TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'On Leave', 'requires_project' => false]);
        TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Public Holiday', 'requires_project' => false]);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $response->assertOk();
        $names = collect($response->viewData('tsCategories'))->pluck('name');
        $this->assertFalse($names->contains('On Leave'));
        $this->assertFalse($names->contains('Public Holiday'));
        $this->assertTrue($names->contains('Others'));
    }

    public function test_locked_days_reach_the_view(): void
    {
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Awal Muharram', 'date' => '2026-06-17']);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $this->assertSame('holiday', $response->viewData('tsLocked')['2026-06-17']['source']);
    }

    public function test_recent_combinations_become_work_items(): void
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'week_start' => '2026-06-08', 'status' => 'submitted', 'total_hours' => 8,
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $sheet->id, 'entry_date' => '2026-06-08',
            'category_id' => $this->category->id, 'percentage' => 100, 'project' => 'Others', 'hours' => 8,
        ]);

        $response = $this->actingInTenant()->get('/app/timesheets?week=2026-06-15');

        $labels = collect($response->viewData('tsItems'))->pluck('label');
        $this->assertTrue($labels->contains('Others'));
    }
```

`viewData()` works here: `AppController::screen()` returns a `ViewContract`, so the response
carries its view data. `pluck('name')` is correct even though `categoryOptions()` yields plain
arrays, since Collection::pluck reads array keys as well as object properties.

- [ ] **Step 2: Run the test to verify it fails**

Run: `lerd artisan test --filter=TimesheetScreenDataTest`
Expected: FAIL, `tsLocked` and `tsItems` undefined.

- [ ] **Step 3: Extend `screenData()`**

In `app/Http/Controllers/TimesheetController.php`, inside `screenData()`, after `$weekStart` is
computed:

```php
        $lockedDays = app(LockedDays::class);
        $locked = $employee ? $lockedDays->forWeek($employee, $weekStart) : [];
```

Filter the categories fed to the picker. `screenData()` builds them at
`'tsCategories' => $this->categoryOptions()`, and `categoryOptions()` maps models to plain
arrays, so the filter belongs inside that method and keys off `$c['name']`, not `$c->name`.
Replace the method body:

```php
    /**
     * Categories offered in the capture picker.
     *
     * On Leave and Public Holiday are excluded (D5): those rows are generated from approved
     * leave requests and the holiday calendar, so offering them by hand would let somebody
     * log leave HR never approved straight into the manday cost report. The categories
     * themselves stay in the table, because LockedDays files its generated rows under them.
     */
    private function categoryOptions(): Collection
    {
        return TimesheetCategory::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (TimesheetCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'name_ms' => $c->name_ms ?: $c->name,
                'requires_project' => (bool) $c->requires_project,
            ])
            ->reject(fn (array $c) => in_array($c['name'], ['On Leave', 'Public Holiday'], true))
            ->values();
    }
```

Build the flat work-item list:

```php
        // The picker offers ready-made "Category · Project · Sub-pillar" combinations rather
        // than three sequential pill choices. Recent first, then saved templates.
        $tsItems = [];

        if ($employee) {
            $recent = TimesheetEntry::with(['category', 'projectRef', 'subPillar'])
                ->whereHas('timesheet', fn ($q) => $q->where('employee_id', $employee->id))
                ->whereNull('source')
                ->where('entry_date', '>=', $weekStart->copy()->subWeeks(8)->toDateString())
                ->latest('entry_date')
                ->get();

            foreach ($recent as $e) {
                $key = $e->category_id.'|'.($e->project_id ?: '').'|'.($e->sub_pillar_id ?: '');

                if (isset($tsItems[$key])) {
                    continue;
                }

                $label = implode(' · ', array_filter([
                    $e->category?->name,
                    $e->projectRef?->name,
                    $e->subPillar?->name,
                ]));

                $tsItems[$key] = [
                    'key' => $key,
                    'category_id' => (int) $e->category_id,
                    'project_id' => $e->project_id ? (int) $e->project_id : null,
                    'sub_pillar_id' => $e->sub_pillar_id ? (int) $e->sub_pillar_id : null,
                    'label' => $label,
                ];
            }
        }

        $tsItems = array_values($tsItems);
```

Add to the returned array:

```php
            'tsLocked' => $locked,
            'tsItems' => $tsItems,
            'tsToday' => Carbon::now()->toDateString(),
            'tsEarliestWeek' => Carbon::now()->startOfWeek()->subWeeks(self::BACKFILL_WEEKS)->toDateString(),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `lerd artisan test --filter=TimesheetScreenDataTest`
Expected: PASS, 3 tests.

- [ ] **Step 5: Run the quality gates and commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse
git add app/Http/Controllers/TimesheetController.php tests/Feature/TimesheetScreenDataTest.php
git commit -m "feat(timesheet): screen data for day-first capture

Adds locked days, a flat recent-combinations work-item list, today, and the
earliest editable week. Drops On Leave and Public Holiday from the picker."
```

---

### Task 7: Day-first Alpine component and screen

Replaces the matrix with a week strip plus one editable day card. Keeps the existing
three-step pill picker for now so the screen stays working; Task 8 replaces it.

**Files:**
- Rewrite: `resources/js/timesheet-capture.js`
- Rewrite: `resources/views/screens/timesheets.blade.php` (the capture card only; the
  "My timesheets" list and "My time spent" sections below it are unchanged)
- Test: `tests/Feature/TimesheetTest.php` (one render assertion)

**Interfaces:**
- Consumes: `tsLocked`, `tsItems`, `tsToday`, `tsEarliestWeek`, `tsCategories`, `tsProjects`,
  `tsTemplates`, `existingGrid` from Task 6, and the JSON response shape from Task 3 Step 7.
- Produces: the same `entries[<i>][entry_date|category_id|project_id|sub_pillar_id|percentage|description]`
  POST body the server already accepts. The wire contract does not change.

- [ ] **Step 1: Write the new Alpine component**

Replace `resources/js/timesheet-capture.js` entirely:

```js
/**
 * Weekly timesheet capture — one day at a time.
 *
 * Replaces the lines-by-days matrix, which testers could not operate: the day columns
 * scrolled sideways inside their card, and choosing what you worked on happened in a panel
 * that expanded between grid rows. This component shows a week strip for navigation and
 * progress, and exactly one editable day beneath it, so the layout is identical on a phone
 * and on a laptop and nothing scrolls sideways.
 *
 * State is `rows`, an ISO date → array of allocations. Locked days (approved leave, public
 * holidays) come from the server, are never editable, and always count as a full day.
 * The POST body is unchanged: one entry per (day, allocation).
 */
export function registerTimesheetCapture(Alpine) {
    Alpine.data('timesheetCapture', (cfg) => ({
        weekStart: cfg.weekStart,
        days: cfg.days || 5,
        today: cfg.today,
        earliestWeek: cfg.earliestWeek,
        locked: cfg.locked || {},
        items: cfg.items || [],
        categories: cfg.categories || [],
        projects: cfg.projects || [],
        templates: cfg.templates || [],
        readonly: cfg.readonly || false,
        rows: {},
        selected: null,
        saving: false,
        savedAt: null,
        error: '',

        init() {
            const seed = cfg.existing || {};
            for (const iso of Object.keys(seed)) {
                if (this.locked[iso]) continue;
                this.rows[iso] = seed[iso].map((e) => ({
                    category_id: e.category_id || '',
                    project_id: e.project_id || '',
                    sub_pillar_id: e.sub_pillar_id || '',
                    description: e.description || '',
                    percentage: e.percentage,
                }));
            }
            this.selected = this.firstDayNeedingWork();
        },

        // ---- the week ------------------------------------------------------
        dayDates() {
            const out = [];
            const [y, m, d] = this.weekStart.split('-').map(Number);
            for (let i = 0; i < this.days; i++) {
                const dt = new Date(Date.UTC(y, m - 1, d + i));
                out.push(dt.toISOString().slice(0, 10));
            }
            return out;
        },
        isLocked(iso) {
            return !!this.locked[iso];
        },
        isFuture(iso) {
            return iso > this.today;
        },
        isEditable(iso) {
            return !this.readonly && !this.isLocked(iso) && !this.isFuture(iso) && iso >= this.earliestWeek;
        },
        dayTotal(iso) {
            if (this.isLocked(iso)) return 100;
            return (this.rows[iso] || []).reduce((sum, r) => sum + (parseFloat(r.percentage) || 0), 0);
        },
        dayState(iso) {
            if (this.isLocked(iso)) return 'locked';
            if (this.isFuture(iso)) return 'future';
            const total = this.dayTotal(iso);
            if (total === 0) return 'empty';
            if (Math.abs(total - 100) < 0.01) return 'done';
            return total > 100 ? 'over' : 'partial';
        },
        firstDayNeedingWork() {
            const days = this.dayDates().filter((d) => this.isEditable(d));
            return days.find((d) => this.dayState(d) !== 'done') || days[days.length - 1] || this.weekStart;
        },
        select(iso) {
            if (this.isFuture(iso)) return;
            this.save();
            this.selected = iso;
        },

        // ---- rows ----------------------------------------------------------
        addRow(item, percentage) {
            const iso = this.selected;
            if (!this.isEditable(iso)) return;
            if (!this.rows[iso]) this.rows[iso] = [];
            this.rows[iso].push({
                category_id: item.category_id,
                project_id: item.project_id || '',
                sub_pillar_id: item.sub_pillar_id || '',
                description: '',
                percentage: percentage != null ? percentage : this.remainder(iso),
            });
        },
        removeRow(i) {
            this.rows[this.selected].splice(i, 1);
        },
        remainder(iso) {
            return Math.max(0, Math.round((100 - this.dayTotal(iso)) * 100) / 100);
        },

        // ---- accelerators --------------------------------------------------
        previousWorkday(iso) {
            const days = this.dayDates();
            const idx = days.indexOf(iso);
            for (let i = idx - 1; i >= 0; i--) {
                if (this.isEditable(days[i]) && (this.rows[days[i]] || []).length) return days[i];
            }
            return null;
        },
        copyPreviousDay() {
            const src = this.previousWorkday(this.selected);
            if (!src) return;
            this.rows[this.selected] = this.rows[src].map((r) => ({ ...r }));
        },
        fillRemainder() {
            const iso = this.selected;
            const list = this.rows[iso] || [];
            if (!list.length) return;
            const last = list[list.length - 1];
            const others = this.dayTotal(iso) - (parseFloat(last.percentage) || 0);
            last.percentage = Math.max(0, Math.round((100 - others) * 100) / 100);
        },

        // ---- submit gate ---------------------------------------------------
        blockingDays() {
            return this.dayDates()
                .filter((d) => this.isEditable(d) && this.dayState(d) !== 'done')
                .map((d) => new Date(d + 'T00:00:00Z').toLocaleDateString('en-GB', { weekday: 'long', timeZone: 'UTC' }));
        },
        weekComplete() {
            return this.blockingDays().length === 0;
        },

        // ---- persistence ---------------------------------------------------
        flatRows() {
            const out = [];
            for (const iso of Object.keys(this.rows)) {
                if (this.isLocked(iso)) continue;
                for (const r of this.rows[iso]) {
                    const pct = parseFloat(r.percentage) || 0;
                    if (pct <= 0) continue;
                    out.push({
                        entry_date: iso,
                        category_id: r.category_id,
                        project_id: r.project_id || null,
                        sub_pillar_id: r.sub_pillar_id || null,
                        percentage: pct,
                        description: r.description || null,
                    });
                }
            }
            return out;
        },
        async save(submitNow = false) {
            if (this.readonly || this.saving) return;
            const entries = this.flatRows();
            // Nothing to persist at all — no typed rows and no locked days to materialise.
            if (!entries.length && !Object.keys(this.locked).length && !submitNow) return;

            this.saving = true;
            this.error = '';
            try {
                const res = await fetch('/app/timesheets', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({
                        week_start: this.weekStart,
                        week_label: cfg.weekLabel || null,
                        submit_now: submitNow,
                        entries,
                    }),
                });
                const body = await res.json();
                if (!res.ok) {
                    this.error = Object.values(body.errors || {}).flat()[0] || 'Could not save.';
                    return;
                }
                this.locked = body.locked || {};
                this.savedAt = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
                if (submitNow) window.location.reload();
            } catch (e) {
                this.error = 'Could not reach the server. Your changes are still on screen.';
            } finally {
                this.saving = false;
            }
        },
    }));
}
```

- [ ] **Step 2: Rewrite the capture card in the Blade view**

In `resources/views/screens/timesheets.blade.php`, replace the `x-data="timesheetCapture({...})"`
config block with:

```blade
         x-data="timesheetCapture({
            weekStart: @js($weekStart),
            days: 5,
            today: @js($tsToday),
            earliestWeek: @js($tsEarliestWeek),
            locked: @js($tsLocked),
            items: @js($tsItems),
            categories: @js($tsCategories),
            projects: @js($tsProjects),
            templates: @js($tsTemplates),
            existing: @js($existingGrid),
            readonly: @js($weekLocked),
            weekLabel: @js($weekLabel ?? null),
         })">
```

Then replace everything between the week picker form and the `Save draft` / `Submit week`
buttons with, in order:

1. **Week header.** Previous and next links pointing at
   `route('app.screen', ['screen' => 'timesheets', 'week' => ...])`, the label
   `{{ $weekStart->format('j M') }} to {{ $weekStart->copy()->addDays(4)->format('j M') }}`,
   and the status chip using the existing `$sc` colour map. Disable the previous link when the
   target week is before `$tsEarliestWeek`.
2. **Week strip:**

```blade
<div style="display:flex;gap:6px;margin:14px 0 16px;">
    <template x-for="d in dayDates()" :key="d">
        <button type="button" @click="select(d)" :disabled="isFuture(d)"
            style="flex:1;background:none;border:0;padding:0;cursor:pointer;text-align:center;"
            :style="isFuture(d) ? { cursor:'not-allowed', opacity:.45 } : {}">
            <div style="height:6px;border-radius:3px;margin-bottom:5px;"
                :style="{ background: {
                    empty:   'var(--hairline)',
                    partial: 'var(--amber)',
                    done:    'var(--success)',
                    over:    'var(--error)',
                    locked:  'var(--muted)',
                    future:  'var(--hairline-soft)',
                }[dayState(d)] }"></div>
            <div style="font-size:11px;"
                :style="d === selected ? { color:'var(--ink)', fontWeight:600 } : { color:'var(--muted)' }">
                <span x-show="isLocked(d)" x-cloak>&#128274;</span>
                <span x-text="dayName(d)"></span>
            </div>
        </button>
    </template>
</div>
```

`dayName(iso)` does not exist yet. Add it to the component next to `dayDates()`:

```js
        dayName(iso) {
            return new Date(iso + 'T00:00:00Z')
                .toLocaleDateString('en-GB', { weekday: 'short', timeZone: 'UTC' });
        },
        dayLong(iso) {
            return new Date(iso + 'T00:00:00Z')
                .toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'short', timeZone: 'UTC' });
        },
```

3. **Day card:**

```blade
<div style="border:1px solid var(--hairline);border-radius:12px;padding:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
        <strong style="font-size:14px;" x-text="dayLong(selected)"></strong>
        <span style="font-size:12.5px;font-family:var(--font-mono);"
            :style="dayState(selected) === 'over' ? { color:'var(--error)' } : { color:'var(--muted)' }"
            x-text="dayTotal(selected) + ' / 100'"></span>
    </div>

    <div style="height:6px;background:var(--hairline-soft);border-radius:3px;overflow:hidden;margin-bottom:14px;">
        <div style="height:100%;transition:width .15s;"
            :style="{
                width: Math.min(100, dayTotal(selected)) + '%',
                background: dayState(selected) === 'done' ? 'var(--success)'
                          : dayState(selected) === 'over' ? 'var(--error)' : 'var(--amber)',
            }"></div>
    </div>

    {{-- Locked: an approved leave day or public holiday. Read-only, always a full day. --}}
    <template x-if="isLocked(selected)">
        <div style="display:flex;align-items:center;gap:10px;padding:12px;border-radius:8px;background:var(--canvas);">
            <span>&#128274;</span>
            <div style="flex:1;">
                <div style="font-size:12.5px;" x-text="locked[selected].label"></div>
                <div style="font-size:11px;color:var(--muted);"
                    x-text="$store.ui.lang==='en' ? 'Nothing to do here.' : 'Tiada apa-apa untuk dibuat.'"></div>
            </div>
            <span style="font-family:var(--font-mono);font-size:13px;">100%</span>
        </div>
    </template>

    <template x-if="!isLocked(selected)">
        <div>
            <template x-for="(r, i) in (rows[selected] || [])" :key="i">
                <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid var(--hairline-soft);">
                    <span style="flex:1;font-size:12.5px;" x-text="rowLabel(r)"></span>
                    <input type="number" min="0" max="100" step="0.01" inputmode="decimal"
                        x-model="r.percentage" @blur="save()" :disabled="!isEditable(selected)"
                        style="width:72px;height:34px;padding:0 8px;text-align:center;border:1px solid var(--hairline);border-radius:7px;font-family:var(--font-mono);font-size:12.5px;outline:none;" />
                    <button type="button" @click="removeRow(i)" :disabled="!isEditable(selected)"
                        class="uj-btn-ghost" style="height:34px;padding:0 9px;color:var(--error);"
                        :aria-label="$store.ui.lang==='en' ? 'Remove' : 'Buang'">&times;</button>
                </div>
            </template>

            <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
                <button type="button" @click="copyPreviousDay()" x-show="previousWorkday(selected)"
                    :disabled="!isEditable(selected)" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;">
                    <span x-text="($store.ui.lang==='en' ? 'Same as ' : 'Sama seperti ') + dayName(previousWorkday(selected) || selected)"></span>
                </button>
                <button type="button" @click="fillRemainder()" x-show="(rows[selected] || []).length"
                    :disabled="!isEditable(selected)" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;">
                    <span x-text="$store.ui.lang==='en' ? 'Give the rest to the last line' : 'Beri bakinya kepada baris akhir'"></span>
                </button>
            </div>
        </div>
    </template>
</div>
```

`rowLabel(r)` does not exist yet. Add it to the component, resolving ids against the config
lists so a row shows the same text the picker offered:

```js
        rowLabel(r) {
            const cat = this.categories.find((c) => String(c.id) === String(r.category_id));
            const proj = this.projects.find((p) => String(p.id) === String(r.project_id));
            const sub = proj && (proj.sub_pillars || []).find((s) => String(s.id) === String(r.sub_pillar_id));
            return [cat && cat.name, proj && proj.name, sub && sub.name].filter(Boolean).join(' · ');
        },
```

Confirm the sub-pillar key name against what `projectOptions()` emits in
`TimesheetController`; the current component reads it via `subPillarsFor()`, so copy whatever
key that method uses rather than assuming `sub_pillars`.
4. **Add affordance.** A dashed button that opens the existing three-step pill picker,
   unchanged from the current file, whose final action calls
   `addRow({category_id, project_id, sub_pillar_id})`. Task 8 replaces this.
5. **Footer.** `Save draft` calling `save(false)`, `Submit week` calling `save(true)` with
   `:disabled="!weekComplete()"`, and beneath it
   `<span x-show="!weekComplete()" x-text="blockingDays().join(' and ') + ' not at 100% yet'">`.

Delete from the file: the day-column header row, the per-line day-cell loop, the day-total
footer row, and the `0 days filled · all days at 100%` line. Their job is now the week strip.

Every user-facing string needs its `x-text="$store.ui.lang==='en' ? '…' : '…'"` pair, matching
the rest of the file.

- [ ] **Step 3: Add a render assertion**

Append to `tests/Feature/TimesheetTest.php`:

```php
    public function test_the_capture_screen_renders_for_an_employee(): void
    {
        $this->actingInTenant()->get('/app/timesheets?week=2026-06-15')
            ->assertOk()
            ->assertSee('timesheetCapture', false);
    }
```

- [ ] **Step 4: Build and run the tests**

Run: `bun run build && lerd artisan test --filter=TimesheetTest`
Expected: build succeeds, tests PASS.

- [ ] **Step 5: Verify in the browser**

Open `http://localhost:9100/app/timesheets`, sign in as `aisyah.rahman@unijaya.example` with
password `password`, and pick the Unijaya workspace. Confirm by eye:

- No horizontal scrollbar at any window width down to 375px.
- The week strip shows five days, and days after today are dimmed and not clickable.
- Adding an allocation and typing a percentage updates the strip bar for that day.
- `Submit week` stays disabled and names the days that are not at 100%.
- Reloading the page keeps what you entered, proving autosave fired.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint && vendor/bin/phpstan analyse
git add resources/js/timesheet-capture.js resources/views/screens/timesheets.blade.php tests/Feature/TimesheetTest.php public/build
git commit -m "feat(timesheet): day-first capture screen

Replaces the lines-by-days matrix with a week strip and one editable day.
Same layout on phone and desktop, nothing scrolls sideways, and the day total
is one bar rather than a footnote about five columns. Drafts autosave."
```

---

### Task 8: Flat work-item picker

Replaces the three-step pill drill-down with one searchable list of ready-made combinations.
This is the change that takes adding a line from roughly nine interactions to two.

**Files:**
- Modify: `resources/js/timesheet-capture.js`
- Modify: `resources/views/screens/timesheets.blade.php`

**Interfaces:**
- Consumes: `tsItems` from Task 6, `addRow(item, percentage)` from Task 7.
- Produces: `filteredItems()` and the `picker` state object, used only inside this component.

- [ ] **Step 1: Add picker state and filtering**

In `resources/js/timesheet-capture.js`, add to the returned object:

```js
        picker: { open: false, search: '', advanced: false },

        openPicker() {
            this.picker = { open: true, search: '', advanced: false };
        },
        filteredItems() {
            const q = this.picker.search.trim().toLowerCase();
            if (!q) return this.items;
            return this.items.filter((i) => i.label.toLowerCase().includes(q));
        },
        chooseItem(item) {
            this.addRow(item, this.remainder(this.selected) || 100);
            this.picker.open = false;
        },
```

- [ ] **Step 2: Render the picker**

In the Blade file, replace the add affordance from Task 7 Step 2 item 4 with a panel shown by
`x-show="picker.open"` containing, in order:

1. A search input bound to `picker.search`, shown only when `items.length > 8`.
2. `<template x-for="item in filteredItems()">` rendering one full-width row per item,
   labelled `x-text="item.label"`, calling `chooseItem(item)`.
3. Amount chips beneath the chosen row: `100`, `50`, `25`, plus a number input, all writing to
   the newly added row's `percentage`.
4. A `Something else` link toggling `picker.advanced`, which reveals the existing three-step
   category → project → sub-pillar pill markup unchanged, so no combination is unreachable.
5. An empty state when `items.length === 0`, sending the user straight to `picker.advanced`.

- [ ] **Step 3: Build and verify in the browser**

Run: `bun run build`

Then at `http://localhost:9100/app/timesheets`, confirm:

- The picker lists combinations you have used before, most recent first.
- Typing in the search box filters the list.
- Choosing an item adds a row already carrying the day's remaining percentage.
- `Something else` still reaches every category, project and sub-pillar.
- `On Leave` and `Public Holiday` appear nowhere in the picker.

- [ ] **Step 4: Run the full suite**

Run: `lerd artisan test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/timesheet-capture.js resources/views/screens/timesheets.blade.php public/build
git commit -m "feat(timesheet): flat searchable work-item picker

One list of ready-made Category · Project · Sub-pillar combinations, recent
first, instead of three sequential pill choices. The drill-down stays behind
'Something else' so nothing becomes unreachable."
```

---

## After the plan

Two follow-ups the spec records but this plan deliberately does not build:

1. **The approval loop does not exist.** Staff still see "Timesheet submitted for approval" and
   nothing approves. At minimum the copy should be corrected. Tracked in the spec's Risks.
2. **The DONE count on the team roster will drop** the first week after Task 2 ships, because
   it previously counted drafts. Tell users before deploying.
