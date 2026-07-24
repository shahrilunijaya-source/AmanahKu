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
 * A fully locked day is filled to 100% and the employee cannot log work against it.
 * A half-day leave locks only 50%: the "On Leave" row covers half the day and the
 * staffer still fills the remaining half with real work, so that day must reach 100%
 * from the leave half plus their own entries. Each locked day therefore carries a
 * `percentage` (100 or 50) and, for a half day, a `period` ('am' | 'pm'). Read-only:
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
     * @return array<string, array{label: string, source: string, percentage: float, period: ?string}> keyed by ISO date, Mon–Fri only
     */
    public function forWeek(Employee $employee, CarbonInterface|string $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $end = $start->addDays(4);

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

        for ($i = 0; $i < 5; $i++) {
            $day = $start->addDays($i);
            $iso = $day->toDateString();

            if ($holiday = $holidays->get($iso)) {
                // A holiday outranks leave: nobody burns annual leave on a public holiday.
                $locked[$iso] = ['label' => $holiday->name, 'source' => 'holiday', 'percentage' => 100.0, 'period' => null];

                continue;
            }

            $covering = $leave->first(
                fn (LeaveRequest $r) => $day->betweenIncluded($r->date_from, $r->date_to)
            );

            if ($covering) {
                $locked[$iso] = $this->leaveEntry($covering);
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
        $end = $start->addDays(4);

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

            for ($i = 0; $i < 5; $i++) {
                $day = $start->addDays($i);
                $iso = $day->toDateString();

                if ($holiday = $holidays->get($iso)) {
                    $locked[$iso] = ['label' => $holiday->name, 'source' => 'holiday', 'percentage' => 100.0, 'period' => null];

                    continue;
                }

                $covering = $leave->first(
                    fn (LeaveRequest $r) => $day->betweenIncluded($r->date_from, $r->date_to)
                );

                if ($covering) {
                    $locked[$iso] = $this->leaveEntry($covering);
                }
            }

            $out[$employee->id] = $locked;
        }

        return $out;
    }

    /**
     * Shape one covering leave request as a locked-day array. A half-day request locks
     * only 50% (the staffer fills the rest); a whole-day request locks the full day.
     *
     * @return array{label: string, source: string, percentage: float, period: ?string}
     */
    private function leaveEntry(LeaveRequest $leave): array
    {
        return [
            'label' => $leave->leaveType?->name ?: 'Leave',
            'source' => 'leave',
            'percentage' => $leave->isHalfDay() ? 50.0 : 100.0,
            'period' => $leave->half_day_period,
        ];
    }

    /**
     * The same locked days shaped as timesheet_entries rows, ready to persist.
     *
     * Categories are matched by name because timesheet_categories has no stable key beyond
     * unique(tenant_id, name). A tenant that renamed or deleted the category gets no rows,
     * which is the intended fail-open: the day simply behaves as a normal working day.
     *
     * @param  CarbonInterface|string  $weekStart  See forWeek() for why this is widened beyond
     *                                             the brief's CarbonInterface-only signature.
     * @return array<int, array<string, mixed>>
     */
    public function entryRows(Employee $employee, CarbonInterface|string $weekStart): array
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

            // 100 for a holiday or whole-day leave, 50 for a half day. Hours track the
            // percentage so manday RM costing (hours * rate) stays correct for a half day.
            $percentage = (float) $day['percentage'];
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
