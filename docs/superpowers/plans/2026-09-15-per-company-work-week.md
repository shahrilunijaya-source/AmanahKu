# Per-company work week Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each company picks its own working days (Mon..Sun). Every place that hardcodes "Mon-Fri plus Unijaya's first-Saturday TOT half day" reads the tenant instead, so a new company that works Tue-Sat (or Sat-Sun) gets correct leave counts, timesheet capacity, attendance reports and reminders. Unijaya keeps its TOT rule through a hidden `tot_saturday` flag.

**Architecture:** Two new columns on `tenants` (`work_days`, `tot_saturday`) read by one helper, `App\Support\WorkWeek`. The helper answers three questions per date (working day? TOT half day? capacity 0/50/100) and nothing else; public holidays stay with the callers that already check them. The seven existing central classes and six inline `isWeekend()` copies route through it. `DayCapacity::isFirstSaturday()` stays as a pure calendar function (WorkWeek reuses it); `DayCapacity::for()` keeps its meaning of "how full must a day with entries be" (50 on a TOT day, 100 otherwise, so the "Show weekend" toggle keeps letting a staffer log a plain Saturday) and only its TOT test becomes tenant-aware. The timesheet capture JS mirrors the rule from two config values. Company Settings gets a Work week card; Launch Center gets a manual step pointing at it.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12 (sqlite in-memory), Alpine 3, Blade with inline styles and `uj-*` classes, bilingual copy via `$store.ui.lang`, bun for the JS test.

**Spec:** `docs/superpowers/specs/2026-09-15-self-serve-company-signup-design.md`, section "Change 2: per-company work week" (lines 48-70) and the "Work week" block under "Edge cases and decisions" (lines 110-116). Mockup screen 3 in `docs/superpowers/mockups/2026-09-15-self-serve-signup/index.html` lines 135-160.

**Global Constraints:**
- `tests/Acceptance/*` is input and must not be edited. They build their tenant with `Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC'])` (`tests/Acceptance/AlwaysChecks.php:30-33`) and none of them asserts the TOT half day (CR14aTest only checks a Sunday publish rolls to Monday at line 189; CR09Test's "first Saturday" is the TOT *sessions* module, which never touches DayCapacity). They stay green with `tot_saturday = false`.
- **Factory decision:** there is no `TenantFactory` in this repo (`database/factories/` holds Flower, GreetingLine, User only) and no test uses one. Do not create one. The migration column defaults (`work_days = [1,2,3,4,5]`, `tot_saturday = false`) are the test defaults. Feature and unit tests that assert Unijaya's TOT half day pass `'tot_saturday' => true` to their own `Tenant::create` (Task 3 lists them).
- Week boundaries stay: Monday start, Friday 19:00 deadline, meeting day, the Friday sign-off window in `DashboardWidgets::fridaySignOffOpen()` (lines 193-201) and `fridayWeekOf()` (208-211). The spec lists `DashboardWidgets` among the inline copies but those two are the only weekday code in the file and they are calendar conventions, so `DashboardWidgets` is not edited.
- `Support\Awards::workingDaysBetween()` (line 563) already calls `$this->dayRules->isWorkingDay()`; it is covered by the DayRules change and is not edited.
- Changing work days is forward-only. No stored leave `days`, submitted week or attendance report is recalculated. The card says so.
- Migration filenames are already ahead of the calendar (latest is `2026_09_29_100200_...`); the new one must sort after it.
- Format PHP with `vendor/bin/pint --dirty --format agent` before each commit. Blade/JS changed in Tasks 5 and 6: run `lerd artisan view:clear && lerd artisan view:cache && bun run build` and commit `public/build` with those tasks.
- Commit on `dev` directly (no feature branch).

---

### Task 1: Columns, casts and the WorkWeek helper

**Files:**
- Create: `database/migrations/2026_09_29_100300_add_work_week_to_tenants.php`
- Create: `app/Support/WorkWeek.php`
- Modify: `app/Models/Tenant.php` lines 17-25 (casts)
- Test: `tests/Unit/WorkWeekTest.php`

**Interfaces:**
- Consumes: `App\Tenancy\CurrentTenant::get(): ?Tenant`, `App\Timesheet\DayCapacity::isFirstSaturday(CarbonInterface|string): bool`.
- Produces:
  - `WorkWeek::__construct(private readonly Tenant $tenant)`
  - `WorkWeek::for(?Tenant $tenant = null): self`
  - `WorkWeek::workingDays(): array` (list<int>, ISO 1..7, ascending, TOT excluded)
  - `WorkWeek::isWorkingDay(CarbonInterface $day): bool`
  - `WorkWeek::isTotDay(CarbonInterface $day): bool`
  - `WorkWeek::totSaturday(): bool`
  - `WorkWeek::capacity(CarbonInterface $day): int` (100 / 50 / 0)
  - `WorkWeek::dayFraction(CarbonInterface $day): float` (1.0 / 0.5 / 0.0)
  - `Tenant::$work_days` cast `array`, `Tenant::$tot_saturday` cast `boolean`.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/WorkWeekTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Support\WorkWeek;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-company working week. August 2026: Sat 1 Aug is the first Saturday of the
 * month (the TOT day when the flag is on), Sat 8 Aug an ordinary Saturday, Sun 2 Aug a
 * Sunday, Mon 3 Aug a Monday.
 */
class WorkWeekTest extends TestCase
{
    use RefreshDatabase;

    private const TOT_SATURDAY = '2026-08-01';

    private const PLAIN_SATURDAY = '2026-08-08';

    private const SUNDAY = '2026-08-02';

    private const MONDAY = '2026-08-03';

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function day(string $iso): CarbonImmutable
    {
        return CarbonImmutable::parse($iso);
    }

    public function test_a_new_tenant_defaults_to_monday_to_friday_without_tot(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC'])->fresh();

        $this->assertSame([1, 2, 3, 4, 5], $tenant->work_days);
        $this->assertFalse($tenant->tot_saturday);

        $week = WorkWeek::for($tenant);
        $this->assertSame([1, 2, 3, 4, 5], $week->workingDays());
        $this->assertTrue($week->isWorkingDay($this->day(self::MONDAY)));
        $this->assertFalse($week->isWorkingDay($this->day(self::TOT_SATURDAY)));
        $this->assertFalse($week->isWorkingDay($this->day(self::SUNDAY)));
        $this->assertFalse($week->isTotDay($this->day(self::TOT_SATURDAY)));
    }

    public function test_a_six_day_week_works_every_saturday_at_full_capacity(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'work_days' => [1, 2, 3, 4, 5, 6]]);
        $week = WorkWeek::for($tenant);

        $this->assertSame([1, 2, 3, 4, 5, 6], $week->workingDays());
        $this->assertTrue($week->isWorkingDay($this->day(self::TOT_SATURDAY)));
        $this->assertTrue($week->isWorkingDay($this->day(self::PLAIN_SATURDAY)));
        $this->assertFalse($week->isWorkingDay($this->day(self::SUNDAY)));
        $this->assertSame(100, $week->capacity($this->day(self::PLAIN_SATURDAY)));
        $this->assertSame(0, $week->capacity($this->day(self::SUNDAY)));
    }

    public function test_tot_on_makes_only_the_first_saturday_a_half_day(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'tot_saturday' => true]);
        $week = WorkWeek::for($tenant);

        $this->assertTrue($week->totSaturday());
        $this->assertTrue($week->isTotDay($this->day(self::TOT_SATURDAY)));
        $this->assertTrue($week->isWorkingDay($this->day(self::TOT_SATURDAY)));
        $this->assertFalse($week->isTotDay($this->day(self::PLAIN_SATURDAY)));
        $this->assertFalse($week->isWorkingDay($this->day(self::PLAIN_SATURDAY)));
        $this->assertSame([1, 2, 3, 4, 5], $week->workingDays(), 'TOT is a half day, never a listed work day');
    }

    public function test_tot_off_leaves_the_first_saturday_a_day_off(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'tot_saturday' => false]);
        $week = WorkWeek::for($tenant);

        $this->assertFalse($week->isTotDay($this->day(self::TOT_SATURDAY)));
        $this->assertSame(0, $week->capacity($this->day(self::TOT_SATURDAY)));
        $this->assertSame(0.0, $week->dayFraction($this->day(self::TOT_SATURDAY)));
    }

    public function test_saturday_as_a_full_work_day_beats_the_tot_half_day(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'work_days' => [1, 2, 3, 4, 5, 6], 'tot_saturday' => true]);
        $week = WorkWeek::for($tenant);

        $this->assertFalse($week->isTotDay($this->day(self::TOT_SATURDAY)));
        $this->assertSame(100, $week->capacity($this->day(self::TOT_SATURDAY)));
        $this->assertSame(1.0, $week->dayFraction($this->day(self::TOT_SATURDAY)));
    }

    public function test_capacity_and_fraction_values(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'tot_saturday' => true]);
        $week = WorkWeek::for($tenant);

        $this->assertSame(100, $week->capacity($this->day(self::MONDAY)));
        $this->assertSame(50, $week->capacity($this->day(self::TOT_SATURDAY)));
        $this->assertSame(0, $week->capacity($this->day(self::PLAIN_SATURDAY)));
        $this->assertSame(0, $week->capacity($this->day(self::SUNDAY)));

        $this->assertSame(1.0, $week->dayFraction($this->day(self::MONDAY)));
        $this->assertSame(0.5, $week->dayFraction($this->day(self::TOT_SATURDAY)));
        $this->assertSame(0.0, $week->dayFraction($this->day(self::PLAIN_SATURDAY)));
    }

    public function test_for_without_an_argument_reads_the_current_tenant(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'work_days' => [2, 3, 4, 5, 6]]);
        app(CurrentTenant::class)->set($tenant);

        $this->assertSame([2, 3, 4, 5, 6], WorkWeek::for()->workingDays());
        $this->assertFalse(WorkWeek::for()->isWorkingDay($this->day(self::MONDAY)));
    }

    public function test_for_without_a_bound_tenant_falls_back_to_monday_to_friday(): void
    {
        app(CurrentTenant::class)->set(null);

        $week = WorkWeek::for();

        $this->assertSame([1, 2, 3, 4, 5], $week->workingDays());
        $this->assertFalse($week->totSaturday());
        $this->assertTrue($week->isWorkingDay($this->day(self::MONDAY)));
        $this->assertFalse($week->isWorkingDay($this->day(self::TOT_SATURDAY)));
    }

    public function test_working_days_are_sorted_deduplicated_integers(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'work_days' => ['5', 1, 5, '3']]);

        $this->assertSame([1, 3, 5], WorkWeek::for($tenant)->workingDays());
    }
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `php artisan test --compact tests/Unit/WorkWeekTest.php`
Expected: errors (class `App\Support\WorkWeek` not found, unknown column `work_days`).

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_29_100300_add_work_week_to_tenants.php` (timestamp sorts after the existing `2026_09_29_100200_create_employee_work_site_table.php`; today's date would sort before it and never run on a DB that already has that one):

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company working week (docs/superpowers/specs/2026-09-15-self-serve-company-signup-design.md,
 * Change 2). `work_days` is a JSON list of ISO weekdays (1 = Monday .. 7 = Sunday) read through
 * App\Support\WorkWeek. It is stored in a varchar rather than a JSON column because MySQL refuses
 * a literal DEFAULT on JSON columns and the Eloquent 'array' cast reads either.
 *
 * `tot_saturday` is Unijaya's first-Saturday-of-the-month half day. It has no UI: this data step
 * turns it on for the Unijaya tenant (the seeded slug and the production dump's slug) and nothing
 * else ever sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('work_days', 32)->default('[1,2,3,4,5]')->after('late_grace_minutes');
            $table->boolean('tot_saturday')->default(false)->after('work_days');
        });

        DB::table('tenants')
            ->whereIn('slug', ['unijaya', 'unijaya-resources-sdn-bhd'])
            ->update(['tot_saturday' => true]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['work_days', 'tot_saturday']);
        });
    }
};
```

