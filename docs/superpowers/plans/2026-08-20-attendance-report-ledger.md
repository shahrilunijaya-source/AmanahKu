# Attendance Report Ledger Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the roster-style attendance report with a per-person-per-day ledger that HR can filter, reconcile, export to CSV, and repair in place.

**Architecture:** Three new value/builder classes under `app/Attendance/` carry the logic (period resolution, row synthesis, totals), leaving `AttendanceReportController` as thin wiring for scope and permissions. A second controller streams the CSV. The Blade screen is split into the table screen plus a person-drawer partial. No new dependencies.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12, Blade, Alpine 3, Tailwind v4 + hand-written `uj-` CSS in `resources/css/app.css`, Larastan 3, Pint.

**Spec:** [docs/superpowers/specs/2026-08-20-attendance-report-ledger-design.md](../specs/2026-08-20-attendance-report-ledger-design.md)

**Approved mockup:** `public/_attendance-report-ledger.html` — serve at `http://localhost:9100/_attendance-report-ledger.html`. It is the source of truth for layout, copy and interaction. Read it before Tasks 7–9.

## Global Constraints

- PHP 8.5 / Laravel 13. **No new composer or npm dependencies.** CSV export uses `App\Support\Csv` + `fputcsv`, never a spreadsheet library.
- Every user-facing string ships English **and** Malay, via the existing `$store.ui.lang` Alpine pattern (`x-text="$store.ui.lang==='en' ? … : …"`). Malay runs ~15% longer; check it at 390px.
- Follow `docs/DESIGN.md` ("The Quiet Ledger"): warm paper canvas, Signal Red only for the primary action and focus ring, 1px hairlines instead of shadows at rest, mono tabular figures for every number, five type steps (11 / 12.5 / 14 / 16 / 22), radius scale 5 / 7 / 9–10 / 12 / 14 / 999.
- Data-dense screens use the 1280px measure.
- Every animated block carries a `prefers-reduced-motion: reduce` branch.
- Row layout goes in a class, never an inline `style`, on anything `x-show` controls — Alpine wipes inline `display` on reveal.
- **Do not delete any test file.** `CLAUDE.md` forbids it without approval. Rewrite in place.
- Run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- Run `vendor/bin/phpstan analyse` before pushing — CI only runs on PRs to `main`, so staging deploys unanalysed code.
- Permission constants keep their current values: `REVERSE_ROLES = ['hr','director']`, `LOCATION_ROLES = Permissions::OVERSIGHT_ROLES + ['director']`.
- Tests run against MySQL parity in CI. Never assert a raw date/time column value — MySQL normalises them and sqlite does not. Compare formatted strings.

---

## File Structure

**Create**
- `app/Attendance/ReportPeriod.php` — resolves `gran`/`from`/`to`/labels/stepper state and the working-day list.
- `app/Attendance/LedgerBuilder.php` — employees × working days → ledger rows.
- `app/Attendance/LedgerTotals.php` — a row set → totals, lens counts, leave-by-type.
- `app/Http/Controllers/AttendanceReportExportController.php` — streams the CSV.
- `resources/views/partials/attendance-report/person-drawer.blade.php` — the drawer.
- `tests/Unit/ReportPeriodTest.php`
- `tests/Feature/AttendanceLedgerRowsTest.php`
- `tests/Feature/AttendanceLedgerTotalsTest.php`
- `tests/Feature/AttendanceReportExportTest.php`
- `tests/Feature/AttendanceReportDrawerTest.php`

**Modify**
- `app/Http/Controllers/AttendanceReportController.php` — gutted to wiring.
- `resources/views/screens/attendance-report.blade.php` — rewritten.
- `resources/css/app.css` — replace the `uj-ar-*` block (currently ~line 1653).
- `routes/web.php` — add the export route and the amend-clock-out route beside the other report routes.
- `app/Http/Controllers/AttendanceAdminController.php` — add `amendClockOut()`; there is no endpoint today that can set a clock-out.
- `tests/Feature/AttendanceAdminTest.php` — cover the new endpoint.
- `tests/Feature/AttendanceReportDataTest.php` — rewrite against ledger rows.
- `tests/Feature/AttendanceReportSummaryTest.php` — rewrite as lens counts.
- `tests/Feature/AttendanceReportScreenTest.php` — update selectors.
- `tests/Feature/AttendanceReportLocationTest.php` — update selectors only.

---

### Task 1: Period resolution

**Files:**
- Create: `app/Attendance/ReportPeriod.php`
- Test: `tests/Unit/ReportPeriodTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `ReportPeriod::fromRequest(array $query, CarbonImmutable $today): self` with public readonly `string $gran`, `CarbonImmutable $from`, `CarbonImmutable $to`, `bool $canPrev`, `bool $canNext`; and methods `label(string $lang): string`, `rangeLabel(string $lang): string`, `captionKey(): string`, `workingDays(array $recordDates): array`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Attendance\ReportPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        // A Thursday, mid-month, so month/week/day all differ.
        $this->today = CarbonImmutable::parse('2026-08-20');
    }

    public function test_it_defaults_to_the_calendar_month_to_date(): void
    {
        $p = ReportPeriod::fromRequest([], $this->today);

        $this->assertSame('month', $p->gran);
        $this->assertSame('2026-08-01', $p->from->toDateString());
        $this->assertSame('2026-08-20', $p->to->toDateString());
        $this->assertSame('August 2026', $p->label('en'));
        $this->assertSame('Ogos 2026', $p->label('ms'));
    }

    public function test_week_is_the_calendar_week_containing_today(): void
    {
        $p = ReportPeriod::fromRequest(['gran' => 'week'], $this->today);

        $this->assertSame('2026-08-17', $p->from->toDateString()); // Monday
        $this->assertSame('2026-08-20', $p->to->toDateString());   // today, not Sunday
    }

    public function test_day_is_today(): void
    {
        $p = ReportPeriod::fromRequest(['gran' => 'day'], $this->today);

        $this->assertSame('2026-08-20', $p->from->toDateString());
        $this->assertSame('2026-08-20', $p->to->toDateString());
        $this->assertSame('Thu, 20 Aug', $p->label('en'));
        $this->assertSame('Kha, 20 Ogos', $p->label('ms'));
    }

    public function test_an_offset_steps_backwards_and_clamps_forward(): void
    {
        $back = ReportPeriod::fromRequest(['gran' => 'week', 'offset' => '-1'], $this->today);
        $this->assertSame('2026-08-10', $back->from->toDateString());
        $this->assertSame('2026-08-14', $back->to->toDateString());
        $this->assertTrue($back->canNext);

        $now = ReportPeriod::fromRequest(['gran' => 'week'], $this->today);
        $this->assertFalse($now->canNext, 'cannot step into the future');
    }

    public function test_a_custom_range_is_honoured(): void
    {
        $p = ReportPeriod::fromRequest(
            ['gran' => 'custom', 'from' => '2026-08-10', 'to' => '2026-08-12'],
            $this->today
        );

        $this->assertSame('custom', $p->gran);
        $this->assertSame('2026-08-10', $p->from->toDateString());
        $this->assertSame('2026-08-12', $p->to->toDateString());
        $this->assertFalse($p->canPrev);
        $this->assertFalse($p->canNext);
    }

    public function test_a_reversed_or_future_custom_range_falls_back_to_the_month(): void
    {
        $reversed = ReportPeriod::fromRequest(
            ['gran' => 'custom', 'from' => '2026-08-12', 'to' => '2026-08-10'],
            $this->today
        );
        $this->assertSame('month', $reversed->gran);

        $future = ReportPeriod::fromRequest(
            ['gran' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'],
            $this->today
        );
        $this->assertSame('2026-08-20', $future->to->toDateString(), 'clamped to today');
    }

    public function test_garbage_input_falls_back_to_the_month(): void
    {
        $p = ReportPeriod::fromRequest(['gran' => '../../etc/passwd'], $this->today);

        $this->assertSame('month', $p->gran);
    }

    public function test_working_days_are_weekdays_plus_any_date_carrying_a_record(): void
    {
        $p = ReportPeriod::fromRequest(['gran' => 'week'], $this->today);

        $this->assertSame(
            ['2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20'],
            $p->workingDays([])
        );
    }

    public function test_a_weekend_record_joins_the_working_days(): void
    {
        $p = ReportPeriod::fromRequest(
            ['gran' => 'custom', 'from' => '2026-08-14', 'to' => '2026-08-18'],
            $this->today
        );

        // 15th is a Saturday. Nobody normally works it; this one person did.
        $this->assertSame(
            ['2026-08-14', '2026-08-15', '2026-08-17', '2026-08-18'],
            $p->workingDays(['2026-08-15'])
        );
    }

    public function test_the_caption_names_the_period_it_totals(): void
    {
        $this->assertSame('month', ReportPeriod::fromRequest([], $this->today)->captionKey());
        $this->assertSame('week', ReportPeriod::fromRequest(['gran' => 'week'], $this->today)->captionKey());
        $this->assertSame('day', ReportPeriod::fromRequest(['gran' => 'day'], $this->today)->captionKey());
        $this->assertSame(
            'weekPast',
            ReportPeriod::fromRequest(['gran' => 'week', 'offset' => '-1'], $this->today)->captionKey(),
            'a past week is named, not called "this week"'
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lerd artisan test --compact tests/Unit/ReportPeriodTest.php`
Expected: FAIL — `Class "App\Attendance\ReportPeriod" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Attendance;

use Carbon\CarbonImmutable;

/**
 * The period an attendance report covers, resolved from query input and clamped
 * so it can never reach into the future. Owns its own labels because the caption
 * above the totals must name the period it actually totals — a block reading
 * "month to date" over one week's figures is worse than no caption.
 */
final class ReportPeriod
{
    private const GRANS = ['day', 'week', 'month', 'custom'];

    /** Carbon ships no bundled BM locale here; same hand-map as AttendanceReportController used. */
    private const MS_DAYS = ['Ahd', 'Isn', 'Sel', 'Rab', 'Kha', 'Jum', 'Sab'];

    /** @var array<int, string> */
    private const MS_MONTHS = [1 => 'Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun',
        'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'];

    /** @var array<int, string> */
    private const MS_MONTHS_SHORT = [1 => 'Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun',
        'Jul', 'Ogos', 'Sep', 'Okt', 'Nov', 'Dis'];

    private function __construct(
        public readonly string $gran,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $offset,
        public readonly bool $canPrev,
        public readonly bool $canNext,
        private readonly CarbonImmutable $today,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromRequest(array $query, CarbonImmutable $today): self
    {
        $today = $today->startOfDay();
        $gran = is_string($query['gran'] ?? null) && in_array($query['gran'], self::GRANS, true)
            ? $query['gran']
            : 'month';

        if ($gran === 'custom') {
            $custom = self::custom($query, $today);
            if ($custom !== null) {
                return $custom;
            }
            $gran = 'month';
        }

        // Negative only: stepping forward past the current period is meaningless.
        $offset = min(0, (int) ($query['offset'] ?? 0));

        [$from, $to] = match ($gran) {
            'day' => self::dayWindow($today, $offset),
            'week' => self::weekWindow($today, $offset),
            default => self::monthWindow($today, $offset),
        };

        return new self($gran, $from, $to, $offset, true, $offset < 0, $today);
    }

    /** @param array<string, mixed> $query */
    private static function custom(array $query, CarbonImmutable $today): ?self
    {
        $from = self::parse($query['from'] ?? null);
        $to = self::parse($query['to'] ?? null);

        if ($from === null || $to === null || $from->gt($to)) {
            return null;
        }

        return new self('custom', $from, $to->min($today), 0, false, false, $today);
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private static function dayWindow(CarbonImmutable $today, int $offset): array
    {
        $d = $today->addDays($offset);

        return [$d, $d];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private static function weekWindow(CarbonImmutable $today, int $offset): array
    {
        $start = $today->addWeeks($offset)->startOfWeek(CarbonImmutable::MONDAY);

        return [$start, $start->addDays(4)->min($today)];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private static function monthWindow(CarbonImmutable $today, int $offset): array
    {
        $start = $today->addMonths($offset)->startOfMonth();

        return [$start, $start->endOfMonth()->startOfDay()->min($today)];
    }

    public function label(string $lang): string
    {
        $ms = $lang === 'ms';

        return match ($this->gran) {
            'day' => ($ms ? self::MS_DAYS[$this->from->dayOfWeek] : $this->from->format('D'))
                .', '.$this->from->day.' '.$this->shortMonth($this->from, $ms),
            'week', 'custom' => $this->from->day.' – '.$this->to->day.' '.$this->shortMonth($this->to, $ms),
            default => ($ms ? self::MS_MONTHS[(int) $this->from->month] : $this->from->format('F'))
                .' '.$this->from->year,
        };
    }

    public function rangeLabel(string $lang): string
    {
        $ms = $lang === 'ms';

        return $this->from->equalTo($this->to)
            ? $this->from->day.' '.$this->shortMonth($this->from, $ms).' '.$this->from->year
            : $this->from->day.' – '.$this->to->day.' '.$this->shortMonth($this->to, $ms).' '.$this->to->year;
    }

    private function shortMonth(CarbonImmutable $at, bool $ms): string
    {
        return $ms ? self::MS_MONTHS_SHORT[(int) $at->month] : $at->format('M');
    }

    /** Which caption the totals block wears. 'weekPast'/'dayPast' get a named date instead. */
    public function captionKey(): string
    {
        if ($this->gran === 'custom') {
            return 'custom';
        }
        if ($this->offset === 0) {
            return $this->gran;
        }

        return $this->gran.'Past';
    }

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
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `lerd artisan test --compact tests/Unit/ReportPeriodTest.php`
Expected: PASS, 10 tests

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Attendance/ReportPeriod.php tests/Unit/ReportPeriodTest.php
git commit -m "feat(attendance-report): resolve the report period as day, week, month or custom range

Calendar month to date replaces the rolling 30-day window, and the period owns its
own caption so a totals block can never claim to be a month while holding a week."
```

