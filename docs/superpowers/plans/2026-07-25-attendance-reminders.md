# Attendance Clock Reminders Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nudge staff who have not clocked in by their start time, or are still clocked in past their end time, through the existing in-app bell plus an OS-level browser notification shown while the app is open (including when the browser window is minimised).

**Architecture:** A scheduled tenant-aware Artisan command (`attendance:remind`) decides who is overdue using the existing `ScheduleResolver`, and writes rows into the existing `app_notifications` table with a new `dedupe_key` so a 15-minute cadence cannot spam anyone. The browser side polls a small JSON endpoint every 60 seconds and raises the alert through a service worker's `showNotification()` — chosen over the `new Notification()` constructor because iOS supports only the service-worker path. The service worker plus a web app manifest also make the app installable, which is a hard prerequisite for any notification at all on iOS.

**Tech Stack:** Laravel 13 / PHP 8.5, PHPUnit 12, Blade + Alpine 3, Tailwind 4, Vite 8 (run through `bun`), MySQL under Lerd.

## Global Constraints

- **No new composer or bun dependencies.** Everything here uses the framework and platform APIs already present. Web Push (VAPID + `minishlink/web-push`) is explicitly out of scope; notifications only fire while a page is open.
- **PHP:** `declare(strict_types=1);` at the top of every new PHP file. Explicit return types and parameter type hints on every method. Constructor property promotion. Curly braces on all control structures. PHPDoc blocks over inline comments.
- **Tests are PHPUnit, never Pest.** Create with `php artisan make:test --phpunit {name}`. Run with `php artisan test --compact --filter=...`.
- **Formatting:** run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- **Multi-tenancy:** every model in play uses the `BelongsToTenant` trait. It fails closed on writes — creating a tenant-owned row with no active `CurrentTenant` and no explicit `tenant_id` throws. All console work must run inside a per-tenant loop that calls `$context->set($tenant)` and clears with `$context->set(null)` at the end.
- **Timezone:** `Asia/Kuala_Lumpur` (`config/app.php`). Always use `now()`, never `Carbon::now()` with an explicit zone.
- **JS:** ES modules under `resources/js/`, each exporting a `registerX(Alpine)` function wired up in `resources/js/app.js`. Build with `bun run build`, never npm.
- **Dev URL is `http://localhost:9100`**, not `http://amanahku.test`.
- **Migrations must be run against the dev database** (`lerd artisan migrate`) as well as passing in tests; the test suite uses a separate database, so a green suite does not mean the dev app works.
- **Bilingual UI copy:** user-facing strings in Blade follow the existing pattern `x-text="$store.ui.lang==='en' ? 'English' : 'Bahasa Melayu'"`.

## Known Ceilings (state these; do not silently paper over them)

- Notifications fire only while a tab or installed window is running. Browser fully closed means no alert. This was the chosen scope.
- Background tabs are timer-throttled to roughly one callback per minute by Chrome and Firefox. The 60-second poll degrades gracefully; do not shorten it expecting more.
- **iOS is weak.** Even installed to the Home Screen, iOS suspends a PWA when it is not foregrounded, so the poll stops. In practice iOS users get the alert only while actively using the app. The in-app bell remains their real fallback. Do not claim iOS parity.
- The scheduler cadence depends on the hPanel cron calling `schedule:run` every minute. That cron cannot be inspected over SSH (see `CLAUDE.md`). **Before deploying, confirm the hPanel cron interval.** If it runs hourly, `everyFifteenMinutes()` silently degrades to hourly and reminders arrive up to an hour late.
- Weekends are treated as non-working days for everyone. There is no per-branch working-days configuration in the schema today. Staff on a Saturday roster will not be reminded.
- `Shift` and `ShiftSwap` models exist but `ScheduleResolver` does not consult them. Reminder timing follows branch/site/WFH hours only, consistent with how clock-in itself already behaves.

---

### Task 1: Notification dedupe key

Without this, a command running every 15 minutes writes a fresh bell row every tick. The key makes a send idempotent for a given user and day.

**Files:**
- Create: `database/migrations/2026_07_25_000001_add_dedupe_key_to_app_notifications.php`
- Modify: `app/Models/AppNotification.php`
- Test: `tests/Feature/NotificationDedupeTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `AppNotification::send(?int $userId, string $title, ?string $body = null, ?string $url = null, ?string $dedupeKey = null): bool` — returns `true` when a row was actually created, `false` when suppressed by the dedupe key or when `$userId` is null. `AppNotification::sendMany(iterable $userIds, string $title, ?string $body = null, ?string $url = null, ?string $dedupeKey = null): void`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/NotificationDedupeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A dedupe key makes AppNotification::send idempotent, so a command that runs on a
 * short cadence can call it every tick without stacking duplicate bells.
 */
class NotificationDedupeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($tenant);
        $this->user = User::create([
            'name' => 'Aina',
            'email' => 'aina@acme.test',
            'password' => Hash::make('password'),
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    public function test_same_dedupe_key_only_creates_one_row(): void
    {
        $first = AppNotification::send($this->user->id, 'Clock-in reminder', 'Body', '/app', 'attendance-in-2026-07-25');
        $second = AppNotification::send($this->user->id, 'Clock-in reminder', 'Body', '/app', 'attendance-in-2026-07-25');

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, AppNotification::where('user_id', $this->user->id)->count());
    }

    public function test_different_dedupe_keys_create_separate_rows(): void
    {
        AppNotification::send($this->user->id, 'Clock-in reminder', null, null, 'attendance-in-2026-07-25');
        AppNotification::send($this->user->id, 'Clock-out reminder', null, null, 'attendance-out-2026-07-25');

        $this->assertSame(2, AppNotification::where('user_id', $this->user->id)->count());
    }

    public function test_notifications_without_a_key_are_never_deduped(): void
    {
        AppNotification::send($this->user->id, 'Claim approved');
        AppNotification::send($this->user->id, 'Claim approved');

        $this->assertSame(2, AppNotification::where('user_id', $this->user->id)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=NotificationDedupeTest
```

