<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Support\WorkWeek;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Single source of truth for weekly timesheet compliance.
 *
 * A week is "complete" when the sheet is finalised (submitted or approved — D6) AND
 * every weekday Mon–Fri of that week has timesheet entries summing to 100% (±0.01) —
 * the same per-day rule the capture screen enforces on submit. A weekday fully covered
 * by a public holiday or whole-day leave is not expected at all: it is read from
 * LockedDays, never from a stored generated row, which only exists when the tenant has
 * the matching "Public Holiday" / "On Leave" category. Weekend days are ignored.
 * Read-only: never writes.
 */
final class TimesheetCompliance
{
    /** Tolerance for floating per-day percentage totals. */
    private const EPSILON = 0.01;

    public function __construct(private readonly LockedDays $lockedDays) {}

    /** Monday 00:00 of $ref's ISO week. */
    public function weekStart(CarbonInterface $ref): CarbonImmutable
    {
        return CarbonImmutable::parse($ref)->startOfWeek();
    }

    /** That week's Friday 19:00 — the submission deadline (unijaya Friday checkout). ($weekStart is Monday.) */
    public function deadline(CarbonInterface $weekStart): CarbonImmutable
    {
        return CarbonImmutable::parse($weekStart)->startOfDay()->addDays(4)->setTime(19, 0);
    }

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
            && $this->weekdaysComplete($sheet->entries, $start, $this->lockedDays->forWeek($employee, $start));
    }

    /**
     * A sheet counts towards compliance only once the staffer has submitted it. 'submitted'
     * is the end of the line: nothing approves a timesheet, so there is no later status to
     * accept here.
     */
    private function isFinalised(Timesheet $sheet): bool
    {
        return $sheet->status === 'submitted';
    }

    /**
     * Not complete AND now() is at/after the Friday deadline. Drives the banner.
     *
     * When the caller has already fetched this week's sheet (e.g. the quick-actions
     * dock loads it for the % tile), pass it with $sheetLoaded=true to skip the
     * duplicate query — $sheet=null then means "loaded, none exists".
     */
    public function isLate(Employee $employee, CarbonInterface $weekStart, ?Timesheet $sheet = null, bool $sheetLoaded = false): bool
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();

        // Same eligibility gate the roster/bell apply: an inactive staffer, or one
        // who joined after the week started, is never "overdue" for that week.
        $locked = $this->lockedDays->forWeek($employee, $start);

        if (! $this->isEligible($employee, $start, $locked)) {
            return false;
        }

        if (CarbonImmutable::now()->lessThan($this->deadline($start))) {
            return false;
        }

        $complete = $sheetLoaded
            ? $sheet !== null && $this->isFinalised($sheet) && $this->weekdaysComplete($sheet->entries, $start, $locked)
            : $this->isComplete($employee, $start);

        return ! $complete;
    }

    /**
     * Whether $employee is expected to have filled the $weekStart week: active,
     * already employed when the week began, and not on leave/holiday for the whole
     * week. Mirrors the SQL filter plus locked-week exclusion in roster().
     *
     * @param  array<string, array{percentage: float}>  $locked  LockedDays::forWeek() for this employee and week
     */
    private function isEligible(Employee $employee, CarbonImmutable $weekStart, array $locked): bool
    {
        if ($employee->status !== 'active') {
            return false;
        }

        if ($employee->joined_at !== null && $employee->joined_at->greaterThan($weekStart)) {
            return false;
        }

        // Nobody is expected to file a timesheet for a week they were never at work for.
        // Only fully locked days (holiday / whole-day leave) count: a half-day leave still
        // expects the staffer to fill the other half, so it does not excuse the week.
        return $this->fullyLockedCount($locked) < count(WorkWeek::for()->workingDays());
    }

    /**
     * How many of a forWeek()/forWeekMany() day map are locked for the whole day (100%).
     * Half-day leave (50%) is excluded — the staffer is still expected to work the rest.
     *
     * @param  array<string, array{percentage: float}>  $locked
     */
    private function fullyLockedCount(array $locked): int
    {
        return count(array_filter($locked, fn (array $d) => $d['percentage'] >= 100));
    }

    /**
     * Every active, eligible employee of $tenant with their status for $weekStart.
     * Sorted late → pending → done, then by name.
     *
     * @param  list<int>|null  $visibleIds
     * @return Collection<int, array{employee: Employee, status: 'done'|'pending'|'late', filledDays: int, expectedDays: int, lastTouched: ?CarbonImmutable}>
     */
    public function roster(Tenant $tenant, CarbonInterface $weekStart, ?array $visibleIds = null): Collection
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $pastDeadline = CarbonImmutable::now()->greaterThanOrEqualTo($this->deadline($start));

        $employees = Employee::active()->attendanceTracked()->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('joined_at')->orWhereDate('joined_at', '<=', $start->toDateString()))
            ->when($visibleIds !== null, fn ($q) => $q->whereIn('id', $visibleIds))
            ->orderBy('name')
            ->get();

        // Nobody is expected to file a timesheet for a week they were never at work
        // for — drop anyone whose whole week is locked by leave/holiday. Batched
        // over the roster (forWeekMany) instead of forWeek() per employee to avoid
        // an N+1: the holiday query is identical for everyone and the leave query
        // is a single whereIn.
        $lockedByEmployee = $this->lockedDays->forWeekMany($employees, $start);
        $employees = $employees->reject(
            fn (Employee $e) => $this->fullyLockedCount($lockedByEmployee[$e->id] ?? []) >= 5
        )->values();

        $sheets = Timesheet::with('entries')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->forWeek($start)
            ->get()
            ->keyBy('employee_id');

        $rank = ['late' => 0, 'pending' => 1, 'done' => 2];

        return $employees
            ->map(function (Employee $e) use ($sheets, $start, $pastDeadline, $lockedByEmployee) {
                $sheet = $sheets->get($e->id);
                $lockedMap = $lockedByEmployee[$e->id] ?? [];
                $complete = $sheet !== null
                    && $this->isFinalised($sheet)
                    && $this->weekdaysComplete($sheet->entries, $start, $lockedMap);
                $status = $complete ? 'done' : ($pastDeadline ? 'late' : 'pending');

                $filledDays = $sheet !== null ? $this->countFilledDays($sheet->entries, $start, $lockedMap) : 0;
                $expectedDays = $this->countExpectedDays($lockedMap, $start);
                $lastTouched = $sheet?->updated_at ? CarbonImmutable::parse($sheet->updated_at) : null;

                return [
                    'employee' => $e,
                    'status' => $status,
                    'filledDays' => $filledDays,
                    'expectedDays' => $expectedDays,
                    'lastTouched' => $lastTouched,
                ];
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

    /**
     * Count how many expected weekdays Mon–Fri (not fully locked by holiday or whole-day
     * leave) have entries summing to 100% (±0.01).
     *
     * @param  array<string, array{percentage: float}>  $locked
     */
    private function countFilledDays(Collection $entries, CarbonImmutable $weekStart, array $locked): int
    {
        $byDay = [];
        foreach ($entries as $e) {
            $d = $e->entry_date->toDateString();
            $byDay[$d] = ($byDay[$d] ?? 0) + (float) $e->percentage;
        }

        $filled = 0;
        for ($i = 0; $i < 5; $i++) {
            $day = $weekStart->addDays($i)->toDateString();
            if (($locked[$day]['percentage'] ?? 0) >= 100) {
                continue;
            }
            if (isset($byDay[$day]) && abs($byDay[$day] - 100) < self::EPSILON) {
                $filled++;
            }
        }

        return $filled;
    }

    /**
     * How many weekdays Mon–Fri are expected (not fully locked 100% by leave or holiday).
     *
     * @param  array<string, array{percentage: float}>  $locked
     */
    private function countExpectedDays(array $locked, CarbonImmutable $weekStart): int
    {
        $expected = 0;
        for ($i = 0; $i < 5; $i++) {
            $day = $weekStart->addDays($i)->toDateString();
            $dayLocked = $locked[$day] ?? null;
            if ($dayLocked === null || $dayLocked['percentage'] < 100) {
                $expected++;
            }
        }

        return $expected;
    }

    /**
     * Every expected weekday Mon–Fri present and summing to 100% (±0.01).
     *
     * @param  array<string, array{percentage: float}>  $locked
     */
    private function weekdaysComplete(Collection $entries, CarbonImmutable $weekStart, array $locked): bool
    {
        return $this->countFilledDays($entries, $weekStart, $locked) === $this->countExpectedDays($locked, $weekStart);
    }
}