---

### Task 2: Ledger row synthesis

**Files:**
- Create: `app/Attendance/LedgerBuilder.php`
- Test: `tests/Feature/AttendanceLedgerRowsTest.php`

**Interfaces:**
- Consumes: `ReportPeriod` from Task 1.
- Produces: `LedgerBuilder::build(Collection $employees, Collection $records, Collection $leaveRequests, array $workingDays, CarbonImmutable $today): Collection` returning the `LedgerRow` shape from the spec.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\LedgerBuilder;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceLedgerRowsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $alice;

    /** @var list<string> */
    private array $days = ['2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00:00'));

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $this->alice = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Alice Tan',
            'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function build(): \Illuminate\Support\Collection
    {
        return app(LedgerBuilder::class)->build(
            Employee::active()->get(),
            AttendanceRecord::all(),
            LeaveRequest::where('status', 'approved')->get(),
            $this->days,
            CarbonImmutable::parse('2026-08-20'),
        );
    }

    public function test_an_employee_with_no_records_still_gets_a_row_per_working_day(): void
    {
        $rows = $this->build();

        $this->assertCount(4, $rows, 'one row per working day even with zero records');
        $this->assertSame(
            ['absent', 'absent', 'absent', 'pending'],
            $rows->pluck('status')->all(),
            'past days read as no-punch; today is still pending'
        );
    }

    public function test_a_clocked_day_carries_its_times_and_hours(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-18', 'clock_in' => '08:52:00', 'clock_out' => '17:35:00',
            'status' => 'on_time', 'worked_minutes' => 523, 'flags' => [],
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-18');

        $this->assertSame('08:52', $row['in']);
        $this->assertSame('17:35', $row['out']);
        $this->assertSame(8.72, round($row['hours'], 2));
        $this->assertSame('ontime', $row['status']);
    }

    public function test_a_clock_in_with_no_clock_out_on_a_past_day_is_missing_out(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-18', 'clock_in' => '08:52:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-18');

        $this->assertSame('miss', $row['status']);
        $this->assertNull($row['hours']);
    }

    public function test_an_open_punch_today_is_pending_not_broken(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-20', 'clock_in' => '08:52:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-20');

        $this->assertSame('ontime', $row['status'], 'still mid-shift, not a missing clock-out');
    }

    public function test_approved_leave_reads_as_leave_and_carries_its_type(): void
    {
        $type = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Annual leave',
            'code' => 'AL', 'days_per_year' => 14,
        ]);
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'leave_type_id' => $type->id, 'date_from' => '2026-08-18',
            'date_to' => '2026-08-18', 'days' => 1, 'status' => 'approved',
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-18');

        $this->assertSame('leave', $row['status']);
        $this->assertSame('Annual leave', $row['leaveType']);
    }

    public function test_a_short_day_is_flagged_and_a_very_short_one_is_a_half_day(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-17', 'clock_in' => '09:00:00', 'clock_out' => '12:00:00',
            'status' => 'on_time', 'worked_minutes' => 180, 'flags' => ['short_hours'],
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-17');

        $this->assertSame('half', $row['status'], 'under 5 hours reads as a half day');
        $this->assertContains('short', $row['flags']);
    }

    public function test_an_off_site_punch_is_flagged_and_advertises_a_map_point(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'status' => 'on_time', 'worked_minutes' => 540,
            'flags' => ['out_of_radius_in'], 'latitude' => 3.1368, 'longitude' => 101.6546,
        ]);

        $row = $this->build()->firstWhere('date', '2026-08-18');

        $this->assertContains('off', $row['flags']);
        $this->assertTrue($row['hasPoint']);
    }

    public function test_an_off_site_flag_without_coordinates_offers_no_map(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'status' => 'on_time', 'worked_minutes' => 540,
            'flags' => ['out_of_radius_in'], 'latitude' => null, 'longitude' => null,
        ]);

        $this->assertFalse($this->build()->firstWhere('date', '2026-08-18')['hasPoint']);
    }

    public function test_an_archived_employee_gets_no_rows(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Gone Person',
            'status' => 'resigned', 'workload' => 'green',
        ]);

        $this->assertSame(['Alice Tan'], $this->build()->pluck('name')->unique()->all());
    }

    public function test_rows_carry_the_record_id_only_when_a_record_exists(): void
    {
        $rec = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->alice->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'status' => 'on_time', 'worked_minutes' => 540, 'flags' => [],
        ]);

        $rows = $this->build();

        $this->assertSame($rec->id, $rows->firstWhere('date', '2026-08-18')['recordId']);
        $this->assertNull($rows->firstWhere('date', '2026-08-17')['recordId'],
            'a synthesized no-punch row has nothing to reverse');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lerd artisan test --compact tests/Feature/AttendanceLedgerRowsTest.php`
Expected: FAIL — `Class "App\Attendance\LedgerBuilder" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns the employee list and the records into one row per person per working day.
 *
 * Built from the EMPLOYEE list, never from the records. A report assembled out of
 * attendance rows cannot show non-attendance: someone who never clocked, or who
 * stopped three weeks ago, produces no record and so would silently vanish. That
 * failure is what retired the pre-roster version of this screen; the rule stands.
 */
final class LedgerBuilder
{
    /** A worked span under this reads as a half day rather than a short full day. */
    private const HALF_DAY_MINUTES = 300;

    /** Record flag → the short name this screen renders. */
    private const FLAG_MAP = [
        'out_of_radius_in' => 'off',
        'out_of_radius_out' => 'off',
        'short_hours' => 'short',
        'early_out' => 'early',
        'no_location' => 'noloc',
    ];

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  Collection<int, \App\Models\LeaveRequest>  $leaveRequests
     * @param  list<string>  $workingDays
     * @return Collection<int, array<string, mixed>>
     */
    public function build(
        Collection $employees,
        Collection $records,
        Collection $leaveRequests,
        array $workingDays,
        CarbonImmutable $today,
    ): Collection {
        $byEmployeeDate = [];
        foreach ($records as $r) {
            $byEmployeeDate[$r->employee_id][$r->date->toDateString()] = $r;
        }

        $leave = [];
        foreach ($leaveRequests as $l) {
            $leave[$l->employee_id][] = [
                'from' => $l->date_from->toDateString(),
                'to' => $l->date_to->toDateString(),
                'type' => $l->leaveType?->name,
            ];
        }

        $todayStr = $today->toDateString();
        $rows = [];

        foreach ($employees as $emp) {
            foreach ($workingDays as $date) {
                $rows[] = $this->row(
                    $emp,
                    $byEmployeeDate[$emp->id][$date] ?? null,
                    $leave[$emp->id] ?? [],
                    $date,
                    $todayStr,
                );
            }
        }

        return collect($rows);
    }

