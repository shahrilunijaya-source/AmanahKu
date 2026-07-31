# Timesheet Compliance & Friday Reminder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nudge every staffer to fully fill their weekly timesheet — a Friday 5pm bell push + an overdue banner — and let all staff see who is/isn't done.

**Architecture:** One read-only `TimesheetCompliance` service is the single source of truth for "is this week done" (every weekday Mon–Fri totals 100%). A scheduled `timesheet:remind` command (Fri 17:00) sends bell notifications to anyone not done. `AppController::quickActions()` (runs on every screen) sets an overdue flag that drives a red banner in the layout. The timesheets screen gains a collapsible all-staff "team status" board. No schema changes.

**Tech Stack:** Laravel 13 (PHP 8, PSR-12, `declare(strict_types=1)`), PHPUnit, Blade + Alpine.js (bilingual via `$store.ui.lang==='en' ? 'EN' : 'BM'`), existing `AppNotification` DB model, `CurrentTenant` tenancy context.

---

## File Structure

| File | Responsibility |
|------|----------------|
| `app/Timesheet/TimesheetCompliance.php` | **New.** All compliance logic: week math, deadline, per-employee + roster status. Read-only, no writes. |
| `app/Console/Commands/TimesheetReminder.php` | **New.** `timesheet:remind` — loops tenants, bell-notifies pending staff. |
| `bootstrap/app.php` | **Modify.** Register the command on the Friday 17:00 schedule. |
| `app/Http/Controllers/AppController.php` | **Modify.** `quickActions()` adds `qaTsOverdue`. |
| `app/Http/Controllers/TimesheetController.php` | **Modify.** `screenData()` adds `tsRoster`. |
| `resources/views/layouts/app.blade.php` | **Modify.** Overdue banner. |
| `resources/views/screens/timesheets.blade.php` | **Modify.** Team-status board section. |
| `tests/Unit/TimesheetComplianceTest.php` | **New.** Service unit tests. |
| `tests/Feature/TimesheetReminderTest.php` | **New.** Command + schedule + banner + board tests. |

**Conventions to follow (verified in repo):**
- Tenancy: models use a `BelongsToTenant` global scope. Console commands set `app(CurrentTenant::class)->set($tenant)` per tenant loop and `->set(null)` at the end (see `WeeklyHrDigest`).
- `AppNotification::sendMany(iterable $userIds, string $title, ?string $body, ?string $url)` creates DB rows; `tenant_id` auto-fills from the active `CurrentTenant`. Null user ids are skipped.
- Week key: `Carbon::now()->startOfWeek()` = Monday. One `Timesheet` per `(employee_id, week_start)`.
- Per-day rule (mirror of `TimesheetController::assertDayTotals`): a day is complete when its `percentage` sums to 100% within ±0.01.
- Screen URL: `route('app.screen', 'timesheets')` (= `/app/timesheets`, GET).
- Bilingual UI text is inline Alpine (`x-text="$store.ui.lang==='en' ? '…' : '…'"`), no `lang/` files.

---

## Task 1: `TimesheetCompliance` service (TDD)

**Files:**
- Create: `app/Timesheet/TimesheetCompliance.php`
- Test: `tests/Unit/TimesheetComplianceTest.php`

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/TimesheetComplianceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Unit coverage for TimesheetCompliance. The "current" week is Mon 2026-06-22
 * (deadline Fri 2026-06-26 17:00). Time is pinned per test.
 */
class TimesheetComplianceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private TimesheetCompliance $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->svc = app(TimesheetCompliance::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function employee(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
        ], $attrs));
    }

    /** Build a timesheet for $emp with a percentage for each given date. */
    private function sheet(Employee $emp, string $weekStart, array $dayPct): Timesheet
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Others'.uniqid(), 'requires_project' => false]);
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id,
            'week_start' => $weekStart, 'status' => 'draft', 'total_hours' => 0,
        ]);
        foreach ($dayPct as $date => $pct) {
            $ts->entries()->create([
                'tenant_id' => $this->tenant->id, 'entry_date' => $date,
                'category_id' => $cat->id, 'percentage' => $pct, 'hours' => 0,
            ]);
        }

        return $ts;
    }

    public function test_week_start_is_the_monday_of_the_reference_day(): void
    {
        $monday = $this->svc->weekStart(Carbon::parse('2026-06-24 09:00')); // Wed
        $this->assertSame('2026-06-22', $monday->toDateString());
    }

    public function test_deadline_is_friday_1700_of_the_week(): void
    {
        $deadline = $this->svc->deadline(Carbon::parse('2026-06-22'));
        $this->assertSame('2026-06-26 17:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_full_week_all_five_weekdays_at_100_is_complete(): void
    {
        $emp = $this->employee();
        $this->sheet($emp, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        $this->assertTrue($this->svc->isComplete($emp, Carbon::parse('2026-06-22')));
    }

    public function test_a_weekday_below_100_is_incomplete(): void
    {
        $emp = $this->employee();
        $this->sheet($emp, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 80,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        $this->assertFalse($this->svc->isComplete($emp, Carbon::parse('2026-06-22')));
    }

    public function test_no_timesheet_row_is_incomplete(): void
    {
        $emp = $this->employee();
        $this->assertFalse($this->svc->isComplete($emp, Carbon::parse('2026-06-22')));
    }

    public function test_weekend_entries_do_not_substitute_for_a_missing_weekday(): void
    {
        // Mon–Thu at 100%, Friday missing, but Saturday filled — still incomplete.
        $emp = $this->employee();
        $this->sheet($emp, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-27' => 100,
        ]);
        $this->assertFalse($this->svc->isComplete($emp, Carbon::parse('2026-06-22')));
    }

    public function test_is_late_only_after_the_friday_deadline(): void
    {
        $emp = $this->employee(); // no sheet → incomplete

        Carbon::setTestNow('2026-06-26 16:59:00'); // before deadline
        $this->assertFalse($this->svc->isLate($emp, Carbon::parse('2026-06-22')));

        Carbon::setTestNow('2026-06-26 17:30:00'); // after deadline
        $this->assertTrue($this->svc->isLate($emp, Carbon::parse('2026-06-22')));
    }

    public function test_a_complete_week_is_never_late(): void
    {
        $emp = $this->employee();
        $this->sheet($emp, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        Carbon::setTestNow('2026-06-26 18:00:00');
        $this->assertFalse($this->svc->isLate($emp, Carbon::parse('2026-06-22')));
    }

    public function test_roster_marks_done_pending_late_and_excludes_new_joiners_and_inactive(): void
    {
        Carbon::setTestNow('2026-06-26 17:30:00'); // past deadline → not-done = late

        $done = $this->employee(['name' => 'Aaron']);
        $this->sheet($done, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        $late = $this->employee(['name' => 'Bella']); // no sheet
        $newJoiner = $this->employee(['name' => 'Cara', 'joined_at' => '2026-06-25']); // joined mid-week
        $inactive = $this->employee(['name' => 'Dan', 'status' => 'inactive']);

        $roster = $this->svc->roster($this->tenant, Carbon::parse('2026-06-22'));
        $byName = $roster->keyBy(fn ($r) => $r['employee']->name);

        $this->assertSame('done', $byName['Aaron']['status']);
        $this->assertSame('late', $byName['Bella']['status']);
        $this->assertArrayNotHasKey('Cara', $byName->all());
        $this->assertArrayNotHasKey('Dan', $byName->all());
    }

    public function test_pending_returns_only_not_done_employees(): void
    {
        $done = $this->employee(['name' => 'Aaron']);
        $this->sheet($done, '2026-06-22', [
            '2026-06-22' => 100, '2026-06-23' => 100, '2026-06-24' => 100,
            '2026-06-25' => 100, '2026-06-26' => 100,
        ]);
        $notDone = $this->employee(['name' => 'Bella']);

        $pending = $this->svc->pending($this->tenant, Carbon::parse('2026-06-22'));

        $this->assertCount(1, $pending);
        $this->assertSame('Bella', $pending->first()->name);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TimesheetComplianceTest`
Expected: FAIL — `Class "App\Timesheet\TimesheetCompliance" not found`.

- [ ] **Step 3: Write the service**

Create `app/Timesheet/TimesheetCompliance.php`:

```php
<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Single source of truth for weekly timesheet compliance.
 *
 * A week is "complete" when every weekday Mon–Fri of that week has timesheet
 * entries summing to 100% (±0.01) — the same per-day rule the capture screen
 * enforces on submit. Weekend days are ignored. Read-only: never writes.
 */
final class TimesheetCompliance
{
    /** Tolerance for floating per-day percentage totals. */
    private const EPSILON = 0.01;

    /** Monday 00:00 of $ref's ISO week. */
    public function weekStart(CarbonInterface $ref): CarbonImmutable
    {
        return CarbonImmutable::parse($ref)->startOfWeek();
    }

    /** That week's Friday 17:00 — the submission deadline. ($weekStart is Monday.) */
    public function deadline(CarbonInterface $weekStart): CarbonImmutable
    {
        return CarbonImmutable::parse($weekStart)->startOfDay()->addDays(4)->setTime(17, 0);
    }

    /** True when every weekday Mon–Fri of $weekStart sums to 100% (±0.01). */
    public function isComplete(Employee $employee, CarbonInterface $weekStart): bool
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();

        $sheet = Timesheet::with('entries')
            ->where('employee_id', $employee->id)
            ->whereDate('week_start', $start->toDateString())
            ->first();

        return $sheet !== null && $this->weekdaysComplete($sheet->entries, $start);
    }

    /** Not complete AND now() is at/after the Friday deadline. Drives the banner. */
    public function isLate(Employee $employee, CarbonInterface $weekStart): bool
    {
        if (CarbonImmutable::now()->lessThan($this->deadline($weekStart))) {
            return false;
        }

        return ! $this->isComplete($employee, $weekStart);
    }

    /**
     * Every active, eligible employee of $tenant with their status for $weekStart.
     * Sorted late → pending → done, then by name.
     *
     * @return Collection<int, array{employee: Employee, status: 'done'|'pending'|'late'}>
     */
    public function roster(Tenant $tenant, CarbonInterface $weekStart): Collection
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $pastDeadline = CarbonImmutable::now()->greaterThanOrEqualTo($this->deadline($start));

        $employees = Employee::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('joined_at')->orWhereDate('joined_at', '<=', $start->toDateString()))
            ->orderBy('name')
            ->get();

        $sheets = Timesheet::with('entries')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('week_start', $start->toDateString())
            ->get()
            ->keyBy('employee_id');

        $rank = ['late' => 0, 'pending' => 1, 'done' => 2];

        return $employees
            ->map(function (Employee $e) use ($sheets, $start, $pastDeadline) {
                $sheet = $sheets->get($e->id);
                $complete = $sheet !== null && $this->weekdaysComplete($sheet->entries, $start);
                $status = $complete ? 'done' : ($pastDeadline ? 'late' : 'pending');

                return ['employee' => $e, 'status' => $status];
            })
            ->sortBy(fn (array $r) => sprintf('%d-%s', $rank[$r['status']], $r['employee']->name))
            ->values();
    }

    /**
     * Active, eligible employees of $tenant who are NOT complete for $weekStart.
     *
     * @return Collection<int, Employee>
     */
    public function pending(Tenant $tenant, CarbonInterface $weekStart): Collection
    {
        return $this->roster($tenant, $weekStart)
            ->reject(fn (array $r) => $r['status'] === 'done')
            ->map(fn (array $r) => $r['employee'])
            ->values();
    }

    /** All five weekdays Mon–Fri present and each summing to 100% (±0.01). */
    private function weekdaysComplete(Collection $entries, CarbonImmutable $weekStart): bool
    {
        $byDay = [];
        foreach ($entries as $e) {
            $d = $e->entry_date->toDateString();
            $byDay[$d] = ($byDay[$d] ?? 0) + (float) $e->percentage;
        }

        for ($i = 0; $i < 5; $i++) {
            $day = $weekStart->addDays($i)->toDateString();
            if (! isset($byDay[$day]) || abs($byDay[$day] - 100) >= self::EPSILON) {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=TimesheetComplianceTest`
Expected: PASS (all methods green).

- [ ] **Step 5: Commit**

```bash
git add app/Timesheet/TimesheetCompliance.php tests/Unit/TimesheetComplianceTest.php
git commit -m "feat(timesheet): add TimesheetCompliance service for weekly done/pending/late status"
```

---

## Task 2: `timesheet:remind` command (TDD)

**Files:**
- Create: `app/Console/Commands/TimesheetReminder.php`
- Test: `tests/Feature/TimesheetReminderTest.php`

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/TimesheetReminderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for the timesheet:remind command. Current week is Mon 2026-06-22.
 * A staffer with a fully-filled week gets no bell; an empty-week staffer does.
 */
class TimesheetReminderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-26 17:00:00'); // Friday 5pm
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function staff(string $name, string $email): array
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ]);

        return [$user, $employee];
    }

    private function fillFullWeek(Employee $emp): void
    {
        $cat = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false]);
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id,
            'week_start' => '2026-06-22', 'status' => 'draft', 'total_hours' => 0,
        ]);
        foreach (['2026-06-22', '2026-06-23', '2026-06-24', '2026-06-25', '2026-06-26'] as $d) {
            $ts->entries()->create([
                'tenant_id' => $this->tenant->id, 'entry_date' => $d,
                'category_id' => $cat->id, 'percentage' => 100, 'hours' => 8,
            ]);
        }
    }

    public function test_reminds_only_staff_whose_week_is_not_complete(): void
    {
        [$doneUser, $doneEmp] = $this->staff('Done Dan', 'dan@acme.test');
        [$pendingUser] = $this->staff('Pending Pat', 'pat@acme.test');
        $this->fillFullWeek($doneEmp);

        $this->artisan('timesheet:remind')->assertSuccessful();

        // Re-set context to read tenant-scoped notifications.
        app(CurrentTenant::class)->set($this->tenant);
        $this->assertTrue(
            AppNotification::where('user_id', $pendingUser->id)->where('title', 'Timesheet reminder')->exists()
        );
        $this->assertFalse(
            AppNotification::where('user_id', $doneUser->id)->exists()
        );
    }

    public function test_no_pending_staff_sends_no_notifications(): void
    {
        [$doneUser, $doneEmp] = $this->staff('Done Dan', 'dan@acme.test');
        $this->fillFullWeek($doneEmp);

        $this->artisan('timesheet:remind')->assertSuccessful();

        app(CurrentTenant::class)->set($this->tenant);
        $this->assertSame(0, AppNotification::count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TimesheetReminderTest`
Expected: FAIL — `Command "timesheet:remind" is not defined.`

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/TimesheetReminder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Timesheet\TimesheetCompliance;
use Illuminate\Console\Command;

/**
 * Friday 5pm timesheet reminder.
 *
 * For each tenant, bell-notifies every active staffer whose current-week
 * timesheet is not fully filled (a weekday below 100%). Tenant-aware like the
 * leave/digest commands: the active tenant is set per loop so AppNotification
 * rows are written under the correct tenant scope. Context is cleared at the end.
 *
 * Idempotent enough for cron retries — a duplicate bell is harmless and clears
 * when the user reads it; there is no dedupe table.
 */
class TimesheetReminder extends Command
{
    protected $signature = 'timesheet:remind';

    protected $description = 'Bell-notify staff whose current-week timesheet is not fully filled in (Friday 5pm reminder).';

    private const TITLE = 'Timesheet reminder';

    private const BODY = "Your timesheet for this week isn't fully filled in. Please complete it to 100% for each day.";

    public function handle(CurrentTenant $context, TimesheetCompliance $compliance): int
    {
        $weekStart = $compliance->weekStart(now());
        $url = route('app.screen', 'timesheets');
        $remindedTenants = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            $userIds = $compliance->pending($tenant, $weekStart)
                ->pluck('user_id')
                ->filter()
                ->values();

            if ($userIds->isEmpty()) {
                continue;
            }

            AppNotification::sendMany($userIds, self::TITLE, self::BODY, $url);
            $remindedTenants++;
        }

        $context->set(null);

        $this->info("Timesheet reminder sent for {$remindedTenants} tenant(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=TimesheetReminderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/TimesheetReminder.php tests/Feature/TimesheetReminderTest.php
git commit -m "feat(timesheet): add timesheet:remind command for Friday 5pm bell reminder"
```

---

## Task 3: Register the Friday 17:00 schedule

**Files:**
- Modify: `bootstrap/app.php` (inside the existing `->withSchedule(...)` closure)
- Test: `tests/Feature/TimesheetReminderTest.php` (add one method)

- [ ] **Step 1: Add the failing schedule test**

Append this method to `tests/Feature/TimesheetReminderTest.php` (add `use Illuminate\Console\Scheduling\Schedule;` to the imports):

```php
    public function test_command_is_scheduled_for_friday_1700(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'timesheet:remind'));

        $this->assertTrue($events->isNotEmpty(), 'timesheet:remind is not scheduled');
        // Cron for Friday 17:00 = "0 17 * * 5".
        $this->assertSame('0 17 * * 5', $events->first()->expression);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=test_command_is_scheduled_for_friday_1700`
Expected: FAIL — `timesheet:remind is not scheduled`.

- [ ] **Step 3: Register the schedule**

In `bootstrap/app.php`, inside the `->withSchedule(function (Schedule $schedule): void { ... })` closure, after the `digest:weekly` line, add:

```php
        // Timesheet reminder: Friday 17:00. Bell-notifies staff who haven't
        // fully filled the current week. Idempotent, so a retry is safe.
        $schedule->command('timesheet:remind')->fridays()->at('17:00');
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=test_command_is_scheduled_for_friday_1700`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add bootstrap/app.php tests/Feature/TimesheetReminderTest.php
git commit -m "feat(timesheet): schedule timesheet:remind for Friday 17:00"
```

---

## Task 4: Overdue banner via `quickActions()` (TDD)

**Files:**
- Modify: `app/Http/Controllers/AppController.php:143-175` (`quickActions()`)
- Modify: `resources/views/layouts/app.blade.php` (after the `session('error')` block, before `@yield('screen')`)
- Test: `tests/Feature/TimesheetReminderTest.php` (add two methods)

- [ ] **Step 1: Write the failing banner tests**

Append to `tests/Feature/TimesheetReminderTest.php`:

```php
    public function test_overdue_banner_shows_after_friday_5pm_when_week_incomplete(): void
    {
        [$user] = $this->staff('Pending Pat', 'pat@acme.test'); // empty week
        Carbon::setTestNow('2026-06-26 17:30:00'); // Friday, past deadline

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('app.screen', 'timesheets'))
            ->assertOk()
            ->assertSee('Your timesheet for this week is overdue', false);
    }

    public function test_no_overdue_banner_before_the_deadline(): void
    {
        [$user] = $this->staff('Pending Pat', 'pat@acme.test'); // empty week
        Carbon::setTestNow('2026-06-24 09:00:00'); // Wednesday, before deadline

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('app.screen', 'timesheets'))
            ->assertOk()
            ->assertDontSee('Your timesheet for this week is overdue', false);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=overdue_banner`
Expected: FAIL — text not present (banner not yet built). (`test_no_overdue_banner...` may pass already; the first must fail.)

- [ ] **Step 3a: Add `qaTsOverdue` to `quickActions()`**

In `app/Http/Controllers/AppController.php`, replace the `quickActions()` return block (currently lines ~166-174) so it computes and returns the overdue flag. The method already loads `$tsEnabled`; add the compliance check before the return:

```php
        // Overdue = past Friday 5pm AND this week isn't fully filled. Drives the
        // app-wide red banner. One light indexed lookup per page load.
        $tsOverdue = false;
        if ($tsEnabled) {
            $tsOverdue = app(\App\Timesheet\TimesheetCompliance::class)
                ->isLate($employee, now()->startOfWeek());
        }

        return [
            'qaShow' => true,
            'qaCi' => $today?->clock_in,
            'qaCo' => $today?->clock_out,
            'qaTsEnabled' => $tsEnabled,
            'qaTsPct' => $tsPct,
            'qaTsOverdue' => $tsOverdue,
        ];
```

(The early `return ['qaShow' => false];` path for no-employee is unchanged; the banner guards on `?? false`.)

- [ ] **Step 3b: Render the banner in the layout**

In `resources/views/layouts/app.blade.php`, insert this block immediately after the `@endif` that closes the `session('error')` block (line ~64) and before `@yield('screen')` (line ~65):

```blade
                @if (($qaTsOverdue ?? false))
                    <div x-data="{ show: true }" x-show="show" style="display:flex;align-items:center;gap:10px;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:13px;border-radius:10px;padding:11px 16px;margin-bottom:16px;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                        <span style="flex:1;" x-text="$store.ui.lang==='en'
                            ? 'Your timesheet for this week is overdue. Fill every working day to 100%.'
                            : 'Timesheet anda untuk minggu ini sudah lewat. Isi setiap hari bekerja ke 100%.'">Your timesheet for this week is overdue. Fill every working day to 100%.</span>
                        <a href="{{ route('app.screen', 'timesheets') }}" style="white-space:nowrap;font-weight:600;text-decoration:underline;color:var(--red);" x-text="$store.ui.lang==='en' ? 'Update now' : 'Kemas kini'">Update now</a>
                        <button @click="show = false" style="color:var(--red);font-size:16px;">×</button>
                    </div>
                @endif
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=overdue_banner`
Expected: PASS both.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AppController.php resources/views/layouts/app.blade.php tests/Feature/TimesheetReminderTest.php
git commit -m "feat(timesheet): app-wide overdue banner after Friday 5pm deadline"
```

---

## Task 5: All-staff "team status" board on the timesheets screen (TDD)

**Files:**
- Modify: `app/Http/Controllers/TimesheetController.php:94-113` (`screenData()` return)
- Modify: `resources/views/screens/timesheets.blade.php` (after the stats-cards `</div>` at line ~65)
- Test: `tests/Feature/TimesheetReminderTest.php` (add one method)

- [ ] **Step 1: Write the failing board test**

Append to `tests/Feature/TimesheetReminderTest.php`:

```php
    public function test_team_status_board_is_visible_to_a_plain_staffer(): void
    {
        [$me] = $this->staff('Me Myself', 'me@acme.test');
        [, $colleagueEmp] = $this->staff('Cathy Colleague', 'cathy@acme.test');
        $this->fillFullWeek($colleagueEmp); // Cathy is done

        $this->actingAs($me)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('app.screen', 'timesheets'))
            ->assertOk()
            ->assertSee('Cathy Colleague', false)   // roster lists everyone
            ->assertSee('team status', false);       // board heading (EN default text)
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=test_team_status_board_is_visible_to_a_plain_staffer`
Expected: FAIL — "team status" / colleague name not rendered.

- [ ] **Step 3a: Add `tsRoster` to `screenData()`**

In `app/Http/Controllers/TimesheetController.php`, inside `screenData()`, add `tsRoster` to the returned array (after the `'existingGrid' => $existingGrid,` line). `$weekStart` is already in scope:

```php
            'existingGrid' => $existingGrid,
            // All-staff weekly compliance board (names + status only, no cost).
            'tsRoster' => app(\App\Timesheet\TimesheetCompliance::class)
                ->roster(app(\App\Tenancy\CurrentTenant::class)->get(), $weekStart),
        ];
```

- [ ] **Step 3b: Render the board in the timesheets screen**

In `resources/views/screens/timesheets.blade.php`, insert this block immediately after the stats-cards `</div>` (the one closing the flex row at line ~65), before the `@if (($positionMissing ?? false))` block:

```blade
@php
    $tsRoster = collect($tsRoster ?? []);
    $tsDone = $tsRoster->where('status', 'done')->count();
    $tsTotal = $tsRoster->count();
    $tsPill = ['done' => 'var(--success)', 'pending' => 'var(--muted)', 'late' => 'var(--red)'];
@endphp
@if ($tsTotal)
<div class="uj-card" style="margin-bottom:16px;padding:14px 18px;" x-data="{ open: true }">
    <div style="display:flex;align-items:center;gap:10px;cursor:pointer;" @click="open = !open">
        <strong style="flex:1;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'This week — team status' : 'Minggu ini — status pasukan'">This week — team status</strong>
        <span style="font-size:12.5px;color:var(--muted);">{{ $tsDone }} / {{ $tsTotal }} <span x-text="$store.ui.lang==='en' ? 'done' : 'selesai'">done</span></span>
        <span x-text="open ? '▾' : '▸'" style="color:var(--muted);"></span>
    </div>
    <div x-show="open" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;">
        @foreach ($tsRoster as $row)
            <span style="display:inline-flex;align-items:center;gap:7px;padding:4px 11px;border-radius:999px;background:var(--surface-2,#f3f4f6);font-size:12px;">
                <span style="width:8px;height:8px;border-radius:50%;background:{{ $tsPill[$row['status']] }};flex:none;"></span>
                <span>{{ $row['employee']->name }}</span>
                <span style="color:var(--muted);font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;">
                    @if ($row['status'] === 'done')
                        <span x-text="$store.ui.lang==='en' ? 'done' : 'selesai'">done</span>
                    @elseif ($row['status'] === 'late')
                        <span x-text="$store.ui.lang==='en' ? 'late' : 'lewat'">late</span>
                    @else
                        <span x-text="$store.ui.lang==='en' ? 'pending' : 'belum'">pending</span>
                    @endif
                </span>
            </span>
        @endforeach
    </div>
</div>
@endif
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=test_team_status_board_is_visible_to_a_plain_staffer`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/TimesheetController.php resources/views/screens/timesheets.blade.php tests/Feature/TimesheetReminderTest.php
git commit -m "feat(timesheet): all-staff team-status board on the timesheets screen"
```

---

## Task 6: Full suite green

- [ ] **Step 1: Run the timesheet + smoke suites**

Run: `php artisan test --filter=Timesheet`
Expected: PASS (TimesheetTest, TimesheetCostTest, TimesheetComplianceTest, TimesheetReminderTest).

- [ ] **Step 2: Run the all-screens render smoke test**

Run: `php artisan test --filter=AllScreensRenderTest`
Expected: PASS — the modified timesheets screen + layout still render for every persona.

- [ ] **Step 3: Commit any fixups** (only if the two runs above required changes)

```bash
git add -A
git commit -m "test(timesheet): fixups for compliance reminder suite"
```

---

## Verification checklist (manual, after merge)

- [ ] Friday 5pm: a staffer with any weekday < 100% receives a bell notification linking to the timesheets screen.
- [ ] After Friday 5pm, that staffer sees the red overdue banner on every screen until they reach 100% (or Monday).
- [ ] A staffer who completed the week gets no bell and no banner.
- [ ] Every staffer (plain employee role) can open the timesheets screen and see the "This week — team status" board with everyone's name + Done/Pending/Late.
- [ ] The board shows no RM/cost figures.
- [ ] BM toggle flips the banner + board copy to Malay.

## Out of scope (YAGNI — do not build)

- Leave/holiday-aware exemptions, email reminders, per-user mute, historical compliance reporting. (See spec.)