- [ ] **Step 4: Add the casts**

Edit `app/Models/Tenant.php` lines 17-25.

Before:
```php
    protected function casts(): array
    {
        return [
            'subscription_start' => 'date',
            'subscription_end' => 'date',
            'onboarding_enforced' => 'boolean',
            'late_grace_minutes' => 'integer',
        ];
    }
```

After:
```php
    protected function casts(): array
    {
        return [
            'subscription_start' => 'date',
            'subscription_end' => 'date',
            'onboarding_enforced' => 'boolean',
            'late_grace_minutes' => 'integer',
            'work_days' => 'array',
            'tot_saturday' => 'boolean',
        ];
    }
```

- [ ] **Step 5: Write the helper**

Create `app/Support/WorkWeek.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Timesheet\DayCapacity;
use Carbon\CarbonInterface;

/**
 * The company's working week: which ISO weekdays (1 = Monday .. 7 = Sunday) are working
 * days, plus Unijaya's TOT rule (the first Saturday of the month is a half day) on tenants
 * with `tot_saturday` set. A Saturday listed in `work_days` is a full day and outranks TOT.
 *
 * Public holidays are not this class's business: every caller that already checks the
 * holiday table keeps doing so after asking here, so a holiday on a non-work day changes
 * nothing.
 *
 * Console and queue paths sometimes run with no tenant bound; for(null) then falls back to
 * Monday to Friday with no TOT so nothing throws.
 */
final class WorkWeek
{
    /** @var list<int> */
    public const DEFAULT_DAYS = [1, 2, 3, 4, 5];

    public function __construct(private readonly Tenant $tenant) {}

    public static function for(?Tenant $tenant = null): self
    {
        $tenant ??= app(CurrentTenant::class)->get()
            ?? new Tenant(['work_days' => self::DEFAULT_DAYS, 'tot_saturday' => false]);

        return new self($tenant);
    }

    /**
     * The listed working days, ascending. A tenant row created in memory before the DB
     * default is read back has null here, hence the fallback.
     *
     * @return list<int>
     */
    public function workingDays(): array
    {
        $days = array_map('intval', (array) ($this->tenant->work_days ?? self::DEFAULT_DAYS));

        sort($days);

        return array_values(array_unique($days));
    }

    public function totSaturday(): bool
    {
        return (bool) $this->tenant->tot_saturday;
    }

    /** A listed work day, or the TOT half day. Holidays are the caller's job. */
    public function isWorkingDay(CarbonInterface $day): bool
    {
        return $this->isListed($day) || $this->isTotDay($day);
    }

    /** The TOT half day: flag on, first Saturday of the month, and Saturday not already a full work day. */
    public function isTotDay(CarbonInterface $day): bool
    {
        return $this->totSaturday()
            && DayCapacity::isFirstSaturday($day)
            && ! in_array(6, $this->workingDays(), true);
    }

    /** 100 on a listed work day, 50 on the TOT half day, 0 on a day off. */
    public function capacity(CarbonInterface $day): int
    {
        if ($this->isListed($day)) {
            return 100;
        }

        return $this->isTotDay($day) ? 50 : 0;
    }

    /** The share of a leave day this date costs: 1.0, 0.5 or 0.0. */
    public function dayFraction(CarbonInterface $day): float
    {
        return $this->capacity($day) / 100;
    }

    private function isListed(CarbonInterface $day): bool
    {
        return in_array((int) $day->dayOfWeekIso, $this->workingDays(), true);
    }
}
```

- [ ] **Step 6: Run the unit test, expect green**

Run: `php artisan test --compact tests/Unit/WorkWeekTest.php`
Expected: 9 passed.

- [ ] **Step 7: Migrate the dev DB and check Unijaya's flag**

Run: `lerd artisan migrate`
Then: `lerd db:shell` and `select slug, work_days, tot_saturday from tenants;`
Expected: `unijaya-resources-sdn-bhd` has `[1,2,3,4,5]` and `tot_saturday = 1`; `acme` has `tot_saturday = 0`.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_29_100300_add_work_week_to_tenants.php app/Support/WorkWeek.php app/Models/Tenant.php tests/Unit/WorkWeekTest.php
git commit -m "feat(work-week): tenants.work_days and tot_saturday columns with the WorkWeek helper