    /**
     * @param  list<array{from: string, to: string, type: string|null}>  $leave
     * @return array<string, mixed>
     */
    private function row(Employee $emp, ?AttendanceRecord $r, array $leave, string $date, string $todayStr): array
    {
        $base = [
            'employeeId' => $emp->id,
            'name' => $emp->display_name,
            'initials' => $emp->initials,
            'color' => $emp->avatar_color,
            'dept' => $emp->department?->name,
            'date' => $date,
            'dow' => CarbonImmutable::parse($date)->dayOfWeek,
            'in' => null,
            'out' => null,
            'hours' => null,
            'flags' => [],
            'leaveType' => null,
            'recordId' => $r?->id,
            'hasPoint' => false,
        ];

        if ($r === null || $r->clock_in === null) {
            $covering = $this->leaveOn($leave, $date);

            return $base + ['status' => match (true) {
                $covering !== false => 'leave',
                $date === $todayStr => 'pending',
                default => 'absent',
            }, 'leaveType' => $covering ?: null];
        }

        $flags = $this->flags($r);
        $minutes = (int) ($r->worked_minutes ?? 0);
        // An open punch dated today is still mid-shift; only a past one is broken.
        $missing = $r->clock_out === null && $date !== $todayStr;

        $status = match (true) {
            $missing => 'miss',
            $r->clock_out !== null && $minutes > 0 && $minutes < self::HALF_DAY_MINUTES => 'half',
            $r->status === 'late' => 'late',
            default => 'ontime',
        };

        return array_merge($base, [
            'in' => $this->hhmm($r->clock_in),
            'out' => $r->clock_out !== null ? $this->hhmm($r->clock_out) : null,
            'hours' => $r->clock_out !== null && $minutes > 0 ? round($minutes / 60, 2) : null,
            'status' => $status,
            'flags' => $flags,
            'hasPoint' => $this->hasPoint($r),
        ]);
    }

    /** @return list<string> */
    private function flags(AttendanceRecord $r): array
    {
        $out = [];
        foreach ($r->flags ?? [] as $flag) {
            // 'late' is a status, not a flag — rendering it twice reads as two problems.
            if ($flag === 'late') {
                continue;
            }
            $mapped = self::FLAG_MAP[$flag] ?? null;
            if ($mapped !== null && ! in_array($mapped, $out, true)) {
                $out[] = $mapped;
            }
        }

        if ($r->work_mode === 'site_visit' || $r->clock_out_work_mode === 'site_visit') {
            array_unshift($out, 'visit');
        }

        return $out;
    }

    /**
     * within() returns null (never false) when coordinates or the geofence are
     * missing, so an out_of_radius flag already implies a usable point. The
     * null-check guards hand-edited rows, not the normal path.
     */
    private function hasPoint(AttendanceRecord $r): bool
    {
        $inPoint = ($this->flagged($r, 'out_of_radius_in') || $r->work_mode === 'site_visit')
            && $r->latitude !== null && $r->longitude !== null;
        $outPoint = ($this->flagged($r, 'out_of_radius_out') || $r->clock_out_work_mode === 'site_visit')
            && $r->clock_out_latitude !== null && $r->clock_out_longitude !== null;

        return $inPoint || $outPoint;
    }

    private function flagged(AttendanceRecord $r, string $flag): bool
    {
        return in_array($flag, $r->flags ?? [], true);
    }

    /** @param list<array{from: string, to: string, type: string|null}> $leave */
    private function leaveOn(array $leave, string $date): string|false
    {
        foreach ($leave as $l) {
            if ($date >= $l['from'] && $date <= $l['to']) {
                return $l['type'] ?? '';
            }
        }

        return false;
    }

