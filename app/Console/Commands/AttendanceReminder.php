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
 * Two nudges in each direction. A heads-up shortly BEFORE the shift boundary, then a chase
 * AFTER it for anyone who did not act: nobody clocked in by their start time, and anyone
 * still clocked in past their end time. Tenant-aware like the leave/digest/timesheet
 * commands: the active tenant is set per loop so AppNotification rows land under the right
 * tenant scope, and the context is cleared at the end.
 *
 * Safe to run every 5 minutes: each bell carries its own per-day dedupe key, so repeat ticks
 * are no-ops rather than a stack of duplicates, and the early and late nudges for the same
 * person never collapse into one another.
 */
class AttendanceReminder extends Command
{
    protected $signature = 'attendance:remind';

    protected $description = 'Notify staff shortly before their shift starts or ends, and again if they have still not clocked in or out.';

    /** Slack allowed after the expected start / end before a late nudge goes out. */
    private const GRACE_MINUTES = 30;

    /** How far ahead of the boundary the heads-up goes out. */
    private const LEAD_MINUTES = 5;

    /**
     * Look-ahead window. One minute wider than the lead so consecutive 5-minute ticks
     * overlap and clock drift between cron and the boundary cannot drop a nudge into a
     * gap. The overlap re-offers the same person next tick; the dedupe key discards it.
     */
    private const WINDOW_MINUTES = self::LEAD_MINUTES + 1;

    private const SOON_IN_TITLE = 'Your shift starts soon';

    private const SOON_IN_BODY = 'Your shift starts in a few minutes. Open Attendance to clock in.';

    private const SOON_OUT_TITLE = 'Your shift ends soon';

    private const SOON_OUT_BODY = 'Your shift ends in a few minutes. Remember to clock out.';

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
                foreach ($targets->dueToClockIn($now, self::WINDOW_MINUTES) as $employee) {
                    $sent += (int) AppNotification::send(
                        $employee->user_id,
                        self::SOON_IN_TITLE,
                        self::SOON_IN_BODY,
                        $url,
                        'attendance-in-soon-'.$day,
                        mail: true,
                    );
                }

                foreach ($targets->dueToClockOut($now, self::WINDOW_MINUTES) as $record) {
                    $sent += (int) AppNotification::send(
                        $record->employee?->user_id,
                        self::SOON_OUT_TITLE,
                        self::SOON_OUT_BODY,
                        $url,
                        'attendance-out-soon-'.$day,
                        mail: true,
                    );
                }

                foreach ($targets->missingClockIn($now, self::GRACE_MINUTES) as $employee) {
                    $sent += (int) AppNotification::send(
                        $employee->user_id,
                        self::IN_TITLE,
                        self::IN_BODY,
                        $url,
                        'attendance-in-'.$day,
                        mail: true,
                    );
                }

                foreach ($targets->missingClockOut($now, self::GRACE_MINUTES) as $record) {
                    $sent += (int) AppNotification::send(
                        $record->employee?->user_id,
                        self::OUT_TITLE,
                        self::OUT_BODY,
                        $url,
                        'attendance-out-'.$day,
                        mail: true,
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