Per-company working days (ISO 1..7) plus Unijaya's hidden TOT flag, read through
one helper so the Mon-Fri hardcodes can be routed through it next. The migration
turns TOT on for the Unijaya tenant only."
```

---

### Task 2: Route the timesheet core through WorkWeek

**Files:**
- Modify: `app/Timesheet/DayCapacity.php` lines 10-38
- Modify: `app/Timesheet/DayRules.php` lines 11-38, 73-93
- Modify: `app/Timesheet/LockedDays.php` lines 44, 103, 154-174, 184-195
- Modify: `app/Timesheet/WeekWriter.php` lines 172, 190
- Modify: `app/Timesheet/BoardSuggestions.php` lines 45, 85-94
- Modify: `app/Timesheet/TimesheetCompliance.php` line 118
- Modify: `app/Models/Timesheet.php` lines 132-144
- Modify: `app/Models/LeaveRequest.php` lines 6, 69-96
- Modify: `app/Attendance/ReportPeriod.php` lines 165-186
- Test: `tests/Feature/WorkWeekBehaviourTest.php`

**Interfaces:**
- Consumes: `WorkWeek::for()`, `WorkWeek::isWorkingDay()`, `WorkWeek::isTotDay()`, `WorkWeek::dayFraction()`, `WorkWeek::workingDays()`.
- Produces (unchanged signatures, tenant-aware behaviour): `DayCapacity::for(CarbonInterface|string): float`, `DayRules::isWorkingDay(CarbonInterface): bool`, `DayRules::weekWorkingDays(CarbonInterface|string): array`, `LockedDays::forWeek()/forWeekMany()`, `LeaveRequest::countDays(Carbon, Carbon): float`, `ReportPeriod::workingDays(array): array`, `Timesheet::computeWeekEndsOn(CarbonInterface): Carbon`.
- New behaviour: `WeekWriter::save(..., submitNow: true)` on a week with nothing fillable returns normally instead of aborting 422.

- [ ] **Step 1: Write the failing behaviour test**

Create `tests/Feature/WorkWeekBehaviourTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Support\WorkWeek;
use App\Tenancy\CurrentTenant;
use App\Timesheet\DayCapacity;
use App\Timesheet\DayRules;
use App\Timesheet\LockedDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The work-day hardcodes read the tenant now. Week under test: Mon 2026-07-27 to Sun
 * 2026-08-02; Sat 2026-08-01 is the first Saturday of August (Unijaya's TOT day). The
 * second week, Mon 2026-08-03 to Sun 2026-08-09, is used for the zero-capacity case.
 */
class WorkWeekBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $staff;

    private TimesheetCategory $work;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $tenantAttrs */
    private function company(array $tenantAttrs): void
    {
        $this->tenant = Tenant::create(array_merge(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC'], $tenantAttrs));
        app(CurrentTenant::class)->set($this->tenant);
        $this->work = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false]);

        $user = User::create(['name' => 'Staffer', 'email' => 'staffer@example.com', 'password' => Hash::make('<redacted>')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->staff = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Staffer', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_without_tot_the_first_saturday_is_a_day_off_for_leave_and_timesheets(): void
    {
        $this->company(['tot_saturday' => false]);

        $this->assertSame(0.0, LeaveRequest::countDays(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01')));
        $this->assertSame(0, WorkWeek::for()->capacity(Carbon::parse('2026-08-01')));
        $this->assertSame(
            ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31'],
            (new DayRules)->weekWorkingDays('2026-07-27'),
        );
        $this->assertFalse((new DayRules)->isWorkingDay(Carbon::parse('2026-08-01')));

        // A holiday on that Saturday locks nothing: the week never asked for it.
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuti Peristiwa', 'date' => '2026-08-01']);
        $this->assertSame([], app(LockedDays::class)->forWeek($this->staff, '2026-07-27'));
    }

    public function test_with_tot_the_first_saturday_is_a_half_day(): void
    {
        $this->company(['tot_saturday' => true]);

        $this->assertSame(0.5, LeaveRequest::countDays(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01')));
        $this->assertSame(50, WorkWeek::for()->capacity(Carbon::parse('2026-08-01')));
        $this->assertEqualsWithDelta(50.0, DayCapacity::for('2026-08-01'), 0.001);
        $this->assertContains('2026-08-01', (new DayRules)->weekWorkingDays('2026-07-27'));
    }

    public function test_saturday_in_work_days_is_a_full_day_even_with_tot_on(): void
    {
        $this->company(['work_days' => [1, 2, 3, 4, 5, 6], 'tot_saturday' => true]);

        $this->assertSame(100, WorkWeek::for()->capacity(Carbon::parse('2026-08-01')));
        $this->assertEqualsWithDelta(100.0, DayCapacity::for('2026-08-01'), 0.001);
        $this->assertSame(1.0, LeaveRequest::countDays(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01')));
        // Every Saturday, not just the first.
        $this->assertSame(1.0, LeaveRequest::countDays(Carbon::parse('2026-08-08'), Carbon::parse('2026-08-08')));
        $this->assertSame(
            ['2026-07-27', '2026-07-28', '2026-07-29', '2026-07-30', '2026-07-31', '2026-08-01'],
            (new DayRules)->weekWorkingDays('2026-07-27'),
        );
    }

    public function test_a_sunday_working_company_reaches_sunday_in_the_week(): void
    {
        $this->company(['work_days' => [6, 7]]);

        $this->assertSame(['2026-08-01', '2026-08-02'], (new DayRules)->weekWorkingDays('2026-07-27'));

        // A holiday on the Sunday locks that Sunday (the week runs to day 7 now).
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuti', 'date' => '2026-08-02']);
        $locked = app(LockedDays::class)->forWeek($this->staff, '2026-07-27');
        $this->assertSame(['2026-08-02'], array_keys($locked));
        $this->assertEqualsWithDelta(100.0, $locked['2026-08-02']['percentage'], 0.001);
    }

    public function test_a_week_with_nothing_to_fill_submits_without_an_error(): void
    {
        $this->company(['work_days' => [6, 7]]);
        Carbon::setTestNow('2026-08-10 09:00:00');
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuti 1', 'date' => '2026-08-08']);
        PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuti 2', 'date' => '2026-08-09']);

        $this->postJson('/app/timesheets', [
            'week_start' => '2026-08-03',
            'entries' => [],
            'submit_now' => 1,
        ])->assertStatus(302);
    }

    public function test_week_ends_on_the_last_working_day(): void
    {
        $this->company(['work_days' => [1, 2, 3, 4, 5, 6, 7]]);
        $this->assertSame('2026-08-02', \App\Models\Timesheet::computeWeekEndsOn(Carbon::parse('2026-07-27'))->toDateString());

        app(CurrentTenant::class)->set(Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BT', 'tot_saturday' => true]));
        $this->assertSame('2026-08-01', \App\Models\Timesheet::computeWeekEndsOn(Carbon::parse('2026-07-27'))->toDateString());
        $this->assertSame('2026-08-07', \App\Models\Timesheet::computeWeekEndsOn(Carbon::parse('2026-08-03'))->toDateString());
    }
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `php artisan test --compact tests/Feature/WorkWeekBehaviourTest.php`
Expected: the "without tot" test fails on `countDays` (0.5 instead of 0.0) and `weekWorkingDays` (includes 2026-08-01); the Sunday test fails (weekWorkingDays stops at Saturday); the nothing-to-fill test gets 422.

- [ ] **Step 3: DayCapacity::for reads the tenant's TOT flag**

Edit `app/Timesheet/DayCapacity.php`. `isFirstSaturday()` stays exactly as is (WorkWeek reuses it).

Before (lines 10-20 and 34-38):
```php
/**
 * How much of a timesheet day is fillable, as a percentage.
 *
 * Unijaya's working week is Mon–Fri plus the first Saturday of every month, which is the
 * TOT day and runs as a half day. That Saturday therefore asks for 50%, not 100%: the
 * submit gate, the capture screen's day dots and the generated holiday / leave rows all
 * measure against this, so "full" means 50% there and 100% everywhere else.
 *
 * Ordinary Saturdays are left at 100% — the capture screen's "Show weekend" toggle has
 * always let a staffer log a full Saturday, and nothing here changes that.
 */
```
```php
    /** The percentage $date must reach to count as full. */
    public static function for(CarbonInterface|string $date): float
    {
        return self::isFirstSaturday($date) ? self::FIRST_SATURDAY_PERCENT : 100.0;
    }
```

After:
```php
/**
 * How full a timesheet day must be to count as complete, as a percentage.
 *
 * On a tenant with `tot_saturday` (Unijaya) the first Saturday of every month is the TOT
 * day and runs as a half day, so it asks for 50%, not 100%: the submit gate, the capture
 * screen's day dots and the generated holiday / leave rows all measure against this.
 * Whether the flag is on, and whether Saturday is a full work day instead, is
 * App\Support\WorkWeek's call.
 *
 * Days off are left at 100% — the capture screen's "Show weekend" toggle has always let
 * a staffer log a full Saturday, and nothing here changes that. Whether a day is asked
 * for at all is WorkWeek::capacity(), not this.
 */
```
```php
    /** The percentage $date must reach to count as full. */
    public static function for(CarbonInterface|string $date): float
    {
        return WorkWeek::for()->isTotDay(CarbonImmutable::parse($date)) ? self::FIRST_SATURDAY_PERCENT : 100.0;
    }
```
Add `use App\Support\WorkWeek;` to the imports (after `namespace App\Timesheet;`, before `use Carbon\CarbonImmutable;`).

- [ ] **Step 4: DayRules asks WorkWeek**

Edit `app/Timesheet/DayRules.php`.

Before (lines 24-38):
```php
    /** True when $day is a working day: Mon-Fri or the TOT Saturday, and not a public holiday. */
    public function isWorkingDay(CarbonInterface $day): bool
    {
        $day = CarbonImmutable::parse($day);

        if ($day->isSunday()) {
            return false;
        }

        if ($day->isSaturday() && ! DayCapacity::isFirstSaturday($day)) {
            return false;
        }

        return ! $this->isHoliday($day);
    }
```
After:
```php
    /** True when $day is one of the tenant's working days (or its TOT Saturday) and not a public holiday. */
    public function isWorkingDay(CarbonInterface $day): bool
    {
        $day = CarbonImmutable::parse($day);

        return WorkWeek::for()->isWorkingDay($day) && ! $this->isHoliday($day);
    }
```

Before (lines 73-93):
```php
    /**
     * Structural working days of the week starting $weekStart: Mon-Fri plus the TOT
     * Saturday, holidays included (a holiday is excluded later by the "fully locked"
     * check, not here — this is the same day set LockedDays::workingDays() walks).
     *
     * @return list<string> ISO dates
     */
    public function weekWorkingDays(CarbonInterface|string $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();

        $days = [];
        for ($i = 0; $i < 6; $i++) {
            $day = $start->addDays($i);
            if ($i < 5 || DayCapacity::isFirstSaturday($day)) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }
```
After:
```php
    /**
     * Structural working days of the week starting $weekStart: the tenant's work days plus
     * its TOT Saturday, holidays included (a holiday is excluded later by the "fully locked"
     * check, not here — this is the same day set LockedDays::workingDays() walks).
     *
     * @return list<string> ISO dates
     */
    public function weekWorkingDays(CarbonInterface|string $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $workWeek = WorkWeek::for();

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $start->addDays($i);
            if ($workWeek->isWorkingDay($day)) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }
```
Update the class docblock lines 16-20 to: `"Working day" here is the tenant's work week (App\Support\WorkWeek: its listed days plus the TOT half day where that flag is on), minus the active tenant's public holidays — ...` keeping the rest of the sentence. Add `use App\Support\WorkWeek;` after `use App\Models\PublicHoliday;`.

- [ ] **Step 5: LockedDays walks the tenant's days and reaches Sunday**

Edit `app/Timesheet/LockedDays.php`.

Line 44 and line 103, before: `        $end = $start->addDays(5);` After (both): `        $end = $start->addDays(6);`

Before (lines 154-174):
```php
    /**
     * The days of the week a staffer can log against: Mon–Fri, plus the first Saturday of
     * the month (the TOT half day). Ordinary Saturdays and Sunday are not locked here —
     * nothing generates rows for a day the week does not ask them to fill.
     *
     * @return array<int, CarbonImmutable>
     */
    private function workingDays(CarbonImmutable $weekStart): array
    {
        $days = [];

        for ($i = 0; $i < 6; $i++) {
            $day = $weekStart->addDays($i);

            if ($i < 5 || DayCapacity::isFirstSaturday($day)) {
                $days[] = $day;
            }
        }

        return $days;
    }
```
After:
```php
    /**
     * The days of the week a staffer can log against: the tenant's work days, plus its TOT
     * Saturday where that flag is on. Days off are not locked here — nothing generates
     * rows for a day the week does not ask them to fill.
     *
     * @return array<int, CarbonImmutable>
     */
    private function workingDays(CarbonImmutable $weekStart): array
    {
        $workWeek = WorkWeek::for();
        $days = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->addDays($i);

            if ($workWeek->isWorkingDay($day)) {
                $days[] = $day;
            }
        }

        return $days;
    }
```

Line 187, before: `        $halves = $leave->isHalfDay() && ! DayCapacity::isFirstSaturday($day);` After: `        $halves = $leave->isHalfDay() && ! WorkWeek::for()->isTotDay($day);`

Add `use App\Support\WorkWeek;` after `use App\Models\TimesheetCategory;`. Update the class docblock line 19-20 `(100%, or 50% on the first Saturday of the month — Unijaya's TOT half day, see DayCapacity)` to `(100%, or 50% on the tenant's TOT half day, see DayCapacity)`.

- [ ] **Step 6: WeekWriter reaches Sunday and treats an unfillable week as nothing to do**

Edit `app/Timesheet/WeekWriter.php`.

Line 172, before: `            $weekEnd = $weekStartCarbon->copy()->addDays(5);` After: `            $weekEnd = $weekStartCarbon->copy()->addDays(6);`

Line 190, before:
```php
            abort_if($candidates === [], 422, 'Nothing left to submit this week.');
```
After:
```php
            // A week the tenant never asked anyone to fill (no work days, or every one of
            // them a holiday / whole-day leave) is not an error: submit_now simply has no
            // days to mark. Only a week that HAD fillable days and has none left is refused.
            $fillable = array_filter(
                $this->dayRules->weekWorkingDays($weekStartCarbon),
                fn (string $iso) => ($locked[$iso]['percentage'] ?? 0) < DayCapacity::for($iso),
            );
            abort_if($candidates === [] && $fillable !== [], 422, 'Nothing left to submit this week.');
```
The `foreach ($candidates ...)` loops below (lines 193 and 208) run zero times when `$candidates === []`, so `$daysToSubmit` stays empty and the save completes normally.

- [ ] **Step 7: BoardSuggestions**

Edit `app/Timesheet/BoardSuggestions.php`.

Line 45, before: `        $end = $start->addDays(5);` After: `        $end = $start->addDays(6);`

Before (lines 85-94):
```php
                // The capture grid renders Monday to Friday, plus the first Saturday of
                // the month (Unijaya's TOT half day) — see timesheet-capture.js's days
                // count. A stint running across a weekend must not propose a row for a
                // day that has no column to put it in. DayCapacity::for() cannot answer
                // this: it returns 100.0 for a plain Saturday, since it is asking how
                // full a day must be, not whether the day is worked.
                if ($day->isSunday() || ($day->isSaturday() && ! DayCapacity::isFirstSaturday($day))) {
                    continue;
                }
```
After:
```php
                // The capture grid renders the tenant's work days (plus its TOT Saturday)
                // — see timesheet-capture.js's baseDays(). A stint running across a day
                // off must not propose a row for a day that has no column to put it in.
                // DayCapacity::for() cannot answer this: it returns 100.0 for a day off,
                // since it is asking how full a day must be, not whether the day is worked.
                if (! $workWeek->isWorkingDay($day)) {
                    continue;
                }
```
Add `        $workWeek = WorkWeek::for();` immediately after line 45 (`$end = ...`). Add `use App\Support\WorkWeek;` to the imports. If `DayCapacity` is now unused in this file, drop its `use` line.

- [ ] **Step 8: TimesheetCompliance eligibility counts the tenant's days**

Edit `app/Timesheet/TimesheetCompliance.php` line 118.

Before:
```php
        return $this->fullyLockedCount($this->lockedDays->forWeek($employee, $weekStart)) < 5;
```
After:
```php
        return $this->fullyLockedCount($this->lockedDays->forWeek($employee, $weekStart)) < count(WorkWeek::for()->workingDays());
```
Add `use App\Support\WorkWeek;` to the imports. (Unijaya: `workingDays()` is still five, TOT excluded, so nothing changes there.)

- [ ] **Step 9: Timesheet::computeWeekEndsOn**

Edit `app/Models/Timesheet.php` lines 132-144.

Before:
```php
    /**
     * A week's cutoff: Friday, unless that week's Saturday is the first Saturday of the
     * month (Unijaya's TOT day, a work half-day), which pushes the cutoff there. Single
     * source of truth for TimesheetController's submit gate (both the capture screen's
     * submit_now and the Review tab's plain-form submit) — mirrors weekEndsOn() in
     * resources/js/timesheet-capture.js for the capture screen's own button state.
     */
    public static function computeWeekEndsOn(CarbonInterface $weekStart): Carbon
    {
        $saturday = Carbon::parse($weekStart)->addDays(5);

        return DayCapacity::isFirstSaturday($saturday) ? $saturday : Carbon::parse($weekStart)->addDays(4);
    }
```
After:
```php
    /**
     * A week's cutoff: the last day the tenant's work week asks to be filled (Friday for a
     * Mon-Fri company, the TOT Saturday on Unijaya's first-Saturday weeks, Sunday for a
     * seven-day company). Falls back to Friday when the week has no work day at all.
     * Single source of truth for TimesheetController's submit gate (both the capture
     * screen's submit_now and the Review tab's plain-form submit) — mirrors weekEndsOn()
     * in resources/js/timesheet-capture.js for the capture screen's own button state.
     */
    public static function computeWeekEndsOn(CarbonInterface $weekStart): Carbon
    {
        $workWeek = WorkWeek::for();

        for ($i = 6; $i >= 0; $i--) {
            $day = Carbon::parse($weekStart)->addDays($i);
            if ($workWeek->isWorkingDay($day)) {
                return $day;
            }
        }

        return Carbon::parse($weekStart)->addDays(4);
    }
```
Add `use App\Support\WorkWeek;` after `use App\Models\Concerns\BelongsToTenant;`. `DayCapacity` is still used at line 91; keep its import.

- [ ] **Step 10: LeaveRequest::countDays**

Edit `app/Models/LeaveRequest.php`.

Line 6, before: `use App\Timesheet\DayCapacity;` After: `use App\Support\WorkWeek;`

Before (lines 69-96):
```php
    /**
     * Working days between $from and $to inclusive. Unijaya works Mon–Fri plus the TOT
     * Saturday (the first Saturday of the month, a half day, counted 0.5); Sundays,
     * ordinary Saturdays and the tenant's public holidays are not working days and cost
     * nothing.
     */
    public static function countDays(Carbon $from, Carbon $to): float
    {
        $holidays = PublicHoliday::whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->pluck('date')
            ->map(fn (Carbon $d) => $d->toDateString())
            ->flip();

        $days = 0.0;
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if ($holidays->has($date->toDateString())) {
                continue;
            }
            if (DayCapacity::isFirstSaturday($date)) {
                $days += 0.5;
            } elseif ($date->isWeekday()) {
                $days += 1.0;
            }
        }

        return $days;
    }
```
After:
```php
    /**
     * Working days between $from and $to inclusive, per the tenant's work week: a listed
     * work day costs 1, the TOT Saturday (Unijaya's first-Saturday half day) 0.5, and days
     * off and the tenant's public holidays cost nothing.
     */
    public static function countDays(Carbon $from, Carbon $to): float
    {
        $holidays = PublicHoliday::whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->pluck('date')
            ->map(fn (Carbon $d) => $d->toDateString())
            ->flip();

        $workWeek = WorkWeek::for();

        $days = 0.0;
        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if ($holidays->has($date->toDateString())) {
                continue;
            }
            $days += $workWeek->dayFraction($date);
        }

        return $days;
    }
```

- [ ] **Step 11: ReportPeriod::workingDays**

Edit `app/Attendance/ReportPeriod.php` lines 165-186.

Before:
```php
    /**
     * Mon–Fri inside the window, plus any date on which somebody actually has a
     * record — a weekend shift is real work and must not vanish from the ledger.
     *
     * @param  list<string>  $recordDates  Y-m-d
     * @return list<string>
     */
    public function workingDays(array $recordDates): array
    {
        $days = [];
        $cursor = $this->from;

        while ($cursor->lte($this->to)) {
            $date = $cursor->toDateString();
            if ($cursor->isWeekday() || in_array($date, $recordDates, true)) {
                $days[] = $date;
            }
            $cursor = $cursor->addDay();
        }

        return $days;
    }
```
After:
```php
    /**
     * The tenant's work days inside the window (App\Support\WorkWeek, TOT Saturday
     * included), plus any date on which somebody actually has a record — a shift on a day
     * off is real work and must not vanish from the ledger.
     *
     * @param  list<string>  $recordDates  Y-m-d
     * @return list<string>
     */
    public function workingDays(array $recordDates): array
    {
        $workWeek = WorkWeek::for();
        $days = [];
        $cursor = $this->from;

        while ($cursor->lte($this->to)) {
            $date = $cursor->toDateString();
            if ($workWeek->isWorkingDay($cursor) || in_array($date, $recordDates, true)) {
                $days[] = $date;
            }
            $cursor = $cursor->addDay();
        }

        return $days;
    }
```
Add `use App\Support\WorkWeek;` after `namespace App\Attendance;` (before `use Carbon\CarbonImmutable;`). Note for the handoff: on Unijaya the attendance ledger now lists the TOT Saturday as a working day; the spec asks for this routing explicitly.

- [ ] **Step 12: Run the behaviour test, expect green**

Run: `php artisan test --compact tests/Feature/WorkWeekBehaviourTest.php`
Expected: 6 passed.

- [ ] **Step 13: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Timesheet/DayCapacity.php app/Timesheet/DayRules.php app/Timesheet/LockedDays.php app/Timesheet/WeekWriter.php app/Timesheet/BoardSuggestions.php app/Timesheet/TimesheetCompliance.php app/Models/Timesheet.php app/Models/LeaveRequest.php app/Attendance/ReportPeriod.php tests/Feature/WorkWeekBehaviourTest.php
git commit -m "feat(work-week): timesheet, leave and attendance day rules read the tenant's work week

DayRules, DayCapacity, LockedDays, WeekWriter, BoardSuggestions, TimesheetCompliance,
Timesheet::computeWeekEndsOn, LeaveRequest::countDays and ReportPeriod all ask
WorkWeek instead of assuming Mon-Fri plus the first Saturday. Weeks now run to
Sunday so a company that works weekends is covered, and a week with nothing to
fill submits cleanly instead of a 422."
```

---

### Task 3: Keep the Unijaya-shaped tests green by setting the flag on

**Files:**
- Modify: `tests/Unit/LockedDaysTest.php` line 34
- Modify: `tests/Unit/LeaveRequestCountDaysTest.php` lines 5-16 (add setUp/tearDown)
- Modify: `tests/Feature/TotSaturdayTimesheetTest.php` line 41
- Modify: `tests/Feature/PublicHolidayTimesheetTest.php` line 41
- Modify: `tests/Feature/HalfDayLeaveTest.php` line 49
- Modify: `tests/Feature/LeaveReportAccuracyTest.php` line 55
- Possibly modify (only if the full run shows a TOT assertion failing): `tests/Feature/AwardsTest.php`, `tests/Feature/BoardCardTest.php`, `tests/Feature/BoardSuggestionsTest.php`, `tests/Feature/GreetingTriggersTest.php`, `tests/Feature/ManagementMeetingTest.php`, `tests/Feature/Mcp/AmanahkuWriteToolsTest.php`, `tests/Feature/NavAttentionDotsTest.php`, `tests/Feature/OperationsWritePathsTest.php`, `tests/Feature/SidebarDayRolloverTest.php`, `tests/Feature/ProjectVariationTest.php`
- Never: anything under `tests/Acceptance/`.

**Interfaces:**
- Consumes: `Tenant::create([... 'tot_saturday' => true])`, `CurrentTenant::set()`.
- Produces: nothing new.

- [ ] **Step 1: Run the six known TOT suites, expect red**

Run: `php artisan test --compact tests/Unit/LockedDaysTest.php tests/Unit/LeaveRequestCountDaysTest.php tests/Feature/TotSaturdayTimesheetTest.php tests/Feature/PublicHolidayTimesheetTest.php tests/Feature/HalfDayLeaveTest.php tests/Feature/LeaveReportAccuracyTest.php`
Expected: failures wherever a test asserts the first Saturday at 50% / 0.5 (their tenants are created without the flag, and `LeaveRequestCountDaysTest` binds no tenant at all).

- [ ] **Step 2: Turn the flag on in each of the five tenant-creating tests**

In each file, the line is exactly `        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);`.

Change it to:
```php
        // Unijaya-shaped: the first Saturday of the month is the TOT half day.
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'tot_saturday' => true]);
```
Files and lines: `tests/Unit/LockedDaysTest.php:34`, `tests/Feature/TotSaturdayTimesheetTest.php:41`, `tests/Feature/PublicHolidayTimesheetTest.php:41`, `tests/Feature/HalfDayLeaveTest.php:49`, `tests/Feature/LeaveReportAccuracyTest.php:55`.

- [ ] **Step 3: Bind a TOT tenant in LeaveRequestCountDaysTest**

Edit `tests/Unit/LeaveRequestCountDaysTest.php`.

Before (lines 5-17):
```php
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Leave is charged for working days only: Mon–Fri plus the TOT Saturday (first Saturday
 * of the month, a half day). Sundays and ordinary Saturdays are never working days.
 */
class LeaveRequestCountDaysTest extends TestCase
{
    use RefreshDatabase;

```
After:
```php
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Leave is charged for working days only. Unijaya-shaped tenant: Mon–Fri plus the TOT
 * Saturday (first Saturday of the month, a half day). Sundays and ordinary Saturdays are
 * never working days. The tot_saturday = false case lives in WorkWeekBehaviourTest.
 */
class LeaveRequestCountDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(CurrentTenant::class)->set(Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'tot_saturday' => true]));
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

```

- [ ] **Step 4: Run the six suites again, expect green**

Run: `php artisan test --compact tests/Unit/LockedDaysTest.php tests/Unit/LeaveRequestCountDaysTest.php tests/Feature/TotSaturdayTimesheetTest.php tests/Feature/PublicHolidayTimesheetTest.php tests/Feature/HalfDayLeaveTest.php tests/Feature/LeaveReportAccuracyTest.php`
Expected: all pass.

- [ ] **Step 5: Run the whole suite and fix any remaining TOT-shaped failure the same way**

Run: `php artisan test --compact`
For each failing test under `tests/Feature` or `tests/Unit` whose assertion is about 2026-08-01 / 2026-09-05 / "first Saturday" / 50% / 0.5 / a Saturday cutoff, add `'tot_saturday' => true` to that file's `Tenant::create(['slug' => 'acme', ...])` line, exactly as in Step 2 (candidate files are listed under **Files** above). A failure that is not about the TOT half day is a real regression from Task 2: fix the source, not the test. `tests/Acceptance/*` must pass untouched; if one fails, the change in Task 2 is wrong.
Expected: full suite green.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Unit tests/Feature
git commit -m "test(work-week): Unijaya-shaped suites turn tot_saturday on explicitly

The TOT half day is now a tenant flag that defaults off, so every test that
asserts the first Saturday at 50% creates its tenant with the flag on."
```

---

### Task 4: Route the inline isWeekend() copies through WorkWeek

**Files:**
- Modify: `app/Http/Controllers/BirthdayWishController.php` lines 159-161
- Modify: `app/Console/Commands/BirthdayNotify.php` lines 54-55
- Modify: `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php` lines 143-146
- Modify: `app/Attendance/HolidayEve.php` lines 44-58
- Modify: `app/Attendance/ReminderTargets.php` lines 189-197
- Modify: `app/Console/Commands/CreateRecurringWorkItems.php` lines 147-149
- Test: extend `tests/Feature/WorkWeekBehaviourTest.php` with one HolidayEve case; existing `tests/Feature/HolidayEveGreetingTest.php`, `tests/Unit/AttendanceReminderTargetsTest.php`, `tests/Feature/BirthdayWishesTest.php` stay green (their tenants default to Mon-Fri, no TOT, which is what `isWeekend()` meant).

**Interfaces:**
- Consumes: `WorkWeek::for()->isWorkingDay(CarbonInterface)`.
- Produces: no signature changes.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/WorkWeekBehaviourTest.php`, inside the class before the closing brace:

```php
    public function test_holiday_eve_next_working_day_follows_the_work_week(): void
    {
        // Sat-working company: a Friday holiday is followed by Saturday, not Monday.
        $this->company(['work_days' => [1, 2, 3, 4, 5, 6]]);
        $holiday = PublicHoliday::create(['tenant_id' => $this->tenant->id, 'name' => 'Cuti', 'date' => '2026-08-07']);

        $eve = app(\App\Attendance\HolidayEve::class)->forDay(Carbon::parse('2026-08-06'));

        $this->assertNotNull($eve);
        $this->assertSame($holiday->id, $eve['holiday']->id);
        $this->assertSame('2026-08-08', $eve['next_working_day']->toDateString());
    }
```

- [ ] **Step 2: Run it, expect failure**

Run: `php artisan test --compact --filter=test_holiday_eve_next_working_day_follows_the_work_week`
Expected: fails with next_working_day `2026-08-10`.

- [ ] **Step 3: HolidayEve**

Edit `app/Attendance/HolidayEve.php` lines 44-58.

Before:
```php
        $first = null;
        $cursor = $day->addDay();
        for ($i = 0; $i < self::MAX_RUN; $i++, $cursor = $cursor->addDay()) {
            $holiday = $holidays->get($cursor->toDateString());
            if ($holiday !== null) {
                $first ??= $holiday;

                continue;
            }
            if ($cursor->isWeekend()) {
                continue;
            }

            break;
        }
```
After:
```php
        $workWeek = WorkWeek::for();
        $first = null;
        $cursor = $day->addDay();
        for ($i = 0; $i < self::MAX_RUN; $i++, $cursor = $cursor->addDay()) {
            $holiday = $holidays->get($cursor->toDateString());
            if ($holiday !== null) {
                $first ??= $holiday;

                continue;
            }
            if (! $workWeek->isWorkingDay($cursor)) {
                continue;
            }

            break;
        }
```
Add `use App\Support\WorkWeek;` to the imports.

- [ ] **Step 4: The three birthday copies**

Each has the identical closure. Replace it in all three.

`app/Http/Controllers/BirthdayWishController.php` lines 160-161, `app/Console/Commands/BirthdayNotify.php` lines 54-55, `app/Http/Controllers/Concerns/BuildsDashboardWidgets.php` lines 145-146.

Before:
```php
        $isWorkingDay = fn (CarbonImmutable $day): bool => ! $day->isWeekend()
            && ! PublicHoliday::whereDate('date', $day->toDateString())->exists();
```
After:
```php
        $isWorkingDay = fn (CarbonImmutable $day): bool => WorkWeek::for()->isWorkingDay($day)
            && ! PublicHoliday::whereDate('date', $day->toDateString())->exists();
```
(`BuildsDashboardWidgets` has the closure indented by 12 spaces; keep its indentation.) In `BuildsDashboardWidgets.php` lines 143-144 replace the comment `// Weekend or a public-holiday row for this tenant — the only two ways a day` / `// is not a working day (CR-13's celebratedOn()).` with `// A day off in the tenant's work week, or a public-holiday row — the only two ways` / `// a day is not a working day (CR-13's celebratedOn()).` Add `use App\Support\WorkWeek;` to each of the three files' imports.

- [ ] **Step 5: ReminderTargets**

Edit `app/Attendance/ReminderTargets.php` lines 189-197.

Before:
```php
    /**
     * Weekends and tenant public holidays. There is no per-branch working-days column in
     * the schema yet, so Saturday rosters are not covered — see the plan's known ceilings.
     */
    private function isNonWorkingDay(Carbon $now): bool
    {
        return $now->isWeekend()
            || PublicHoliday::query()->whereDate('date', $now->toDateString())->exists();
    }
```
After:
```php
    /**
     * Days off in the tenant's work week (App\Support\WorkWeek) and tenant public
     * holidays. The work week is company-wide; there is no per-branch roster.
     */
    private function isNonWorkingDay(Carbon $now): bool
    {
        return ! WorkWeek::for()->isWorkingDay($now)
            || PublicHoliday::query()->whereDate('date', $now->toDateString())->exists();
    }
```
Add `use App\Support\WorkWeek;` to the imports.

- [ ] **Step 6: CreateRecurringWorkItems**

Edit `app/Console/Commands/CreateRecurringWorkItems.php` lines 147-149.

Before:
```php
        while ($day->isWeekend() || $this->isPublicHoliday($day)) {
            $day = $day->addDay();
        }
```
After:
```php
        $workWeek = WorkWeek::for();
        while (! $workWeek->isWorkingDay($day) || $this->isPublicHoliday($day)) {
            $day = $day->addDay();
        }
```
Update the docblock line 139 `a plain weekend does not, the card simply waits on the board until Monday.` to `a plain day off does not, the card simply waits on the board until the next work day.` Add `use App\Support\WorkWeek;` to the imports.

- [ ] **Step 7: Run the affected suites, expect green**

Run: `php artisan test --compact tests/Feature/WorkWeekBehaviourTest.php tests/Feature/HolidayEveGreetingTest.php tests/Unit/AttendanceReminderTargetsTest.php tests/Feature/BirthdayWishesTest.php tests/Feature/ReportPeriodTest.php tests/Unit/ReportPeriodTest.php`
(`tests/Feature/ReportPeriodTest.php` may not exist; drop it from the command if PHPUnit says so.) Also run `php artisan test --compact --filter=Recurring`.
Expected: all pass.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/BirthdayWishController.php app/Console/Commands/BirthdayNotify.php app/Http/Controllers/Concerns/BuildsDashboardWidgets.php app/Attendance/HolidayEve.php app/Attendance/ReminderTargets.php app/Console/Commands/CreateRecurringWorkItems.php tests/Feature/WorkWeekBehaviourTest.php
git commit -m "feat(work-week): birthday, holiday-eve, reminder and recurring-card day checks read the work week

Six inline isWeekend() copies now ask WorkWeek, so a Saturday-working company gets
its birthday band, holiday-eve greeting, attendance reminders and recurring cards
on the right days."
```

---

### Task 5: Timesheet capture screen mirrors the work week

**Files:**
- Modify: `resources/js/timesheet-capture.js` lines 45-51, 89-95, 224-229, 270-275, 929-934
- Modify: `resources/js/timesheet-capture.test.js` lines 26-33 (makeComponent defaults) and append tests
- Modify: `app/Http/Controllers/TimesheetController.php` line 176 area (view data)
- Modify: `resources/views/screens/timesheets.blade.php` lines 131-148 (x-data cfg) and 679-680 (comment)
- Test: `bun test resources/js/timesheet-capture.test.js`

**Interfaces:**
- Consumes: `WorkWeek::for()->workingDays()`, `WorkWeek::for()->totSaturday()`.
- Produces:
  - JS exports `isoWeekday(iso: string): number` (1..7), `isWorkDayFor(iso, workDays: number[], totSaturday: boolean): boolean`, `baseDaysFor(weekStart, workDays, totSaturday): number`.
  - Component cfg keys `workDays: number[]`, `totSaturday: boolean`; methods `isTotDay(iso)`, `isWorkDay(iso)`; `baseDays()`, `capacityFor(iso)`, `weekEndsOn()` become tenant-aware.
  - Blade view data `tsWorkDays: list<int>`, `tsTotSaturday: bool`.

- [ ] **Step 1: Make the existing JS tests Unijaya-shaped and add failing ones**

Edit `resources/js/timesheet-capture.test.js`.

Before (lines 26-33):
```js
/** Builds the raw component object literal and wires up the $store magic property. */
function makeComponent(cfg) {
    const c = capturedFactory({ weekStart: WEEK_START, today: TODAY, earliestWeek: '2026-01-01', categories: CATEGORIES, ...cfg });
```
After:
```js
/** Builds the raw component object literal and wires up the $store magic property.
 *  Unijaya-shaped by default: Mon-Fri with the TOT first Saturday on. */
function makeComponent(cfg) {
    const c = capturedFactory({ weekStart: WEEK_START, today: TODAY, earliestWeek: '2026-01-01', categories: CATEGORIES, workDays: [1, 2, 3, 4, 5], totSaturday: true, ...cfg });
```

Change the import on line 2 to:
```js
import { registerTimesheetCapture, findEditTarget, isoWeekday, isWorkDayFor, baseDaysFor } from './timesheet-capture';
```

Append at the end of the file:
```js
test('isoWeekday maps Sunday to 7', () => {
    expect(isoWeekday('2026-08-03')).toBe(1); // Monday
    expect(isoWeekday('2026-08-08')).toBe(6); // Saturday
    expect(isoWeekday('2026-08-09')).toBe(7); // Sunday
});

test('isWorkDayFor: listed days, TOT only when flagged and Saturday not listed', () => {
    const TOT = '2026-08-01';
    expect(isWorkDayFor(TOT, [1, 2, 3, 4, 5], true)).toBe(true);
    expect(isWorkDayFor(TOT, [1, 2, 3, 4, 5], false)).toBe(false);
    expect(isWorkDayFor('2026-08-08', [1, 2, 3, 4, 5], true)).toBe(false);
    expect(isWorkDayFor('2026-08-08', [1, 2, 3, 4, 5, 6], false)).toBe(true);
    expect(isWorkDayFor('2026-08-09', [6, 7], false)).toBe(true);
});

test('baseDaysFor: 5 for Mon-Fri, 6 on a TOT week, 7 when Sunday is worked, never under 5', () => {
    expect(baseDaysFor('2026-07-27', [1, 2, 3, 4, 5], true)).toBe(6);
    expect(baseDaysFor('2026-07-27', [1, 2, 3, 4, 5], false)).toBe(5);
    expect(baseDaysFor('2026-08-03', [1, 2, 3, 4, 5], true)).toBe(5);
    expect(baseDaysFor('2026-08-03', [6, 7], false)).toBe(7);
    expect(baseDaysFor('2026-08-03', [1, 2, 3], false)).toBe(5);
});

test('capacityFor and weekEndsOn follow the tenant: TOT off means Friday and 100 on the first Saturday', () => {
    const c = makeComponent({ weekStart: '2026-07-27', totSaturday: false });
    expect(c.days).toBe(5);
    expect(c.capacityFor('2026-08-01')).toBe(100);
    expect(c.weekEndsOn()).toBe('2026-07-31');

    const sixDay = makeComponent({ weekStart: '2026-07-27', workDays: [1, 2, 3, 4, 5, 6], totSaturday: true });
    expect(sixDay.days).toBe(6);
    expect(sixDay.capacityFor('2026-08-01')).toBe(100);
    expect(sixDay.weekEndsOn()).toBe('2026-08-01');
});
```

- [ ] **Step 2: Run, expect failure**

Run: `bun test resources/js/timesheet-capture.test.js`
Expected: the four new tests fail (exports missing / `days` still 6 with TOT off).

- [ ] **Step 3: Add the exported helpers**

Edit `resources/js/timesheet-capture.js`. Directly after the `isFirstSaturday` function (line 51), add:

```js
/** ISO weekday of an ISO date: 1 = Monday .. 7 = Sunday. */
export function isoWeekday(iso) {
    const dow = new Date(iso + 'T00:00:00Z').getUTCDay();

    return dow === 0 ? 7 : dow;
}

/** Mirrors App\Support\WorkWeek::isWorkingDay(): a listed work day, or the TOT half day
 *  (flag on, first Saturday, Saturday not already a full work day). */
export function isWorkDayFor(iso, workDays, totSaturday) {
    return workDays.includes(isoWeekday(iso))
        || (totSaturday && isFirstSaturday(iso) && !workDays.includes(6));
}

/** Columns shown with the weekend hidden: up to the last day the week asks to be filled,
 *  never fewer than five so a short week still reads as a week. 5 for Mon-Fri, 6 on a
 *  TOT week, 7 for a company that works Sundays. */
export function baseDaysFor(weekStart, workDays, totSaturday) {
    let last = 5;
    for (let i = 0; i < 7; i++) {
        if (isWorkDayFor(addDaysIso(weekStart, i), workDays, totSaturday)) last = i + 1;
    }

    return last;
}
```

- [ ] **Step 4: Wire the component**

Before (lines 89-95):
```js
    Alpine.data('timesheetCapture', (cfg) => ({
        weekStart: cfg.weekStart,
        // 5 on an ordinary week, 6 when the week holds the first Saturday of the month —
        // Unijaya's TOT half day, which staff must be able to fill without hunting for the
        // "Show weekend" toggle. cfg.days still wins when the caller passes one (tests).
        days: cfg.days || (isFirstSaturday(addDaysIso(cfg.weekStart, 5)) ? 6 : 5),
        // Kept in sync with the "Show weekend" toggle, which flips between this and 7.
```
After:
```js
    Alpine.data('timesheetCapture', (cfg) => ({
        weekStart: cfg.weekStart,
        // The tenant's work week (App\Support\WorkWeek): ISO weekdays plus the TOT flag.
        workDays: cfg.workDays || [1, 2, 3, 4, 5],
        totSaturday: cfg.totSaturday === true,
        // Columns with the weekend hidden: see baseDaysFor(). cfg.days still wins when
        // the caller passes one (tests). Flips to 7 with the "Show weekend" toggle.
        days: cfg.days || baseDaysFor(cfg.weekStart, cfg.workDays || [1, 2, 3, 4, 5], cfg.totSaturday === true),
```

Before (lines 224-229):
```js
        // ---- the week ------------------------------------------------------
        // The week's own day count with the weekend hidden: 6 when it holds the TOT
        // Saturday, 5 otherwise. The "Show weekend" toggle returns here.
        baseDays() {
            return isFirstSaturday(addDaysIso(this.weekStart, 5)) ? 6 : 5;
        },
```
After:
```js
        // ---- the week ------------------------------------------------------
        // The week's own day count with the weekend hidden. The "Show weekend" toggle
        // returns here.
        baseDays() {
            return baseDaysFor(this.weekStart, this.workDays, this.totSaturday);
        },
        isTotDay(iso) {
            return this.totSaturday && isFirstSaturday(iso) && !this.workDays.includes(6);
        },
        isWorkDay(iso) {
            return isWorkDayFor(iso, this.workDays, this.totSaturday);
        },
```

Before (lines 270-275):
```js
        // How much this day asks to be filled: 50% on the first Saturday of the month (the
        // TOT half day), 100% on every other day. Mirrors App\Timesheet\DayCapacity, which
        // is what the submit gate actually enforces.
        capacityFor(iso) {
            return isFirstSaturday(iso) ? 50 : 100;
        },
```
After:
```js
        // How full this day must be: 50% on the tenant's TOT half day, 100% on every other
        // day. Mirrors App\Timesheet\DayCapacity, which is what the submit gate enforces.
        capacityFor(iso) {
            return this.isTotDay(iso) ? 50 : 100;
        },
```

Before (lines 929-934):
```js
        // The week's cutoff date: Friday, unless this week's Saturday is the first Saturday
        // of the month (Unijaya's TOT day), which pushes the cutoff to that Saturday.
        weekEndsOn() {
            const saturday = addDaysIso(this.weekStart, 5);
            return isFirstSaturday(saturday) ? saturday : addDaysIso(this.weekStart, 4);
        },
```
After:
```js
        // The week's cutoff date: the last day the work week asks to be filled (Friday for
        // Mon-Fri, the TOT Saturday on Unijaya's first-Saturday weeks, Sunday for a
        // seven-day company). Mirrors Timesheet::computeWeekEndsOn(); Friday when the week
        // has no work day at all.
        weekEndsOn() {
            for (let i = 6; i >= 0; i--) {
                const iso = addDaysIso(this.weekStart, i);
                if (this.isWorkDay(iso)) return iso;
            }
            return addDaysIso(this.weekStart, 4);
        },
```

- [ ] **Step 5: Run the JS tests, expect green**

Run: `bun test resources/js/timesheet-capture.test.js`
Expected: all pass, including the pre-existing `weekEndsOn moves to the TOT Saturday...` and `reviewDays() keeps a filled Saturday...` tests.

- [ ] **Step 6: Pass the values from the controller and Blade**

Edit `app/Http/Controllers/TimesheetController.php`. In the view-data array that contains `'weekStart' => $weekStart->toDateString(),` (line 176), add two entries immediately after that line:
```php
            'tsWorkDays' => WorkWeek::for()->workingDays(),
            'tsTotSaturday' => WorkWeek::for()->totSaturday(),
```
Add `use App\Support\WorkWeek;` to the imports.

Edit `resources/views/screens/timesheets.blade.php`. Before (lines 131-133):
```blade
         x-data="timesheetCapture({
            weekStart: @js($weekStart),
            today: @js($tsToday),
```
After:
```blade
         x-data="timesheetCapture({
            weekStart: @js($weekStart),
            workDays: @js($tsWorkDays),
            totSaturday: @js($tsTotSaturday),
            today: @js($tsToday),
```
Lines 679-680, before:
```blade
            {{-- Base is 6 on a first-Saturday week (the TOT half day is shown by default),
                 5 otherwise; the toggle reaches Sunday and back. --}}
```
After:
```blade
            {{-- Base is the tenant's work week (5 for Mon-Fri, 6 on a TOT week, 7 when
                 Sunday is worked); the toggle reaches Sunday and back. --}}
```

- [ ] **Step 7: Build assets and check the screen**

Run: `lerd artisan view:clear && lerd artisan view:cache && bun run build`
Then run the PHP timesheet suites: `php artisan test --compact tests/Feature/TotSaturdayTimesheetTest.php tests/Feature/PublicHolidayTimesheetTest.php tests/Feature/WorkWeekBehaviourTest.php`
Expected: green. Open `http://localhost:9100`, quick-login as Shazwan (Employee), open Timesheets: the week still shows five columns (six on the first-Saturday week, since Unijaya's flag is on).

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/js/timesheet-capture.js resources/js/timesheet-capture.test.js app/Http/Controllers/TimesheetController.php resources/views/screens/timesheets.blade.php public/build
git commit -m "feat(work-week): timesheet capture grid follows the tenant's work week

The capture screen gets workDays and totSaturday from the tenant and derives its
column count, per-day capacity and week cutoff from them, mirroring the server."
```

---

### Task 6: Work week card on Company Settings

**Files:**
- Modify: `routes/web.php` line 384 (add route after `admin.settings.update`)
- Modify: `app/Http/Controllers/AdminController.php` (add `updateWorkWeek()` after `updateSettings()`, which ends around line 85)
- Modify: `resources/views/screens/settings.blade.php` line 88 (insert the card at the top of the right column, before the Branches card at line 89)
- Test: `tests/Feature/WorkWeekSettingsTest.php`

**Interfaces:**
- Consumes: `AdminController::authorizeAdmin()` (management + hr, 403 otherwise), `AuditLog::record(string $action, ?string $target)`, `$company` and `$canManageFeatures` already passed by `BuildsSettingsData::settingsData()` (lines 35, 41), `partials.hint`.
- Produces: `POST /app/admin/work-week` named `admin.workweek.update`, body `work_days[]` (ints 1..7), `AdminController::updateWorkWeek(Request $request): RedirectResponse`; audit action `'Updated work week'` with the day list as target; `?section=work_week` renders the card alone (existing `$only` embed pattern, line 15).

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/WorkWeekSettingsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The Work week card on Company Settings: HR and management save it, staff cannot see or change it. */
class WorkWeekSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function actingAsRole(string $role): self
    {
        $this->seq++;
        $user = User::create(['name' => ucfirst($role), 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('<redacted>')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_hr_saves_a_six_day_week_and_it_is_audited(): void
    {
        $this->actingAsRole('hr')
            ->post(route('admin.workweek.update'), ['work_days' => ['6', '1', '2', '3', '4', '5']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([1, 2, 3, 4, 5, 6], $this->tenant->fresh()->work_days);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Updated work week', 'target' => 'Mon, Tue, Wed, Thu, Fri, Sat']);
    }

    public function test_management_can_save_too(): void
    {
        $this->actingAsRole('management')
            ->post(route('admin.workweek.update'), ['work_days' => [2, 3, 4, 5, 6]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([2, 3, 4, 5, 6], $this->tenant->fresh()->work_days);
    }

    public function test_at_least_one_working_day_is_required(): void
    {
        $this->actingAsRole('hr')
            ->from('/app/settings')
            ->post(route('admin.workweek.update'), [])
            ->assertSessionHasErrors('work_days');

        $this->assertSame([1, 2, 3, 4, 5], $this->tenant->fresh()->work_days);
    }

    public function test_days_must_be_iso_weekdays_without_repeats(): void
    {
        $this->actingAsRole('hr')
            ->post(route('admin.workweek.update'), ['work_days' => [0, 8]])
            ->assertSessionHasErrors('work_days.0');

        $this->actingAsRole('hr')
            ->post(route('admin.workweek.update'), ['work_days' => [1, 1]])
            ->assertSessionHasErrors('work_days.0');

        $this->assertSame([1, 2, 3, 4, 5], $this->tenant->fresh()->work_days);
    }

    public function test_an_employee_is_refused(): void
    {
        $this->actingAsRole('employee')
            ->post(route('admin.workweek.update'), ['work_days' => [1]])
            ->assertStatus(403);

        $this->assertSame([1, 2, 3, 4, 5], $this->tenant->fresh()->work_days);
        $this->assertSame(0, AuditLog::where('action', 'Updated work week')->count());
    }

    public function test_the_card_renders_for_hr_and_alone_with_section_work_week(): void
    {
        $this->actingAsRole('hr')->get('/app/settings')->assertOk()->assertSee('Work week');
        $this->actingAsRole('hr')->get('/app/settings?section=work_week')
            ->assertOk()
            ->assertSee('Work week')
            ->assertDontSee('Workspace profile');
    }
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `php artisan test --compact tests/Feature/WorkWeekSettingsTest.php`
Expected: route `admin.workweek.update` not defined.

- [ ] **Step 3: Route**

Edit `routes/web.php`. After line 384 (`Route::post('/app/admin/settings', ...)->name('admin.settings.update');`) add:
```php
        // Per-company work week (ISO weekdays). HR + management; TOT flag has no UI.
        Route::post('/app/admin/work-week', [AdminController::class, 'updateWorkWeek'])->name('admin.workweek.update');
```

- [ ] **Step 4: Controller action**

Edit `app/Http/Controllers/AdminController.php`. Add this method directly after `updateSettings()` (before the docblock of `updateFeatures()`):

```php
    /** Short day names in ISO order, for the audit line. */
    private const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * Which ISO weekdays are working days for this company. Forward-only: stored leave
     * day counts, submitted weeks and past attendance reports are not recalculated.
     * tot_saturday is deliberately not accepted here (Unijaya-only, set by migration).
     */
    public function updateWorkWeek(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', 'between:1,7', 'distinct'],
        ]);

        $days = array_map('intval', $data['work_days']);
        sort($days);
        $days = array_values($days);

        app(CurrentTenant::class)->get()->update(['work_days' => $days]);

        AuditLog::record('Updated work week', implode(', ', array_map(fn (int $d) => self::DAY_NAMES[$d], $days)));

        return back()->with('ok', count($days).' working day'.(count($days) === 1 ? '' : 's').' saved.');
    }
```

- [ ] **Step 5: The card**

Edit `resources/views/screens/settings.blade.php`. Insert between line 87 (`<div style="{{ $only ? '' : 'flex:1;min-width:280px;display:flex;flex-direction:column;gap:16px;' }}">`) and line 89 (`@if (! $only || $only === 'branches')`):

```blade
        @if (!empty($canManageFeatures) && (! $only || $only === 'work_week'))
        {{-- Work week: which ISO weekdays (1 = Mon .. 7 = Sun) are working days. Read by
             App\Support\WorkWeek. Forward-only; the TOT flag is Unijaya-only and has no UI. --}}
        <div class="uj-card" style="padding:20px;"
             x-data="{
                days: @js(\App\Support\WorkWeek::for($company)->workingDays()),
                names: { en: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], ms: ['Isn','Sel','Rab','Kha','Jum','Sab','Ahd'] },
                has(n) { return this.days.includes(n); },
                toggle(n) { this.has(n) ? this.days = this.days.filter(d => d !== n) : this.days.push(n); },
             }">
            <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Work week' : 'Minggu bekerja'">Work week</h3>
            <p style="font-size:13px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Which days count as working days. Leave balances, timesheet capacity and attendance reports all follow this.' : 'Hari mana dikira sebagai hari bekerja. Baki cuti, kapasiti timesheet dan laporan kehadiran semuanya mengikut ini.'">Which days count as working days. Leave balances, timesheet capacity and attendance reports all follow this.</p>

            <form method="post" action="{{ route('admin.workweek.update') }}">
                @csrf
                @if ($errors->has('work_days') || $errors->has('work_days.*'))<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:12px;" x-text="$store.ui.lang==='en' ? 'Pick at least one working day.' : 'Pilih sekurang-kurangnya satu hari bekerja.'">Pick at least one working day.</div>@endif

                <div style="display:flex;gap:6px;margin-bottom:14px;">
                    <template x-for="n in [1,2,3,4,5,6,7]" :key="n">
                        <button type="button" @click="toggle(n)" :aria-pressed="has(n)"
                                :style="has(n) ? 'border-color:var(--red);background:var(--red-tint);' : ''"
                                style="display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 0 8px;border:1px solid var(--hairline);border-radius:10px;background:#fff;cursor:pointer;flex:1;min-width:0;user-select:none;">
                            <span style="font-size:13px;font-weight:600;color:var(--ink);" x-text="names[$store.ui.lang==='en' ? 'en' : 'ms'][n-1]"></span>
                            <span style="font-size:11px;" :style="has(n) ? 'color:var(--red);' : 'color:var(--muted);'" x-text="has(n) ? ($store.ui.lang==='en' ? 'Work' : 'Kerja') : ($store.ui.lang==='en' ? 'Off' : 'Cuti')"></span>
                        </button>
                    </template>
                </div>
                <template x-for="d in days" :key="'wd'+d"><input type="hidden" name="work_days[]" :value="d"></template>

                @include('partials.hint', ['en' => 'Applies from today. Past records are not recalculated.', 'ms' => 'Berkuat kuasa dari hari ini. Rekod lepas tidak dikira semula.'])

                <div style="display:flex;align-items:center;gap:12px;margin-top:6px;">
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;" :disabled="days.length === 0"><span x-text="$store.ui.lang==='en' ? 'Save work week' : 'Simpan minggu bekerja'">Save work week</span></button>
                    <span style="font-size:12.5px;color:var(--muted);" x-text="days.length + ' ' + ($store.ui.lang==='en' ? (days.length === 1 ? 'working day' : 'working days') : 'hari bekerja')">5 working days</span>
                </div>
            </form>
        </div>
        @endif

```

- [ ] **Step 6: Run the feature test, expect green**

Run: `php artisan test --compact tests/Feature/WorkWeekSettingsTest.php`
Expected: 6 passed. If `assertDontSee('Workspace profile')` fails because the layout's nav also contains the phrase, change that assertion to `->assertDontSee('name="industry"', false)`.

- [ ] **Step 7: Build assets and look at it**

Run: `lerd artisan view:clear && lerd artisan view:cache && bun run build`
Open `http://localhost:9100`, quick-login as Hidayah (HR), Company Settings: the Work week card sits at the top of the right column above Branches, Mon-Fri lit, "5 working days". Tap Sat, save: toast "6 working days saved.", Sat stays lit after reload. Untick everything: Save is disabled. Login as Shazwan (Employee): Company Settings is not reachable (existing gate).

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php app/Http/Controllers/AdminController.php resources/views/screens/settings.blade.php tests/Feature/WorkWeekSettingsTest.php public/build
git commit -m "feat(work-week): Work week card on Company Settings

Seven day tiles, at least one required, HR and management only, audited as
'Updated work week'. Forward-only, and the card says so."
```

---

### Task 7: Launch Center step "Set work week"

**Files:**
- Modify: `app/Http/Controllers/SetupController.php` line 74 (stepDefs, after `'profile'`)
- Test: extend `tests/Feature/WorkWeekSettingsTest.php`

**Interfaces:**
- Consumes: `SetupController::stepDefs(): array`, `SetupController::markStep()` (manual steps, lines 262-274), settings screen `?section=` embed.
- Produces: step key `work_week` (`domain` basics, `auto` false, `critical` false, `screen` settings, `query` `['section' => 'work_week']`).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/WorkWeekSettingsTest.php` inside the class:

```php
    public function test_launch_center_has_a_manual_set_work_week_step_after_profile(): void
    {
        // stepDefs() reads the current tenant for the payroll step; bind it as the middleware would.
        app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);
        $defs = app(\App\Http\Controllers\SetupController::class)->stepDefs();
        app(\App\Tenancy\CurrentTenant::class)->set(null);
        $keys = array_keys($defs);

        $this->assertContains('work_week', $keys);
        $this->assertSame(array_search('profile', $keys, true) + 1, array_search('work_week', $keys, true));
        $this->assertSame('settings', $defs['work_week']['screen']);
        $this->assertSame(['section' => 'work_week'], $defs['work_week']['query']);
        $this->assertFalse($defs['work_week']['auto']);
        $this->assertSame('basics', $defs['work_week']['domain']);

        // Manual step: HR ticks it by hand.
        $this->actingAsRole('hr')->post(route('setup.step'), ['step' => 'work_week'])->assertRedirect();
    }
```

- [ ] **Step 2: Run it, expect failure**

Run: `php artisan test --compact --filter=test_launch_center_has_a_manual_set_work_week_step_after_profile`
Expected: fails, `work_week` not in the keys.

- [ ] **Step 3: Add the step**

Edit `app/Http/Controllers/SetupController.php`. After line 74 (the `'profile' => [...]` entry) add:

```php
            // Work week: which days are working days. Manual tick (Mon-Fri is a valid
            // answer, so there is no data signal); deep-links to the card alone.
            'work_week' => ['label' => 'Set work week', 'label_ms' => 'Tetapkan minggu bekerja', 'desc' => 'Which days of the week count as working days. Leave, timesheets and attendance follow it.', 'screen' => 'settings', 'query' => ['section' => 'work_week'], 'auto' => false, 'domain' => 'basics', 'critical' => false],
```

- [ ] **Step 4: Run, expect green**

Run: `php artisan test --compact tests/Feature/WorkWeekSettingsTest.php`
Expected: 7 passed. Then the Launch Center suites: `php artisan test --compact --filter=Setup`
Expected: green (a test that pins the exact step count or order under basics will need `work_week` added to its expected list; do that only in `tests/Feature`, never `tests/Acceptance`).

- [ ] **Step 5: Look at it**

Open `http://localhost:9100`, HR login, Launch Center: "Set work week" appears under Company basics right after "Complete company profile"; its link opens Company Settings showing only the Work week card.

- [ ] **Step 6: Full suite, then commit**

Run: `php artisan test --compact`
Expected: green.

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SetupController.php tests/Feature/WorkWeekSettingsTest.php
git commit -m "feat(work-week): Launch Center step 'Set work week' under Company basics

Manual tick, deep-links to the Work week card alone via ?section=work_week."
```

---

## Coverage check against the spec

| Spec line | Where |
|---|---|
| `tenants.work_days` JSON list, default `[1,2,3,4,5]` | Task 1 migration (varchar holding JSON, reason stated) |
| `tenants.tot_saturday` default false, no UI, migration sets Unijaya | Task 1 migration data step (both Unijaya slugs); Task 6 controller never accepts it |
| Validation: at least one working day | Task 6 `required|array|min:1`, test `test_at_least_one_working_day_is_required` |
| `WorkWeek::isWorkingDay / capacity / isTotDay` | Task 1 |
| Central classes routed | Task 2 (DayRules, DayCapacity, LockedDays, LeaveRequest::countDays, ReportPeriod, BoardSuggestions); Awards already goes through DayRules |
| Inline copies routed | Task 4 (BirthdayWishController, BirthdayNotify, BuildsDashboardWidgets, HolidayEve, ReminderTargets, plus CreateRecurringWorkItems); DashboardWidgets has only the Friday sign-off window, out of scope |
| Week boundaries unchanged | Not touched (Monday start, Friday deadline, meeting day, Friday sign-off) |
| Settings panel, HR only; Launch Center step | Tasks 6 and 7 |
| Forward-only, panel says so | Task 6 hint line; no recalculation anywhere |
| Saturday ticked beats TOT | Task 1 `isTotDay` precedence; tests in Tasks 1 and 2 |
| Holidays checked after the work-day check | Every caller keeps its holiday check after the WorkWeek check (Tasks 2, 4) |
| Zero-working-day week: capacity 0, submit not blocked | Task 2 WeekWriter gate + `test_a_week_with_nothing_to_fill_submits_without_an_error` |
| Factory defaults | No factory exists; DB defaults serve; Unijaya-shaped tests set the flag (Task 3) |
| WorkWeek unit tests (default, six-day, TOT on/off, capacity) | Task 1 |
| tot_saturday=false feature test (leave 0, capacity 0) | Task 2 `test_without_tot_the_first_saturday_is_a_day_off_for_leave_and_timesheets` |
| Existing TOT tests green with the flag on | Task 3 |
