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
