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