    private function hhmm(string $time): string
    {
        return (string) Str::of($time)->limit(5, '');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `lerd artisan test --compact tests/Feature/AttendanceLedgerRowsTest.php`
Expected: PASS, 10 tests

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Attendance/LedgerBuilder.php tests/Feature/AttendanceLedgerRowsTest.php
git commit -m "feat(attendance-report): synthesize one ledger row per employee per working day

Rows come from the employee list crossed with the working days, so a person who
never clocked occupies real rows saying No punch instead of vanishing."
```

---

### Task 3: Totals, lens counts, and the scope-not-lens rule

**Files:**
- Create: `app/Attendance/LedgerTotals.php`
- Test: `tests/Feature/AttendanceLedgerTotalsTest.php`

**Interfaces:**
- Consumes: the row shape from Task 2.
- Produces: `LedgerTotals::of(Collection $scopedRows): array` returning `['present','absent','late','leave','staff','hours','leaveByType']`, and `LedgerTotals::counts(Collection $scopedRows): array` returning `['all','miss','absent','short','late']`, and `LedgerTotals::applyLens(Collection $rows, ?string $lens): Collection`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\LedgerTotals;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class AttendanceLedgerTotalsTest extends TestCase
{
    private function rows(): Collection
    {
        return collect([
            $this->row(1, 'ontime', hours: 8.5),
            $this->row(1, 'late', hours: 8.0),
            $this->row(1, 'miss', hours: null),
            $this->row(2, 'absent', hours: null),
            $this->row(2, 'leave', hours: null, leaveType: 'Annual leave'),
            $this->row(2, 'leave', hours: null, leaveType: 'Medical leave'),
            $this->row(2, 'half', hours: 3.0, flags: ['short']),
        ]);
    }

    /** @param list<string> $flags */
    private function row(int $emp, string $status, ?float $hours, array $flags = [], ?string $leaveType = null): array
    {
        return ['employeeId' => $emp, 'status' => $status, 'hours' => $hours,
            'flags' => $flags, 'leaveType' => $leaveType];
    }

    public function test_totals_count_each_status_and_sum_the_hours(): void
    {
        $t = LedgerTotals::of($this->rows());

        // present = anything that is neither absent nor leave: on time, late, miss, half.
        $this->assertSame(4, $t['present']);
        $this->assertSame(1, $t['absent']);
        $this->assertSame(1, $t['late']);
        $this->assertSame(2, $t['leave']);
        $this->assertSame(2, $t['staff'], 'distinct people, not rows');
        $this->assertSame(19.5, $t['hours']);
    }

    public function test_leave_is_broken_down_by_type(): void
    {
        $this->assertSame(
            ['Annual leave' => 1, 'Medical leave' => 1],
            LedgerTotals::of($this->rows())['leaveByType']
        );
    }

    public function test_lens_counts_describe_the_scope(): void
    {
        $this->assertSame(
            ['all' => 7, 'miss' => 1, 'absent' => 1, 'short' => 1, 'late' => 1],
            LedgerTotals::counts($this->rows())
        );
    }

    public function test_a_lens_narrows_the_rows(): void
    {
        $rows = $this->rows();

        $this->assertCount(1, LedgerTotals::applyLens($rows, 'miss'));
        $this->assertCount(1, LedgerTotals::applyLens($rows, 'late'));
        $this->assertCount(1, LedgerTotals::applyLens($rows, 'short'));
        $this->assertCount(7, LedgerTotals::applyLens($rows, null));
        $this->assertCount(7, LedgerTotals::applyLens($rows, 'nonsense'), 'unknown lens shows everything');
    }

    public function test_the_totals_do_not_move_when_a_lens_is_applied(): void
    {
        $rows = $this->rows();
        $before = LedgerTotals::of($rows);
        $after = LedgerTotals::of($rows);   // callers pass SCOPED rows, never lensed ones

        $this->assertSame($before, $after);

        // The guarantee that matters: totals over scope != totals over the lensed subset.
        $lensed = LedgerTotals::of(LedgerTotals::applyLens($rows, 'miss'));
        $this->assertNotSame(
            $before['present'],
            $lensed['present'],
            'if these ever match, the caller is totalling the wrong set'
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lerd artisan test --compact tests/Feature/AttendanceLedgerTotalsTest.php`
Expected: FAIL — `Class "App\Attendance\LedgerTotals" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Attendance;

use Illuminate\Support\Collection;

/**
 * Totals and lens counts for a set of ledger rows.
 *
 * Callers must pass SCOPED rows — filtered by department and staff search, but
 * NOT by the exception lens. Department and name change whose period this is;
 * the lens only changes which rows you are looking at within it. A block
 * captioned "month to date" that reads 19 present because somebody clicked
 * "missing clock-out" is not a total, it is a lie.
 */
final class LedgerTotals
{
    /**
     * @param  Collection<int, array<string, mixed>>  $scopedRows
     * @return array<string, mixed>
     */
    public static function of(Collection $scopedRows): array
    {
        $leaveByType = [];
        foreach ($scopedRows as $row) {
            if ($row['status'] === 'leave' && ($row['leaveType'] ?? null)) {
                $leaveByType[$row['leaveType']] = ($leaveByType[$row['leaveType']] ?? 0) + 1;
            }
        }
        arsort($leaveByType);

        return [
            'present' => $scopedRows->whereNotIn('status', ['absent', 'leave', 'pending'])->count(),
            'absent' => $scopedRows->where('status', 'absent')->count(),
            'late' => $scopedRows->where('status', 'late')->count(),
            'leave' => $scopedRows->where('status', 'leave')->count(),
            'staff' => $scopedRows->pluck('employeeId')->unique()->count(),
            'hours' => round((float) $scopedRows->sum(fn (array $r) => $r['hours'] ?? 0), 1),
            'leaveByType' => $leaveByType,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $scopedRows
     * @return array<string, int>
     */
    public static function counts(Collection $scopedRows): array
    {
        return [
            'all' => $scopedRows->count(),
            'miss' => $scopedRows->where('status', 'miss')->count(),
            'absent' => $scopedRows->where('status', 'absent')->count(),
            'short' => $scopedRows->filter(fn (array $r) => in_array('short', $r['flags'], true))->count(),
            'late' => $scopedRows->where('status', 'late')->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public static function applyLens(Collection $rows, ?string $lens): Collection
    {
        return match ($lens) {
            'miss' => $rows->where('status', 'miss')->values(),
            'absent' => $rows->where('status', 'absent')->values(),
            'late' => $rows->where('status', 'late')->values(),
            'short' => $rows->filter(fn (array $r) => in_array('short', $r['flags'], true))->values(),
            default => $rows->values(),
        };
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `lerd artisan test --compact tests/Feature/AttendanceLedgerTotalsTest.php`
Expected: PASS, 5 tests

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Attendance/LedgerTotals.php tests/Feature/AttendanceLedgerTotalsTest.php
git commit -m "feat(attendance-report): total the period scope, never the exception lens

Department and staff search change whose period this is and move the totals.
The exception chips only change which rows are shown and leave them alone."
```

---

### Task 4: Controller rewrite

**Files:**
- Modify: `app/Http/Controllers/AttendanceReportController.php` (replace the body; keep the class name, the two permission constants, and the `screenData(Request): array` signature that `AppController.php:381` calls)
- Modify: `tests/Feature/AttendanceReportDataTest.php` — rewrite against the new payload
- Modify: `tests/Feature/AttendanceReportSummaryTest.php` — rewrite as lens-count assertions

**Interfaces:**
- Consumes: `ReportPeriod`, `LedgerBuilder`, `LedgerTotals` from Tasks 1–3.
- Produces: the `screenData()` payload documented in the spec's Data contract.

> **Read first:** the spec's "Data contract" and "Permissions" sections, and the
> current controller's scope handling at `AttendanceReportController.php:70-99`
> — the `DataScope`, orphan guard and `?emp=` refusal logic all carry forward
> unchanged and must not be re-derived.

- [ ] **Step 1: Rewrite `AttendanceReportDataTest` against the new payload**

Keep the existing `setUp()` (tenant, HR user, employee, leave type) verbatim. Replace the test methods with these, preserving each old test's underlying claim:

```php
    public function test_an_employee_with_no_records_still_appears_in_the_ledger(): void
    {
        $data = $this->screenData(['gran' => 'week']);

        $this->assertContains(
            $this->employee->name,
            $data['rows']->pluck('name')->all(),
            'a person with zero records must still occupy rows'
        );
    }

    public function test_approved_leave_is_not_counted_as_absence(): void
    {
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id, 'date_from' => '2026-07-14',
            'date_to' => '2026-07-14', 'days' => 1, 'status' => 'approved',
        ]);

        $data = $this->screenData(['gran' => 'week']);
        $row = $data['rows']->firstWhere('date', '2026-07-14');

        $this->assertSame('leave', $row['status']);
        $this->assertSame(0, $data['counts']['absent']);
    }

    public function test_pending_leave_does_not_excuse_an_absence(): void
    {
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id, 'date_from' => '2026-07-14',
            'date_to' => '2026-07-14', 'days' => 1, 'status' => 'pending',
        ]);

        $this->assertSame('absent', $this->screenData(['gran' => 'week'])
            ->get('rows')->firstWhere('date', '2026-07-14')['status']);
    }

    public function test_a_narrowed_data_scope_hides_other_staff(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Someone Else',
            'status' => 'active', 'workload' => 'green',
        ]);

        $data = $this->screenDataScoped(['gran' => 'week'], [$this->employee->id]);

        $this->assertNotContains('Someone Else', $data['rows']->pluck('name')->all());
        $this->assertSame(1, $data['totals']['staff']);
    }

    public function test_an_archived_employee_gets_no_rows(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Left The Company',
            'status' => 'resigned', 'workload' => 'green',
        ]);

        $this->assertNotContains(
            'Left The Company',
            $this->screenData(['gran' => 'week'])['rows']->pluck('name')->all()
        );
    }

    public function test_a_weekend_record_is_added_to_the_working_days(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-07-12', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00',
            'status' => 'on_time', 'worked_minutes' => 480, 'flags' => [],
        ]);

        $data = $this->screenData(['gran' => 'custom', 'from' => '2026-07-10', 'to' => '2026-07-14']);

        $this->assertContains('2026-07-12', $data['workingDays'], 'a Sunday somebody worked');
    }

    public function test_the_default_period_is_the_calendar_month_to_date(): void
    {
        $data = $this->screenData([]);

        $this->assertSame('month', $data['gran']);
        $this->assertSame('2026-07-01', $data['from']);
        $this->assertSame('2026-07-15', $data['to']);
    }

    public function test_a_department_filter_narrows_the_totals(): void
    {
        $dept = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Finance']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Finance Person',
            'department_id' => $dept->id, 'status' => 'active', 'workload' => 'green',
        ]);

        $data = $this->screenData(['gran' => 'week', 'dept' => 'Finance']);

        $this->assertSame(1, $data['totals']['staff']);
        $this->assertSame(['Finance Person'], $data['rows']->pluck('name')->unique()->all());
    }

    public function test_a_name_search_narrows_the_totals(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Zainab Osman',
            'status' => 'active', 'workload' => 'green',
        ]);

        $data = $this->screenData(['gran' => 'week', 'q' => 'zainab']);

        $this->assertSame(['Zainab Osman'], $data['rows']->pluck('name')->unique()->all());
        $this->assertSame(1, $data['totals']['staff']);
    }

    public function test_a_lens_narrows_the_rows_but_not_the_totals(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-07-14', 'clock_in' => '09:41:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $plain = $this->screenData(['gran' => 'week']);
        $lensed = $this->screenData(['gran' => 'week', 'lens' => 'miss']);

        $this->assertLessThan($plain['rows']->count(), $lensed['rows']->count());
        $this->assertSame(
            $plain['totals'],
            $lensed['totals'],
            'the caption says "to date"; a lens must not rewrite what that means'
        );
    }

    public function test_a_drill_through_outside_the_viewers_scope_is_refused(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Out Of Scope',
            'status' => 'active', 'workload' => 'green',
        ]);

        $data = $this->screenDataScoped(['emp' => (string) $other->id], [$this->employee->id]);

        $this->assertNull($data['person']);
    }
```

Add these two helpers to the class (the second stubs `DataScope` so the scope tests do not depend on org-chart fixtures):

```php
    /** @param array<string, string> $query */
    private function screenData(array $query): array
    {
        $request = Request::create('/app/attendance-report', 'GET', $query);
        $request->setUserResolver(fn () => $this->user);
        $request->attributes->set('tenantScope', 'company');
        $request->attributes->set('employee', $this->employee);

        return app(AttendanceReportController::class)->screenData($request);
    }

    /**
     * @param  array<string, string>  $query
     * @param  list<int>  $visibleIds
     * @return array<string, mixed>
     */
    private function screenDataScoped(array $query, array $visibleIds): array
    {
        $this->mock(\App\Services\DataScope::class, function ($mock) use ($visibleIds) {
            $mock->shouldReceive('visibleEmployeeIds')->andReturn($visibleIds);
        });

        return $this->screenData($query);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `lerd artisan test --compact tests/Feature/AttendanceReportDataTest.php`
Expected: FAIL — `Undefined array key "rows"` (the controller still returns the roster payload)

- [ ] **Step 3: Rewrite the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Attendance\LedgerBuilder;
use App\Attendance\LedgerTotals;
use App\Attendance\ReportPeriod;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\DataScope;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only attendance ledger for management / HR: one row per active employee
 * per working day, whether they clocked or not.
 */
class AttendanceReportController extends Controller
{
    /**
     * Reversing a punch is a step above the rest of this (already management/HR-gated)
     * report: 'management' is left out on purpose — only HR, a director, or a
     * super-admin observer may undo one. Mirrors AttendanceAdminController::REVERSE_ROLES,
     * which owns the actual reversePunch() action.
     */
    private const REVERSE_ROLES = ['hr', 'director'];

    /**
     * Seeing where a colleague physically stood is a step beyond reading that they
     * were off-site, so it does not inherit this screen's own gate.
     */
    private const LOCATION_ROLES = [...Permissions::OVERSIGHT_ROLES, 'director'];

    private const LENSES = ['miss', 'absent', 'short', 'late'];

    /** @return array<string, mixed> */
    public function screenData(Request $request): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $period = ReportPeriod::fromRequest($request->query(), $today);

        $dept = $request->query('dept') ?: null;
        $q = trim((string) $request->query('q', ''));
        $sort = $request->query('sort') === 'person' ? 'person' : 'date';
        $lens = in_array($request->query('lens'), self::LENSES, true) ? $request->query('lens') : null;

        // A branch/department-restricted manager only sees their own slice (AK-AUTHZ-01).
        $scope = $request->attributes->get('tenantScope', 'company');
        $self = $request->attributes->get('employee');
        $visibleIds = app(DataScope::class)->visibleEmployeeIds($scope, $self);

        $employees = Employee::active()
            ->when($visibleIds !== null, fn ($b) => $b->whereIn('id', $visibleIds))
            ->when($dept, fn ($b) => $b->whereHas('department', fn ($d) => $d->where('name', $dept)))
            ->when($q !== '', fn ($b) => $b->where('name', 'like', '%'.$q.'%'))
            ->where('status', '!=', 'resigned')
            ->with(['department:id,name'])
            ->get();

        $employeeIds = $employees->pluck('id')->all();
        $from = $period->from->toDateString();
        $to = $period->to->toDateString();

        // Tenant scope is automatic (BelongsToTenant).
        $records = AttendanceRecord::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get();

        $leaveRequests = LeaveRequest::query()
            ->where('status', 'approved')
            ->whereIn('employee_id', $employeeIds)
            ->where('date_from', '<=', $to)
            ->where('date_to', '>=', $from)
            ->with('leaveType:id,name')
            ->get();

        $workingDays = $period->workingDays(
            $records->map(fn ($r) => $r->date->toDateString())->unique()->values()->all()
        );

        $scoped = app(LedgerBuilder::class)
            ->build($employees, $records, $leaveRequests, $workingDays, $today);

        $rows = $this->sort(LedgerTotals::applyLens($scoped, $lens), $sort);

        return [
            'gran' => $period->gran,
            'from' => $from,
            'to' => $to,
            'label' => ['en' => $period->label('en'), 'ms' => $period->label('ms')],
            'rangeLabel' => ['en' => $period->rangeLabel('en'), 'ms' => $period->rangeLabel('ms')],
            'captionKey' => $period->captionKey(),
            'canPrev' => $period->canPrev,
            'canNext' => $period->canNext,
            'offset' => $period->offset,
            'workingDays' => $workingDays,

            'dept' => $dept,
            'departments' => Department::orderBy('name')->pluck('name'),
            'q' => $q,
            'sort' => $sort,
            'lens' => $lens,

            'rows' => $rows,
            'counts' => LedgerTotals::counts($scoped),
            'totals' => LedgerTotals::of($scoped),

            'person' => $this->person($request, $scoped, $visibleIds),
            'canReversePunch' => (bool) $request->user()?->isSuperAdmin()
                || $this->hasTenantRole($request, self::REVERSE_ROLES),
            'canSeeLocation' => (bool) $request->user()?->isSuperAdmin()
                || $this->hasTenantRole($request, self::LOCATION_ROLES),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sort(Collection $rows, string $sort): Collection
    {
        return $rows->sort(function (array $a, array $b) use ($sort): int {
            if ($sort === 'person') {
                return [$a['name'], $a['date']] <=> [$b['name'], $b['date']];
            }

            // Newest first, then alphabetical inside the day.
            return [$b['date'], $a['name']] <=> [$a['date'], $b['name']];
        })->values();
    }

    /**
     * The drawer's payload. Returns null when ?emp= is absent, archived, or outside
     * the viewer's data scope — a crafted id must not open somebody else's month.
     *
     * @param  Collection<int, array<string, mixed>>  $scoped
     * @param  list<int>|null  $visibleIds
     * @return array<string, mixed>|null
     */
    private function person(Request $request, Collection $scoped, ?array $visibleIds): ?array
    {
        if (! $request->filled('emp')) {
            return null;
        }

        $id = (int) $request->query('emp');
        if ($visibleIds !== null && ! in_array($id, $visibleIds, true)) {
            return null;
        }

        $employee = Employee::active()->with('department:id,name')->find($id);
        if ($employee === null) {
            return null;
        }

        $days = $scoped->where('employeeId', $id)->sortByDesc('date')->values();

        // ?day= deep-links a single day open, so the Fix button on a ledger row and
        // a plain click on the person both land on the same screen.
        $openDay = $request->query('day');
        $openDay = is_string($openDay) && $days->contains('date', $openDay) ? $openDay : null;

        return [
            'id' => $employee->id,
            'name' => $employee->display_name,
            'initials' => $employee->initials,
            'color' => $employee->avatar_color,
            'dept' => $employee->department?->name,
            'days' => $days,
            'openDay' => $openDay,
        ];
    }
}
```

- [ ] **Step 4: Rewrite `AttendanceReportSummaryTest` as lens counts**

Keep `setUp()`. Replace the twelve tests with these, preserving every claim:

```php
    public function test_a_missed_day_is_counted_in_the_no_punch_lens(): void
    {
        $this->assertSame(1, $this->counts(['gran' => 'day'])['absent']);
    }

    public function test_today_without_a_punch_is_not_counted_as_absent(): void
    {
        // 'pending', not 'absent' — nobody is late for a day that has not ended.
        $this->assertSame(0, $this->counts(['gran' => 'day'])['absent']);
    }

    public function test_late_days_are_counted_in_the_late_lens(): void
    {
        $this->record('2026-07-14', '09:41:00', '18:00:00', status: 'late');

        $this->assertSame(1, $this->counts(['gran' => 'week'])['late']);
    }

    public function test_an_off_site_day_is_not_counted_as_late(): void
    {
        $this->record('2026-07-14', '08:50:00', '18:00:00', flags: ['out_of_radius_in']);

        $this->assertSame(0, $this->counts(['gran' => 'week'])['late']);
    }

    public function test_approved_leave_is_not_counted_as_absent(): void
    {
        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id, 'date_from' => '2026-07-14',
            'date_to' => '2026-07-14', 'days' => 1, 'status' => 'approved',
        ]);

        $this->assertSame(0, $this->counts(['gran' => 'week'])['absent']);
    }

    public function test_short_hours_days_are_counted_in_the_short_lens(): void
    {
        $this->record('2026-07-14', '09:00:00', '13:00:00', minutes: 240, flags: ['short_hours']);

        $this->assertSame(1, $this->counts(['gran' => 'week'])['short']);
    }

    public function test_a_short_day_that_started_on_time_is_neither_late_nor_absent(): void
    {
        $this->record('2026-07-14', '08:50:00', '13:00:00', minutes: 250, flags: ['short_hours']);

        $counts = $this->counts(['gran' => 'week']);

        $this->assertSame(1, $counts['short']);
        $this->assertSame(0, $counts['late']);
        $this->assertSame(0, $counts['absent']);
    }

    public function test_a_full_length_day_is_not_counted_as_short(): void
    {
        $this->record('2026-07-14', '09:00:00', '18:00:00', minutes: 540);

        $this->assertSame(0, $this->counts(['gran' => 'week'])['short']);
    }

    public function test_a_missing_clock_out_is_counted_in_the_missing_lens(): void
    {
        $this->record('2026-07-14', '09:00:00', null);

        $this->assertSame(1, $this->counts(['gran' => 'week'])['miss']);
    }

    public function test_lens_counts_equal_the_rows_that_lens_returns(): void
    {
        $this->record('2026-07-14', '09:41:00', '18:00:00', status: 'late');

        $counts = $this->counts(['gran' => 'week']);
        $rows = $this->screenData(['gran' => 'week', 'lens' => 'late'])['rows'];

        $this->assertSame($counts['late'], $rows->count(),
            'a chip that says 1 and a table that shows 3 is a bug the user sees');
    }
```

With helpers:

```php
    /** @param array<string, string> $query */
    private function counts(array $query): array
    {
        return $this->screenData($query)['counts'];
    }

    /** @param list<string> $flags */
    private function record(string $date, string $in, ?string $out, string $status = 'on_time', ?int $minutes = 480, array $flags = []): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => $date, 'clock_in' => $in, 'clock_out' => $out,
            'status' => $status, 'worked_minutes' => $out === null ? null : $minutes,
            'flags' => $flags,
        ]);
    }
```

Copy the `screenData()` helper from Step 1 into this class too.

- [ ] **Step 5: Run both suites to verify they pass**

Run: `lerd artisan test --compact --filter='AttendanceReportData|AttendanceReportSummary'`
Expected: PASS

- [ ] **Step 6: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Http/Controllers/AttendanceReportController.php app/Attendance
git add app/Http/Controllers/AttendanceReportController.php tests/Feature/AttendanceReportDataTest.php tests/Feature/AttendanceReportSummaryTest.php
git commit -m "feat(attendance-report): return a ledger payload instead of a roster

Rows, lens counts and period totals replace the strip, coverage buckets and
summary dialog. Scope still comes from DataScope and an out-of-scope ?emp= is
still refused."
```

---

### Task 5: Amend a missing clock-out

**Files:**
- Modify: `app/Http/Controllers/AttendanceAdminController.php` — add `amendClockOut()` beside `reversePunch()` (line 152)
- Modify: `routes/web.php` — beside `attendance.admin.records.reverse` (line 190)
- Modify: `tests/Feature/AttendanceAdminTest.php` — add the cases below

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `POST /app/attendance-admin/records/{record}/clock-out` named `attendance.admin.records.amend`, accepting `time` as `H:i`.

> **Why this task exists.** The screen's `Fix` button is the answer to the stated
> recurring pain point, and **there is no endpoint behind it.** The only writes
> to `clock_out` today are `ClockService` (the employee clocking themselves) and
> `reversePunch` (which clears it). This was missed until the plan's self-review;
> without it, `Fix` is a button that cannot work.

**Two decisions this task locks in:**

1. **Same gate as reversing.** Typing a clock-out for somebody is an equivalent-weight write to voiding one, so it takes `REVERSE_ROLES` (`hr`, `director`, super-admin) — not the wider screen gate.
2. **An amended punch is marked as one.** An HR-typed time has no selfie and no coordinates; it is not a punch. The record gets an `amended` flag so nothing downstream mistakes it for one. `flags` is already an array-cast JSON column, so this needs no migration.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AttendanceAdminTest.php`:

```php
    public function test_hr_can_set_a_missing_clock_out(): void
    {
        $record = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $this->actAsHr()
            ->post(route('attendance.admin.records.amend', $record), ['time' => '18:00'])
            ->assertRedirect();

        $record->refresh();

        $this->assertSame('18:00:00', $record->clock_out);
        $this->assertSame(540, $record->worked_minutes);
        $this->assertContains('amended', $record->flags, 'a typed time is not a punch');
    }

    public function test_a_short_amended_day_picks_up_the_short_hours_flag(): void
    {
        $record = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $this->actAsHr()->post(route('attendance.admin.records.amend', $record), ['time' => '12:00']);

        $this->assertContains('short_hours', $record->refresh()->flags);
    }

    public function test_a_clock_out_before_the_clock_in_is_rejected(): void
    {
        $record = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $this->actAsHr()
            ->post(route('attendance.admin.records.amend', $record), ['time' => '08:00'])
            ->assertSessionHasErrors('time');

        $this->assertNull($record->refresh()->clock_out);
    }

    public function test_a_record_that_already_has_a_clock_out_is_refused(): void
    {
        $record = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => '17:00:00',
            'status' => 'on_time', 'worked_minutes' => 480, 'flags' => [],
        ]);

        $this->actAsHr()
            ->post(route('attendance.admin.records.amend', $record), ['time' => '18:00'])
            ->assertStatus(422);

        $this->assertSame('17:00:00', $record->refresh()->clock_out,
            'reverse the clock-out first; this endpoint only fills a hole');
    }

    public function test_a_manager_cannot_amend_a_clock_out(): void
    {
        $record = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $this->actAsManager()
            ->post(route('attendance.admin.records.amend', $record), ['time' => '18:00'])
            ->assertForbidden();
    }

    public function test_the_amendment_is_recorded_in_the_audit_trail(): void
    {
        $record = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-08-18', 'clock_in' => '09:00:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $this->actAsHr()->post(route('attendance.admin.records.amend', $record), ['time' => '18:00']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'Amended clock-out']);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `lerd artisan test --compact --filter='amend|clock_out' tests/Feature/AttendanceAdminTest.php`
Expected: FAIL — `Route [attendance.admin.records.amend] not defined`

- [ ] **Step 3: Add the route**

In `routes/web.php`, directly under the reverse route at line 190:

```php
Route::post('/app/attendance-admin/records/{record}/clock-out', [AttendanceAdminController::class, 'amendClockOut'])
    ->name('attendance.admin.records.amend');
```

- [ ] **Step 4: Write the method**

Add to `AttendanceAdminController`, directly after `reversePunch()`:

```php
    /**
     * Fill in a clock-out somebody forgot. Only ever fills a HOLE: a record that
     * already has a clock-out must be reversed first, so there is exactly one way
     * to overwrite a real punch and it leaves two audit entries rather than one.
     *
     * The typed time carries no selfie and no coordinates, so it is not a punch and
     * is marked `amended`. Location-derived flags are deliberately NOT recomputed —
     * inventing an out_of_radius verdict for a time nobody stood anywhere to record
     * would be a fabricated fact in an audit trail.
     */
    public function amendClockOut(Request $request, AttendanceRecord $record): RedirectResponse
    {
        $this->authorizeReverse($request);
        $this->assertTenant($record->tenant_id);

        abort_if($record->clock_in === null, 422);
        abort_if($record->clock_out !== null, 422);

        $validated = $request->validate([
            'time' => ['required', 'date_format:H:i'],
        ]);

        $in = CarbonImmutable::parse($record->date->toDateString().' '.$record->clock_in);
        $out = CarbonImmutable::parse($record->date->toDateString().' '.$validated['time']);

        if ($out->lte($in)) {
            return back()->withErrors([
                'time' => 'The clock-out must be after the '.$in->format('H:i').' clock-in.',
            ]);
        }

        $minutes = $in->diffInMinutes($out);
        $expected = (float) ($record->expected_min_hours ?? 8);

        $flags = $record->flags ?? [];
        if (! in_array('amended', $flags, true)) {
            $flags[] = 'amended';
        }
        if ($minutes < $expected * 60 && ! in_array('short_hours', $flags, true)) {
            $flags[] = 'short_hours';
        }

        $record->update([
            'clock_out' => $out->format('H:i:s'),
            'worked_minutes' => $minutes,
            'flags' => array_values($flags),
        ]);

        AuditLog::record(
            'Amended clock-out',
            ($record->employee?->name ?? 'Unknown employee')
                .' · '.$record->date->format('j M').' · set to '.$out->format('H:i')
        );

        return back()->with('ok', 'Clock-out set to '.$out->format('H:i').'.');
    }
```

Add `use Carbon\CarbonImmutable;` if it is not already imported.

- [ ] **Step 5: Run to verify they pass**

Run: `lerd artisan test --compact tests/Feature/AttendanceAdminTest.php`
Expected: PASS (the six new cases plus the file's existing ones)

- [ ] **Step 6: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Http/Controllers/AttendanceAdminController.php
git add app/Http/Controllers/AttendanceAdminController.php routes/web.php tests/Feature/AttendanceAdminTest.php
git commit -m "feat(attendance): let HR fill in a forgotten clock-out

Only ever fills a hole — a record that already has a clock-out must be reversed
first. The typed time has no selfie and no coordinates, so it is flagged amended
and location verdicts are not invented for it."
```

---

### Task 6: CSV export

**Files:**
- Create: `app/Http/Controllers/AttendanceReportExportController.php`
- Create: `tests/Feature/AttendanceReportExportTest.php`
- Modify: `routes/web.php` (beside the other report routes, inside the same auth/tenant group that guards `/app/attendance-report`)

**Interfaces:**
- Consumes: `AttendanceReportController::screenData()` from Task 4 — the export re-uses the exact payload the screen shows, so the file can never disagree with the table.
- Produces: `GET /app/attendance-report/export` named `attendance.report.export`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00:00'));

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $this->hr = User::create([
            'name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password'),
        ]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->hr->id,
            'name' => 'Alice Tan', 'status' => 'active', 'workload' => 'green',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'date' => '2026-08-18', 'clock_in' => '08:52:00', 'clock_out' => '17:35:00',
            'status' => 'on_time', 'worked_minutes' => 523, 'flags' => [],
        ]);
    }

    private function download(array $query = []): string
    {
        $response = $this->actingAs($this->hr)
            ->get('/app/attendance-report/export?'.http_build_query($query));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        return $response->streamedContent();
    }

    public function test_it_exports_a_header_and_one_row_per_person_day(): void
    {
        $csv = $this->download(['gran' => 'custom', 'from' => '2026-08-18', 'to' => '2026-08-18']);
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertStringContainsString('Date,Staff,Department,Clock in,Clock out,Hours,Status,Flags', $lines[0]);
        $this->assertStringContainsString('2026-08-18', $lines[1]);
        $this->assertStringContainsString('Alice Tan', $lines[1]);
        $this->assertStringContainsString('08:52', $lines[1]);
        $this->assertStringContainsString('17:35', $lines[1]);
        $this->assertStringContainsString('8.72', $lines[1]);
        $this->assertStringContainsString('On time', $lines[1]);
    }

    public function test_the_filename_names_the_period(): void
    {
        $response = $this->actingAs($this->hr)
            ->get('/app/attendance-report/export?gran=custom&from=2026-08-10&to=2026-08-14');

        $response->assertDownload('attendance-2026-08-10-to-2026-08-14.csv');
    }

    public function test_the_export_honours_the_lens(): void
    {
        $all = $this->download(['gran' => 'custom', 'from' => '2026-08-18', 'to' => '2026-08-18']);
        $lensed = $this->download(['gran' => 'custom', 'from' => '2026-08-18', 'to' => '2026-08-18', 'lens' => 'miss']);

        $this->assertGreaterThan(
            substr_count(trim($lensed), "\n"),
            substr_count(trim($all), "\n"),
            'exporting what you filtered to is the whole point'
        );
    }

    public function test_a_name_beginning_with_a_formula_character_is_neutralised(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => '=cmd|calc',
            'status' => 'active', 'workload' => 'green',
        ]);

        $csv = $this->download(['gran' => 'custom', 'from' => '2026-08-18', 'to' => '2026-08-18']);

        $this->assertStringContainsString("'=cmd|calc", $csv, 'CWE-1236: must not open as a formula');
    }

    public function test_the_export_is_recorded_in_the_audit_trail(): void
    {
        $this->download(['gran' => 'custom', 'from' => '2026-08-18', 'to' => '2026-08-18']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'Exported attendance report']);
    }

    public function test_a_plain_employee_cannot_export(): void
    {
        $employee = User::create([
            'name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('password'),
        ]);
        $employee->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->actingAs($employee)->get('/app/attendance-report/export')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `lerd artisan test --compact tests/Feature/AttendanceReportExportTest.php`
Expected: FAIL — 404, the route does not exist

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the same group that already guards `/app/attendance-report` (near the `attendance.admin.records.reverse` route at line 190):

```php
Route::get('/app/attendance-report/export', [AttendanceReportExportController::class, 'download'])
    ->name('attendance.report.export');
```

Add `use App\Http\Controllers\AttendanceReportExportController;` to the imports.

> **Route order matters.** `Route::get('/app/{screen?}', …)` at
> `routes/web.php:484` is a catch-all. Laravel matches in registration order, so
> an export route declared *after* it never fires — `{screen?}` swallows
> `attendance-report/export` and you get the screen, not a file. Register it
> above line 484. The test in Step 1 asserts a CSV content-type, so this failure
> shows up as "expected text/csv, got text/html" rather than as a 404.

- [ ] **Step 4: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\Csv;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The attendance ledger as a CSV Excel opens on a double-click.
 *
 * Deliberately not .xlsx: that needs a spreadsheet dependency, and the rest of
 * this app (employees, payroll) already exports plain CSV through App\Support\Csv.
 * The trade is no bold header and no second sheet — see the design doc.
 */
class AttendanceReportExportController extends Controller
{
    private const HEADER = ['Date', 'Staff', 'Department', 'Clock in', 'Clock out', 'Hours', 'Status', 'Flags'];

    private const STATUS_LABEL = [
        'ontime' => 'On time', 'late' => 'Late', 'miss' => 'Missing clock-out',
        'absent' => 'No punch', 'leave' => 'On leave', 'half' => 'Half day',
        'pending' => 'Pending',
    ];

    private const FLAG_LABEL = [
        'off' => 'Off-site', 'visit' => 'Site visit', 'short' => 'Short hours',
        'early' => 'Left early', 'noloc' => 'No location',
    ];

    public function download(Request $request): StreamedResponse
    {
        abort_unless(
            (bool) $request->user()?->isSuperAdmin()
                || $this->hasTenantRole($request, Permissions::OVERSIGHT_ROLES),
            403
        );

        // Re-uses the screen's own payload, so the file can never disagree with
        // the table the user was looking at when they pressed the button.
        $data = app(AttendanceReportController::class)->screenData($request);
        $rows = $data['rows'];

        AuditLog::record(
            'Exported attendance report',
            $data['rangeLabel']['en'].' · '.$rows->count().' rows'
        );

        $filename = $data['from'] === $data['to']
            ? 'attendance-'.$data['from'].'.csv'
            : 'attendance-'.$data['from'].'-to-'.$data['to'].'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM so Excel reads the UTF-8 names correctly rather than as mojibake.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADER);

            foreach ($rows as $row) {
                $flags = array_map(fn (string $f) => self::FLAG_LABEL[$f] ?? $f, $row['flags']);
                if ($row['leaveType']) {
                    $flags[] = $row['leaveType'];
                }

                // Staff names are user-controlled — neutralise CSV injection (CWE-1236).
                fputcsv($out, Csv::safeRow([
                    $row['date'],
                    $row['name'],
                    $row['dept'] ?? '',
                    $row['in'] ?? '',
                    $row['out'] ?? '',
                    $row['hours'] !== null ? number_format($row['hours'], 2, '.', '') : '',
                    self::STATUS_LABEL[$row['status']] ?? $row['status'],
                    implode('; ', $flags),
                ]));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `lerd artisan test --compact tests/Feature/AttendanceReportExportTest.php`
Expected: PASS, 6 tests

- [ ] **Step 6: Format, analyse, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Http/Controllers/AttendanceReportExportController.php
git add app/Http/Controllers/AttendanceReportExportController.php routes/web.php tests/Feature/AttendanceReportExportTest.php
git commit -m "feat(attendance-report): export the filtered ledger as CSV

Re-uses the screen's own payload so the file always matches the table, writes an
audit entry, and neutralises formula injection in staff names."
```

---

### Task 7: CSS for the ledger screen

**Files:**
- Modify: `resources/css/app.css` — replace the `uj-ar-*` block (currently starting ~line 1653, comment "Namespaced uj-ar- (attendance report)")

**Interfaces:**
- Consumes: nothing.
- Produces: the class names the Blade in Tasks 8–9 uses: `.uj-ar-filter`, `.uj-ar-seg`, `.uj-ar-month`, `.uj-ar-search`, `.uj-ar-lensrow`, `.uj-ar-chips`, `.uj-ar-chip`, `.uj-ar-sum`, `.uj-ar-tbl`, `.uj-ar-cols`, `.uj-ar-thead`, `.uj-ar-row`, `.uj-ar-date`, `.uj-ar-who`, `.uj-ar-av`, `.uj-ar-t`, `.uj-ar-hrs`, `.uj-ar-stamp`, `.uj-ar-flag`, `.uj-ar-fix`, `.uj-ar-empty`, `.uj-ar-drawer`, `.uj-ar-dr-head`, `.uj-ar-dr-sum`, `.uj-ar-dr-days`, `.uj-ar-day`, `.uj-ar-day-btn`, `.uj-ar-day-panel`, `.uj-ar-shots`, `.uj-ar-shot`, `.uj-ar-note`, `.uj-ar-day-acts`, `.uj-ar-lightbox`.

- [ ] **Step 1: Port the mockup's CSS**

Copy the stylesheet from `public/_attendance-report-ledger.html` (the `<style>` block) into the `uj-ar-*` section of `resources/css/app.css`, with these changes:

1. **Rename every `.ar-` class to `.uj-ar-`** to match the app's namespace.
2. **Delete the token block** (`:root{--red:…}`) — `app.css` already defines all of them.
3. **Delete the harness**: `.proto-bar`, `#stageframe`, `#stage`, and every `html[data-vp="phone"]` rule. Those exist only to preview a phone inside a desktop window.
4. **Convert the container queries to viewport media queries.** The mockup used `@container (max-width:760px)` because it previewed a narrow frame inside a wide window. In the app, use `@media (max-width: 760px)`. The rules inside are unchanged.
5. **Keep** the `prefers-reduced-motion` block, the scroll-edge `::after` gradient on `.uj-ar-thead`, and the `::selection` / scrollbar theming.

- [ ] **Step 2: Verify the tokens all resolve**

Run:
```bash
grep -oP '(?<=var\()--[a-z-]+' resources/css/app.css | sort -u > /tmp/used.txt
grep -oP '^\s+\K--[a-z-]+(?=:)' resources/css/app.css | sort -u > /tmp/defined.txt
comm -23 /tmp/used.txt /tmp/defined.txt
```
Expected: empty output. Any name printed is a token the ledger CSS uses that `app.css` does not define — add it or switch to an existing one.

- [ ] **Step 3: Build and commit**

```bash
lerd artisan view:cache   # REQUIRED before build: makes the Tailwind scan complete
bun run build
git add resources/css/app.css public/build
git commit -m "style(attendance-report): ledger table, lens row and person drawer

Ported from the approved mockup, renamed into the uj-ar namespace, container
queries swapped for viewport media queries now there is no preview frame."
```

---

### Task 8: The ledger screen

**Files:**
- Modify: `resources/views/screens/attendance-report.blade.php` (full rewrite)
- Modify: `tests/Feature/AttendanceReportScreenTest.php`

**Interfaces:**
- Consumes: the `screenData()` payload from Task 4, the CSS from Task 7.
- Produces: the rendered table; the drawer is Task 8.

> **Read first:** `public/_attendance-report-ledger.html`. Every string, column
> order and interaction below is in it. Do not invent copy.

- [ ] **Step 1: Update the screen test**

Keep `setUp()`. Rewrite the tests to assert the ledger:

```php
    public function test_an_employee_with_no_records_is_named_on_the_screen(): void
    {
        $this->actAsHr()->get('/app/attendance-report')
            ->assertOk()
            ->assertSee($this->employee->name);
    }

    public function test_each_working_day_produces_a_row(): void
    {
        $response = $this->actAsHr()->get('/app/attendance-report?gran=week');

        $this->assertSame(
            count($response->viewData('workingDays')),
            substr_count($response->getContent(), 'uj-ar-row'),
            'one row per working day for the single employee'
        );
    }

    public function test_a_missing_clock_out_row_is_flagged_and_offers_a_fix(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-07-14', 'clock_in' => '09:00:00', 'clock_out' => null,
            'status' => 'on_time', 'flags' => [],
        ]);

        $response = $this->actAsHr()->get('/app/attendance-report?gran=week&lens=miss');

        $response->assertSee('data-alert', false);
        $response->assertSee('Missing', false);
    }

    public function test_the_totals_row_reports_hours(): void
    {
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'date' => '2026-07-14', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00',
            'status' => 'on_time', 'worked_minutes' => 540, 'flags' => [],
        ]);

        $this->actAsHr()->get('/app/attendance-report?gran=week')
            ->assertSee('9.0')
            ->assertSee('total hours');
    }

    public function test_a_plain_employee_cannot_reach_the_report(): void
    {
        $this->actAsEmployee()->get('/app/attendance-report')->assertForbidden();
    }

    public function test_the_export_button_carries_the_current_filters(): void
    {
        $this->actAsHr()->get('/app/attendance-report?gran=week&lens=late&dept=Finance')
            ->assertSee('lens=late', false)
            ->assertSee('dept=Finance', false);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `lerd artisan test --compact tests/Feature/AttendanceReportScreenTest.php`
Expected: FAIL — the old Blade renders `uj-ar-r`, not `uj-ar-row`

- [ ] **Step 3: Write the Blade**

Rewrite `resources/views/screens/attendance-report.blade.php` following the mockup's structure. Required elements, in order:

1. `@include('partials.guide', [...])` — **update the body and steps** to describe the ledger, not the roster. `ScreenGuideTest` asserts this block exists with `'key' => 'attendance-report'`; keep the key.
2. `<h1>` Attendance + the range subline, both bilingual.
3. Filter bar (`.uj-ar-filter`, hidden below 760px): granularity segment, month stepper with prev/next disabled per `canPrev`/`canNext`, Custom range button, department `<select>`, name search `<input>`, sort segment. All are plain `<a href>`/`<form method=get>` links carrying the other query params, so the screen works without JavaScript.
4. Lens row (`.uj-ar-lensrow`): five chips as links carrying `lens=`; Export as an `<a>` to `route('attendance.report.export', request()->query())`.
5. Totals (`.uj-ar-sum`), captioned from `captionKey`, with the leave-by-type line.
6. Table: header row hidden when `gran === 'day'`; one `.uj-ar-row` per row; `data-alert` when `status === 'miss'`; `Fix` button on those rows only.

   The row markup the tests pin, so the class names and attributes are not left
   to taste:

```blade
<div class="uj-ar-cols uj-ar-row" data-staff="{{ $row['employeeId'] }}"
     @if($row['status'] === 'miss') data-alert @endif>
    @if ($gran !== 'day')
        <span class="c-date uj-ar-date">{{ $d->day }} {{ $mon }}<em>{{ $dow }}</em></span>
    @endif
    <span class="c-who uj-ar-who">
        <a href="{{ $baseUrl.'&emp='.$row['employeeId'] }}" class="uj-ar-person">
            <span class="uj-ar-av" style="background:{{ $row['color'] }}">{{ $row['initials'] }}</span>
            <span class="nm"><b>{{ $row['name'] }}</b><span>{{ $row['dept'] ?? '—' }}</span></span>
        </a>
    </span>
    <span class="c-in uj-ar-t uj-ar-num" @if(! $row['in']) data-nil @endif>{{ $row['in'] ?? '—' }}</span>
    <span class="c-out uj-ar-num">
        @if ($row['out'])
            <span class="uj-ar-t">{{ $row['out'] }}</span>
        @elseif ($row['status'] === 'miss')
            <span class="uj-ar-stamp" data-t="miss"><i></i><span
                x-text="$store.ui.lang==='en' ? 'Missing' : 'Tiada'">Missing</span></span>
        @else
            <span class="uj-ar-t" data-nil>—</span>
        @endif
    </span>
    <span class="c-hours uj-ar-hrs uj-ar-num" @if($row['hours'] === null) data-nil @endif>
        {{ $row['hours'] !== null ? number_format($row['hours'], 2) : '—' }}
    </span>
    <span class="c-status">
        <span class="uj-ar-stamp" data-t="{{ $row['status'] }}"><i></i><span
            x-text="$store.ui.lang==='en' ? @js($statusEn) : @js($statusMs)">{{ $statusEn }}</span></span>
    </span>
    <span class="c-flags uj-ar-flags">
        {{-- Decision 9: an off-site or site-visit chip is the only row element with
             real coordinates behind it, so it IS the control that opens the map.
             No extra column, and the affordance sits on the thing it explains. --}}
        @foreach ($row['flags'] as $flag)
            @if ($canSeeLocation && $row['hasPoint'] && in_array($flag, ['off', 'visit'], true))
                <button type="button" class="uj-ar-flag" data-t="{{ $flag }}" x-data
                        @click="window.dispatchEvent(new CustomEvent('open-map-view', { detail: @js($mapDetail) }))">
                    <span x-text="$store.ui.lang==='en' ? @js($flagEn) : @js($flagMs)">{{ $flagEn }}</span>
                    <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path
                         d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
                </button>
            @else
                <span class="uj-ar-flag" data-t="{{ $flag }}"
                      x-text="$store.ui.lang==='en' ? @js($flagEn) : @js($flagMs)">{{ $flagEn }}</span>
            @endif
        @endforeach
    </span>
    <span class="c-fix">
        @if ($row['status'] === 'miss' && $canReversePunch)
            <a href="{{ $baseUrl.'&emp='.$row['employeeId'].'&day='.$row['date'] }}" class="uj-ar-fix"
               x-text="$store.ui.lang==='en' ? 'Fix' : 'Betulkan'">Fix</a>
        @endif
    </span>
</div>
```

   `Fix` deep-links into the drawer with that day already expanded, so the
   one-click path and the considered path are the same screen.

7. Empty state when `rows` is empty.
8. `@include('partials.attendance-report.person-drawer')` (Task 9).
9. `@include('partials.map-view')` — unchanged, still consumes the `open-map-view` event.

Use `@js()` for every string handed to Alpine, and `x-text="$store.ui.lang==='en' ? … : …"` for bilingual text, exactly as the current file does.

Follow the **No full-page reloads** rule: filter links go through `resources/js/partial-nav.js` so only the screen body swaps and `pushState` keeps the URL honest.

- [ ] **Step 4: Run the screen tests**

Run: `lerd artisan test --compact --filter='AttendanceReportScreen|ScreenGuide|AllScreensRender'`
Expected: PASS

- [ ] **Step 5: Verify in the browser**

```bash
lerd artisan view:cache && bun run build
```
Open `http://localhost:9100/app/attendance-report` as `hr@amanahku.test`. Check: month defaults to the calendar month; Day hides the Date column; a lens narrows the rows while the totals hold still; Export downloads; the layout survives at 390px and in Malay.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/screens/attendance-report.blade.php tests/Feature/AttendanceReportScreenTest.php public/build
git commit -m "feat(attendance-report): render the ledger table, lens row and totals

Totals sit above the rows and follow scope only; the lens is a segmented control
so it reads as a control rather than as statistics."
```

---

### Task 9: The person drawer

**Files:**
- Create: `resources/views/partials/attendance-report/person-drawer.blade.php`
- Create: `tests/Feature/AttendanceReportDrawerTest.php`
- Modify: `tests/Feature/AttendanceReportLocationTest.php` — selectors only

**Interfaces:**
- Consumes: `person` from the Task 4 payload, `canReversePunch`, `canSeeLocation`, the CSS from Task 7.
- Produces: nothing downstream.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

// setUp mirrors AttendanceReportScreenTest: tenant, HR user, employee, one record.

    public function test_the_drawer_lists_the_persons_days(): void
    {
        $response = $this->actAsHr()
            ->get('/app/attendance-report?gran=week&emp='.$this->employee->id);

        $response->assertOk()
            ->assertSee('uj-ar-drawer', false)
            ->assertSee($this->employee->name);
    }

    public function test_hr_sees_a_reverse_control_in_the_drawer(): void
    {
        $this->recordFor('2026-07-14');

        $this->actAsHr()->get('/app/attendance-report?gran=week&emp='.$this->employee->id)
            ->assertSee('Reverse clock-out');
    }

    public function test_a_manager_does_not_see_a_reverse_control(): void
    {
        $this->recordFor('2026-07-14');

        $this->actAsManager()->get('/app/attendance-report?gran=week&emp='.$this->employee->id)
            ->assertDontSee('Reverse clock-out');
    }

    public function test_the_drawer_shows_the_remark_the_employee_typed(): void
    {
        $this->recordFor('2026-07-14', justification: 'Stuck on the Federal Highway.');

        $this->actAsHr()->get('/app/attendance-report?gran=week&emp='.$this->employee->id)
            ->assertSee('Stuck on the Federal Highway.');
    }

    public function test_the_drawer_offers_the_photo_when_one_was_captured(): void
    {
        $this->recordFor('2026-07-14', photo: 'attendance/2026/07/alice-in.jpg');

        $this->actAsHr()->get('/app/attendance-report?gran=week&emp='.$this->employee->id)
            ->assertSee('uj-ar-shot', false);
    }

    public function test_a_record_with_no_photo_offers_no_thumbnail(): void
    {
        $this->recordFor('2026-07-14');

        $this->actAsHr()->get('/app/attendance-report?gran=week&emp='.$this->employee->id)
            ->assertDontSee('uj-ar-shot', false);
    }

    public function test_a_drawer_for_someone_outside_scope_renders_nothing(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Not Yours',
            'status' => 'active', 'workload' => 'green',
        ]);

        $this->actAsManagerScopedTo([$this->employee->id])
            ->get('/app/attendance-report?emp='.$other->id)
            ->assertDontSee('Not Yours');
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `lerd artisan test --compact tests/Feature/AttendanceReportDrawerTest.php`
Expected: FAIL — `uj-ar-drawer` not in the response

- [ ] **Step 3: Write the partial**

`resources/views/partials/attendance-report/person-drawer.blade.php`, following the mockup:

- Renders only when `$person !== null`.
- Header: avatar, name, department, close (a link back to the screen without `emp=`).
- Their period totals: days clocked, hours, late, needs-a-fix.
- One disclosure row per day, using the app's standard pattern: a full-width button that expands a panel animating `grid-template-rows: 0fr → 1fr`. Alpine holds only `open` per row, seeded from `$person['openDay'] === $day['date']` so a `Fix` deep-link arrives with the right day already expanded.
- Expanded panel carries, in this order: the two photo thumbnails (`Storage::disk('attendance')->temporaryUrl(...)`, guarded by `canSeeLocation`-independent existence checks), the `In` / `Out` remarks, then the actions.
- Actions, all inside `@if($canReversePunch)` except the first:
  - `View location` — only when `canSeeLocation && hasPoint`, dispatching `open-map-view` with the same point shape `partials/map-view.blade.php` already consumes.
  - `Add clock-out` — when `status === 'miss'`. A `<form method="post">` to `route('attendance.admin.records.amend', $row['recordId'])` carrying `@csrf` and an `<input type="time" name="time">`, revealed inline by Alpine rather than in a modal. This is the endpoint built in Task 5.
  - `Reverse clock-in` / `Reverse clock-out` — a `<form method="post">` to `route('attendance.admin.records.reverse', $row['recordId'])` with `@csrf` and `onsubmit="return confirm(...)"`. Offer only the one the record is in for: with a clock-out it is *Reverse clock-out*, without one it is *Reverse clock-in*.

The reverse form's confirm text must name the real consequence, matching `AttendanceAdminController::reversePunch()`: with a clock-out it clears only that; without one it deletes the record.

- [ ] **Step 4: Update `AttendanceReportLocationTest` selectors**

The nine tests keep their assertions; only the markup they look for changes — `uj-ar-loc` becomes the drawer's action button. Do not weaken any assertion.

- [ ] **Step 5: Run the suites**

Run: `lerd artisan test --compact --filter='AttendanceReportDrawer|AttendanceReportLocation'`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/partials/attendance-report/person-drawer.blade.php tests/Feature/AttendanceReportDrawerTest.php tests/Feature/AttendanceReportLocationTest.php
git commit -m "feat(attendance-report): person drawer with photos, remarks, map and reverse

Reversing a punch after seeing the selfie and the typed reason is a different
decision from reversing it blind off a table row, so the control lives here."
```

---

### Task 10: Full suite, analysis, and staging

**Files:** none created; this task is the gate.

- [ ] **Step 1: Run the whole suite**

Run: `lerd artisan test --compact`
Expected: PASS. Any failure outside the attendance-report files is a regression this change caused — fix it, do not skip it.

- [ ] **Step 2: Static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: no errors. CI only runs on PRs to `main`, so this is the only gate before staging.

- [ ] **Step 3: Confirm nothing references the deleted concepts**

```bash
grep -rn "uj-ar-strip\|uj-ar-cell\|uj-ar-sumtile\|stripUnit\|bucketClocking\|'roster'" app/ resources/ tests/
```
Expected: empty. Anything left is a dangling reference to the roster view.

- [ ] **Step 4: Browser check at both breakpoints and both languages**

Open `http://localhost:9100/app/attendance-report`. Verify: Day/Week/Month/Custom all filter; totals hold still under a lens; Export downloads a file whose row count matches the table; the drawer opens, expands a day, shows photo and remark, opens the map, and reverses a punch; Malay at 390px does not clip.

- [ ] **Step 5: Commit assets and deploy to staging**

```bash
lerd artisan view:cache
bun run build
git add public/build && git commit -m "chore(attendance-report): rebuild assets"
git push origin dev:staging
ssh amanahku 'cd ~/domains/amanahku-staging.myappsonline.net/public_html && git status -sb'
ssh amanahku 'cd ~/domains/amanahku-staging.myappsonline.net/public_html && git pull origin staging && bash deploy.sh'
```

No migration in this change, so no `mysqldump` is required — but read `docs/RULES.md` before any release regardless.

- [ ] **Step 6: Test on staging, then open the PR**

Once staging passes, PR `staging` → `main`, merge, and `git push gitlab main` in the same sitting.

---

## Open decisions a builder must not invent

- **Photo URLs.** The spec assumes `Storage::disk('attendance')->temporaryUrl()`. Confirm the disk name and whether it supports temporary URLs on the staging driver before Task 9; if it does not, serve through a signed route rather than exposing a path.
- **Half-day threshold.** Fixed at 5 hours in `LedgerBuilder::HALF_DAY_MINUTES`. If Unijaya has a written policy, that number wins — ask before shipping.
- **Custom-range ceiling.** The plan does not cap how wide a custom range may be. 34 staff × 365 days is ~12k rows, which renders but is slow. If it matters, add a 92-day cap in `ReportPeriod::custom()` — but ask first, because a year-to-date export is a plausible payroll need.
