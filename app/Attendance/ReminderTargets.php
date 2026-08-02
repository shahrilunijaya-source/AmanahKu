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
    /** How long the clock-in nudge window stays open when the site has no configured end time. */
    private const FALLBACK_WINDOW_HOURS = 4;

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
     * Active staff whose shift starts within the next `$windowMinutes` and who have not
     * clocked in yet — the heads-up before the boundary, as opposed to the chase after it.
     *
     * Shares every exclusion with missingClockIn(): weekends, public holidays, approved
     * leave, staff with no login account, and sites with no configured start time.
     *
     * @return Collection<int, Employee>
     */
    public function dueToClockIn(Carbon $now, int $windowMinutes): Collection
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
            ->filter(function (Employee $employee) use ($now, $windowMinutes): bool {
                $site = $this->resolver->resolve($employee, $now);

                if ($site->workStart === null) {
                    return false;
                }

                return $this->startsWithin($now, $site->workStart, $windowMinutes);
            })
            ->values();
    }

    /**
     * Today's open records whose expected end falls within the next `$windowMinutes`.
     *
     * Like missingClockOut(), this reads the `expected_end` stamped onto the record at
     * clock-in rather than re-resolving the schedule.
     *
     * @return Collection<int, AttendanceRecord>
     */
    public function dueToClockOut(Carbon $now, int $windowMinutes): Collection
    {
        return AttendanceRecord::query()
            ->onDate($now)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->whereNotNull('expected_end')
            ->with('employee')
            ->get()
            ->filter(function (AttendanceRecord $record) use ($now, $windowMinutes): bool {
                if ($record->employee?->user_id === null) {
                    return false;
                }

                return $this->startsWithin($now, $record->expected_end, $windowMinutes);
            })
            ->values();
    }

    /**
     * True when today's `$time` is still ahead of `$now` but no more than `$windowMinutes` away.
     *
     * Half-open on purpose: the exact boundary minute belongs to the late path, so a nudge
     * cannot fire twice for the same moment from both directions.
     */
    private function startsWithin(Carbon $now, string $time, int $windowMinutes): bool
    {
        $moment = $now->copy()->setTimeFromTimeString($time);

        return $moment->greaterThan($now) && $moment->lessThanOrEqualTo($now->copy()->addMinutes($windowMinutes));
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

        // ponytail: single business day only. An overnight shift (work_end < work_start)
        // puts $cutoff before $due, so this silently never fires for those staff. The whole
        // attendance subsystem shares that assumption (ClockService::isLate / isEarly /
        // minutesBetween), so fixing it here alone would not make night shifts work —
        // roll the day over in all of them together if night shifts ever land.
        $cutoff = $site->workEnd !== null
            ? $now->copy()->setTimeFromTimeString($site->workEnd)
            : $due->copy()->addHours(self::FALLBACK_WINDOW_HOURS);

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