Expected: FAIL. `send()` currently returns `void`, so `assertTrue($first)` fails, and the `dedupe_key` column does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_25_000001_add_dedupe_key_to_app_notifications.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            // Optional idempotency handle for scheduled senders ("attendance-in-2026-07-25").
            // NULL is the normal case for one-off event notifications, and SQL treats NULLs
            // as distinct in a unique index, so existing rows and ad-hoc sends are unaffected.
            $table->string('dedupe_key')->nullable()->after('url');
            $table->unique(['tenant_id', 'user_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'user_id', 'dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/AppNotification.php`, replace the `send` and `sendMany` methods with:

```php
    /**
     * Queue an in-app notification for a single user (tenant auto-filled).
     *
     * Pass $dedupeKey from a scheduled sender to make the call idempotent: the first
     * call for a given tenant + user + key wins, every repeat is a no-op.
     *
     * @return bool True when a row was created, false when suppressed.
     */
    public static function send(?int $userId, string $title, ?string $body = null, ?string $url = null, ?string $dedupeKey = null): bool
    {
        if (! $userId) {
            return false;
        }

        $attributes = ['user_id' => $userId, 'title' => $title, 'body' => $body, 'url' => $url];

        if ($dedupeKey === null) {
            static::create($attributes);

            return true;
        }

        return static::firstOrCreate(
            ['user_id' => $userId, 'dedupe_key' => $dedupeKey],
            $attributes,
        )->wasRecentlyCreated;
    }

    /** @param iterable<int> $userIds */
    public static function sendMany(iterable $userIds, string $title, ?string $body = null, ?string $url = null, ?string $dedupeKey = null): void
    {
        foreach ($userIds as $id) {
            static::send($id, $title, $body, $url, $dedupeKey);
        }
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=NotificationDedupeTest
```

Expected: PASS, 3 tests.

- [ ] **Step 6: Confirm no existing caller broke**

`send()` changed from `void` to `bool`, which is safe for callers that ignore the value, but run the notification suites to be sure.

```bash
php artisan test --compact --filter="NotificationsTest|SubmitNotificationTest|TimesheetReminderTest"
```

Expected: PASS.

- [ ] **Step 7: Migrate the dev database**

The test suite uses a separate database. Without this the dev app will 500 on any notification write.

```bash
lerd artisan migrate
```

Expected: `2026_07_25_000001_add_dedupe_key_to_app_notifications ... DONE`

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_25_000001_add_dedupe_key_to_app_notifications.php app/Models/AppNotification.php tests/Feature/NotificationDedupeTest.php
git commit -m "feat(notifications): add optional dedupe_key so scheduled senders are idempotent

A reminder command running every 15 minutes would otherwise write a fresh bell
row on every tick. send() now takes a dedupe key and reports whether it created
anything, so short-cadence senders can be called freely."
```

---

### Task 2: ReminderTargets — decide who is overdue

All the "should this person be nudged" logic lives here so the command stays a thin loop and the rules are testable on their own.

**Files:**
- Create: `app/Attendance/ReminderTargets.php`
- Test: `tests/Feature/AttendanceReminderTargetsTest.php`

**Interfaces:**
- Consumes: `App\Attendance\ScheduleResolver::resolve(Employee $employee, CarbonInterface $date): SiteSpec` (existing). `SiteSpec` exposes readonly `workStart` and `workEnd` as `'HH:MM'` strings or null. `AttendanceRecord::scopeOnDate(Builder $query, CarbonInterface|string $day)` (existing).
- Produces:
  - `ReminderTargets::missingClockIn(Carbon $now, int $graceMinutes): Collection<int, Employee>`
  - `ReminderTargets::missingClockOut(Carbon $now, int $graceMinutes): Collection<int, AttendanceRecord>` — each record is eager-loaded with `employee`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceReminderTargetsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\ReminderTargets;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for who deserves a clock nudge. Reference day is Thursday 2026-07-23,
 * office hours 09:00-18:00, 30-minute grace.
 */
class AttendanceReminderTargetsTest extends TestCase
{
    use RefreshDatabase;

    private const GRACE = 30;

    private Tenant $tenant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->branch = Branch::create([
            'name' => 'HQ',
            'latitude' => 3.1390,
            'longitude' => 101.6869,
            'radius_m' => 200,
            'work_start' => '09:00:00',
            'work_end' => '18:00:00',
            'min_hours' => 8,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function staff(string $name, string $email): Employee
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return Employee::create([
            'user_id' => $user->id,
            'name' => $name,
            'email' => $email,
            'branch_id' => $this->branch->id,
            'work_arrangement' => 'office',
        ]);
    }

    private function targets(): ReminderTargets
    {
        return app(ReminderTargets::class);
    }

    public function test_flags_staff_who_have_not_clocked_in_after_the_grace_window(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        $now = Carbon::parse('2026-07-23 09:31:00');

        $due = $this->targets()->missingClockIn($now, self::GRACE);

        $this->assertSame([$employee->id], $due->pluck('id')->all());
    }

    public function test_stays_quiet_inside_the_grace_window(): void
    {
        $this->staff('Aina', 'aina@acme.test');
        $now = Carbon::parse('2026-07-23 09:29:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_stays_quiet_once_the_working_day_has_ended(): void
    {
        $this->staff('Aina', 'aina@acme.test');
        $now = Carbon::parse('2026-07-23 18:05:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_skips_staff_who_already_clocked_in(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '08:55:00',
        ]);
        $now = Carbon::parse('2026-07-23 09:31:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_skips_staff_on_approved_leave(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'date_from' => '2026-07-22',
            'date_to' => '2026-07-24',
            'days' => 3,
            'status' => 'approved',
        ]);
        $now = Carbon::parse('2026-07-23 09:31:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_skips_public_holidays(): void
    {
        $this->staff('Aina', 'aina@acme.test');
        PublicHoliday::create(['name' => 'Awal Muharram', 'date' => '2026-07-23']);
        $now = Carbon::parse('2026-07-23 09:31:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_skips_weekends(): void
    {
        $this->staff('Aina', 'aina@acme.test');
        $now = Carbon::parse('2026-07-25 09:31:00'); // Saturday

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_skips_staff_with_no_login_account(): void
    {
        Employee::create([
            'user_id' => null,
            'name' => 'Unprovisioned',
            'email' => 'nobody@acme.test',
            'branch_id' => $this->branch->id,
            'work_arrangement' => 'office',
        ]);
        $now = Carbon::parse('2026-07-23 09:31:00');

        $this->assertTrue($this->targets()->missingClockIn($now, self::GRACE)->isEmpty());
    }

    public function test_flags_an_open_record_past_its_expected_end(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        $record = AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '09:00:00',
            'expected_end' => '18:00:00',
        ]);
        $now = Carbon::parse('2026-07-23 18:31:00');

        $open = $this->targets()->missingClockOut($now, self::GRACE);

        $this->assertSame([$record->id], $open->pluck('id')->all());
    }

    public function test_ignores_a_record_that_is_already_clocked_out(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '09:00:00',
            'clock_out' => '18:02:00',
            'expected_end' => '18:00:00',
        ]);
        $now = Carbon::parse('2026-07-23 18:31:00');

        $this->assertTrue($this->targets()->missingClockOut($now, self::GRACE)->isEmpty());
    }

    public function test_ignores_an_open_record_still_inside_the_grace_window(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '09:00:00',
            'expected_end' => '18:00:00',
        ]);
        $now = Carbon::parse('2026-07-23 18:29:00');

        $this->assertTrue($this->targets()->missingClockOut($now, self::GRACE)->isEmpty());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=AttendanceReminderTargetsTest
```

Expected: FAIL with `Class "App\Attendance\ReminderTargets" does not exist`.

- [ ] **Step 3: Write the implementation**

Create `app/Attendance/ReminderTargets.php`:

```php
<?php

declare(strict_types=1);

namespace App\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out who deserves a clock-in / clock-out nudge right now, within the active tenant.
 *
 * Deliberately quiet. Weekends, tenant public holidays, approved leave, staff with no
 * login account, and staff whose site has no configured hours are all skipped, so the
 * reminder never fires at someone with nothing to clock. Read-only: the caller decides
 * how to deliver.
 */
class ReminderTargets
{
    public function __construct(private ScheduleResolver $resolver) {}

    /**
     * Active staff who were due to clock in by now and have not.
     *
     * @return Collection<int, Employee>
     */
    public function missingClockIn(Carbon $now, int $graceMinutes): Collection
    {
        if ($this->isNonWorkingDay($now)) {
            return collect();
        }

        $clockedIn = AttendanceRecord::query()
            ->onDate($now)
            ->whereNotNull('clock_in')
            ->pluck('employee_id');

        return Employee::active()
            ->whereNotNull('user_id')
            ->whereNotIn('id', $this->employeeIdsOnLeave($now))
            ->whereNotIn('id', $clockedIn)
            ->with(['branch', 'workSite', 'tenant'])
            ->get()
            ->filter(fn (Employee $employee): bool => $this->isOverdueToClockIn($employee, $now, $graceMinutes))
            ->values();
    }

    /**
     * Today's open records — clocked in, never clocked out, already past their expected end.
     *
     * Uses the `expected_end` stamped onto the record at clock-in rather than re-resolving
     * the schedule, so a mid-day change to branch hours cannot retroactively move the bar.
     *
     * @return Collection<int, AttendanceRecord>
     */
    public function missingClockOut(Carbon $now, int $graceMinutes): Collection
    {
        return AttendanceRecord::query()
            ->onDate($now)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->whereNotNull('expected_end')
            ->with('employee')
            ->get()
            ->filter(function (AttendanceRecord $record) use ($now, $graceMinutes): bool {
                if ($record->employee?->user_id === null) {
                    return false;
                }

                $due = $now->copy()->setTimeFromTimeString($record->expected_end)->addMinutes($graceMinutes);

                return $now->greaterThanOrEqualTo($due);
            })
            ->values();
    }

    /**
     * True once the grace window after the expected start has passed, but only while the
     * working day is still running — a 6pm "you never clocked in" is noise, not a save.
     */
    private function isOverdueToClockIn(Employee $employee, Carbon $now, int $graceMinutes): bool
    {
        $site = $this->resolver->resolve($employee, $now);

        if ($site->workStart === null) {
            return false;
        }

        $due = $now->copy()->setTimeFromTimeString($site->workStart)->addMinutes($graceMinutes);

        $cutoff = $site->workEnd !== null
            ? $now->copy()->setTimeFromTimeString($site->workEnd)
            : $due->copy()->addHours(4);

        return $now->greaterThanOrEqualTo($due) && $now->lessThan($cutoff);
    }

    /**
     * Weekends and tenant public holidays. There is no per-branch working-days column in
     * the schema yet, so Saturday rosters are not covered — see the plan's known ceilings.
     */
    private function isNonWorkingDay(Carbon $now): bool
    {
        return $now->isWeekend()
            || PublicHoliday::query()->whereDate('date', $now->toDateString())->exists();
    }

    /** @return Collection<int, int> */
    private function employeeIdsOnLeave(Carbon $now): Collection
    {
        $day = $now->toDateString();

        return LeaveRequest::query()
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $day)
            ->whereDate('date_to', '>=', $day)
            ->pluck('employee_id');
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=AttendanceReminderTargetsTest
```

Expected: PASS, 11 tests. If `Branch::create` or `Employee::create` rejects a column, inspect the real schema with `lerd artisan db:shell` and adjust the test fixture, not the service.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Attendance/ReminderTargets.php tests/Feature/AttendanceReminderTargetsTest.php
git commit -m "feat(attendance): add ReminderTargets to find overdue clock-ins and clock-outs

Keeps the 'who should be nudged' rules in one testable place: weekends, public
holidays, approved leave and staff with no login account are all skipped so the
reminder never fires at someone with nothing to clock."
```

---

### Task 3: attendance:remind command and scheduler entry

**Files:**
- Create: `app/Console/Commands/AttendanceReminder.php`
- Modify: `bootstrap/app.php` (inside `->withSchedule(...)`, after the `staff:archive-departed` entry around line 55)
- Test: `tests/Feature/AttendanceReminderCommandTest.php`

**Interfaces:**
- Consumes: `ReminderTargets::missingClockIn` / `missingClockOut` from Task 2, `AppNotification::send(..., ?string $dedupeKey): bool` from Task 1, and the existing `App\Tenancy\CurrentTenant` context object (`set(?Tenant $tenant): void`).
- Produces: the Artisan command `attendance:remind`. Bell rows carry the titles `Clock-in reminder` and `Clock-out reminder` and the dedupe keys `attendance-in-<Y-m-d>` / `attendance-out-<Y-m-d>`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AttendanceReminderCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for attendance:remind. Reference day is Thursday 2026-07-23, office hours
 * 09:00-18:00. The command must be safe to run every 15 minutes, so repeat runs on the
 * same day must not stack bells.
 */
class AttendanceReminderCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->branch = Branch::create([
            'name' => 'HQ',
            'latitude' => 3.1390,
            'longitude' => 101.6869,
            'radius_m' => 200,
            'work_start' => '09:00:00',
            'work_end' => '18:00:00',
            'min_hours' => 8,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function staff(string $name, string $email): Employee
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return Employee::create([
            'user_id' => $user->id,
            'name' => $name,
            'email' => $email,
            'branch_id' => $this->branch->id,
            'work_arrangement' => 'office',
        ]);
    }

    public function test_bells_a_staffer_who_has_not_clocked_in(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        Carbon::setTestNow('2026-07-23 09:31:00');

        Artisan::call('attendance:remind');

        $bell = AppNotification::where('user_id', $employee->user_id)->sole();
        $this->assertSame('Clock-in reminder', $bell->title);
        $this->assertSame('attendance-in-2026-07-23', $bell->dedupe_key);
    }

    public function test_bells_a_staffer_still_clocked_in_past_their_end(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '09:00:00',
            'expected_end' => '18:00:00',
        ]);
        Carbon::setTestNow('2026-07-23 18:31:00');

        Artisan::call('attendance:remind');

        $bell = AppNotification::where('user_id', $employee->user_id)->sole();
        $this->assertSame('Clock-out reminder', $bell->title);
        $this->assertSame('attendance-out-2026-07-23', $bell->dedupe_key);
    }

    public function test_repeat_runs_on_the_same_day_do_not_stack_bells(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        Carbon::setTestNow('2026-07-23 09:31:00');

        Artisan::call('attendance:remind');
        Carbon::setTestNow('2026-07-23 09:46:00');
        Artisan::call('attendance:remind');
        Carbon::setTestNow('2026-07-23 10:01:00');
        Artisan::call('attendance:remind');

        $this->assertSame(1, AppNotification::where('user_id', $employee->user_id)->count());
    }

    public function test_sends_nothing_when_everyone_has_clocked_in(): void
    {
        $employee = $this->staff('Aina', 'aina@acme.test');
        AttendanceRecord::create([
            'employee_id' => $employee->id,
            'date' => '2026-07-23',
            'clock_in' => '08:55:00',
            'expected_end' => '18:00:00',
        ]);
        Carbon::setTestNow('2026-07-23 09:31:00');

        Artisan::call('attendance:remind');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_command_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'attendance:remind'));

        $this->assertCount(1, $events);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=AttendanceReminderCommandTest
```

Expected: FAIL with `The command "attendance:remind" does not exist.`

- [ ] **Step 3: Generate and write the command**

```bash
php artisan make:command AttendanceReminder --no-interaction
```

Replace the generated `app/Console/Commands/AttendanceReminder.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Attendance\ReminderTargets;
use App\Models\AppNotification;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

/**
 * Clock-in / clock-out nudges, run on a short cadence by the scheduler.
 *
 * For each tenant it bells anyone who is past their start time without a clock-in, and
 * anyone still clocked in past their end time. Tenant-aware like the leave/digest/timesheet
 * commands: the active tenant is set per loop so AppNotification rows land under the right
 * tenant scope, and the context is cleared at the end.
 *
 * Safe to run every 15 minutes: each bell carries a per-day dedupe key, so repeat ticks are
 * no-ops rather than a stack of duplicates.
 */
class AttendanceReminder extends Command
{
    protected $signature = 'attendance:remind';

    protected $description = 'Bell-notify staff who have not clocked in by their start time, or are still clocked in past their end time.';

    /** Slack allowed after the expected start / end before a nudge goes out. */
    private const GRACE_MINUTES = 30;

    private const IN_TITLE = 'Clock-in reminder';

    private const IN_BODY = 'You have not clocked in yet today. Open Attendance to clock in.';

    private const OUT_TITLE = 'Clock-out reminder';

    private const OUT_BODY = 'You are still clocked in. Remember to clock out when you leave.';

    public function handle(CurrentTenant $context, ReminderTargets $targets): int
    {
        $now = now();
        $day = $now->toDateString();
        $url = route('app.screen', 'attendance');
        $sent = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                foreach ($targets->missingClockIn($now, self::GRACE_MINUTES) as $employee) {
                    $sent += (int) AppNotification::send(
                        $employee->user_id,
                        self::IN_TITLE,
                        self::IN_BODY,
                        $url,
                        'attendance-in-'.$day,
                    );
                }

                foreach ($targets->missingClockOut($now, self::GRACE_MINUTES) as $record) {
                    $sent += (int) AppNotification::send(
                        $record->employee?->user_id,
                        self::OUT_TITLE,
                        self::OUT_BODY,
                        $url,
                        'attendance-out-'.$day,
                    );
                }
            } catch (\Throwable $e) {
                // Isolate per-tenant failures so one bad tenant does not silently skip
                // everyone after it (AK-REL-04). Log and carry on.
                report($e);
                $this->error("Attendance reminder failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Attendance reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Wire the scheduler**

In `bootstrap/app.php`, inside `->withSchedule(...)`, add after the `staff:archive-departed` entry:

```php
        // Clock nudges: every 15 minutes across the working day. Each bell carries a
        // per-day dedupe key, so the short cadence costs at most one notification per
        // staffer per type per day regardless of how many ticks fire.
        $schedule->command('attendance:remind')->everyFifteenMinutes()->between('6:00', '22:00')
            ->withoutOverlapping()->onFailure($onFailure('attendance:remind'));
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=AttendanceReminderCommandTest
```

Expected: PASS, 5 tests.

- [ ] **Step 6: Smoke-run against the dev database**

```bash
lerd artisan attendance:remind
```

Expected: `Attendance reminders sent: N.` with no exception. `N` may legitimately be 0.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/AttendanceReminder.php bootstrap/app.php tests/Feature/AttendanceReminderCommandTest.php
git commit -m "feat(attendance): schedule clock-in/clock-out reminder bells every 15 minutes

Staff forgetting to clock in or out had no prompt at all. The command nudges
anyone past their start time without a clock-in, and anyone still clocked in
past their end time, deduped once per staffer per type per day."
```

---

### Task 4: Unseen-notifications JSON endpoint

The browser needs something cheap to poll. The existing bell is rendered server-side on page load only.

**Files:**
- Modify: `app/Http/Controllers/NotificationController.php`
- Modify: `routes/web.php:245` (add the new route beside `notifications.read`)
- Test: `tests/Feature/UnseenNotificationsTest.php`

**Interfaces:**
- Consumes: `AppNotification` (tenant-scoped via `BelongsToTenant`).
- Produces: `GET /app/notifications/unseen?since=<int>`, route name `notifications.unseen`, JSON body
  `{"notifications": [{"id": int, "title": string, "body": string|null, "url": string|null}], "latestId": int}`.
  `notifications` holds at most 5 unread rows with `id > since`, newest first. `latestId` is the highest notification id for this user in this tenant regardless of read state — the client stores it as its next cursor.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/UnseenNotificationsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The poll endpoint that backs the browser notification. It must never leak another
 * user's bells, and must respect the client's cursor so a returning tab is not spammed.
 */
class UnseenNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->user = $this->member('Aina', 'aina@acme.test');
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function member(string $name, string $email): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return $user;
    }

    public function test_returns_unread_notifications_newer_than_the_cursor(): void
    {
        $old = AppNotification::create(['user_id' => $this->user->id, 'title' => 'Old']);
        $new = AppNotification::create(['user_id' => $this->user->id, 'title' => 'New', 'body' => 'Body', 'url' => '/app']);

        $response = $this->actingAs($this->user)->getJson(route('notifications.unseen', ['since' => $old->id]));

        $response->assertOk()
            ->assertJsonPath('latestId', $new->id)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $new->id)
            ->assertJsonPath('notifications.0.title', 'New')
            ->assertJsonPath('notifications.0.body', 'Body')
            ->assertJsonPath('notifications.0.url', '/app');
    }

    public function test_excludes_already_read_notifications(): void
    {
        AppNotification::create(['user_id' => $this->user->id, 'title' => 'Read', 'read_at' => now()]);

        $this->actingAs($this->user)->getJson(route('notifications.unseen'))
            ->assertOk()
            ->assertJsonCount(0, 'notifications');
    }

    public function test_never_returns_another_users_notifications(): void
    {
        $other = $this->member('Bala', 'bala@acme.test');
        AppNotification::create(['user_id' => $other->id, 'title' => 'Not yours']);

        $this->actingAs($this->user)->getJson(route('notifications.unseen'))
            ->assertOk()
            ->assertJsonCount(0, 'notifications')
            ->assertJsonPath('latestId', 0);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('notifications.unseen'))->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=UnseenNotificationsTest
```

Expected: FAIL with `Route [notifications.unseen] not defined.`

- [ ] **Step 3: Add the controller action**

In `app/Http/Controllers/NotificationController.php`, add the `Illuminate\Http\JsonResponse` import and this method below `markRead`:

```php
    /**
     * Poll target for the browser-notification client: unread bells newer than the
     * cursor the caller last saw, plus the current high-water id to store as the next
     * cursor. Capped at 5 so a long-idle tab raises a few alerts, not a burst.
     */
    public function unseen(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $tenantId = app(CurrentTenant::class)->id();
        $since = (int) $request->query('since', '0');

        $base = fn () => AppNotification::where('user_id', $userId)
            ->where('tenant_id', $tenantId);   // explicit, not just the global scope

        return response()->json([
            'notifications' => $base()
                ->whereNull('read_at')
                ->where('id', '>', $since)
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'title', 'body', 'url']),
            'latestId' => (int) ($base()->max('id') ?? 0),
        ]);
    }
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, directly below the existing `notifications.read` line (line 245):

```php
        Route::get('/app/notifications/unseen', [NotificationController::class, 'unseen'])->name('notifications.unseen');
```

Note it must sit **above** the catch-all `Route::get('/app/{screen?}', ...)` near line 426. It does, since line 245 comes first.

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=UnseenNotificationsTest
```

Expected: PASS, 4 tests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/NotificationController.php routes/web.php tests/Feature/UnseenNotificationsTest.php
git commit -m "feat(notifications): add unseen poll endpoint for browser alerts

The bell only rendered on page load, so an open tab never learned about new
notifications. Adds a cursor-based JSON poll capped at five rows so an idle tab
raises a few alerts instead of a burst."
```

---

### Task 5: PWA shell — manifest, icons, service worker

Two jobs in one shippable unit: make the app installable (the only route to notifications on iOS), and register the worker whose `showNotification()` Task 6 calls.

**Files:**
- Create: `resources/icons/app-icon.svg` (source art, not shipped to the browser)
- Create: `public/icons/icon-192.png`, `public/icons/icon-512.png`, `public/icons/icon-maskable-512.png`, `public/icons/apple-touch-icon.png`
- Create: `public/manifest.webmanifest`
- Create: `public/sw.js`
- Create: `resources/js/pwa.js`
- Modify: `resources/js/app.js`
- Modify: `resources/views/layouts/app.blade.php` (head, after the `@vite` line around line 8)
- Test: `tests/Feature/PwaManifestTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `registerServiceWorker(): void` exported from `resources/js/pwa.js`, called once from `app.js`. After it runs, `navigator.serviceWorker.ready` resolves to a registration with `showNotification()` available. The worker reads `notification.data.url` on click.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PwaManifestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The manifest and icons are static files served by nginx, so they are asserted on disk
 * rather than through the HTTP kernel. Installability is a hard prerequisite for
 * notifications on iOS, and a missing icon silently kills the install prompt.
 */
class PwaManifestTest extends TestCase
{
    public function test_manifest_declares_everything_an_install_prompt_needs(): void
    {
        $path = public_path('manifest.webmanifest');
        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Amanahku', $manifest['name']);
        $this->assertSame('Amanahku', $manifest['short_name']);
        $this->assertSame('/app', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['icons']);

        $sizes = array_column($manifest['icons'], 'sizes');
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
    }

    public function test_every_declared_icon_exists_on_disk(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), "Missing icon {$icon['src']}");
        }

        $this->assertFileExists(public_path('icons/apple-touch-icon.png'));
    }

    public function test_service_worker_handles_notification_clicks(): void
    {
        $path = public_path('sw.js');
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);
        $this->assertStringContainsString('notificationclick', $source);
        // A fetch listener must be declared or Chrome will not offer "Install app".
        $this->assertStringContainsString("addEventListener('fetch'", $source);
    }

    public function test_layout_links_the_manifest_and_apple_touch_icon(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('rel="manifest"', $layout);
        $this->assertStringContainsString('apple-touch-icon', $layout);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=PwaManifestTest
```

Expected: FAIL with `Failed asserting that file "…/public/manifest.webmanifest" exists.`

- [ ] **Step 3: Create the icon source**

Create `resources/icons/app-icon.svg`. Brand red is `#d6232b` and ink is `#26251e`, both from `resources/css/app.css`. This is a plain placeholder mark — swap in real brand art later without touching any other file.

```xml
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
  <rect width="512" height="512" rx="96" fill="#d6232b"/>
  <path d="M256 116 L372 396 H316 L256 248 L196 396 H140 Z" fill="#ffffff"/>
  <rect x="196" y="316" width="120" height="34" rx="17" fill="#ffffff"/>
</svg>
```

- [ ] **Step 4: Generate the PNG icons**

`magick` is already installed on this machine. The maskable icon needs padding so Android's mask does not clip the mark.

```bash
mkdir -p public/icons
magick -background none resources/icons/app-icon.svg -resize 192x192 public/icons/icon-192.png
magick -background none resources/icons/app-icon.svg -resize 512x512 public/icons/icon-512.png
magick -background none resources/icons/app-icon.svg -resize 410x410 -gravity center -background '#d6232b' -extent 512x512 public/icons/icon-maskable-512.png
magick -background '#d6232b' -alpha remove resources/icons/app-icon.svg -resize 180x180 public/icons/apple-touch-icon.png
```

Verify all four exist and are non-empty:

```bash
ls -l public/icons/
```

Expected: four PNG files, each well above 0 bytes.

- [ ] **Step 5: Create the manifest**

Create `public/manifest.webmanifest`:

```json
{
    "name": "Amanahku",
    "short_name": "Amanahku",
    "description": "Amanahku HR workspace",
    "start_url": "/app",
    "scope": "/",
    "display": "standalone",
    "background_color": "#ffffff",
    "theme_color": "#d6232b",
    "icons": [
        { "src": "/icons/icon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any" },
        { "src": "/icons/icon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any" },
        { "src": "/icons/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
    ]
}
```

- [ ] **Step 6: Create the service worker**

Create `public/sw.js`. It must live at the site root so its scope covers every screen.

```js
// Minimal service worker. It exists for exactly two reasons:
//   1. Installability — Chrome and Edge only offer "Install app" once a worker with a
//      fetch listener is registered, and installing is the ONLY way iOS will show a
//      notification from a web app at all.
//   2. Notifications — iOS has no `new Notification()` constructor, so every platform
//      goes through registration.showNotification() instead.
// It deliberately caches nothing: the app is server-rendered and must never serve stale
// HR data from a previous session.

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

// Declared but intentionally passive. Registering the handler satisfies the install
// criteria; not calling respondWith() lets every request go straight to the network.
self.addEventListener('fetch', () => {});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/app';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            const existing = clients.find((client) => 'focus' in client);
            if (!existing) {
                return self.clients.openWindow(url);
            }
            // navigate() can reject when the client is not controlled by this worker;
            // focusing the existing window is a good enough fallback.
            return Promise.resolve(existing.navigate(url))
                .catch(() => existing)
                .then(() => existing.focus());
        }),
    );
});
```

- [ ] **Step 7: Create the registration module**

Create `resources/js/pwa.js`:

```js
// Registers the root-scoped service worker. Kept separate from the notifier so the
// install/PWA concern has one owner; the notifier just awaits navigator.serviceWorker.ready.
export function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch((error) => {
            // Registration fails on insecure origins and in private windows. Not fatal:
            // the in-app bell keeps working, only the OS-level alert is lost.
            console.warn('Service worker registration failed', error);
        });
    });
}
```

- [ ] **Step 8: Wire it into the bundle**

In `resources/js/app.js`, add the import beside the others and the call beside the other `register*` calls:

```js
import { registerServiceWorker } from './pwa';
```

```js
registerServiceWorker();
```

Place the call **before** `Alpine.start();`.

- [ ] **Step 9: Link the manifest from the layout**

In `resources/views/layouts/app.blade.php`, immediately after the `@vite([...])` line (around line 8):

```blade
    {{-- Installable web app. On iOS this is the only route to notifications at all:
         Safari shows them just for a web app added to the Home Screen. --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#d6232b">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Amanahku">
```

- [ ] **Step 10: Run tests to verify they pass**

```bash
php artisan test --compact --filter=PwaManifestTest
```

Expected: PASS, 4 tests.

- [ ] **Step 11: Build and verify in the browser**

```bash
bun run build
```

Then open `http://localhost:9100/dev/login?email=employee@amanahku.test&tenant=unijaya` in the browser pane and confirm in the console that the worker registered:

```js
navigator.serviceWorker.getRegistration().then((r) => console.log('scope:', r && r.scope));
```

Expected: `scope: http://localhost:9100/`. Also check the console shows no service-worker errors.

- [ ] **Step 12: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/icons/app-icon.svg public/icons public/manifest.webmanifest public/sw.js resources/js/pwa.js resources/js/app.js resources/views/layouts/app.blade.php public/build tests/Feature/PwaManifestTest.php
git commit -m "feat(pwa): add manifest, icons and a minimal service worker

Makes the app installable and gives every platform one notification path. iOS has
no Notification constructor and only shows alerts for a web app added to the Home
Screen, so the worker's showNotification() is the only option that works anywhere.
The worker caches nothing so HR data is never served stale."
```

---

### Task 6: Browser notification client

**Files:**
- Create: `resources/js/notifier.js`
- Modify: `resources/js/app.js`
- Modify: `resources/views/partials/header.blade.php` (the bell block starting at line 160, and the dropdown header at lines 169-174)
- Test: `tests/Feature/NotifierMarkupTest.php`

**Interfaces:**
- Consumes: `GET /app/notifications/unseen?since=<int>` from Task 4; `navigator.serviceWorker.ready` from Task 5.
- Produces: an Alpine component registered as `notifier` via `registerNotifier(Alpine)` from `resources/js/notifier.js`. Exposes `permission` (string), `canAsk` (getter, boolean) and `enable()` (async) to the Blade markup.

- [ ] **Step 1: Write the failing test**

There is no JS test harness in this repo and adding one for sixty lines is not worth it, so the PHPUnit test covers the markup contract and the JS behaviour is verified in the browser at Step 6. Create `tests/Feature/NotifierMarkupTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The bell must carry the Alpine hooks the notifier binds to. A rename on either side
 * silently breaks browser alerts with no error anywhere, so the contract is pinned here.
 */
class NotifierMarkupTest extends TestCase
{
    public function test_bell_mounts_the_notifier_component(): void
    {
        $header = (string) file_get_contents(resource_path('views/partials/header.blade.php'));

        $this->assertStringContainsString('x-data="notifier"', $header);
        $this->assertStringContainsString('x-show="canAsk"', $header);
        $this->assertStringContainsString('@click="enable()"', $header);
    }

    public function test_notifier_module_is_registered_in_the_bundle(): void
    {
        $appJs = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("import { registerNotifier } from './notifier'", $appJs);
        $this->assertStringContainsString('registerNotifier(Alpine)', $appJs);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=NotifierMarkupTest
```

Expected: FAIL — the header has no `x-data="notifier"`.

- [ ] **Step 3: Write the notifier module**

Create `resources/js/notifier.js`:

```js
const POLL_MS = 60_000;
const CURSOR_KEY = 'amanahku:lastNotificationId';

/**
 * Raises OS-level notifications for new bells while the app is open.
 *
 * Scope is deliberate: this fires while a tab or installed window is running, including
 * when the browser is minimised. It does NOT fire with the browser closed — that needs
 * Web Push (VAPID keys, stored subscriptions, a server-side sender), which is not built.
 *
 * Alerts go through the service worker's showNotification() rather than `new Notification()`
 * because iOS has no such constructor; the worker path is the only one that works on every
 * platform.
 */
export function registerNotifier(Alpine) {
    Alpine.data('notifier', () => ({
        permission: 'Notification' in window ? Notification.permission : 'unsupported',
        timer: null,

        init() {
            if (this.permission === 'granted') {
                this.startPolling();
            }

            // Stop polling when the component goes away so a long session cannot stack
            // intervals across Alpine re-inits.
            this.$el.addEventListener('alpine:destroyed', () => this.stopPolling());
        },

        /** Only offer the opt-in when the browser has not already decided. */
        get canAsk() {
            return this.permission === 'default';
        },

        async enable() {
            // Browsers reject a permission request that is not driven by a click, so this
            // must stay wired to a real button and never run on page load.
            this.permission = await Notification.requestPermission();

            if (this.permission === 'granted') {
                this.startPolling();
            }
        },

        startPolling() {
            if (this.timer !== null) {
                return;
            }
            this.poll();
            // Background tabs are throttled to roughly one timer callback per minute, so a
            // shorter interval buys nothing once the window is hidden.
            this.timer = setInterval(() => this.poll(), POLL_MS);
        },

        stopPolling() {
            if (this.timer !== null) {
                clearInterval(this.timer);
                this.timer = null;
            }
        },

        async poll() {
            const cursor = localStorage.getItem(CURSOR_KEY);

            let payload;
            try {
                const response = await fetch(`/app/notifications/unseen?since=${cursor ?? '0'}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    return; // signed out, or the session expired: stay quiet
                }
                payload = await response.json();
            } catch {
                return; // offline: try again on the next tick
            }

            const firstRun = cursor === null;
            localStorage.setItem(CURSOR_KEY, String(payload.latestId));

            // A first run only records the starting point. Without this, a returning user
            // is hit with a burst of alerts for bells they already know about.
            if (firstRun) {
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            for (const notification of payload.notifications) {
                registration.showNotification(notification.title, {
                    body: notification.body ?? '',
                    icon: '/icons/icon-192.png',
                    badge: '/icons/icon-192.png',
                    tag: `amanahku-${notification.id}`,
                    data: { url: notification.url ?? '/app' },
                });
            }
        },
    }));
}
```

- [ ] **Step 4: Wire it into the bundle**

In `resources/js/app.js`, add beside the other imports and registrations:

```js
import { registerNotifier } from './notifier';
```

```js
registerNotifier(Alpine);
```

Keep the existing alphabetical-ish grouping: place the import after `registerMapPicker` and the call likewise.

- [ ] **Step 5: Mount it on the bell**

Alpine 3 allows only one `x-data` per element, so the bell's existing dropdown flag moves into the notifier component rather than sitting alongside it.

In `resources/js/notifier.js`, add `notif: false` as the first property of the returned object, directly above `permission`:

```js
        notif: false,
```

Then in `resources/views/partials/header.blade.php`, replace line 160:

```blade
    <div x-data="{ notif: false }" style="position:relative;">
```

with:

```blade
    <div x-data="notifier" style="position:relative;">
```

The existing `@click="notif = ! notif"` and `x-show="notif"` bindings keep working unchanged, because `notif` is now a property of the notifier component.

- [ ] **Step 6: Add the opt-in button**

In the same file, inside the dropdown header block (lines 169-174), add the button after the closing `@endif` of the "Mark all read" form, still inside the header `<div>`:

```blade
                {{-- Opt-in must be click-driven: browsers reject a permission request that
                     is not tied to a user gesture. Hidden once granted, denied, or unsupported. --}}
                <button type="button" x-show="canAsk" x-cloak @click="enable()"
                        style="font-size:12px;color:var(--red);background:none;"
                        x-text="$store.ui.lang==='en' ? 'Turn on alerts' : 'Hidupkan makluman'">Turn on alerts</button>
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
php artisan test --compact --filter=NotifierMarkupTest
```

Expected: PASS, 2 tests.

- [ ] **Step 8: Verify the real behaviour in the browser**

```bash
bun run build
```

Open `http://localhost:9100/dev/login?email=employee@amanahku.test&tenant=unijaya`, then:

1. Open the bell. The "Turn on alerts" button should be visible on a fresh profile. Click it and accept the browser prompt.
2. Clear the cursor so the next poll counts as a returning session rather than a first run, then seed a bell and force a poll:

```js
localStorage.setItem('amanahku:lastNotificationId', '0');
```

3. In a terminal, create a notification for that user:

```bash
lerd artisan tinker --execute 'App\Tenancy\CurrentTenant::class; $t = App\Models\Tenant::where("slug","unijaya")->first(); app(App\Tenancy\CurrentTenant::class)->set($t); $u = App\Models\User::where("email","employee@amanahku.test")->first(); App\Models\AppNotification::send($u->id, "Clock-out reminder", "You are still clocked in.", "/app/attendance");'
```

4. Wait up to 60 seconds, or force it immediately from the console:

```js
navigator.serviceWorker.ready.then((r) => r.showNotification('Probe', { body: 'worker path alive' }));
```

Expected: an OS notification appears. Minimise the browser and repeat step 3 to confirm it still fires while minimised. Check `read_console_messages` for errors.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/notifier.js resources/js/app.js resources/views/partials/header.blade.php public/build tests/Feature/NotifierMarkupTest.php
git commit -m "feat(notifications): raise OS alerts for new bells while the app is open

Polls the unseen endpoint every 60s and shows the result through the service
worker, so a minimised window still surfaces a clock reminder. Permission is
opt-in from a button in the bell dropdown because browsers reject requests that
are not click-driven."
```

---

### Task 7: iOS add-to-Home-Screen prompt

iOS shows no install prompt of its own and blocks notifications entirely until the app is on the Home Screen, so the instruction has to be given in the UI.

**Files:**
- Create: `resources/views/partials/ios-install.blade.php`
- Modify: `resources/views/layouts/app.blade.php` (inside `@unless ($embed)`, after `@include('partials.header')`)
- Test: `tests/Feature/IosInstallPromptTest.php`

**Interfaces:**
- Consumes: nothing. Self-contained Alpine markup.
- Produces: a dismissible banner. Dismissal is stored under the localStorage key `amanahku:iosInstallDismissed`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/IosInstallPromptTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The banner must only target iOS Safari outside standalone mode, and must be
 * dismissible — an undismissable nag on every page load is worse than no banner.
 */
class IosInstallPromptTest extends TestCase
{
    public function test_banner_targets_ios_outside_standalone_and_can_be_dismissed(): void
    {
        $partial = (string) file_get_contents(resource_path('views/partials/ios-install.blade.php'));

        $this->assertStringContainsString('navigator.standalone', $partial);
        $this->assertStringContainsString('amanahku:iosInstallDismissed', $partial);
        $this->assertStringContainsString('dismiss()', $partial);
    }

    public function test_layout_includes_the_banner(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("@include('partials.ios-install')", $layout);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=IosInstallPromptTest
```

Expected: FAIL — `resources/views/partials/ios-install.blade.php` does not exist.

- [ ] **Step 3: Write the partial**

Create `resources/views/partials/ios-install.blade.php`:

```blade
{{-- iOS shows no install prompt of its own, and Safari refuses notifications entirely
     until the app sits on the Home Screen. So the instruction has to be spelled out.
     Shown only on iPhone/iPad, only outside standalone mode, and only until dismissed. --}}
<div x-data="{
        show: false,
        init() {
            const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
            const installed = window.navigator.standalone === true;
            const dismissed = localStorage.getItem('amanahku:iosInstallDismissed') === '1';
            this.show = isIos && ! installed && ! dismissed;
        },
        dismiss() {
            localStorage.setItem('amanahku:iosInstallDismissed', '1');
            this.show = false;
        },
     }"
     x-show="show" x-cloak
     style="display:flex;align-items:flex-start;gap:12px;padding:12px 16px;background:var(--red-tint);border-bottom:1px solid var(--hairline);flex-shrink:0;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;"><path d="M12 16V4M8 8l4-4 4 4"></path><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"></path></svg>
    <div style="flex:1;min-width:0;">
        <div style="font-size:13px;font-weight:600;color:var(--ink);"
             x-text="$store.ui.lang==='en' ? 'Get clock reminders on this iPhone' : 'Dapatkan peringatan jam di iPhone ini'">Get clock reminders on this iPhone</div>
        <div style="font-size:12px;color:var(--body);margin-top:2px;line-height:1.45;"
             x-text="$store.ui.lang==='en'
                ? 'Tap Share, then Add to Home Screen. Reminders only work from the installed app on iPhone.'
                : 'Tekan Kongsi, kemudian Tambah ke Skrin Utama. Peringatan hanya berfungsi dari aplikasi yang dipasang di iPhone.'">Tap Share, then Add to Home Screen.</div>
    </div>
    <button type="button" @click="dismiss()"
            :aria-label="$store.ui.lang==='en' ? 'Dismiss' : 'Tutup'"
            style="background:none;padding:2px;flex-shrink:0;line-height:0;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"></path></svg>
    </button>
</div>
```

- [ ] **Step 4: Include it in the layout**

In `resources/views/layouts/app.blade.php`, inside the `@unless ($embed)` block, on the line directly after `@include('partials.header')`:

```blade
        @include('partials.ios-install')
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=IosInstallPromptTest
```

Expected: PASS, 2 tests.

- [ ] **Step 6: Verify it stays hidden on desktop**

```bash
bun run build
```

Open `http://localhost:9100/dev/login?email=employee@amanahku.test&tenant=unijaya` and confirm no banner appears (desktop user agent). Then force it on:

```js
document.querySelector('[x-data*="iosInstallDismissed"]').__x.$data.show = true;
```

Expected: the banner renders, and clicking the X hides it and writes `amanahku:iosInstallDismissed` to localStorage.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/partials/ios-install.blade.php resources/views/layouts/app.blade.php public/build tests/Feature/IosInstallPromptTest.php
git commit -m "feat(pwa): prompt iOS users to add Amanahku to the Home Screen

Safari shows no install prompt and blocks notifications for a browser tab, so
iPhone staff get no clock reminders until the app is installed. Dismissible and
scoped to iOS outside standalone mode."
```

---

### Task 8: Full-suite check and deploy notes

**Files:**
- Modify: none (verification only)

- [ ] **Step 1: Run the whole suite**

```bash
php artisan test --compact
```

Expected: PASS. If anything unrelated fails, check whether it failed before this branch with `git stash && php artisan test --compact` before assuming this work broke it.

- [ ] **Step 2: Run static analysis**

```bash
composer analyse
```

Expected: no new errors. The `?->user_id` accesses in `ReminderTargets` and `AttendanceReminder` are the likely complaints; fix them in place rather than adding baseline entries.

- [ ] **Step 3: Confirm the built assets are committed**

`public/build/manifest.json` must be in the tree or CSS and JS 404 on staging.

```bash
git status --short public/build
```

Expected: clean (already committed in Tasks 5-7).

- [ ] **Step 4: Confirm the hPanel cron before deploying**

This is a manual check, not a command. `attendance:remind` is scheduled `everyFifteenMinutes()`, which only holds if the hPanel cron runs `php artisan schedule:run` every minute. That cron is not visible over SSH. Ask the user to confirm the interval in hPanel. If it is hourly, either fix the cron or change the schedule to `hourly()` and widen `GRACE_MINUTES`, so the stated behaviour matches reality.

- [ ] **Step 5: Note the HTTPS requirement**

Service workers and the Notification API need a secure context. `http://localhost:9100` counts as secure, and staging is already HTTPS, so no change is needed. Record it so nobody moves the app to a plain-HTTP host later and wonders why alerts vanished.

---

## Self-Review

**Spec coverage**

| Requirement | Task |
|---|---|
| Remind staff who forgot to clock in | 2, 3 |
| Remind staff who forgot to clock out, 30 min after expected end | 2, 3 (`GRACE_MINUTES = 30`) |
| Notification while tab open, including minimised browser | 4, 6 |
| PWA install prompt | 5, 7 |
| iOS coverage | 5, 7, with the ceiling stated up front |
| No new dependencies | held throughout |
| Do not spam | Task 1 dedupe key, Task 2 leave/holiday/weekend skips, Task 6 first-run cursor |

**Type consistency**

`AppNotification::send` returns `bool` from Task 1 and is consumed as `(int)` in Task 3. `ReminderTargets::missingClockIn` returns `Collection<int, Employee>` and Task 3 reads `$employee->user_id`; `missingClockOut` returns `Collection<int, AttendanceRecord>` and Task 3 reads `$record->employee?->user_id`, which the eager `with('employee')` in Task 2 supports. `registerNotifier` and `registerServiceWorker` names match between their modules and `app.js`. The localStorage keys `amanahku:lastNotificationId` (Task 6) and `amanahku:iosInstallDismissed` (Task 7) are distinct.

**Open judgement call left to the implementer**

Task 6 Step 5 folds `notif: false` into the notifier component because Alpine 3 allows one `x-data` per element. If the header later needs the bell state kept separate, nest a `<div x-data="{ notif: false }">` inside the notifier element rather than trying to merge two scopes on one node.
