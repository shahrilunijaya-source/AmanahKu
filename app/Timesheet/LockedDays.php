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
 * Which working days of a timesheet week are already accounted for by a fact HR owns:
 * an approved leave request, or a public holiday.
 *
 * A fully locked day is filled to that day's capacity (100%, or 50% on the first Saturday
 * of the month — Unijaya's TOT half day, see DayCapacity) and the employee cannot log work
 * against it.
 * A half-day leave locks only 50%: the "On Leave" row covers half the day and the
 * staffer still fills the remaining half with real work, so that day must reach 100%
 * from the leave half plus their own entries. Each locked day therefore carries a
 * `percentage` (the day's capacity, or half of it for a half-day leave) and, for a half
 * day, a `period` ('am' | 'pm'). Read-only:
 * this class never writes. Callers persist the rows it returns.
 */
final class LockedDays
{
    /** Category names the generated rows are filed under, by source. */
    private const CATEGORY_NAME = ['holiday' => 'Public Holiday', 'leave' => 'On Leave'];

    /**
     * @param  CarbonInterface|string  $weekStart  Accepts a raw date string too (widened beyond the
     *                                             brief's CarbonInterface-only signature) because
     *                                             CarbonImmutable::parse() already normalizes either,
     *                                             and this is a strictly backward-compatible superset.
     * @return array<string, array{label: string, source: string, percentage: float, period: ?string}> keyed by ISO date, working days only
     */
    public function forWeek(Employee $employee, CarbonInterface|string $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $end = $start->addDays(5);

        // whereDate() (not whereBetween) because the 'date' column's stored value is not
        // guaranteed to be a bare Y-m-d string: SQLite (the test driver) preserves whatever
        // Eloquent's 'date' cast writes, which includes a " 00:00:00" suffix, so a raw
        // whereBetween upper-bound string comparison silently drops a holiday that falls on
        // $end (Friday) — "2026-06-26 00:00:00" sorts after "2026-06-26". whereDate() casts
        // the column with SQL DATE() before comparing, sidestepping the format entirely.
        $holidays = PublicHoliday::whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->keyBy(fn (PublicHoliday $h) => CarbonImmutable::parse($h->date)->toDateString());

        $leave = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $end->toDateString())
            ->whereDate('date_to', '>=', $start->toDateString())
            ->get();

        $locked = [];

        foreach ($this->workingDays($start) as $day) {
            $iso = $day->toDateString();

            if ($holiday = $holidays->get($iso)) {
                // A holiday outranks leave: nobody burns annual leave on a public holiday.
                $locked[$iso] = ['label' => $holiday->name, 'source' => 'holiday', 'percentage' => DayCapacity::for($day), 'period' => null];

                continue;
            }

            $covering = $leave->first(
                fn (LeaveRequest $r) => $day->betweenIncluded($r->date_from, $r->date_to)
            );

            if ($covering) {
                $locked[$iso] = $this->leaveEntry($covering, $day);
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
     * @param  Collection<int, Employee>  $employees
     * @param  CarbonInterface|string  $weekStart  See forWeek() for why this is widened beyond
     *                                             the brief's CarbonInterface-only signature.
     * @return array<int, array<string, array{label: string, source: string, percentage: float, period: ?string}>>
     */
    public function forWeekMany(Collection $employees, CarbonInterface|string $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $end = $start->addDays(5);

        // whereDate() (not whereBetween) because the 'date' column's stored value is not
        // guaranteed to be a bare Y-m-d string: SQLite (the test driver) preserves whatever
        // Eloquent's 'date' cast writes, which includes a " 00:00:00" suffix, so a raw
        // whereBetween upper-bound string comparison silently drops a holiday that falls on
        // $end (Friday) — "2026-06-26 00:00:00" sorts after "2026-06-26". whereDate() casts
        // the column with SQL DATE() before comparing, sidestepping the format entirely.
        $holidays = PublicHoliday::whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->keyBy(fn (PublicHoliday $h) => CarbonImmutable::parse($h->date)->toDateString());

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

            foreach ($this->workingDays($start) as $day) {
                $iso = $day->toDateString();

                if ($holiday = $holidays->get($iso)) {
                    $locked[$iso] = ['label' => $holiday->name, 'source' => 'holiday', 'percentage' => DayCapacity::for($day), 'period' => null];

                    continue;
                }

                $covering = $leave->first(
                    fn (LeaveRequest $r) => $day->betweenIncluded($r->date_from, $r->date_to)
                );

                if ($covering) {
                    $locked[$iso] = $this->leaveEntry($covering, $day);
                }
            }

            $out[$employee->id] = $locked;
        }

        return $out;
    }

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

    /**
     * Shape one covering leave request as a locked-day array. A half-day request locks half
     * the day's capacity (the staffer fills the rest); a whole-day request locks all of it.
     * On the TOT Saturday capacity is 50%, so a whole day there locks 50 and a half day 25.
     *
     * @return array{label: string, source: string, percentage: float, period: ?string}
     */
    private function leaveEntry(LeaveRequest $leave, CarbonImmutable $day): array
    {
        $capacity = DayCapacity::for($day);

        return [
            'label' => $leave->leaveType?->name ?: 'Leave',
            'source' => 'leave',
            'percentage' => $leave->isHalfDay() ? $capacity / 2 : $capacity,
            'period' => $leave->half_day_period,
        ];
    }

    /**
     * Whether a locked day's staffer-typed rows survive the merge, or get dropped in favour
     * of the generated row filling the whole day. A public holiday always keeps them — see
     * entryRows(), which shrinks the generated Public Holiday row to whatever capacity the
     * typed rows leave, rather than dropping the typed rows. A day with no locked fact at
     * all, or one locked to less than its own capacity (a half-day leave), also keeps them.
     * Only a day locked to its FULL capacity by something other than a holiday — a
     * whole-day approved leave — drops them: that generated row is a fact HR owns, and it
     * already fills the day, so anything typed against it is wrong by definition.
     *
     * @param  array{label: string, source: string, percentage: float, period: ?string}|null  $day
     */
    public function keepsTypedRows(?array $day, CarbonInterface|string $date): bool
    {
        return $day === null || $day['source'] === 'holiday' || $day['percentage'] < DayCapacity::for($date);
    }

    /**
     * The same locked days shaped as timesheet_entries rows, ready to persist.
     *
     * Categories are matched by name because timesheet_categories has no stable key beyond
     * unique(tenant_id, name). A tenant that renamed or deleted the category gets no rows,
     * which is the intended fail-open: the day simply behaves as a normal working day.
     *
     * $userRows (already filtered to the rows keepsTypedRows() keeps — normally the caller's
     * $kept) lets a Public Holiday row shrink to the remainder: capacity minus whatever the
     * staffer typed that date, floored at 0 and omitted entirely once it hits 0. Leave rows
     * are unaffected — a whole-day leave never receives typed rows to begin with, and a
     * half-day leave already carries only half the capacity regardless of what is typed.
     *
     * @param  CarbonInterface|string  $weekStart  See forWeek() for why this is widened beyond
     *                                             the brief's CarbonInterface-only signature.
     * @param  array<int, array{entry_date: string, percentage: float|int|string}>  $userRows
     * @return array<int, array<string, mixed>>
     */
    public function entryRows(Employee $employee, CarbonInterface|string $weekStart, array $userRows = []): array
    {
        $locked = $this->forWeek($employee, $weekStart);

        if ($locked === []) {
            return [];
        }

        $categories = TimesheetCategory::whereIn('name', array_values(self::CATEGORY_NAME))
            ->get()
            ->keyBy('name');

        $hoursPerDay = (float) config('manday.hours_per_day', 8);

        $typedByDate = [];
        foreach ($userRows as $row) {
            $date = CarbonImmutable::parse($row['entry_date'])->toDateString();
            $typedByDate[$date] = ($typedByDate[$date] ?? 0.0) + (float) $row['percentage'];
        }

        $rows = [];

        foreach ($locked as $iso => $day) {
            $category = $categories->get(self::CATEGORY_NAME[$day['source']]);

            if (! $category) {
                continue;
            }

            // The day's full capacity for a holiday or whole-day leave, half of it for a
            // half day. A holiday's row shrinks by whatever the staffer typed that date —
            // see the docblock above — and is skipped once nothing is left to fill.
            $percentage = (float) $day['percentage'];

            if ($day['source'] === 'holiday') {
                $percentage = max(0.0, $percentage - ($typedByDate[$iso] ?? 0.0));

                if ($percentage <= 0.0) {
                    continue;
                }
            }

            // Hours track the (possibly shrunk) percentage so manday RM costing
            // (hours * rate) stays correct for a half day, the TOT Saturday, and a
            // partly-worked holiday alike.
            $periodSuffix = ['am' => ' (morning)', 'pm' => ' (afternoon)'][$day['period']] ?? '';

            $rows[] = [
                'entry_date' => $iso,
                'category_id' => $category->id,
                'project_id' => null,
                'sub_pillar_id' => null,
                'percentage' => $percentage,
                'description' => null,
                // Legacy readable fallback for any code still reading the string column.
                'project' => $category->name.' — '.$day['label'].$periodSuffix,
                'hours' => round($hoursPerDay * $percentage / 100, 2),
                'source' => $day['source'],
            ];
        }

        return $rows;
    }
}
